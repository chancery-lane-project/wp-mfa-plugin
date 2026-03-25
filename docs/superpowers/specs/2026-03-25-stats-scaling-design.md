# Stats Scaling: Write Buffering and Data Retention

**Date:** 2026-03-25
**Status:** Approved

## Problem

The `wp_mfa_access_stats` table has two scaling concerns:

1. **Unbounded growth** — daily rows accumulate forever with no pruning mechanism.
2. **Write contention** — every agent request fires a synchronous `INSERT ... ON DUPLICATE KEY UPDATE` against a hot `(post_id, agent, date)` row. Under a traffic spike this causes row-level lock contention.

## Assumptions

- InnoDB is the storage engine in use (WordPress default since 5.0). Transactions and row-level locking depend on this.
- MySQL `AUTO_INCREMENT` with InnoDB assigns strictly increasing IDs; new inserts always receive IDs greater than any previously assigned ID in the same table.

## Approach: INSERT-only buffer table + scheduled flush + configurable retention with monthly rollup

### Database schema

Three tables in total (one existing, two new):

**`wp_mfa_access_stats`** (existing) — daily aggregated counters, unchanged.

**`wp_mfa_write_buffer`** (new) — one row per raw access event:
```sql
id          bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
post_id     bigint(20) unsigned NOT NULL
agent       varchar(100) NOT NULL
created_at  datetime NOT NULL
```
No unique keys. Plain `INSERT` only — zero row contention under load.

**`wp_mfa_access_stats_monthly`** (new) — rolled-up summaries of pruned daily records:
```sql
id          bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
post_id     bigint(20) unsigned NOT NULL
agent       varchar(100) NOT NULL
year        smallint(4) unsigned NOT NULL
month       tinyint(2) unsigned NOT NULL
count       int unsigned NOT NULL DEFAULT 0
UNIQUE KEY  post_agent_year_month (post_id, agent, year, month)
```

Both new tables created via `dbDelta()` on plugin activation and upgrade.

### Write flow

`AccessLogger::log_access()` calls a new `StatsRepository::buffer_access()` instead of `record_access()`. Signature is identical: `buffer_access(int $post_id, string $agent): void`. The method generates `created_at` internally via `gmdate('Y-m-d H:i:s')`. `AccessLogger` requires no other changes.

`buffer_access()` does a plain INSERT with no duplicate-key handling:

```sql
INSERT INTO wp_mfa_write_buffer (post_id, agent, created_at) VALUES (%d, %s, %s)
```

Failures are silently swallowed — a missed count is not worth failing the HTTP response.

### Buffer flush

A new WP-Cron event `wp_mfa_flush_buffer` fires every 5 minutes. Its handler is a new `BufferFlusher` class.

`BufferFlusher` processes in batches of **500** rows (class constant) and loops until the buffer is drained or the time budget is exceeded. At the start of each iteration, elapsed wall-clock time is checked; if more than **20 seconds** have elapsed since the flush began, the loop exits. This protects against `max_execution_time` (typically 30s) regardless of backlog size. Any unprocessed rows are picked up on the next 5-minute run.

Each batch:

1. Opens a transaction: `$wpdb->query('START TRANSACTION')`
2. Reads and locks up to 500 rows: `SELECT ... FROM wp_mfa_write_buffer ORDER BY id ASC LIMIT 500 FOR UPDATE`
3. If the result is empty, commits (no-op) and exits the loop
4. Records the maximum `id` from the fetched rows
5. Aggregates the rows in PHP by `(post_id, agent, date)` — date is `Y-m-d` derived from `created_at`
6. Upserts each group into `wp_mfa_access_stats` via `INSERT ... ON DUPLICATE KEY UPDATE count = count + %d`
7. Deletes the processed rows: `DELETE FROM wp_mfa_write_buffer WHERE id <= $max_id`
8. Commits; on any failure issues `ROLLBACK` and breaks the loop

Moving the `SELECT` inside the transaction with `FOR UPDATE` serialises concurrent flush processes: a second cron instance running in parallel will block at step 2 until the first transaction commits, then read the next unprocessed batch. This prevents double-counting without requiring an external mutex.

### Retention and rollup

A new WP-Cron event `wp_mfa_prune_stats` fires daily. Its handler is a new `StatsPruner` class. The cutoff is `CURDATE() - INTERVAL N DAY` (date-precise).

`StatsPruner` reads `retention_days` from the plugin options array (constructor-injected via `Options::get()`). If the stored value is not a positive integer, it falls back to 90.

`StatsPruner` processes old daily rows in batches of **500** with the same **20-second time budget** loop as `BufferFlusher`. Remaining rows are retried on the next daily run.

Each batch:

1. Opens a transaction
2. Reads and locks up to 500 rows: `SELECT ... FROM wp_mfa_access_stats WHERE access_date < CURDATE() - INTERVAL N DAY ORDER BY id ASC LIMIT 500 FOR UPDATE`
3. If the result is empty, commits and exits the loop
4. Records the IDs fetched
5. Aggregates in PHP by `(post_id, agent, year, month)`
6. Upserts each group into `wp_mfa_access_stats_monthly` via `INSERT ... ON DUPLICATE KEY UPDATE count = count + %d`
7. Deletes the fetched rows: `DELETE FROM wp_mfa_access_stats WHERE id IN (...)`
8. Commits; on any failure issues `ROLLBACK` and breaks the loop

**`DELETE WHERE id IN (...)` implementation note:** Placeholder string is constructed dynamically: `implode(',', array_fill(0, count($ids), '%d'))`.

After the batching loop, `StatsPruner` deletes orphaned buffer rows — rows in `wp_mfa_write_buffer` where `created_at < CURDATE() - INTERVAL N DAY`. These are events from cron-outage periods that will never be flushed. A single `DELETE WHERE created_at < %s` suffices; failures are silently ignored.

### Settings

A `retention_days` integer field is added to the existing plugin settings page. Minimum: 1. No maximum. Default: 90. Added to `Options::get_defaults()`. Validated as a positive integer on save; invalid values are rejected with an admin notice. `Options::get()` always returns the default for missing or invalid stored values.

### Stats page

When no date filter is active (default / "All time" state), a notice is shown below the preset links:

> "Records older than N days are not shown. Use the date filter to view daily detail within the last N days."

**Known limitation:** After pruning, the post and agent filter dropdowns only query `wp_mfa_access_stats`. Posts and agents fully pruned from daily records will not appear in the dropdowns. Not addressed in this iteration.

Monthly summary data is not surfaced in the UI in this iteration.

### New classes

| Class | Responsibility |
|---|---|
| `Stats\BufferFlusher` | Drains write buffer in time-budgeted batches, upserts to stats table |
| `Stats\StatsPruner` | Prunes old daily rows with monthly rollup; cleans up orphaned buffer rows |

Both receive `\wpdb` and the plugin options array via constructor injection. Registered as WP-Cron callbacks in `Core\Loader`.

`StatsRepository` gains:
- `buffer_access(int $post_id, string $agent): void`
- `get_buffer_rows_for_update(int $limit): array` — `SELECT ... FOR UPDATE LIMIT N` (called inside an open transaction)
- `delete_buffer_rows_up_to(int $max_id): void`
- `upsert_monthly(int $post_id, string $agent, int $year, int $month, int $count): void`
- `get_old_daily_rows_for_update(int $retention_days, int $limit): array` — `SELECT ... FOR UPDATE` (called inside an open transaction)
- `delete_daily_rows(array $ids): void`
- `delete_orphaned_buffer_rows(string $cutoff_date): void`

### Error handling

- `buffer_access()` failure: silently ignored.
- `BufferFlusher` batch failure: `ROLLBACK`; rows retried next 5-minute run.
- `StatsPruner` batch failure: `ROLLBACK`; rows retried next daily run.
- `StatsPruner` orphaned cleanup failure: silently ignored.

### Testing

- `BufferFlusherTest`: given buffer rows, assert correct aggregated upserts and `DELETE WHERE id <= $max_id`. Assert empty buffer causes early exit. Assert time-budget guard exits the loop before 20s. Assert the loop continues across batches until drained.
- `StatsPrunerTest`: given old daily rows, assert correct monthly upserts and explicit-ID deletes. Assert early exit on empty result. Assert delete does not run on transaction failure. Assert orphaned buffer rows are deleted.
- `AccessLoggerTest`: replace `record_access()` assertions with `buffer_access($post_id, $agent)` assertions.
- `StatsRepositoryTest`: add coverage for all new methods, including dynamic placeholder construction in `delete_daily_rows()`.

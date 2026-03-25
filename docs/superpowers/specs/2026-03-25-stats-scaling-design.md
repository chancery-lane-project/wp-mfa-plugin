# Stats Scaling: Write Buffering and Data Retention

**Date:** 2026-03-25
**Status:** Approved

## Problem

The `wp_mfa_access_stats` table has two scaling concerns:

1. **Unbounded growth** — daily rows accumulate forever with no pruning mechanism.
2. **Write contention** — every agent request fires a synchronous `INSERT ... ON DUPLICATE KEY UPDATE` against a hot `(post_id, agent, date)` row. Under a traffic spike this causes row-level lock contention.

## Approach: INSERT-only buffer table + scheduled flush + configurable retention with monthly rollup

### Database schema

Three tables in total (one existing, two new):

**`wp_mfa_access_stats`** (existing) — daily aggregated counters, unchanged:
```sql
id          bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
post_id     bigint(20) unsigned NOT NULL
agent       varchar(100) NOT NULL
access_date date NOT NULL
count       int unsigned NOT NULL DEFAULT 1
UNIQUE KEY  post_agent_date (post_id, agent, access_date)
KEY         access_date (access_date)
```

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

`AccessLogger::log_access()` calls a new `StatsRepository::buffer_access()` method instead of `record_access()`. `buffer_access()` does a plain INSERT:

```sql
INSERT INTO wp_mfa_write_buffer (post_id, agent, created_at) VALUES (%d, %s, %s)
```

If this INSERT fails, the error is silently swallowed — a missed access count is not worth failing the HTTP response. This follows the existing pattern.

A new WP-Cron event `wp_mfa_flush_buffer` fires every 5 minutes. Its handler is a new `BufferFlusher` class which:

1. Reads all rows from `wp_mfa_write_buffer` (bounded batch to handle very large backlogs)
2. Aggregates in PHP by `(post_id, agent, date)` — date derived from `created_at`
3. Upserts each group into `wp_mfa_access_stats` via `INSERT ... ON DUPLICATE KEY UPDATE count = count + %d`
4. Deletes the processed buffer rows by ID range

Steps 3 and 4 run inside a DB transaction. If the transaction fails, buffer rows remain and are retried on the next run.

### Retention and rollup

A new WP-Cron event `wp_mfa_prune_stats` fires daily. Its handler is a new `StatsPruner` class which:

1. Reads `retention_days` from plugin options
2. Selects daily rows in `wp_mfa_access_stats` where `access_date < (NOW() - INTERVAL N DAY)`
3. Aggregates those rows in PHP by `(post_id, agent, year, month)`
4. Upserts each group into `wp_mfa_access_stats_monthly` via `INSERT ... ON DUPLICATE KEY UPDATE count = count + %d`
5. Deletes the archived daily rows

Steps 4 and 5 are run per-batch with the delete only executing after the upsert succeeds, making the operation safe to re-run if interrupted (idempotent).

### Settings

A `retention_days` integer field is added to the existing plugin settings page. Minimum value: 1. Default: 90. Validated as a positive integer on save.

### Stats page

When no date filter is active, a notice is shown below the preset links:

> "Records older than N days are stored as monthly summaries only. Use the date filter to view daily detail within the last N days."

Monthly summary data is not included in the detail table. It is not surfaced in the UI in this iteration.

### New classes

| Class | Responsibility |
|---|---|
| `Stats\BufferFlusher` | Reads buffer, aggregates, upserts to stats table, deletes buffer rows |
| `Stats\StatsPruner` | Reads old daily rows, rolls up to monthly, deletes daily rows |

Both classes receive `\wpdb` via constructor injection. Both are registered as WP-Cron handlers in `Core\Loader`.

`StatsRepository` gains:
- `buffer_access(int $post_id, string $agent, string $created_at): void`
- `get_buffer_rows(int $limit): array`
- `delete_buffer_rows(int $min_id, int $max_id): void`
- `upsert_monthly(int $post_id, string $agent, int $year, int $month, int $count): void`
- `get_old_daily_rows(int $retention_days): array`
- `delete_daily_rows(array $ids): void`

### Error handling

- `buffer_access()` failure: silently ignored, access event dropped.
- `BufferFlusher` mid-flush failure: buffer rows remain, retried next run.
- `StatsPruner` mid-prune failure: monthly upsert is idempotent; daily rows not deleted until upsert succeeds.

### Testing

- `BufferFlusherTest`: given buffer rows, assert correct aggregated upserts and ID-range deletes are issued.
- `StatsPrunerTest`: given old daily rows, assert correct monthly upserts and daily deletes. Assert idempotency.
- `AccessLoggerTest`: assert `buffer_access()` is called, not `record_access()`.
- Update existing `StatsRepositoryTest` to cover new methods.

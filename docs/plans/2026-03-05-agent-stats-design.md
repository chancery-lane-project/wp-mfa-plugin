# Design: Agent Access Statistics

**Date:** 2026-03-05
**Status:** Approved
**Depends on:** [UA Detection](2026-03-05-ua-detection-design.md) — must be implemented first.

## Overview

Track, store, and display statistics for how many LLM agents access
pre-generated Markdown files, filterable by content and agent. Data is stored
in a custom database table as daily aggregated counters.

## Motivation

Site administrators need visibility into which agents are consuming their
Markdown content, how frequently, and which posts are most accessed. This
informs decisions about content strategy and agent management.

## Database Schema

Custom table `{$wpdb->prefix}wp_mfa_access_stats`:

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint unsigned AUTO_INCREMENT` | Primary key |
| `post_id` | `bigint unsigned NOT NULL` | FK to `posts.ID` |
| `agent` | `varchar(100) NOT NULL` | Matched UA substring or `"accept-header"` |
| `access_date` | `date NOT NULL` | Day of access |
| `count` | `int unsigned NOT NULL DEFAULT 1` | Incremented per hit |

**Indexes:**
- Primary: `id`
- Unique: `(post_id, agent, access_date)` — for upsert
- Index: `(access_date)` — for date-range queries

**Upsert pattern:**
```sql
INSERT INTO {table} (post_id, agent, access_date, count)
VALUES (%d, %s, %s, 1)
ON DUPLICATE KEY UPDATE count = count + 1
```

No data retention — stats are kept indefinitely.

## Architecture

### New class: `Stats\StatsRepository`

Query layer over the custom table. All database access goes through this class.

```php
class StatsRepository {
    public function __construct(private readonly \wpdb $wpdb) {}
    public function record_access(int $post_id, string $agent): void;
    public function get_stats(array $filters = []): array;
    public function get_total_count(array $filters = []): int;
    public function get_distinct_agents(): array;
    public function get_posts_with_stats(): array;
    public static function get_table_name(\wpdb $wpdb): string;
    public static function get_create_table_sql(\wpdb $wpdb): string;
}
```

- `record_access()` performs the daily upsert.
- `get_stats()` accepts optional `post_id`, `agent`, `limit`, `offset`.
  Returns rows sorted by `access_date DESC`. Default limit 50.
- `get_total_count()` returns row count for pagination (same filters, no
  limit/offset).
- `get_distinct_agents()` returns unique agent strings for the filter dropdown.
- `get_posts_with_stats()` returns `[ id => title ]` for posts that have at
  least one stat row.

### New class: `Stats\AccessLogger`

Thin orchestrator called by `Negotiator` before serving a file.

```php
class AccessLogger {
    public function __construct(private readonly StatsRepository $repository) {}
    public function log_access(int $post_id, string $agent): void;
}
```

- `$agent` is either the matched UA substring (from `AgentDetector`) or
  `"accept-header"`.
- Guards against `$post_id <= 0`.

### New class: `Stats\StatsPage`

Admin page registered as a top-level menu item with `dashicons-chart-bar`.

- Summary row: total accesses across all posts and agents.
- Two filter dropdowns: post (from `get_posts_with_stats()`) and agent
  (from `get_distinct_agents()`). Both optional, applied as AND.
- Results table: columns `Post Title | Agent | Date | Count`, sorted by
  date descending. Paginated at 50 rows per page.
- Simple HTML table (not `WP_List_Table`) for testability.

### Changes to `Negotiate\AgentDetector`

New method `get_matched_agent(string $ua): ?string` that returns the first
matching UA substring, or null. `Negotiator` calls this instead of
`is_known_agent()` — a non-null return is treated as a match, and the
returned string is passed to `AccessLogger` as the agent identifier.

`is_known_agent()` is retained as a convenience wrapper:
```php
public function is_known_agent(string $ua): bool {
    return null !== $this->get_matched_agent($ua);
}
```

### Changes to `Negotiate\Negotiator`

- `AccessLogger` added as a fourth constructor parameter.
- `maybe_serve_markdown()` calls `$this->access_logger->log_access()`
  right before `readfile()` / `exit`, passing the post ID and either the
  matched agent string or `"accept-header"`.
- Uses `$this->agent_detector->get_matched_agent($ua)` instead of
  `is_known_agent($ua)` to get both the match result and the agent name
  in one call.

### Changes to `Core\Activator`

`activate()` creates the custom table using `dbDelta()`.

### Changes to `Core\Plugin`

- Instantiates `StatsRepository` (with `$wpdb`).
- Instantiates `AccessLogger` (with `StatsRepository`).
- Injects `AccessLogger` into `Negotiator`.
- Registers `StatsPage` admin hooks.

## Data Flow

```
template_redirect (priority 1)
  └─ Negotiator::maybe_serve_markdown()
       ├─ is_eligible_singular()?  → no → return
       ├─ Accept: text/markdown?   → matched_agent = "accept-header"
       ├─ AgentDetector::get_matched_agent(UA)?  → matched_agent = "GPTBot"
       ├─ neither → return
       ├─ file exists + safe path?  → no → return
       ├─ AccessLogger::log_access(post_id, matched_agent)
       └─ serve file + exit
```

## Integration with UA Detection Plan

This feature depends on the UA detection plan being implemented first.
The two plans touch the same files:

| File | UA plan changes | Stats plan changes |
|------|----------------|-------------------|
| `Negotiator.php` | Add `AgentDetector` param, check UA | Add `AccessLogger` param, call `log_access()`, use `get_matched_agent()` |
| `AgentDetector.php` | Create class with `is_known_agent()` | Add `get_matched_agent()`, refactor `is_known_agent()` to use it |
| `Plugin.php` | Wire `AgentDetector` | Wire `StatsRepository`, `AccessLogger`, `StatsPage` |
| `Activator.php` | No changes | Add `dbDelta()` for custom table |
| `Options.php` | Add UA options | No changes |

**Execution order:** All UA detection plan tasks (1–6) must complete before
stats plan tasks begin.

## Testing

All tests use the existing `$wpdb` mock pattern (mock object, not SQLite).

### `StatsRepositoryTest` (new)
- `record_access()` constructs correct upsert SQL
- `get_stats()` with no filters
- `get_stats()` with `post_id` filter
- `get_stats()` with `agent` filter
- `get_stats()` with both filters
- `get_stats()` respects `limit` and `offset`
- `get_total_count()` returns count matching filters
- `get_distinct_agents()` returns unique agent strings
- `get_posts_with_stats()` returns post ID/title pairs
- `get_create_table_sql()` contains expected column definitions

### `AccessLoggerTest` (new)
- Calls `record_access()` with correct post ID and agent
- Does not call `record_access()` when post ID is 0

### `AgentDetectorTest` (extended)
- `get_matched_agent()` returns matched substring
- `get_matched_agent()` returns null for unknown UA
- `get_matched_agent()` is case-insensitive
- `get_matched_agent()` returns null when `ua_force_enabled` is false
- `is_known_agent()` still works (delegates to `get_matched_agent()`)

### `NegotiatorTest` (extended)
- `log_access()` called with correct agent when UA matches
- `log_access()` called with `"accept-header"` when Accept header matches
- `log_access()` not called when request doesn't match

### `StatsPageTest` (new)
- Page registered with `add_menu_page`
- Passes filters to repository
- Renders table rows from repository data
- Handles empty results

### `ActivatorTest` (new or extended)
- `get_create_table_sql()` contains expected column definitions and indexes

## Decisions

- **Daily aggregated counters** — one row per `(post_id, agent, date)`,
  compact and efficient. No individual request logging.
- **Custom table** — proper indexing, efficient queries, clean separation
  from WordPress options/post meta.
- **No data retention** — stats kept indefinitely. Admins can truncate
  the table manually if needed.
- **`get_matched_agent()` on AgentDetector** — avoids a second pass over
  the UA substring list to determine which agent matched. Single method
  serves both detection and identification.
- **`$wpdb` mock for tests** — consistent with existing test patterns,
  no SQLite dependency.
- **Simple HTML table** — easier to unit test than `WP_List_Table`.
  Can be upgraded later if pagination/sorting needs grow.
- **Top-level admin page** — gives stats their own space rather than
  crowding the settings page.

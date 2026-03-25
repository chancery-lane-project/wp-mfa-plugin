# Stats Page — Date Range Filter & Agent Summary Table

**Date:** 2026-03-25
**Status:** Approved

## Goal

Add date range filtering to the Agent Access Statistics page and, when a date range is active, display a headline summary table showing total accesses and unique posts accessed per agent.

---

## User-facing behaviour

### Filter bar

The existing filter bar (post dropdown, agent dropdown, Filter button) gains two `<input type="date">` fields — **From** and **To** — placed between the agent dropdown and the Filter button. Submitting the form applies all active filters together.

### Preset links

Below the filter bar, four plain anchor links allow one-click date range navigation without going through the form:

| Label | date_from | date_to |
|---|---|---|
| Last 7 days | today − 6 days | today |
| Last 30 days | today − 29 days | today |
| This month | first day of current month | today |
| All time | _(cleared)_ | _(cleared)_ |

Each link is built with `add_query_arg()`, preserving the current post/agent filter values and resetting `paged` to 1. The active preset (if the current `date_from`/`date_to` values match one of the computed ranges) is highlighted with bold + underline.

### Headline summary table

When either `date_from` or `date_to` is non-empty, a `widefat` summary table is rendered **above** the detail table. It shows one row per agent with three columns:

| Agent | Total accesses | Unique posts |
|---|---|---|
| GPTBot | 1,842 | 38 |
| ClaudeBot | 974 | 22 |
| PerplexityBot | 431 | 61 |
| **Total** | **3,247** | — |

The table respects all active filters (post, agent, date range). When no date range is set, the table is not rendered and the repository call is skipped.

---

## Architecture

### 1. `StatsRepository` — two changes

#### 1a. Extend `build_where()`

`build_where(array $filters)` gains two new optional keys:

- `date_from` (string, `Y-m-d`) → appends `access_date >= %s`
- `date_to` (string, `Y-m-d`) → appends `access_date <= %s`

No signature change. All existing callers (`get_stats()`, `get_total_count()`) gain date filtering automatically.

#### 1b. New `get_agent_summary()` method

```php
/**
 * Return per-agent totals for the given filters.
 *
 * @since  1.3.0
 * @param  array<string, mixed> $filters  Supports post_id, agent, date_from, date_to.
 * @return array<int, object{agent: string, total: int, unique_posts: int}>
 */
public function get_agent_summary(array $filters = []): array
```

SQL:
```sql
SELECT agent,
       SUM(count)              AS total,
       COUNT(DISTINCT post_id) AS unique_posts
FROM   {table}
{WHERE}
GROUP  BY agent
ORDER  BY total DESC
```

Uses `build_where()` output for the WHERE clause, so all four filter axes (post_id, agent, date_from, date_to) apply consistently.

### 2. `StatsPage` — filter reading and rendering

#### New GET params

`date_from` and `date_to` are read from `$_GET`, sanitised with `sanitize_text_field()`, and validated against the pattern `Y-m-d` (via `DateTime::createFromFormat()`). Any value that fails validation is silently treated as empty.

Both are added to `$count_filters`, so they flow into `get_stats()`, `get_total_count()`, and `get_agent_summary()` automatically.

#### Preset link URLs

Computed with `gmdate()` for the current date, using `date_modify()` or arithmetic offsets. Built with `add_query_arg(['date_from' => ..., 'date_to' => ..., 'paged' => 1])`. "All time" removes `date_from` and `date_to` from the current query string using `remove_query_arg()`.

Active preset detection: compare `$date_from` and `$date_to` against each preset's computed values — if they match, add `font-weight:bold; text-decoration:underline` inline style.

#### Headline table rendering

Condition: `$date_from !== '' || $date_to !== ''`. When true:

1. Call `$this->repository->get_agent_summary($count_filters)`
2. Render a `<table class="widefat striped">` with columns: Agent, Total accesses, Unique posts
3. Append a totals row: `array_sum(array_column($summary, 'total'))` for Total accesses; em-dash for Unique posts
4. If the summary result is empty, render a single row: "No data for this period."

When false: skip the call and the table entirely.

---

## Testing

### `StatsRepositoryTest` — 5 new tests

| Test | What it asserts |
|---|---|
| `test_get_stats_with_date_from_filter` | Query contains `access_date >=` |
| `test_get_stats_with_date_to_filter` | Query contains `access_date <=` |
| `test_get_stats_with_full_date_range` | Query contains both conditions |
| `test_get_agent_summary_builds_grouped_query` | Query contains `GROUP BY`, `SUM`, `COUNT(DISTINCT` |
| `test_get_agent_summary_with_date_filter` | Date conditions appear in the summary query |

### `StatsPageTest` — 4 new tests

| Test | What it asserts |
|---|---|
| `test_render_page_shows_date_inputs_in_form` | HTML contains `type="date"` inputs with names `date_from` and `date_to` |
| `test_render_page_shows_preset_links` | HTML contains all four preset labels |
| `test_render_page_shows_headline_table_when_date_set` | `get_agent_summary` called; summary HTML present when `$_GET['date_from']` set |
| `test_render_page_hides_headline_table_without_date` | `get_agent_summary` not called; summary table absent |

No new files. Both test classes already exist.

---

## Out of scope

- Persisting the selected date range across sessions (no user preferences)
- Charts or visualisations
- Export / CSV download
- Stats retention / pruning (separate TODO item)

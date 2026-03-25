# Stats Page — Date Range Filter & Agent Summary Table

**Date:** 2026-03-25
**Version:** 1.3.0
**Status:** Approved

## Goal

Add date range filtering to the Agent Access Statistics page and, when a date range is active, display a headline summary table showing total accesses and unique posts accessed per agent.

---

## User-facing behaviour

### Filter bar

The existing filter bar (post dropdown, agent dropdown, Filter button) gains two `<input type="date">` fields — **From** and **To** — placed between the agent dropdown and the Filter button. Submitting the form applies all active filters together.

Partial ranges (only `date_from` or only `date_to`) are valid and applied as-is: `build_where()` adds each condition independently. There is no validation notice for partial ranges.

### Preset links

Below the filter bar, four plain anchor links allow one-click date range navigation without going through the form:

| Label | date_from | date_to |
|---|---|---|
| Last 7 days | today − 6 days | today |
| Last 30 days | today − 29 days | today |
| This month | first day of current month | today |
| All time | _(cleared)_ | _(cleared)_ |

Each link is built with `add_query_arg()`, preserving the current post/agent filter values and resetting `paged` to 1. "All time" uses `remove_query_arg(['date_from', 'date_to'])` then `add_query_arg(['paged' => 1])`.

Date arithmetic uses `new \DateTime('now', new \DateTimeZone('UTC'))` with `->modify()` calls (e.g. `->modify('-6 days')`, `->modify('first day of this month')`). Dates are formatted with `->format('Y-m-d')`.

**Active preset detection:**
- "All time" is active when `$date_from === '' && $date_to === ''`.
- Each other preset is active when both `$date_from` and `$date_to` match the preset's computed values.
- Active preset receives `style="font-weight:bold;text-decoration:underline"` on its `<a>` tag.

### Headline summary table

When either `$date_from !== ''` or `$date_to !== ''`, a `widefat` summary table is rendered **above** the detail table. It shows one row per agent:

| Agent | Total accesses | Unique posts |
|---|---|---|
| GPTBot | 1,842 | 38 |
| ClaudeBot | 974 | 22 |
| PerplexityBot | 431 | 61 |
| **Total** | **3,247** | — |

Access counts are formatted with `number_format_i18n()`. The totals row sums the `total` column with `array_sum(array_column($summary, 'total'))`, also passed through `number_format_i18n()`. The Unique posts cell in the totals row is an em-dash (`—`).

If `get_agent_summary()` returns an empty array, render a single `<td colspan="3">` cell containing `esc_html_e( 'No data for this period.', 'wp-markdown-for-agents' )`.

When no date is set (`$date_from === '' && $date_to === ''`), the table is not rendered and `get_agent_summary()` is not called.

---

## Architecture

### 1. `StatsRepository` — two changes

#### 1a. Extend `build_where()`

`build_where(array $filters)` gains two new optional keys:

- `date_from` (string, `Y-m-d`) → appends `access_date >= %s`
- `date_to` (string, `Y-m-d`) → appends `access_date <= %s`

No signature change. All existing callers (`get_stats()`, `get_total_count()`) gain date filtering automatically.

The docblock is updated to `@since 1.3.0` (was `@since 1.2.0`) and the "Supports" line is updated to list all four keys: `'post_id'` (int), `'agent'` (string), `'date_from'` (string `Y-m-d`), `'date_to'` (string `Y-m-d`).

#### 1b. New `get_agent_summary()` method

```php
/**
 * Return per-agent totals for the given filters.
 *
 * @since  1.3.0
 * @param  array<string, mixed> $filters  Supports post_id, agent, date_from, date_to.
 * @return array<int, object>             Each object has agent (string), total (int), unique_posts (int).
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

Uses `build_where()` output for the WHERE clause, so all four filter axes (post_id, agent, date_from, date_to) apply consistently. Uses `$this->wpdb->get_results()`.

### 2. `StatsPage` — filter reading and rendering

#### New GET params and filter propagation

`date_from` and `date_to` are read from `$_GET`, sanitised with `sanitize_text_field()`, and validated: if `\DateTime::createFromFormat('Y-m-d', $value)` returns `false`, the value is silently replaced with `''`.

The assignment order in `render_page()` is:

```php
// 1. Build count_filters (used by get_total_count and get_agent_summary)
$count_filters = [];
if ($filter_post_id > 0)  { $count_filters['post_id']   = $filter_post_id; }
if ($filter_agent !== '')  { $count_filters['agent']     = $filter_agent; }
if ($date_from !== '')     { $count_filters['date_from'] = $date_from; }
if ($date_to !== '')       { $count_filters['date_to']   = $date_to; }

// 2. Build filters (used by get_stats — adds pagination on top of count_filters)
$filters            = $count_filters;
$filters['limit']   = self::PER_PAGE;
$filters['offset']  = ($paged - 1) * self::PER_PAGE;
```

This guarantees date filters flow into `get_stats()`, `get_total_count()`, and `get_agent_summary()` consistently, and that `limit`/`offset` are never passed to the summary query.

#### Preset link URLs

Computed in `render_page()` before the HTML output begins. Example for "Last 7 days":
```php
$today      = new \DateTime('now', new \DateTimeZone('UTC'));
$seven_ago  = (clone $today)->modify('-6 days');
$preset_7d  = add_query_arg([
    'date_from' => $seven_ago->format('Y-m-d'),
    'date_to'   => $today->format('Y-m-d'),
    'paged'     => 1,
]);
```

"All time":
```php
$preset_all = add_query_arg(['paged' => 1], remove_query_arg(['date_from', 'date_to']));
```

Active preset detection: compare `$date_from` and `$date_to` against each preset's computed values. "All time" is active when `$date_from === '' && $date_to === ''`.

#### Headline table rendering

Condition: `$date_from !== '' || $date_to !== ''`. When true:

1. Call `$this->repository->get_agent_summary($count_filters)`
2. Render a `<table class="widefat striped">` with `<th>` headers: Agent, Total accesses, Unique posts
3. Render one `<tr>` per result row; format `total` with `number_format_i18n()`
4. Append totals row with `number_format_i18n(array_sum(array_column($summary, 'total')))` and `—` for Unique posts
5. If `$summary` is empty, render `<tr><td colspan="3">` with `esc_html_e('No data for this period.', 'wp-markdown-for-agents')`

When false: skip the call and the table entirely.

---

## Testing

### `StatsRepositoryTest` — 5 new tests

| Test | What it asserts |
|---|---|
| `test_get_stats_with_date_from_filter` | Last captured query contains `access_date >=` |
| `test_get_stats_with_date_to_filter` | Last captured query contains `access_date <=` |
| `test_get_stats_with_full_date_range` | Last captured query contains both `access_date >=` and `access_date <=` |
| `test_get_agent_summary_builds_grouped_query` | Last captured query contains `GROUP BY`, `SUM`, and `COUNT(DISTINCT` |
| `test_get_agent_summary_with_date_filter` | Query from `get_agent_summary(['date_from' => '2026-03-01'])` contains `access_date >=` |

### `StatsPageTest` — 4 new tests

| Test | Arrange | Assert |
|---|---|---|
| `test_render_page_shows_date_inputs_in_form` | No `$_GET` date values | `$output` contains `name="date_from"` and `name="date_to"` |
| `test_render_page_shows_preset_links` | No date values | `$output` contains "Last 7 days", "Last 30 days", "This month", "All time" |
| `test_render_page_shows_headline_table_when_date_set` | `$_GET['date_from'] = '2026-03-01'`; `get_agent_summary` mocked to return one row with `agent='GPTBot', total=10, unique_posts=3` | `$output` contains "Total accesses" (column heading) and "GPTBot"; `get_agent_summary` called exactly once |
| `test_render_page_hides_headline_table_without_date` | No date `$_GET` values | `$output` does not contain "Total accesses"; `get_agent_summary` not called |

No new files. Both test classes already exist.

---

## Test infrastructure — mock stubs required

Two WordPress functions used by the new code have no stubs in `tests/mocks/wordpress-mocks.php` and must be added as part of the implementation PR before the new `StatsPageTest` cases are written:

```php
if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string {
        return number_format($number, $decimals);
    }
}

if (!function_exists('remove_query_arg')) {
    function remove_query_arg(string|array $key, string $query = ''): string {
        return $query;
    }
}
```

---

## Out of scope

- Persisting the selected date range across sessions
- Charts or visualisations
- Export / CSV download
- Validation notices for partial date ranges
- Stats retention / pruning (separate TODO item)

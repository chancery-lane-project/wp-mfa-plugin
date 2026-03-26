# Request Method Tracking in Agent Access Stats

**Date:** 2026-03-26
**Status:** Approved

## Goal

Extend the agent access statistics system to track *how* each request was made — via User-Agent match, `Accept: text/markdown` header, or `?output_format=md` query parameter — as a separate dimension alongside agent identity. This enables trend analysis of access method adoption per agent over time.

## Background

The existing `wp_mfa_access_stats` table stores a single `agent` column that conflates two concerns: agent identity (e.g. `GPTBot`) and request method (e.g. `accept-header`, `query-param`). Known agents are logged by identity; unknown agents are logged by method only. This makes it impossible to see how a known agent's preferred access method is changing, or to count unknown-agent method usage alongside identity-based rows.

## Data Model

### Schema change

Add `access_method VARCHAR(20) NOT NULL DEFAULT ''` to `wp_mfa_access_stats`. The `agent` column is retained but narrowed to identity only (empty string for unknown agents).

| Column | Type | Notes |
|---|---|---|
| `post_id` | bigint unsigned | unchanged |
| `agent` | varchar(100) | identity only: `'GPTBot'`, `''` for unknown |
| `access_method` | varchar(20) | `'ua'`, `'accept-header'`, `'query-param'` |
| `access_date` | date | unchanged |
| `count` | int unsigned | unchanged |

The UNIQUE index changes from `(post_id, agent, access_date)` to `(post_id, agent, access_method, access_date)`.

Empty string is used rather than NULL for unknown agent to keep the UNIQUE index well-behaved (MySQL treats NULLs as always distinct, which would permit duplicate rows).

### Migration mechanism

The plugin has no existing version-check runner. A `DB_VERSION` constant (e.g. `'1.1'`) is introduced in `StatsRepository`. The installed version is stored in a WordPress option (`wp_mfa_db_version`). On `plugins_loaded`, `Plugin` registers a migration check. **This hook must be registered unconditionally — before the `if ( empty( $options['enabled'] ) ) { return; }` guard in `define_hooks()` — so that migration runs even on sites where the plugin is currently disabled.**

`Migrator::maybe_migrate()`:

1. Compares the stored `wp_mfa_db_version` option against `DB_VERSION`. Returns early if they match.
2. Calls `dbDelta()` with the updated `CREATE TABLE` SQL (adding the column — `dbDelta` is safe to re-run and will add missing columns without dropping existing ones).
3. Drops the existing `post_agent_date` UNIQUE index if it exists. `dbDelta` can add new indexes but will not alter an existing one — because the index name already exists, it would silently leave the old three-column form in place, causing duplicate-key errors when the same post/agent/date appears with different `access_method` values. The drop must happen before `dbDelta()` is called so the new four-column index is created fresh.
4. Calls `dbDelta()` with the updated `CREATE TABLE` SQL containing the new `access_method` column and the four-column `UNIQUE KEY post_agent_date (post_id, agent, access_method, access_date)`.
5. Runs the data UPDATE statements below.
6. Updates the stored `wp_mfa_db_version` option **only after both UPDATEs complete successfully**. This ensures that if the process is interrupted mid-migration, the next `plugins_loaded` will re-run the full migration rather than assuming it finished.

`Activator::activate()` also calls `Migrator::maybe_migrate()` so fresh installs and re-activations are handled consistently.

### Migration SQL

```sql
-- Step 1: rows where agent encodes the method (old unknown-agent rows)
UPDATE wp_mfa_access_stats
SET access_method = agent, agent = ''
WHERE agent IN ('accept-header', 'query-param');

-- Step 2: remaining rows had a named agent, which could only have arrived via UA.
-- After the column is added with DEFAULT '', all un-migrated rows will have access_method = ''.
-- IS NULL is unreachable post-column-addition but included for defensive completeness.
UPDATE wp_mfa_access_stats
SET access_method = 'ua'
WHERE access_method IS NULL OR access_method = '';
```

Both UPDATEs are idempotent — re-running them on already-migrated rows is harmless. They are wrapped in a transaction where the storage engine permits it. There is no DDL rollback mechanism in WordPress/MySQL; if the process is interrupted after `dbDelta` but before the UPDATEs, re-running `Migrator::maybe_migrate()` will complete the job safely since Step 2 targets `access_method = ''`.

## Detection Logic

### Method precedence

Query-param takes precedence over accept-header, which takes precedence over UA. This reflects the intent: if a client explicitly requests markdown via header or query param that is more meaningful to record than the UA match that passively identifies it.

```php
if ( $via_query ) {
    $access_method = 'query-param';
} elseif ( $via_accept ) {
    $access_method = 'accept-header';
} else {
    $access_method = 'ua';
}
```

### Agent detection decoupled from force-serving

`AgentDetector::get_matched_agent()` currently returns `null` when `ua_force_enabled` is off, because that option controls whether a UA match alone triggers serving — not whether the agent can be identified. A new `detect_agent(string $ua): ?string` method performs the UA substring matching unconditionally. `get_matched_agent()` delegates to `detect_agent()` internally after applying the `ua_force_enabled` guard, preserving existing serving behaviour. `is_known_agent()` is unchanged — it continues to delegate to `get_matched_agent()`. `Negotiator` uses `detect_agent()` for the stats label only.

This means an agent whose UA is recognised but `ua_force_enabled` is off, and which sends an Accept header, will now be logged as `agent='GPTBot', access_method='accept-header'` rather than `agent='accept-header'` with no identity.

### Call chain

`Negotiator` makes two separate UA calls for two distinct purposes:

```
Negotiator::maybe_serve_markdown()
  → get_matched_agent( $ua )    // serving gate — respects ua_force_enabled; null when off
  → detect_agent( $ua )         // stats label — ignores ua_force_enabled
  → determine $access_method    // query-param > accept-header > ua
  → AccessLogger::log_access( $post_id, $agent, $access_method )
      → StatsRepository::record_access( $post_id, $agent, $access_method )
```

`get_matched_agent()` remains in the early-return guard (`if ( ! $via_accept && ! $via_query && null === $matched_agent ) { return; }`). It must not be removed — it is the mechanism by which `ua_force_enabled` controls whether a UA-only request triggers serving.

`AccessLogger::log_access()` truncates `$agent` to 100 chars as before. `$access_method` is passed through directly — its values (`ua`, `accept-header`, `query-param`) are well within the 20-char column. Taxonomy archive requests are not logged (pre-existing behaviour, out of scope).

## Admin UI

### Headline summary table

The existing `get_agent_summary()` query groups by `agent` and includes `unique_posts`. After this change it groups by `(agent, access_method)`. `unique_posts` is retained but now means unique posts for that agent+method combination, not per agent overall. The table gains a Method column:

| Agent | Method | Total | Unique posts |
|---|---|---|---|
| GPTBot | ua | 420 | 18 |
| GPTBot | accept-header | 83 | 12 |
| (unknown) | query-param | 61 | 9 |
| (unknown) | accept-header | 14 | 5 |

Rows where `agent = ''` are displayed as `(unknown)` in the UI. The summary table footer (grand total) sums the `total` column across all returned rows; after the GROUP BY change a single agent may produce multiple rows, but the arithmetic remains correct — the sum represents total accesses across all agent+method combinations.

### Main results table

The paginated results table gains an `Access Method` column alongside the existing `Agent` column. Empty-string agent values display as `(unknown)`.

### Filters

A new `access_method` filter dropdown is added: options are all, ua, accept-header, query-param, backed by a new `access_method` key in `build_where()`.

The existing agent filter is backed by `get_distinct_agents()`, which excludes empty string from results (filtering to unknown agents is not needed given the method filter covers that use case). Post-migration, `'accept-header'` and `'query-param'` will no longer appear as agent values. As a defensive measure, `get_distinct_agents()` also excludes these two strings, guarding against the transient window between `dbDelta()` running and the data UPDATEs completing (e.g. if a stats page is loaded mid-migration).

The `build_where()` method currently uses `! empty( $filters['agent'] )` which would silently skip an empty-string agent filter. Since `get_distinct_agents()` excludes `''` and the UI offers no option to filter to unknown agents, this limitation has no practical impact and no change is required.

## Components Affected

| Component | Change |
|---|---|
| `AgentDetector` | Add `detect_agent()` method; `get_matched_agent()` delegates to it; `is_known_agent()` unchanged |
| `Negotiator` | Use `detect_agent()` for stats; determine `$access_method` with method-precedence rule |
| `AccessLogger` | Accept `$access_method` parameter; pass to repository |
| `StatsRepository` | Accept `$access_method` in `record_access()`; add to all queries and filters; update schema SQL; add `DB_VERSION` constant; `get_distinct_agents()` excludes `''`, `'accept-header'`, `'query-param'`; update `get_agent_summary()` `@return` docblock to include `access_method` |
| `StatsPage` | Add method column to tables; add method filter dropdown; update `get_agent_summary()` grouping; display `''` agent as `(unknown)` |
| `Migrator` (new) | Version-check runner: calls `dbDelta()` then runs UPDATE statements, updates stored version option |
| `Plugin` | Hook `Migrator::maybe_migrate()` to `plugins_loaded` |
| `Activator` | Call `Migrator::maybe_migrate()` in place of bare `dbDelta()` call |

## Testing

- Unit: `AgentDetector::detect_agent()` returns match regardless of `ua_force_enabled`
- Unit: `AgentDetector::get_matched_agent()` still returns null when `ua_force_enabled` is off
- Unit: `AgentDetector::is_known_agent()` behaviour unchanged
- Unit: `Negotiator` assigns correct `access_method` for all combinations (query+UA, accept+UA, UA-only, accept-only, query-only)
- Unit: `StatsRepository::record_access()` upserts correctly with both `agent` and `access_method`
- Unit: `get_distinct_agents()` excludes empty-string agent
- Unit: `get_agent_summary()` groups by `(agent, access_method)` and returns `unique_posts` per pair
- Integration: migration leaves no rows with empty `access_method`
- Integration: existing `'accept-header'`/`'query-param'` agent rows are correctly converted
- Integration: `Migrator::maybe_migrate()` is idempotent (safe to run twice)

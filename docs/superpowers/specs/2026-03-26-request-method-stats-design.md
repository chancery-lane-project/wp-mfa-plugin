# Request Method Tracking in Agent Access Stats

**Date:** 2026-03-26
**Status:** Approved

## Goal

Extend the agent access statistics system to track *how* each request was made — via User-Agent match, `Accept: text/markdown` header, or `?output_format=md` query parameter — as a separate dimension alongside agent identity. This enables trend analysis of access method adoption per agent over time.

## Background

The existing `wp_mfa_access_stats` table stores a single `agent` column that conflates two concerns: agent identity (e.g. `GPTBot`) and request method (e.g. `accept-header`, `query-param`). Known agents are logged by identity; unknown agents are logged by method only. This makes it impossible to see how a known agent's preferred access method is changing, or to count unknown-agent method usage alongside identity-based rows.

## Data Model

### Schema change

Add `access_method VARCHAR(20) NOT NULL` to `wp_mfa_access_stats`. The `agent` column is retained but narrowed to identity only (empty string for unknown agents).

| Column | Type | Notes |
|---|---|---|
| `post_id` | bigint unsigned | unchanged |
| `agent` | varchar(100) | identity only: `'GPTBot'`, `''` for unknown |
| `access_method` | varchar(20) | `'ua'`, `'accept-header'`, `'query-param'` |
| `access_date` | date | unchanged |
| `count` | int unsigned | unchanged |

The UNIQUE index changes from `(post_id, agent, access_date)` to `(post_id, agent, access_method, access_date)`.

Empty string is used rather than NULL for unknown agent to keep the UNIQUE index well-behaved (MySQL treats NULLs as always distinct, which would permit duplicate rows).

### Migration

A versioned migration converts existing rows in two steps:

```sql
-- Rows where agent encodes the method (old unknown-agent rows)
UPDATE wp_mfa_access_stats
SET access_method = agent, agent = ''
WHERE agent IN ('accept-header', 'query-param');

-- Remaining rows had a named agent, which could only arrive via UA
UPDATE wp_mfa_access_stats
SET access_method = 'ua'
WHERE access_method IS NULL OR access_method = '';
```

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

`AgentDetector::get_matched_agent()` currently returns `null` when `ua_force_enabled` is off, because that option controls whether a UA match alone triggers serving — not whether the agent can be identified. A new `detect_agent(string $ua): ?string` method performs the UA substring matching unconditionally. `get_matched_agent()` calls it internally, preserving existing serving behaviour. `Negotiator` uses `detect_agent()` for the stats label.

This means an unknown-to-force-serving agent (UA match present but `ua_force_enabled = false`) that sends an Accept header will now be logged as `agent='GPTBot', access_method='accept-header'` rather than `agent='accept-header'` with no identity.

### Call chain

```
Negotiator::maybe_serve_markdown()
  → detect_agent( $ua )         // always attempts UA match
  → determine $access_method    // query-param > accept-header > ua
  → AccessLogger::log_access( $post_id, $agent, $access_method )
      → StatsRepository::record_access( $post_id, $agent, $access_method )
```

## Admin UI

### Headline summary table

The existing agent summary table (shown when a date filter is active) gains a second grouping column:

| Agent | Method | Total |
|---|---|---|
| GPTBot | ua | 420 |
| GPTBot | accept-header | 83 |
| (unknown) | query-param | 61 |
| (unknown) | accept-header | 14 |

### Main results table

The paginated results table gains an `Access Method` column alongside the existing `Agent` column.

### Filters

A new `access_method` filter dropdown is added alongside the existing agent filter: options are all, ua, accept-header, query-param.

The existing agent filter dropdown will no longer list `'accept-header'` or `'query-param'` as selectable values after migration, since those strings no longer appear in the `agent` column.

## Components Affected

| Component | Change |
|---|---|
| `AgentDetector` | Add `detect_agent()` method; `get_matched_agent()` delegates to it |
| `Negotiator` | Use `detect_agent()` for stats; determine `$access_method` with method-precedence rule |
| `AccessLogger` | Accept `$access_method` parameter; pass to repository |
| `StatsRepository` | Accept `$access_method` in `record_access()`; add to all queries and filters; update schema install/migration |
| `StatsPage` | Add method column to tables; add method filter dropdown; update `get_agent_summary()` grouping |
| DB migration | One-time UPDATE to convert existing rows |

## Testing

- Unit: `AgentDetector::detect_agent()` returns match regardless of `ua_force_enabled`
- Unit: `Negotiator` assigns correct `access_method` for all combinations (query+UA, accept+UA, UA-only, accept-only, query-only)
- Unit: `StatsRepository::record_access()` upserts correctly with the new column
- Integration: migration leaves no rows with null `access_method`
- Integration: existing agent filter excludes `'accept-header'`/`'query-param'` post-migration

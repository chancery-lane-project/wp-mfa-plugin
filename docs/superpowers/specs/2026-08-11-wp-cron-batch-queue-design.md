# WP-Cron Batch Queue for Bulk Generation — Design Spec

**Date:** 2026-08-11
**Scope:** Replace the client-driven AJAX batching loop (introduced in `2026-03-17-ajax-bulk-generation-design.md`) for bulk "Generate all" flows with a server-driven WP-Cron job queue, and fix the offset-pagination and upfront-collection costs underneath it. Single-post regeneration on save and WP-CLI commands are unaffected.

---

## Problem

On large sites, bulk regeneration (`Generate all`, `Generate all: {post_type}`, taxonomy archive generation, and the bundle rebuild that follows) reports:

1. **PHP timeout mid-batch** — the client-driven AJAX loop still runs each batch inside a user-facing HTTP request; slow batches (heavy meta/ACF resolution, slow hosting) can exceed `max_execution_time`.
2. **Memory exhaustion** — most visibly in the synchronous whole-export-tree zip rebuild (`Admin::maybe_rebuild_bundle()`) that runs on the final batch's request.
3. **Browser/tab dependency** — the JS loop (`assets/js/bulk-generate.js`) is the only thing driving progress; closing the tab, navigating away, or a dropped connection kills the run partway through.
4. **Whole-site collection upfront** — `TaxonomyArchiveGenerator::generate_batch()` (`TaxonomyArchiveGenerator.php:188`) fetches *all* terms via `get_terms()` before slicing a batch out of the in-memory array, and `Generator::generate_batch()` (`Generator.php:186`) uses `WP_Query` with `'offset' => $offset`, whose scan cost grows linearly with offset — batch 800 on a 40,000-post site scans and discards 40,000 rows to return 50.
5. **Endless run when a batch's items are skipped** — a live bug in the current JS loop, worth naming explicitly since the new design must not reintroduce it. `assets/js/bulk-generate.js:114` decides whether to fetch another batch with `accumulated.processed < total`. `Generator::generate_batch()` never increments `processed` for ineligible/skipped posts, so on a post type with any skipped posts `accumulated.processed` permanently lags `total`. `offset` keeps advancing regardless, so once it runs past the real result set, every further batch returns `processed: 0` against an unchanged `total` — the stop condition never becomes false and the loop polls empty batches indefinitely. The correct termination signal is query exhaustion (fetched-count-less-than-limit / cursor past the last row) — the same pattern already used correctly by `Generator::write_manifests()`'s own collection loop (`Generator.php:394-409`, `} while ( $fetched === $batch_size )`) — not the processed/skip/error count.

These aren't independent bugs — they're all downstream of the client-driven, offset-paginated model itself.

### Platform constraints the design has to respect

- **WP-Cron spawn cadence.** WP-Cron only runs when a request triggers a spawn, and `spawn_cron()` is rate-limited by `WP_CRON_LOCK_TIMEOUT` (60 seconds by default). A naive "one batch per cron tick, reschedule for `time() + 1`" chain therefore advances at roughly **one batch per minute** no matter what timestamp it asks for: 40,000 posts at 50 per batch would take about 13 hours. Ticks must be **time-boxed** (process batches in a loop until a wall-clock budget is spent) rather than limited to a single batch. See §3.
- **Duplicate-event suppression.** `wp_schedule_single_event()` refuses to schedule an event whose hook and args duplicate one already scheduled within 10 minutes of the requested timestamp, and returns `false`. A self-rescheduling chain that ignores that return value can die silently. A watchdog is required. See §3.
- **`SQL_CALC_FOUND_ROWS` scans the whole matching set.** Removing `OFFSET` in favour of an ID cursor does not on its own make batch cost constant if each batch still asks for a total row count. Totals must be computed once per stage. See §1.

---

## Solution Overview

A single job record (one option) tracks progress through an ordered list of stages (post types, then taxonomy archives, then bundle rebuild). A self-rescheduling `wp_schedule_single_event()` chain processes a time-boxed run of batches per cron tick until the job completes. The browser only starts a job and polls its status — it never drives processing.

```
SettingsPage (PHP)
  └── "Generate all" / "Generate all: {post_type}" / taxonomy button
        └── POST admin-ajax.php action=mfa_start_generation_job
              └── Admin::handle_start_generation_job_ajax()
                    └── Jobs\GenerationJob::start(stages)
                          └── writes job option, schedules first tick

Jobs\JobRunner (hooked to markdown_for_agents_process_batch cron action,
                markdown_for_agents_job_watchdog cron action, and admin_init)
  └── acquires tick mutex (token-based), loads job, re-validates lock_token
  └── loops while time budget remains:
        └── current stage's Stage::process_batch(cursor, limit)
              └── PostTypeStage | TaxonomyStage | BundleStage
        └── updates job progress/errors, heartbeats the mutex
        └── on stage done: advance stage_index, reset cursor, count next stage's total
  └── marks done/failed, or reschedules the next tick
  └── releases the mutex (try/finally)

Admin JS (rewritten)
  └── on start response: flip to progress view
  └── poll admin-ajax.php action=mfa_job_status every 5 seconds
  └── render "stage k of n — {processed} processed, {skipped} skipped, {errors} error(s)"
  └── stop polling on done/failed
  └── no cancel control (deferred — see Rejected/Deferred below)
```

---

## 1. Shared `Stage` interface (DRY consolidation)

Both existing batch methods have the same shape — offset/limit in, `{total, processed, errors}` out — but are separately implemented and both carry the offset-cost problem. Consolidate behind one contract so `JobRunner` doesn't special-case post-type vs taxonomy vs bundle, and the cursor-pagination fix lands in one place per implementation rather than being copy-pasted:

```php
namespace Tclp\WpMarkdownForAgents\Jobs;

interface Stage {
	/**
	 * Total items this stage will walk. Called ONCE, when the stage becomes
	 * current; the result is stored in the job record. Never recomputed
	 * per batch — a cursor-filtered count shrinks as the cursor advances,
	 * and a per-batch count query costs a full scan every tick.
	 */
	public function count_total(): int;

	/**
	 * @return array{
	 *   processed: int,          // items whose Markdown was written
	 *   skipped: int,            // items intentionally not written (ineligible/excluded)
	 *   errors: list<array{post_id?: int, term_id?: int, message: string}>,
	 *   next_cursor: int,        // resume key for the next batch
	 *   done: bool,              // rows returned this batch < $limit
	 * }
	 */
	public function process_batch( int $cursor, int $limit ): array;
}
```

Note what is **not** in the batch return: `total`. Totals come from `count_total()` once per stage.

### 1.1 Cursor pagination via explicit SQL, not query filters

An earlier draft paginated by registering a scoped `posts_where` / `terms_clauses` filter around `WP_Query` / `get_terms()` to append `AND ID > $cursor`. That is rejected in favour of **explicit `$wpdb` ID queries** in both stages:

- It is explicit rather than clever: the pagination predicate is visible in the query, not injected into someone else's SQL through a global filter that must be added and removed in matched pairs.
- It removes a whole bug class (filter left registered, filter firing for an unrelated nested query, `remove_filter` signature mismatch).
- It is unit-testable against the existing `wpdb` mock. The filter approach is not: the mock `WP_Query` (`tests/mocks/wordpress-mocks.php:511`) never applies `posts_where`, and the mock `get_terms()` (`:1172`) ignores `number`/`offset`/`orderby` and never applies `terms_clauses`, so a filter-injected cursor would be invisible to every assertion.

**Trade-off, accepted:** these ID-collection queries no longer pass through `pre_get_posts` / `terms_clauses`, so a third-party plugin filtering those hooks will not influence which items bulk generation walks. This matches intent — the set to export is "published posts of this type" and "terms in public taxonomies", not "whatever a query filter reshapes it into". Everything downstream of ID collection is unchanged: `get_post()` / `get_term()` and then `generate_post()` / `generate_term()`, with all their existing hooks and filters intact.

**`PostTypeStage`** (wraps `Generator`):

```php
$ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = %s AND post_status = 'publish' AND ID > %d
		 ORDER BY ID ASC LIMIT %d",
		$this->post_type,
		$cursor,
		$limit
	)
);
```

- `count_total()`: `(int) wp_count_posts( $this->post_type )->publish` — one cheap, cached call, no scan.
- `done` is `count( $ids ) < $limit`. `next_cursor` is the last ID returned, or `$cursor` unchanged when the batch was empty.
- Per-item work is unchanged from today's `generate_batch()` body: `get_post()`, `generate_post()` in a `try/catch`, `clean_post_cache()`. The one behavioural addition is counting **skipped** items separately — today's loop distinguishes "ineligible, intentional skip" from "eligible but write failed" via `is_eligible()` (`Generator.php:220-227`) but throws the skip count away.

**`TaxonomyStage`** (wraps `TaxonomyArchiveGenerator`):

The rejected draft assumed "the single `get_terms()` call". There isn't one: `get_all_public_terms()` (`TaxonomyArchiveGenerator.php:236-249`) loops public taxonomies, calling `get_terms()` once each and concatenating. Terms therefore arrive grouped by taxonomy, not in global `term_id` order, so a plain `AND t.term_id > $cursor` would silently skip every later-taxonomy term with a lower id. Two further traps: `get_terms()` defaults to `orderby => 'name'`, and a legacy shared term belongs to more than one taxonomy, so `term_id` is not unique per archive to generate.

The cursor is therefore **`term_taxonomy_id`**, which is unique per (term, taxonomy) pair, monotonically ascending, and exactly one row per archive file:

```php
$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy
		 FROM {$wpdb->term_taxonomy} tt
		 WHERE tt.taxonomy IN ($placeholders) AND tt.term_taxonomy_id > %d
		 ORDER BY tt.term_taxonomy_id ASC LIMIT %d",
		...array_merge( $taxonomies, array( $cursor, $limit ) )
	)
);
```

- `$taxonomies` is `array_keys( get_taxonomies( array( 'public' => true ) ) )`, the same set `generate_all()` uses (`TaxonomyArchiveGenerator.php:156`). An empty list short-circuits to `done: true`.
- `count_total()`: one `SELECT COUNT(*)` over the same `WHERE`, run once when the stage becomes current.
- Each row becomes `get_term( $row->term_id, $row->taxonomy )`, then the existing `generate_term()` in a `try/catch`. `generate_term()` has no skip path, so `skipped` is always 0 here.
- `done` / `next_cursor` computed exactly as in `PostTypeStage`, from rows returned vs `$limit`.

### 1.2 Termination and totals rules

- **`done` must never be derived from `processed`/`skipped`/`error` counts** — only from query exhaustion (rows returned this batch `< $limit`). This is the exact bug being fixed (see Problem #5): a batch where every item was skipped still correctly reports `done: false` if a full page came back, `done: true` if it didn't, regardless of how many of those rows were actually written.
- **`total` is stable for the life of a stage.** It is `count_total()`'s value, written into the job record on stage entry and never touched again. A total that shrinks as the cursor advances (which is what a cursor-filtered `found_posts` would give) makes the progress display nonsense and re-introduces a count-based completion signal by the back door.
- Neither stage asks for a row count in its batch query, so batch cost is genuinely constant in cursor position: an indexed range scan of `LIMIT` rows.

### 1.3 BundleStage

Bundle rebuild becomes a `BundleStage` — a single-shot stage with `count_total() === 1`, returning `processed: 1, done: true` on its first (only) call — so it runs as an ordinary tick in the same chain, off any user-facing request. It always calls `rebuild_bundle( $bundle_generator, true )` (`only_if_stale = true`), preserving today's "skip the zip if nothing changed" behaviour (see §4 for which scopes get this stage). A `BundleStage` batch is never followed by another batch inside the same tick regardless of remaining time budget — it is the single heaviest unit of work in the flow and gets a tick to itself (see §3).

### 1.4 CLI

CLI's bulk loop (`CLI/Commands.php:519`, inside `generate_incremental()`) currently loops per post checking `generate_post()`'s boolean return with no try/catch around it; it could call the same `Stage::process_batch()` directly instead — opportunistic reuse, not required for this fix. If it does, CLI **must not** read or write the job option or the tick mutex: CLI runs synchronously outside the queue, and letting it participate in the job state machine would allow a CLI run and a cron chain to interleave over the same cursor.

`generate_post()` remains the single funnel for actually writing a post's Markdown — untouched, already reused everywhere (admin single-regen, CLI, `on_save_post`, both batch stages).

---

## 2. `Jobs\GenerationJob` (new)

Thin repository around one option, `markdown_for_agents_job`, written with **`autoload = false`** (`add_option( 'markdown_for_agents_job', $record, '', false )`). Autoloading it would put a record rewritten every tick into `alloptions`, so every front-end request would carry it and every tick would bust the whole options cache. An option, not a transient: a persistent object cache can evict a transient mid-run, and losing the record mid-job would strand the chain.

```php
[
	'status'       => 'idle' | 'running' | 'done' | 'failed',
	'lock_token'   => string,        // random (wp_generate_password( 20, false )), set on start
	'stages'       => [
		[ 'type' => 'post_type', 'slug' => 'post', 'total' => 1240,
		  'processed' => 1240, 'skipped' => 3, 'error_count' => 1, 'state' => 'done' ],
		[ 'type' => 'taxonomy', 'total' => 812,
		  'processed' => 300, 'skipped' => 0, 'error_count' => 0, 'state' => 'running' ],
		[ 'type' => 'bundle', 'total' => 1,
		  'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ],
	],
	'stage_index'  => int,
	'cursor'       => int,           // cursor within the CURRENT stage only
	'errors'       => list<array{...}>,  // capped at 50 most recent
	'error_count'  => int,           // total errors seen, uncapped — drives the "+N more" note
	'last_tick_at' => int,           // timestamp, updated on every tick and every batch
	'message'      => string,        // human-readable reason when status is 'failed'
]
```

Per-stage counters live **in the stage list**, not in a single current-stage `progress` key. The polling UI renders per-stage progress (§5), so the record has to keep completed stages' numbers rather than overwriting them on each stage transition. `total` per stage is filled in from `count_total()` when that stage becomes current (`null` until then — counting all stages upfront would put every stage's count query in the start request).

- `start( array $stages )`: rejects with an error if `status === 'running'` **and** `last_tick_at` is within the stale threshold (**10 minutes** — comfortably longer than one tick's time budget plus the mutex window in §3). If `status === 'running'` but `last_tick_at` is older than the threshold, the job is treated as dead (its tick fatal'd — e.g. OOM, an uncaught `Error` — with nothing left to reschedule it) and `start()` supersedes it: writes a fresh record with a new `lock_token`, so a crashed job can't permanently block every future run. Otherwise writes a fresh record and schedules the first `markdown_for_agents_process_batch` tick via `wp_schedule_single_event( time(), ... )`.
- A superseded job's orphaned cron event, if any, fires later, finds a `lock_token` mismatch, and returns without doing work or rescheduling — the new job's own chain is unaffected.
- `JobRunner` re-reads the option each tick and only writes back if its in-memory `lock_token` still matches (optimistic lock), and stamps `last_tick_at` on every write — guards against a duplicate tick and gives `start()` the staleness signal above.
- A WP-CLI companion command (`wp markdown-agents job-status` / `job-clear`) is out of scope for this fix — the staleness checks in `start()` and the watchdog (§3) are sufficient recovery; a manual override can be added later if needed.

---

## 3. `Jobs\JobRunner` (new)

### 3.1 Time-boxed ticks

A tick does **not** process one batch. Because WP-Cron spawns are rate-limited to roughly one per `WP_CRON_LOCK_TIMEOUT` (60s), one-batch ticks would cap throughput at ~50 items/minute. Instead each tick spends a wall-clock budget:

```php
$max_exec = (int) ini_get( 'max_execution_time' );   // 0 = unlimited (CLI, some hosts)
$budget   = $max_exec > 0 ? max( 10, (int) ( $max_exec * 0.6 ) ) : 30;
$budget   = min( $budget, 30 );
$budget   = (int) apply_filters( 'markdown_for_agents_tick_budget', $budget );
```

The tick loops `process_batch()` calls while `microtime( true ) - $started < $budget` **and** the current stage is not `done`, checking the budget *between* batches (never mid-batch), then reschedules. A `BundleStage` batch always ends the tick immediately, whatever the remaining budget. With a 30s budget and 50-item batches this is roughly two orders of magnitude faster than one-batch ticks on the same host, while still keeping every individual request bounded well inside `max_execution_time`.

This intentionally supersedes the earlier success criterion "no PHP request does more than one batch's worth of work". The property that matters is a **bounded, self-chosen time budget per request**, not a batch count.

### 3.2 Tick entry points

Three hooks reach the same tick handler:

1. **`markdown_for_agents_process_batch`** (cron) — the normal chain.
2. **`admin_init`** — a same-process fallback for a missing scheduled event. Fires when `status === 'running'`, `wp_next_scheduled( 'markdown_for_agents_process_batch' )` is `false`, and `last_tick_at` is more than 60 seconds old. Unlike the earlier draft this is **not** limited to the plugin's settings screen: a chain that has lost its event should recover from any admin request, and the guard is two cheap reads. It calls the tick handler directly and never schedules — scheduling here is what would race the cron path.
3. **`markdown_for_agents_job_watchdog`** (cron, `hourly`, registered once on `init` if not already scheduled) — the backstop for a chain that died where no admin request will notice: `status === 'running'`, `last_tick_at` older than 10 minutes, no pending `markdown_for_agents_process_batch`. It schedules a fresh tick rather than running one inline.

The reschedule at the end of a tick checks `wp_next_scheduled()` before calling `wp_schedule_single_event()` — mirroring the existing convention in `BundleGenerator::mark_stale_and_schedule()` (`BundleGenerator.php:267-277`; hooked up via `Plugin.php:147-158`).

### 3.3 Scheduling failures are not silently ignored

`wp_schedule_single_event()` returns `false` when a duplicate hook+args event exists within 10 minutes of the requested timestamp, or when `pre_schedule_event` vetoes it. The tick checks the return value:

- On `false`, it increments a `schedule_failures` counter in the record and leaves `status` as `running`. The watchdog will pick the job up within the hour, and any admin request within 60 seconds will pick it up sooner.
- On the third consecutive failure, the tick flips the job to `failed` with `message` explaining that the cron event could not be scheduled, so the UI stops showing an apparently-live run that will never advance.

### 3.4 Tick body

Each tick, in order:

1. Acquire the tick mutex (§3.5). Failure to acquire is an immediate no-op return.
2. Load the job; return unless `status === 'running'`; capture `lock_token`.
3. Loop while time budget remains:
   - `count_total()` for the current stage if its `total` is still `null`.
   - `process_batch( cursor, limit )`.
   - Merge `processed` / `skipped` into the current stage's counters; append `errors` (list capped at the 50 most recent, `error_count` incremented uncapped); set `cursor = next_cursor`.
   - Write the record (only if `lock_token` still matches), stamp `last_tick_at`, and heartbeat the mutex.
   - If `done`: mark the stage `done`, advance `stage_index`, **reset `cursor` to `0`**, and — for a `post_type` stage — clear that slug from the needs-regen transient (§3.6). The cursor is scoped to one stage: carrying a leftover post-ID or `term_taxonomy_id` cursor into the next stage would make its first query return nothing, silently marking it complete having done no work. If that was the last stage, set `status = 'done'` and break.
   - Break if the stage just processed was `BundleStage`.
4. If `status` is still `running`, reschedule (`time() + 1`, guarded by `wp_next_scheduled()`, return value checked per §3.3).
5. Release the mutex — in a `finally`, so it also releases on the done/failed/mismatch/throw paths.

A per-item error (post/term throws) does not stop the job — mirrors the existing `try/catch`-per-post pattern in today's `generate_batch()`. Only a structural failure (the job option can't be written; a stage's `count_total()` throws) flips the job to `failed` with a `message`.

### 3.5 Tick mutex

The `wp_next_scheduled()` guards stop duplicate *pending cron events*, not two ticks physically running at once — e.g. two admin tabs whose `admin_init` nudges overlap, both seeing no scheduled event, both calling the tick handler with the same still-valid `lock_token`. The `lock_token` check doesn't help: both hold a matching token. So each tick wraps its body in a mutex option, `markdown_for_agents_job_tick_lock`, `autoload = false`.

The mutex value is **`{token, acquired_at}`**, not a bare timestamp. A token is required because of a race in the naive stale-recovery path: if two ticks both see a stale lock and both `delete_option()` then `add_option()`, the second tick's delete removes the *first* tick's freshly-acquired lock and both proceed — exactly the double-execution the mutex exists to prevent.

Acquisition:

```php
$mine = wp_generate_password( 20, false );

// Fast path: atomic insert, fails if the row already exists. The
// wp_options.option_name unique key makes this safe against two requests
// racing past a PHP-level existence check at the same instant.
if ( add_option( self::LOCK_OPTION, array( 'token' => $mine, 'acquired_at' => time() ), '', false ) ) {
	return $mine;   // acquired
}

$held = get_option( self::LOCK_OPTION );

if ( is_array( $held ) && ( time() - (int) ( $held['acquired_at'] ?? 0 ) ) < $this->lock_window() ) {
	return null;    // another tick is legitimately running: do nothing, schedule nothing
}

// Lock looks abandoned (its owner fatal'd before releasing). Steal it, then
// confirm we actually own it — if another tick stole it in the same instant,
// its token wins and we back off.
delete_option( self::LOCK_OPTION );

if ( ! add_option( self::LOCK_OPTION, array( 'token' => $mine, 'acquired_at' => time() ), '', false ) ) {
	return null;
}

$confirm = get_option( self::LOCK_OPTION );

return ( is_array( $confirm ) && ( $confirm['token'] ?? '' ) === $mine ) ? $mine : null;
```

- **Window.** `lock_window()` is `max( 300, 2 * (int) ini_get( 'max_execution_time' ) )` seconds. An earlier draft suggested 30–60s, which contradicts Problem #1: a tick legitimately slower than its own budget (heavy ACF resolution on a slow host) would be declared abandoned by a second tick, and both would write the same files and both reschedule.
- **Heartbeat.** The tick refreshes `acquired_at` after every batch (`update_option`, only when the stored token is still its own), so a long but healthy tick never looks abandoned.
- **Release.** `delete_option()` in a `finally`, and only when the stored token is still the tick's own — a tick that has had its lock stolen must not delete the thief's.
- A tick that fails to acquire returns without scheduling anything. No work is lost: the tick holding the lock reschedules when it finishes.

Without the stale-recovery path, a single fatal inside the locked section would block every future tick forever, even after `GenerationJob::start()`'s own staleness check (§2) supersedes the crashed job — that check only touches the job option, not this separate mutex option.

### 3.6 `markdown_for_agents_needs_regen` handoff

Today `Admin::mark_post_type_regenerated()` (`Admin.php:211`) clears a post type from this transient when its AJAX batch run completes, driving `SettingsPage::display_regen_notice()`. It is `private` on `Admin`, and so is `maybe_rebuild_bundle()` (`Admin.php:276`) — `JobRunner` cannot call either. Rather than widening `Admin`'s internals to a `Jobs\` class, the transient logic moves to a small `Admin\NeedsRegenTracker` (methods `flag( string $post_type )` / `clear( string $post_type )`), which both `Admin` and `JobRunner` use. `SettingsPage`'s notice-reading code is unchanged. `maybe_rebuild_bundle()`'s body moves into `BundleStage`, including its `WP_DEBUG` error logging.

### 3.7 Interaction with the existing debounced bundle rebuild

Every `markdown_for_agents_file_generated` / `_taxonomy_file_generated` event fired while a stage runs already calls `BundleGenerator::mark_stale_and_schedule()`, which does two distinct things (`BundleGenerator.php:267-277`): `delete_option( self::HASH_OPTION )` to **mark the tree stale**, then schedules `markdown_for_agents_rebuild_bundle` five minutes out. Left alone, that scheduled rebuild fires in parallel with the job's own `BundleStage` and both rebuild the same zip.

The guard skips **only the scheduling**, never the staleness marking:

```php
delete_option( self::HASH_OPTION );

if ( GenerationJob::is_running() ) {
	return;   // the job's own BundleStage will rebuild
}

if ( ! wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) ) {
	wp_schedule_single_event( time() + 300, 'markdown_for_agents_rebuild_bundle' );
}
```

Skipping the whole method would leave a hole: a post saved *after* the job's `BundleStage` tick but before the job's status stops being `running` would be neither marked stale nor scheduled, leaving a permanently wrong zip until some unrelated later change. Marking stale unconditionally closes it, and `JobRunner` closes the rest — on setting `status = 'done'`, if `HASH_OPTION` is absent (i.e. something went stale after `BundleStage` ran) it schedules `markdown_for_agents_rebuild_bundle` for `time() + 300` through the normal guarded path.

---

## 4. Admin changes

- `mfa_generate_batch` / `mfa_generate_taxonomy_batch` → `mfa_start_generation_job` (accepts a `scope` param; builds the stage list and calls `GenerationJob::start()`) and `mfa_job_status` (read-only, returns the current job record for polling). Same nonce/capability checks as today (`check_ajax_referer`, `current_user_can( 'manage_options' )`).
- **Scope → stage list, matching today's per-button behaviour exactly** (today, *every* completed AJAX batch — single post type or taxonomy — already triggers a bundle rebuild via `maybe_rebuild_bundle( only_if_stale = true )`, not only the "generate everything" flow):
  - `Generate all: {post_type}` → `[ {post_type: slug}, {bundle} ]`
  - Taxonomy button → `[ {taxonomy}, {bundle} ]`
  - `Generate all` (all types + taxonomy) → `[ {post_type: slug_1}, ..., {post_type: slug_n}, {taxonomy}, {bundle} ]`
  - Every scope ends in exactly one `BundleStage`, never more than one, regardless of how many post-type stages precede it.
- `maybe_rebuild_bundle()` is retired as a request-time call from the AJAX handlers — bundle rebuild is now the final `Stage` in the chain, not a synchronous step tacked onto the last batch's response.
- A rejected `start()` (job already running and fresh) returns a 409 with a clear message; the JS switches straight to the progress view for the run that is already going rather than showing an error.

## 5. SettingsPage / JS changes

- Buttons unchanged in placement (`Generate all`, `Generate all: {post_type}`, taxonomy button) but now POST to `mfa_start_generation_job` with a scope instead of driving batches themselves.
- `assets/js/bulk-generate.js` rewritten: on start, flip to a progress view; poll `mfa_job_status` every 5 seconds (fixed floor — `admin-ajax.php` is uncached); render per stage `"{label}: {processed} processed, {skipped} skipped, {error_count} error(s)"` plus `"stage k of n"`; stop polling on `done` / `failed`; show the capped error list with a "+N more" note derived from `error_count` minus the list length.
- **Skipped counts are surfaced, not hidden.** Dropping the processed-vs-total stop condition (Problem #5) means a finished run legitimately shows `processed < total`. Without an explicit skipped figure that reads as a silent failure, so the UI states it.
- Polling stops after three consecutive request failures and shows "lost contact with the server — reload to see current progress", rather than reporting the job as failed. A long run can outlive the AJAX nonce (12–24h), and a dead poller does not mean a dead job.

---

## Rejected / deferred

- **Action Scheduler dependency:** rejected. Its presence on a site is contingent on an unrelated plugin bundling it, not a deliberate choice for this plugin; a conditional dual-path (WP-Cron with an AS fallback) would mean maintaining two queue engines — differing retry/concurrency/persistence semantics — for a benefit that only materialises on the subset of sites where AS happens to already be loaded. No evidence yet that WP-Cron reliability (rather than the AJAX/offset model) is the actual bottleneck. Revisit as a deliberate, bundled dependency only if real-world telemetry shows WP-Cron itself is the weak link.
- **Cursor pagination via `posts_where` / `terms_clauses` filters:** rejected in favour of explicit `$wpdb` queries — see §1.1 for the reasoning and the accepted trade-off.
- **One batch per cron tick:** rejected — `WP_CRON_LOCK_TIMEOUT` makes it roughly one batch per minute. Replaced by the time-boxed tick in §3.1.
- **Mid-run cancel:** deferred by explicit choice — progress polling only, no cancel button, to keep the state machine and its test surface smaller.
- **`wp markdown-agents job-status` / `job-clear`:** deferred — `start()`'s staleness check plus the watchdog cover recovery.

---

## Tests

- `Jobs\GenerationJobTest` (new): `start()` rejects a second start while `running` and fresh; `start()` supersedes a `running` job whose `last_tick_at` is past the 10-minute threshold; the job option is written with `autoload = false`; lock-token mismatch on write is a no-op; the error list caps at 50 while `error_count` keeps counting.
- `Jobs\JobRunnerTest` (new):
  - a tick advances cursor and per-stage counters and stamps `last_tick_at`;
  - **time-boxing**: with a stubbed clock, a tick processes multiple batches within budget and stops at the boundary (asserted between batches, never mid-batch); a `BundleStage` batch ends the tick even with budget left;
  - **totals**: `count_total()` is called exactly once per stage and the stored `total` never changes across that stage's batches (regression test for the shrinking-total blocker);
  - a tick that finishes a stage advances `stage_index` **and resets `cursor` to `0`** — asserting the next stage's first query uses cursor `0`, not the previous stage's leftover value;
  - finishing a `post_type` stage clears that slug via `NeedsRegenTracker`;
  - the final stage's completion flips `status` to `done`, and schedules a bundle rebuild when `HASH_OPTION` is absent at that moment;
  - a per-item error is collected without halting the tick or the job;
  - a batch where every item is skipped still reports `done` from rows-returned, not from `processed` (regression test for Problem #5);
  - **scheduling**: no duplicate tick is scheduled when one is already pending; `admin_init`'s direct-call nudge fires only when no event is pending *and* `last_tick_at` is over 60s old; a `wp_schedule_single_event()` returning `false` increments `schedule_failures` and leaves the job `running`; the third consecutive failure flips it to `failed` with a message; the watchdog schedules a tick for a `running` job with a stale `last_tick_at` and does nothing for a fresh one.
- `Jobs\TickMutexTest` (new): a second tick finding a fresh lock exits without processing a batch or scheduling; a lock past `lock_window()` is stolen and the tick proceeds; **the steal is token-confirmed** — simulating a competing steal between `delete_option()` and the confirming read makes the losing tick back off rather than run concurrently (regression test for the stale-recovery race); the heartbeat refreshes `acquired_at` between batches so a long tick is never declared abandoned; the lock is released on the done, failed, token-mismatch, and throwing paths; a tick never deletes a lock whose token isn't its own.
- `PostTypeStage` / `TaxonomyStage`: cursor pagination asserted against the `wpdb` mock's recorded queries — no `OFFSET`, no `SQL_CALC_FOUND_ROWS`, `ID > %d` / `tt.term_taxonomy_id > %d` present, `ORDER BY ... ASC`, correct `LIMIT`; a given cursor returns the correct next slice; `done` flips only when fewer than `$limit` rows come back; `TaxonomyStage` covers terms from multiple taxonomies interleaved by `term_taxonomy_id` and a shared term appearing in two taxonomies (both archives generated, neither skipped) — the regression tests for the taxonomy-cursor blocker; empty public-taxonomy list short-circuits to `done`.
- `BundleGenerator::mark_stale_and_schedule()`: extended tests confirming it **still deletes `HASH_OPTION`** while a job is running but does **not** schedule (regression test for the dropped-rebuild hole), and that it schedules normally when no job is running.
- Scope-to-stage-list mapping: a test per scope (single post type, taxonomy-only, "generate all") asserting the exact stage list built, and that exactly one `BundleStage` appears regardless of scope.
- `Core\DeactivatorTest`: deactivation clears the job option and the tick-lock option and unschedules `markdown_for_agents_process_batch` and `markdown_for_agents_job_watchdog`.
- Existing `GeneratorTest` / `TaxonomyArchiveGeneratorTest` batch tests: rewritten against the `Stage` implementations; behavioural assertions (skip vs error vs processed) carry over, with skip now asserted as a returned `skipped` count rather than inferred.
- Constant-cost claim (success criterion 3) is asserted structurally — the emitted SQL contains no `OFFSET` and no `SQL_CALC_FOUND_ROWS`, and an early-cursor and late-cursor batch emit structurally identical queries. Wall-clock equivalence is manual acceptance, not a unit test.

### Test mock work (required — not "no changes anticipated")

`tests/mocks/wordpress-mocks.php` needs:

- `wpdb::$posts`, `$term_taxonomy`, `$terms` table properties (only `$prefix` exists today) and a `get_col()` method; `get_results()`/`get_var()` already exist but return a single canned value — they need per-query queueing so successive cursor batches can return different slices.
- `wp_count_posts()`, `wp_count_terms()`, `get_term()`, `wp_unschedule_event()`, `wp_generate_password()`, `remove_filter()` — none exist today. (`remove_filter()` is needed regardless of §1.1, since other code under test calls it.)
- `wp_schedule_single_event()` currently always returns `true` and never dedupes; it needs a configurable return so the §3.3 failure paths are testable, and a mock of the duplicate-within-10-minutes suppression.
- A monotonic injectable clock for the time-boxing and lock-window tests; `time()`/`microtime()` must be reachable through a seam rather than called directly in `JobRunner`.
- Confirm `reset_mock_scheduled_events()` and an options reset run in `setUp()` for the new suites.

---

## Affected files

| File | Change |
|------|--------|
| `src/Jobs/Stage.php` | New (interface: `count_total()` + `process_batch()`) |
| `src/Jobs/GenerationJob.php` | New (job record repository, `start()`, `is_running()`) |
| `src/Jobs/JobRunner.php` | New (time-boxed tick, stage advance, scheduling, watchdog) |
| `src/Jobs/TickMutex.php` | New (token-based acquire/heartbeat/release) |
| `src/Jobs/PostTypeStage.php` | New (`$wpdb` ID-cursor query; delegates per post to `Generator::generate_post()`) |
| `src/Jobs/TaxonomyStage.php` | New (`$wpdb` `term_taxonomy_id`-cursor query; delegates to `TaxonomyArchiveGenerator::generate_term()`) |
| `src/Jobs/BundleStage.php` | New (single-shot; absorbs `Admin::maybe_rebuild_bundle()`'s body) |
| `src/Generator/Generator.php` | Retire `generate_batch()` (offset query) in favour of `PostTypeStage`; expose the per-post eligibility/skip distinction the stage needs |
| `src/Generator/TaxonomyArchiveGenerator.php` | Retire `generate_batch()` and its upfront `get_all_public_terms()` collection in favour of `TaxonomyStage` |
| `src/Generator/BundleGenerator.php` | `mark_stale_and_schedule()` always marks stale; skips only the scheduling while a job is running |
| `src/Admin/Admin.php` | Replace `handle_generate_batch_ajax()` / `handle_generate_taxonomy_batch_ajax()` / `maybe_rebuild_bundle()` with `handle_start_generation_job_ajax()` / `handle_job_status_ajax()`; needs-regen transient logic extracted |
| `src/Admin/NeedsRegenTracker.php` | New — the needs-regen transient logic, shared by `Admin` and `JobRunner` (today's `Admin::mark_post_type_regenerated()` is `private`) |
| `src/Admin/SettingsPage.php` | Button markup targets the new start action; regen-notice logic unchanged, now driven by `NeedsRegenTracker` |
| `src/Core/Plugin.php` | Register `wp_ajax_mfa_start_generation_job`, `wp_ajax_mfa_job_status`, `markdown_for_agents_process_batch`, `markdown_for_agents_job_watchdog` (+ its `init` scheduling), `admin_init` nudge; drop the two retired AJAX registrations |
| `src/Core/Deactivator.php` | Clear job + tick-lock options; unschedule the tick and watchdog events |
| `assets/js/bulk-generate.js` | Rewritten: start + 5s poll, per-stage progress with skipped counts, poll-failure handling; no batch-driving loop (removes Problem #5 entirely) |
| `tests/mocks/wordpress-mocks.php` | Substantial additions — see "Test mock work" above |
| `tests/Unit/Jobs/*` | New: `GenerationJobTest`, `JobRunnerTest`, `TickMutexTest`, `PostTypeStageTest`, `TaxonomyStageTest`, `BundleStageTest` |
| `tests/Unit/Generator/GeneratorTest.php` | Batch tests rewritten against `PostTypeStage` |
| `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php` | Batch tests rewritten against `TaxonomyStage` |
| `tests/Unit/Generator/BundleGeneratorTest.php` | Extend: marks stale but doesn't schedule while a job runs |
| `tests/Unit/Core/DeactivatorTest.php` | New/extend: job state cleared on deactivation |
| `src/CLI/Commands.php` | Opportunistic: reuse `Stage::process_batch()` in the bulk loop; must not touch the job option or mutex |
| `README.md` | Document `markdown_for_agents_tick_budget` (the one new public filter) |

---

## Success criteria

1. Starting "Generate all" on a large site returns immediately; the job continues after the browser tab is closed.
2. Every PHP request in the flow is bounded by a self-chosen wall-clock budget (default ≤30s, or 60% of `max_execution_time` where lower), checked only between batches — and throughput is not capped by WP-Cron's one-spawn-per-60s limit, because a tick processes as many batches as its budget allows.
3. No batch's query cost grows with how far into the run it is: emitted SQL contains no `OFFSET` and no `SQL_CALC_FOUND_ROWS`, and early- and late-cursor batches emit structurally identical queries.
4. Each stage's `total` is counted once and stays constant for the life of that stage; the progress display never goes backwards.
5. Taxonomy archive generation no longer loads the full term list before slicing a batch, walks terms from every public taxonomy in `term_taxonomy_id` order, and generates one archive per (term, taxonomy) pair including legacy shared terms.
6. Only one job runs at a time; starting a second while one is live is rejected with a clear message and the UI shows the live run's progress.
7. A single post/term failure doesn't halt the job; errors surface in the polled status, capped at 50 with an accurate "+N more" from `error_count`.
8. A batch where every item is skipped still terminates correctly — no endless empty-batch polling (regression test for the current live bug, Problem #5) — and the finished run reports its skipped count explicitly rather than looking like a partial failure.
9. A job whose tick fatals (e.g. OOM) doesn't permanently block future runs: the tick mutex is stealable after `lock_window()`, the steal is token-confirmed so two would-be stealers can't both proceed, and `start()` supersedes the dead job.
10. A healthy tick that runs longer than expected is never declared abandoned — the mutex heartbeats between batches.
11. A chain that loses its scheduled event recovers: within 60s from any admin request, or within the hour from the watchdog. A chain that genuinely cannot schedule fails loudly after three consecutive failures instead of showing a live run that never advances.
12. The `markdown_for_agents_needs_regen` notice still clears correctly once a job finishes regenerating the relevant post type(s).
13. A stage transition never carries the previous stage's cursor forward — each stage's first batch starts from `0`.
14. Two ticks firing at once never both process a batch — one proceeds, the other is a no-op that schedules nothing.
15. The export tree is never left with a stale zip: staleness is always recorded even while a job runs, and a change landing after the job's `BundleStage` still results in a scheduled rebuild.
16. Deactivating the plugin mid-job leaves no orphaned cron events or lock options behind.
17. All existing tests pass after changes; new job/stage/mutex tests pass.

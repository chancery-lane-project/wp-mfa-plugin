# WP-Cron Batch Queue for Bulk Generation — Design Spec

**Date:** 2026-08-11
**Scope:** Replace the client-driven AJAX batching loop (introduced in `2026-03-17-ajax-bulk-generation-design.md`) for bulk "Generate all" flows with a server-driven WP-Cron job queue, and fix the offset-pagination and upfront-collection costs underneath it. Single-post regeneration on save and WP-CLI commands are unaffected.

---

## Problem

On large sites, bulk regeneration (`Generate all`, `Generate all: {post_type}`, taxonomy archive generation, and the bundle rebuild that follows) reports:

1. **PHP timeout mid-batch** — the client-driven AJAX loop still runs each batch inside a user-facing HTTP request; slow batches (heavy meta/ACF resolution, slow hosting) can exceed `max_execution_time`.
2. **Memory exhaustion** — most visibly in the synchronous whole-export-tree zip rebuild (`Admin::maybe_rebuild_bundle()`) that runs on the final batch's request.
3. **Browser/tab dependency** — the JS loop (`assets/js/bulk-generate.js`) is the only thing driving progress; closing the tab, navigating away, or a dropped connection kills the run partway through.
4. **Whole-site collection upfront** — `TaxonomyArchiveGenerator::generate_batch()` fetches *all* terms via `get_terms()` before slicing a batch out of the in-memory array, and `Generator::generate_batch()` uses `WP_Query` with `'offset' => $offset`, whose scan cost grows linearly with offset — batch 800 on a 40,000-post site scans and discards 40,000 rows to return 50.
5. **Endless run when a batch's items are skipped** — a live bug in the current JS loop, worth naming explicitly since the new design must not reintroduce it. `assets/js/bulk-generate.js:114` decides whether to fetch another batch with `accumulated.processed < total`. `Generator::generate_batch()` never increments `processed` for ineligible/skipped posts, so on a post type with any skipped posts `accumulated.processed` permanently lags `total`. `offset` keeps advancing regardless, so once it runs past the real result set, every further batch returns `processed: 0` against an unchanged `total` — the stop condition never becomes false and the loop polls empty batches indefinitely. The correct termination signal is query exhaustion (fetched-count-less-than-limit / cursor past the last row) — the same pattern already used correctly by `Generator::write_manifests()`'s own collection loop (`Generator.php:394-409`, `} while ( $fetched === $batch_size )`) — not the processed/skip/error count.

These aren't independent bugs — they're all downstream of the client-driven, offset-paginated model itself.

---

## Solution Overview

A single job record (one option) tracks progress through an ordered list of stages (post types, then taxonomy archives, then bundle rebuild). A self-rescheduling `wp_schedule_single_event()` chain processes one batch per cron tick until the job completes. The browser only starts a job and polls its status — it never drives processing.

```
SettingsPage (PHP)
  └── "Generate all" / "Generate all: {post_type}" / taxonomy button
        └── POST admin-ajax.php action=mfa_start_generation_job
              └── Admin::handle_start_generation_job_ajax()
                    └── Jobs\GenerationJob::start(stages)
                          └── writes job option, schedules first tick

Jobs\JobRunner (hooked to markdown_for_agents_process_batch cron action, and admin_init while a job is running)
  └── loads job, re-validates lock_token
  └── current stage's Stage::process_batch(cursor, limit)
        └── Generator (post-type stages) | TaxonomyArchiveGenerator (taxonomy stage) | BundleStage (bundle rebuild)
  └── updates job progress/errors
  └── advances to next stage, or reschedules tick, or marks done/failed

Admin JS (rewritten)
  └── on start response: flip to progress view
  └── poll admin-ajax.php action=mfa_job_status every few seconds
  └── render "{processed} / {total}" per stage; stop polling on done/failed
  └── no cancel control (deferred — see Rejected/Deferred below)
```

---

## 1. Shared `Stage` interface (DRY consolidation)

Both existing batch methods have the same shape — offset/limit in, `{total, processed, errors}` out — but are separately implemented and both carry the offset-cost problem. Consolidate behind one contract so `JobRunner` doesn't special-case post-type vs taxonomy vs bundle, and the cursor-pagination fix lands in one place per implementation rather than being copy-pasted:

```php
namespace Tclp\WpMarkdownForAgents\Jobs;

interface Stage {
    /**
     * @return array{total: int, processed: int, errors: list<array{post_id?: int, term_id?: int, message: string}>, next_cursor: int, done: bool}
     */
    public function process_batch( int $cursor, int $limit ): array;
}
```

- `Generator` gains a `PostTypeStage` (or implements `Stage` directly) that drops `'offset' => $offset` for an ID-cursor query: `'orderby' => 'ID', 'order' => 'ASC'`, `WHERE ID > $cursor`. Neither `WP_Query` nor a bare query arg exposes a minimum-ID param, so this needs a scoped `posts_where` filter: added immediately before `new WP_Query(...)`, appending `AND {$wpdb->posts}.ID > {$cursor}` (cursor passed via a closure variable, not user input, so a placeholder-free append is safe), and `remove_filter`'d immediately after the query runs — mirroring the add/remove-around-the-call pattern, not a permanently-registered filter. `done` is `count( $query->posts ) < $limit`; `next_cursor` is the last ID in the batch (or unchanged if the batch was empty).
- `TaxonomyArchiveGenerator` gains an equivalent `TaxonomyStage`. `get_terms()`'s only pagination args are `number`/`offset` — the same `LIMIT`/`OFFSET` cost this fix exists to remove — so it needs the same technique via the `terms_clauses` filter, appending `AND t.term_id > {$cursor}` to the `where` clause it returns, added/removed around the single `get_terms()` call. `done`/`next_cursor` computed the same way as the post-type stage, by count-returned vs `$limit`.
- **`done` must never be derived from `processed`/skipped/error counts** — only from query exhaustion (rows returned this batch `< $limit`). This is the exact bug being fixed (see Problem #5): a batch where every item was skipped still correctly reports `done: false` if a full page came back, `done: true` if it didn't, regardless of how many of those rows were actually written.
- Bundle rebuild becomes a `BundleStage` — a single-shot "stage" with `total = processed = 1`, `done: true` on its first (only) call — so it runs as an ordinary tick in the same chain, off any user-facing request. It always calls `rebuild_bundle( $bundle_generator, true )` (`only_if_stale = true`), preserving today's "skip the zip if nothing changed" behaviour (see §4 for which scopes get this stage).
- CLI's bulk loop (`CLI/Commands.php:519`, inside `generate_incremental()`) currently loops per post checking `generate_post()`'s boolean return with no try/catch around it; it could call the same `Stage::process_batch()` directly instead — opportunistic reuse, not required for this fix, and CLI's synchronous behaviour is otherwise unchanged (no cron involved there).

`generate_post()` remains the single funnel for actually writing a post's Markdown — untouched, already reused everywhere (admin single-regen, CLI, `on_save_post`, both batch stages).

---

## 2. `Jobs\GenerationJob` (new)

Thin repository around one option, e.g. `markdown_for_agents_job`:

```php
[
    'status'      => 'idle' | 'running' | 'done' | 'failed',
    'lock_token'  => string,               // random, set on start; re-validated each tick
    'stages'      => [ ['type' => 'post_type', 'slug' => 'post'], ['type' => 'taxonomy'], ['type' => 'bundle'] ],
    'stage_index' => int,
    'cursor'      => int,                  // cursor within current stage
    'progress'    => [ 'total' => int, 'processed' => int ],   // current stage
    'errors'      => list<array{...}>,     // capped, e.g. last 50
    'last_tick_at' => int,                 // timestamp, updated on every tick
]
```

- `start( array $stages )`: rejects with an error if `status === 'running'` **and** `last_tick_at` is recent (within a stale threshold, e.g. 5 minutes — comfortably longer than one tick's `time() + 1` reschedule gap). If `status === 'running'` but `last_tick_at` is older than the threshold, the job is treated as dead (its tick fatal'd — e.g. OOM, an uncaught `Error` — with nothing left to reschedule it) and `start()` supersedes it: writes a fresh record with a new `lock_token`, so a crashed job can't permanently block every future run. Otherwise writes a fresh record and schedules the first `markdown_for_agents_process_batch` tick via `wp_schedule_single_event( time(), ... )`.
- `JobRunner` re-reads the option each tick and only writes back if its in-memory `lock_token` still matches (optimistic lock), and stamps `last_tick_at` on every successful write — guards against a duplicate tick (e.g. real cron and the `admin_init` nudge below racing each other) and gives `start()` the staleness signal above.
- A WP-CLI companion command (`wp markdown-agents job-status` / `job-clear`) is out of scope for this fix — the `last_tick_at` staleness check in `start()` is sufficient recovery; a manual override can be added later if needed.

---

## 3. `Jobs\JobRunner` (new)

- Hooked to `markdown_for_agents_process_batch` (cron action) **and** `admin_init`. To prevent the two paths racing each other (both scheduling or both directly running a tick around the same moment), `admin_init`'s nudge does not schedule anything — it calls the tick handler directly, and only when `status === 'running'`, the settings page is the current screen, **and** `wp_next_scheduled( 'markdown_for_agents_process_batch' )` returns false (i.e. no pending cron tick already exists to do the same work). This makes the nudge a same-process fallback for exactly the case where the scheduled event is missing, never a duplicate of one that's pending.
- Symmetrically, the reschedule at the end of a tick (below) checks `wp_next_scheduled()` before calling `wp_schedule_single_event()` — mirroring the existing convention in `BundleGenerator::mark_stale_and_schedule()` (`BundleGenerator.php:267-277`; hooked up via `Plugin.php:147-158`), which guards the same way before scheduling its bundle-rebuild event.
- Each tick: acquire the tick mutex (below) → validate lock token → call current stage's `process_batch( cursor, limit )` → merge `errors` into the job record (capped at 50, with an "+N more" note past the cap) → if `done`, advance `stage_index` **and reset `cursor` to `0`** (the cursor is scoped to the current stage only — carrying a leftover post-ID cursor into a `taxonomy` or a lower-ID `post_type` stage would make that stage's first query return nothing, silently marking it complete having done no work) — calling the post-type regen-tracking hook below when a `post_type` stage finishes — or mark job `done` if that was the last stage → if not done, update `cursor`/`progress`, stamp `last_tick_at`, and reschedule another tick (`time() + 1`) unless one is already pending → release the mutex.
- **Concurrent-execution guard:** the `wp_next_scheduled()` checks above stop duplicate *pending cron events*, but not two ticks physically running at once — e.g. two admin tabs open, both hitting the `admin_init` nudge in overlapping requests, both seeing no event scheduled yet (because neither has scheduled anything), both calling the tick handler directly with the same still-valid `lock_token`. The `lock_token` write-time check doesn't stop this, since both would still hold a matching token. Each tick therefore wraps its body in a short-lived mutex, stored as a timestamp rather than a bare flag so a crashed tick can't wedge it permanently: `add_option( 'markdown_for_agents_job_tick_lock', time(), '', false )` acquires it (atomic insert-fails-if-already-exists, unlike a plain `update_option`; the `wp_options.option_name` unique key makes this safe even against two requests racing past a PHP-level existence check at the same instant). A tick that fails to acquire reads the existing value: if it's within a short staleness window (comfortably longer than one tick, e.g. 30–60s), the other tick is still legitimately running — return immediately, doing no work and scheduling nothing (the tick that holds the lock will reschedule when it finishes, so no work is lost). If the existing value is *older* than that window, the lock is abandoned — its owning tick fatal'd (OOM, uncaught `Error`) before reaching its `delete_option()` — so `delete_option()` it and retry acquisition once, rather than treating a crashed tick as "still running" forever. Without this, a single fatal inside the locked section would permanently block every future tick even after `GenerationJob::start()`'s own staleness check (§2) supersedes the crashed job, since that check only touches the job option, not this separate mutex option. `delete_option()` releases the mutex normally at the end of a tick, including on the early-return paths (done/failed).
- A per-item error (post/term throws) does not stop the job — mirrors the existing `try/catch`-per-post pattern in today's `generate_batch()`. Only a structural failure (e.g. the job option itself can't be written) flips the job to `failed`.
- **`markdown_for_agents_needs_regen` handoff:** today, `Admin::mark_post_type_regenerated()` clears a post type from this transient when its AJAX batch run completes (driving `SettingsPage::display_regen_notice()`), called from the handler being retired. `JobRunner` takes over this call: on advancing past a `post_type` stage (i.e. that stage's `done` was true), it calls the same `mark_post_type_regenerated( $slug )` logic before moving to the next stage — so the "settings changed, regenerate" notice still clears correctly once the job actually regenerates that type. `SettingsPage.php`'s notice/transient-reading code is otherwise unchanged.
- **Interaction with the existing debounced bundle rebuild:** every `markdown_for_agents_file_generated`/`_taxonomy_file_generated` event fired while a stage runs already triggers `BundleGenerator::mark_stale_and_schedule()`, independently scheduling `markdown_for_agents_rebuild_bundle` five minutes out (`Plugin.php:147-158`). Left alone, this fires in parallel with the job's own `BundleStage` and both would rebuild the same zip. `mark_stale_and_schedule()` gains one added check: skip scheduling while `GenerationJob`'s `status === 'running'`, since the job's own `BundleStage` will cover the rebuild when it gets there. This is a one-line guard in existing code, not a new mechanism — accepted as the simplest way to avoid the redundant rebuild rather than leaving it as "harmless but wasteful."

---

## 4. Admin changes

- `mfa_generate_batch` / `mfa_generate_taxonomy_batch` → `mfa_start_generation_job` (accepts a `scope` param; builds the stage list and calls `GenerationJob::start()`) and `mfa_job_status` (read-only, returns the current job record for polling). Same nonce/capability checks as today (`check_ajax_referer`, `current_user_can( 'manage_options' )`).
- **Scope → stage list, matching today's per-button behaviour exactly** (today, *every* completed AJAX batch — single post type or taxonomy — already triggers a bundle rebuild via `maybe_rebuild_bundle( only_if_stale = true )`, not only the "generate everything" flow):
  - `Generate all: {post_type}` → `[ {post_type: slug}, {bundle} ]`
  - Taxonomy button → `[ {taxonomy}, {bundle} ]`
  - `Generate all` (all types + taxonomy) → `[ {post_type: slug_1}, ..., {post_type: slug_n}, {taxonomy}, {bundle} ]`
  - Every scope ends in exactly one `BundleStage`, never more than one, regardless of how many post-type stages precede it.
- `maybe_rebuild_bundle()` is retired as a request-time call from the AJAX handlers — bundle rebuild is now the final `Stage` in the chain, not a synchronous step tacked onto the last batch's response.

## 5. SettingsPage / JS changes

- Buttons unchanged in placement (`Generate all`, `Generate all: {post_type}`, taxonomy button) but now POST to `mfa_start_generation_job` with a scope instead of driving batches themselves.
- `assets/js/bulk-generate.js` rewritten: on start, flip to a progress view; poll `mfa_job_status` every few seconds; render `"{processed} / {total}"` per stage; stop polling on `done`/`failed`; show accumulated error count (and the "+N more" note if capped). No cancel control (explicitly deferred, see below).

---

## Rejected / deferred

- **Action Scheduler dependency:** rejected. Its presence on a site is contingent on an unrelated plugin bundling it, not a deliberate choice for this plugin; a conditional dual-path (WP-Cron with an AS fallback) would mean maintaining two queue engines — differing retry/concurrency/persistence semantics — for a benefit that only materialises on the subset of sites where AS happens to already be loaded. No evidence yet that WP-Cron reliability (rather than the AJAX/offset model) is the actual bottleneck. Revisit as a deliberate, bundled dependency only if real-world telemetry shows WP-Cron itself is the weak link.
- **Mid-run cancel:** deferred by explicit choice — progress polling only, no cancel button, to keep the state machine and its test surface smaller.

---

## Tests

- `Jobs\GenerationJobTest` (new): `start()` rejects a second start while `running` and recent; `start()` supersedes a `running` job whose `last_tick_at` is past the staleness threshold; lock-token mismatch is a no-op; error list is capped.
- `Jobs\JobRunnerTest` (new): a tick advances cursor/progress and stamps `last_tick_at`; a tick that finishes a stage advances `stage_index` **and resets `cursor` to `0`** (the regression test for the cursor-carryover gap — assert the next stage's first batch is queried from `0`, not the previous stage's leftover cursor), and, for a `post_type` stage, clears that type from `markdown_for_agents_needs_regen`; the final stage's completion flips `status` to `done`; a per-item error is collected without halting the tick; a batch where every item is skipped still correctly reports `done` based on rows-returned, not on `processed` count (the regression test for Problem #5); mocked `wp_schedule_single_event`/`wp_next_scheduled` (extend `tests/mocks/wordpress-mocks.php`) confirm no duplicate tick is scheduled when one is already pending, and that `admin_init`'s direct-call nudge only fires when none is pending; a second tick invocation that finds the mutex option already present (recent timestamp) exits immediately without processing a batch or rescheduling (the regression test for the concurrent-execution race); a mutex option left over with a stale (past-threshold) timestamp is deleted and retried rather than honoured forever (the regression test for a crashed tick permanently wedging future runs).
- `Generator`'s and `TaxonomyArchiveGenerator`'s `Stage` implementations: cursor pagination tested directly against the existing `WP_Query`/`get_terms` mocks — verifying the `posts_where`/`terms_clauses` filter is added and removed around the call, no offset is used, and a given cursor returns the correct next slice.
- Scope-to-stage-list mapping: a test per scope (single post type, taxonomy-only, "generate all") asserting the exact stage list built, and that exactly one `BundleStage` appears regardless of scope.
- `BundleGenerator::mark_stale_and_schedule()`: extended test confirming it does not schedule while a `GenerationJob` is `running`.
- Existing `GeneratorTest` / `TaxonomyArchiveGenerator` batch tests updated for the cursor-based signature; behavioural assertions (skip vs error vs processed) carry over unchanged.

---

## Affected files

| File | Change |
|------|--------|
| `src/Jobs/GenerationJob.php` | New |
| `src/Jobs/JobRunner.php` | New |
| `src/Jobs/Stage.php` | New (interface) |
| `src/Generator/Generator.php` | Replace offset batch query with `posts_where`-filtered ID-cursor query; implement `Stage` |
| `src/Generator/TaxonomyArchiveGenerator.php` | Replace upfront `get_terms()`+slice with `terms_clauses`-filtered ID-cursor query; implement `Stage` |
| `src/Admin/Admin.php` | Replace `handle_generate_batch_ajax()`/`handle_generate_taxonomy_batch_ajax()`/`maybe_rebuild_bundle()` request-time call with `handle_start_generation_job_ajax()` / `handle_job_status_ajax()`; move `mark_post_type_regenerated()` call site into `JobRunner` (method itself can stay on `Admin` or move — implementation's call) |
| `src/Generator/BundleGenerator.php` | `mark_stale_and_schedule()` gains a check to skip scheduling while a `GenerationJob` is `running` |
| `src/Admin/SettingsPage.php` | Button markup targets new start action; regen-notice/transient logic unchanged, now driven by `JobRunner` |
| `src/Core/Plugin.php` | Register `wp_ajax_mfa_start_generation_job`, `wp_ajax_mfa_job_status`, `markdown_for_agents_process_batch` (cron), `admin_init` (nudge) hooks; drop the two retired AJAX hook registrations |
| `assets/js/bulk-generate.js` | Rewritten: start + poll, no batch-driving loop (fixes Problem #5 as a side effect of removing the loop entirely) |
| `tests/mocks/wordpress-mocks.php` | Already provides the `add_option` (fails-if-exists), `wp_schedule_single_event`, and `wp_next_scheduled` mocks the tick mutex and reschedule-guard tests need — no changes anticipated; confirm `reset_mock_scheduled_events()` runs in `setUp()` for the new job/runner test suites |
| `tests/Unit/Jobs/GenerationJobTest.php` | New |
| `tests/Unit/Jobs/JobRunnerTest.php` | New |
| `tests/Unit/Generator/GeneratorTest.php` | Update batch tests for cursor signature |
| `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php` | Update batch tests for cursor signature |
| `tests/Unit/Generator/BundleGeneratorTest.php` | Extend: no schedule while a job is running |
| `src/CLI/Commands.php` | Opportunistic: reuse `Stage::process_batch()` in bulk loop (not required for this fix) |
| `README.md` | Document new filters/actions if any are added (none anticipated — internal only) |

---

## Success criteria

1. Starting "Generate all" on a large site returns immediately; the job continues after the browser tab is closed.
2. No PHP request in the flow does more than one batch's worth of work — including the bundle rebuild, now a tick of its own.
3. No batch's query cost grows with how far into the run it is (verified: a late-stage batch and an early-stage batch cost the same).
4. Taxonomy archive generation no longer loads the full term list before slicing a batch.
5. Only one job can run at a time; starting a second while one runs is rejected with a clear message.
6. A single post/term failure doesn't halt the job; errors surface in the polled status, capped and noted if truncated.
7. A batch where every item is skipped still terminates correctly — no endless empty-batch polling (regression test for the current live bug, Problem #5).
8. A job whose tick process fatals (e.g. OOM) doesn't permanently block future runs — `start()` recovers via the staleness check.
9. The `markdown_for_agents_needs_regen` admin notice still clears correctly once a job finishes regenerating the relevant post type(s).
10. A stage transition never carries the previous stage's cursor forward — each stage's first batch starts from `0`.
11. Two ticks firing at once (e.g. two admin tabs both nudging via `admin_init`) never both process a batch — one proceeds, the other is a no-op.
12. All existing tests pass after changes; new job/cursor tests pass.

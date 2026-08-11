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

- `Generator` gains a `PostTypeStage` (or implements `Stage` directly) that replaces `'offset' => $offset` with an ID-cursor query: `'orderby' => 'ID', 'order' => 'ASC'`, filtering `WHERE ID > $cursor` (via `WP_Query`'s post-clauses filter, or — simpler — dropping `offset` and adding a minimum-ID constraint through an existing WP_Query arg if one covers it; confirmed at implementation time). No batch ever pays for skipping earlier rows.
- `TaxonomyArchiveGenerator` gains an equivalent `TaxonomyStage`, replacing the upfront `get_terms()` + `array_slice()` with a paginated `get_terms()` call (`number` + an ID-cursor equivalent, since `get_terms()` doesn't support raw `offset` cleanly at scale either — confirmed at implementation time) so no batch requires materialising the full term list.
- Bundle rebuild becomes a `BundleStage` — a single-shot "stage" with `total = processed = 1` — so it runs as an ordinary tick in the same chain, off any user-facing request.
- CLI's bulk loop (`CLI/Commands.php:519`) can call the same `Stage::process_batch()` directly in its own foreach instead of its separate hand-rolled per-post try/catch — opportunistic reuse, not required for this fix, and CLI's synchronous behaviour is otherwise unchanged (no cron involved there).

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
]
```

- `start( array $stages )`: rejects with an error if `status === 'running'` (concurrency guard — one job at a time). Otherwise writes a fresh record with a new `lock_token` and schedules the first `markdown_for_agents_process_batch` tick via `wp_schedule_single_event( time(), ... )`.
- `JobRunner` re-reads the option each tick and only writes back if its in-memory `lock_token` still matches (optimistic lock) — guards against a duplicate tick (e.g. real cron and the `admin_init` nudge below racing each other).

---

## 3. `Jobs\JobRunner` (new)

- Hooked to `markdown_for_agents_process_batch` (cron action) **and** `admin_init` (only when `status === 'running'` and the settings page is the current screen) — the `admin_init` hook is a cheap self-nudge for pseudo-cron sites where a job started from wp-admin could otherwise stall between page loads. It is not a substitute for real cron; documented as such in the settings-page copy ("processing continues in the background; visiting this page also nudges it forward").
- Each tick: validate lock token → call current stage's `process_batch( cursor, limit )` → merge `errors` into the job record (capped at 50, with an "+N more" note past the cap) → if `done`, advance `stage_index` (or mark job `done` if that was the last stage) → if not done, update `cursor`/`progress` and reschedule another tick (`time() + 1`).
- A per-item error (post/term throws) does not stop the job — mirrors the existing `try/catch`-per-post pattern in today's `generate_batch()`. Only a structural failure (e.g. the job option itself can't be written) flips the job to `failed`.

---

## 4. Admin changes

- `mfa_generate_batch` / `mfa_generate_taxonomy_batch` → `mfa_start_generation_job` (accepts a scope: one post type, all enabled post types, or taxonomies; builds the stage list and calls `GenerationJob::start()`) and `mfa_job_status` (read-only, returns the current job record for polling). Same nonce/capability checks as today (`check_ajax_referer`, `current_user_can( 'manage_options' )`).
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

- `Jobs\GenerationJobTest` (new): `start()` rejects a second start while `running`; lock-token mismatch is a no-op; error list is capped.
- `Jobs\JobRunnerTest` (new): a tick advances cursor/progress; a tick that finishes a stage advances `stage_index`; the final stage's completion flips `status` to `done`; a per-item error is collected without halting the tick; mocked `wp_schedule_single_event` (extend `tests/mocks/wordpress-mocks.php`) is called with the next-tick args when not done.
- `Generator`'s and `TaxonomyArchiveGenerator`'s `Stage` implementations: cursor pagination tested directly against the existing `WP_Query`/`get_terms` mocks — verifying no offset is used and a given cursor returns the correct next slice.
- Existing `GeneratorTest` / `TaxonomyArchiveGenerator` batch tests updated for the cursor-based signature; behavioural assertions (skip vs error vs processed) carry over unchanged.

---

## Affected files

| File | Change |
|------|--------|
| `src/Jobs/GenerationJob.php` | New |
| `src/Jobs/JobRunner.php` | New |
| `src/Jobs/Stage.php` | New (interface) |
| `src/Generator/Generator.php` | Replace offset batch query with ID-cursor; implement `Stage` |
| `src/Generator/TaxonomyArchiveGenerator.php` | Replace upfront `get_terms()`+slice with paginated cursor query; implement `Stage` |
| `src/Admin/Admin.php` | Replace `handle_generate_batch_ajax()`/`handle_generate_taxonomy_batch_ajax()`/`maybe_rebuild_bundle()` request-time call with `handle_start_generation_job_ajax()` / `handle_job_status_ajax()` |
| `src/Admin/SettingsPage.php` | Button markup targets new start action |
| `src/Core/Plugin.php` | Register `wp_ajax_mfa_start_generation_job`, `wp_ajax_mfa_job_status`, `markdown_for_agents_process_batch` (cron), `admin_init` (nudge) hooks; drop the two retired AJAX hook registrations |
| `assets/js/bulk-generate.js` | Rewritten: start + poll, no batch-driving loop |
| `tests/mocks/wordpress-mocks.php` | Extend with `wp_schedule_single_event` mock hooks as needed |
| `tests/Unit/Jobs/GenerationJobTest.php` | New |
| `tests/Unit/Jobs/JobRunnerTest.php` | New |
| `tests/Unit/Generator/GeneratorTest.php` | Update batch tests for cursor signature |
| `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php` | Update batch tests for cursor signature |
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
7. All existing tests pass after changes; new job/cursor tests pass.

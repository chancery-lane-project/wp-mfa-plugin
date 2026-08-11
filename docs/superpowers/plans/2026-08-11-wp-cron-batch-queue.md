# WP-Cron Batch Queue for Bulk Generation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the browser-driven AJAX batch loop for bulk Markdown generation with a server-driven WP-Cron job queue that survives tab closure, uses cursor pagination instead of `OFFSET`, and cannot be wedged by a crashed tick.

**Architecture:** One option (`markdown_for_agents_job`) holds a job record: an ordered list of stages (post types → taxonomy archives → bundle rebuild), a cursor scoped to the current stage, and per-stage counters. A self-rescheduling `wp_schedule_single_event()` chain calls `JobRunner::run_tick()`, which holds a token-based mutex option and loops `Stage::process_batch()` calls until a wall-clock budget is spent, then reschedules. Each `Stage` paginates with an explicit `$wpdb` id-cursor query (`ID > %d` for posts, `tt.term_taxonomy_id > %d` for term archives) and reports `done` purely from rows-returned-vs-limit. The browser only POSTs a scope to start a job and polls a read-only status endpoint.

**Tech Stack:** PHP 8.1+, WordPress plugin (no framework, no service container — wiring lives in `src/Core/Plugin.php`), PHPUnit 9.6 unit tests against hand-written WordPress mocks in `tests/mocks/wordpress-mocks.php`, vanilla ES5 admin JS.

**Spec:** `docs/superpowers/specs/2026-08-11-wp-cron-batch-queue-design.md` — read it before starting. Every "why" question this plan does not answer is answered there.

---

## Ground rules for every task

- `declare(strict_types=1);` at the top of every new PHP file; namespace `Tclp\WpMarkdownForAgents\...` matching the directory under `src/`.
- **Tabs** for indentation in `src/`. **Four spaces** in `tests/` and `tests/mocks/` (that is the existing, inconsistent-but-real convention — match the directory you are editing).
- UK English in comments and docs. Docblocks carry `@since 1.7.0` on new code.
- Never import `League\HTMLToMarkdown\` directly (irrelevant here, but `tests/Unit/StaticImportCheckTest.php` enforces it).
- Run `composer test` before every commit. Run `composer phpcs` before the commits that touch `src/`.
- Commit after every task. Conventional-commit prefixes (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`).

## File structure

**New — `src/Jobs/`** (the queue; nothing here knows about admin UI):

| File | Responsibility |
|------|----------------|
| `Clock.php` | `interface Clock { now(): int; monotonic(): float; }` — the seam that makes time-boxing and lock windows testable |
| `SystemClock.php` | `Clock` backed by `time()` / `microtime( true )` |
| `Stage.php` | `interface Stage { count_total(): int; process_batch( int $cursor, int $limit ): array; }` |
| `PostTypeStage.php` | One post type, `$wpdb` `ID > %d` cursor, delegates each post to `Generator::generate_post()` |
| `TaxonomyStage.php` | All public taxonomies, `$wpdb` `tt.term_taxonomy_id > %d` cursor, delegates to `TaxonomyArchiveGenerator::generate_term()` |
| `BundleStage.php` | Single-shot zip/manifest rebuild (absorbs `Admin::maybe_rebuild_bundle()`) |
| `StageFactory.php` | Scope string → stage-descriptor list; descriptor → `Stage` instance |
| `GenerationJob.php` | Repository for the job option: read, `start()`, token-guarded `save()`, error capping, `is_running()` |
| `TickMutex.php` | Token-based acquire / heartbeat / release over `markdown_for_agents_job_tick_lock` |
| `JobRunner.php` | The tick: mutex → time-boxed batch loop → stage advance → reschedule / watchdog / nudge |

**New — `src/Core/NeedsRegenTracker.php`:** the `markdown_for_agents_needs_regen` transient logic, lifted out of `Admin`'s `private` method so `JobRunner` can call it. Filed under `Core\` rather than `Admin\` (a Task 7 review finding): both the admin UI and the background queue depend on it, so `Admin\` would point `Jobs\JobRunner` at the admin-UI layer. It also owns the transient key as `NeedsRegenTracker::TRANSIENT`, which `Admin::display_regen_notice()` and `SettingsPage`'s flagging path both now use.

**Modified:** `src/Generator/Generator.php` (retire `generate_batch()`), `src/Generator/TaxonomyArchiveGenerator.php` (retire `generate_batch()` + `get_all_public_terms()`), `src/Generator/BundleGenerator.php` (staleness guard), `src/Admin/Admin.php` (two new AJAX handlers replace three methods), `src/Admin/SettingsPage.php` (button markup), `src/Core/Plugin.php` (wiring), `src/Core/Deactivator.php` (cleanup), `assets/js/bulk-generate.js` (rewrite), `src/CLI/Commands.php` (untouched by default — see Task 16).

**Tests:** `tests/Unit/Jobs/{ClockTest,PostTypeStageTest,TaxonomyStageTest,BundleStageTest,StageFactoryTest,GenerationJobTest,TickMutexTest,JobRunnerTest,JobRunnerSchedulingTest}.php`, `tests/Unit/Core/NeedsRegenTrackerTest.php`, `tests/Support/FrozenClock.php` (test double, autoloaded via the `Tclp\WpMarkdownForAgents\Tests\` PSR-4 dev mapping), plus updates to `AdminAjaxTest`, `GeneratorTest`, `TaxonomyArchiveGeneratorTest`, `BundleGeneratorTest`.

---

## Task 1: Mock harness additions

The spec's test plan is unbuildable on today's mocks: `wpdb` has no table properties and no `get_col()`, its `get_results()` returns one canned value forever, and `remove_filter()`, `get_term()`, `wp_count_posts()`, `wp_generate_password()`, `wp_unschedule_hook()`, `wp_schedule_event()` do not exist. `wp_schedule_single_event()` always returns `true` and never dedupes, so the scheduling-failure paths cannot be tested.

**Files:**
- Modify: `tests/mocks/wordpress-mocks.php` (wpdb class at `:874`, cron block at `:145-168`, filters block at `:85-101`)
- Create: `tests/Unit/Support/MockHarnessTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Guards the mock harness itself. These mocks are load-bearing for every
 * Jobs test; a silent regression here shows up as a confusing failure
 * three suites away.
 */
class MockHarnessTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_mock_options'] = [];
        reset_mock_scheduled_events();
        unset( $GLOBALS['_mock_schedule_single_event_return'] );
    }

    public function test_wpdb_exposes_table_properties(): void {
        $wpdb = new \wpdb();

        $this->assertSame( 'wp_posts', $wpdb->posts );
        $this->assertSame( 'wp_term_taxonomy', $wpdb->term_taxonomy );
    }

    public function test_get_col_returns_queued_slices_in_order(): void {
        $wpdb                     = new \wpdb();
        $wpdb->mock_get_col_queue = [ [ 1, 2 ], [ 3 ], [] ];

        $this->assertSame( [ 1, 2 ], $wpdb->get_col( 'SELECT ID …' ) );
        $this->assertSame( [ 3 ], $wpdb->get_col( 'SELECT ID …' ) );
        $this->assertSame( [], $wpdb->get_col( 'SELECT ID …' ) );
        $this->assertCount( 3, $wpdb->queries );
    }

    public function test_get_results_prefers_queue_then_falls_back(): void {
        $wpdb                         = new \wpdb();
        $wpdb->mock_get_results       = [ 'fallback' ];
        $wpdb->mock_get_results_queue = [ [ 'first' ] ];

        $this->assertSame( [ 'first' ], $wpdb->get_results( 'SELECT …' ) );
        $this->assertSame( [ 'fallback' ], $wpdb->get_results( 'SELECT …' ) );
    }

    public function test_schedule_single_event_suppresses_near_duplicates(): void {
        $this->assertTrue( wp_schedule_single_event( 1000, 'mfa_hook' ) );
        $this->assertFalse( wp_schedule_single_event( 1001, 'mfa_hook' ) );
        $this->assertTrue( wp_schedule_single_event( 5000, 'mfa_hook' ) );
    }

    public function test_schedule_single_event_return_can_be_forced(): void {
        $GLOBALS['_mock_schedule_single_event_return'] = false;

        $this->assertFalse( wp_schedule_single_event( 1000, 'mfa_hook' ) );
        $this->assertSame( [], $GLOBALS['_mock_scheduled_events'] );
    }

    public function test_unschedule_hook_clears_every_event_for_that_hook(): void {
        wp_schedule_single_event( 1000, 'mfa_a' );
        wp_schedule_single_event( 1000, 'mfa_b' );

        $this->assertSame( 1, wp_unschedule_hook( 'mfa_a' ) );
        $this->assertFalse( wp_next_scheduled( 'mfa_a' ) );
        $this->assertSame( 1000, wp_next_scheduled( 'mfa_b' ) );
    }

    public function test_remove_filter_drops_only_the_matching_callback(): void {
        $keep = static fn( $v ) => $v;
        $drop = static fn( $v ) => $v;

        $GLOBALS['_mock_filters'] = [];
        add_filter( 'mfa_hook', $keep );
        add_filter( 'mfa_hook', $drop );

        $this->assertTrue( remove_filter( 'mfa_hook', $drop ) );
        $this->assertCount( 1, $GLOBALS['_mock_filters']['mfa_hook'] );
    }

    public function test_count_posts_and_get_term_read_their_globals(): void {
        $GLOBALS['_mock_post_counts']  = [ 'post' => [ 'publish' => 7 ] ];
        $term                          = new \WP_Term();
        $term->term_id                 = 12;
        $term->taxonomy                = 'category';
        $GLOBALS['_mock_terms_by_id']  = [ 12 => $term ];

        $this->assertSame( 7, (int) wp_count_posts( 'post' )->publish );
        $this->assertSame( 0, (int) wp_count_posts( 'page' )->publish );
        $this->assertSame( 'category', get_term( 12, 'category' )->taxonomy );
        $this->assertNull( get_term( 99, 'category' ) );
    }

    public function test_generate_password_returns_distinct_tokens(): void {
        $this->assertNotSame( wp_generate_password( 20, false ), wp_generate_password( 20, false ) );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Support/MockHarnessTest.php`
Expected: FAIL — `Undefined property: wpdb::$posts`, `Call to undefined function ...remove_filter()`, etc.

- [ ] **Step 3: Extend the `wpdb` mock**

In `tests/mocks/wordpress-mocks.php`, inside `class wpdb` (starts `:875`), add the table properties and queue-aware readers next to the existing `$prefix` / `$mock_get_results` members:

```php
        public string $posts         = 'wp_posts';
        public string $terms         = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';

        /** @var list<mixed> Queued get_col() return values, consumed in order. */
        public array $mock_get_col_queue = [];
        /** @var mixed Fallback once the queue is empty. */
        public $mock_get_col = [];
        /** @var list<mixed> Queued get_results() return values, consumed in order. */
        public array $mock_get_results_queue = [];

        public function get_col(string|null $query = null, int $column_offset = 0): array {
            $this->queries[] = ['query' => $query, 'args' => []];

            if ($this->mock_get_col_queue) {
                return (array) array_shift($this->mock_get_col_queue);
            }

            return (array) $this->mock_get_col;
        }
```

and change the existing `get_results()` body to consult the queue first:

```php
        public function get_results(string|null $query = null, string $output = 'OBJECT'): array {
            $this->queries[] = ['query' => $query, 'args' => []];

            if ($this->mock_get_results_queue) {
                return (array) array_shift($this->mock_get_results_queue);
            }

            return $this->mock_get_results;
        }
```

- [ ] **Step 4: Extend the cron mocks**

Replace the `wp_schedule_single_event` / `wp_next_scheduled` block (`:153-168`) with:

```php
if (!function_exists('wp_schedule_single_event')) {
    /**
     * Mirrors two real behaviours the queue depends on: a forced-failure hook
     * for tests, and WordPress's suppression of a duplicate hook+args event
     * scheduled within 10 minutes of an existing one (which returns false).
     */
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool {
        if (isset($GLOBALS['_mock_schedule_single_event_return']) && !$GLOBALS['_mock_schedule_single_event_return']) {
            return false;
        }

        foreach ($GLOBALS['_mock_scheduled_events'] ?? [] as $e) {
            if ($e['hook'] === $hook && $e['args'] === $args && abs($e['timestamp'] - $timestamp) < 600) {
                return false;
            }
        }

        $GLOBALS['_mock_scheduled_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'recurrence' => ''];
        return true;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool {
        $GLOBALS['_mock_scheduled_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'recurrence' => $recurrence];
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []) {
        foreach ($GLOBALS['_mock_scheduled_events'] ?? [] as $e) {
            if ($e['hook'] === $hook) {
                return $e['timestamp'];
            }
        }
        return false;
    }
}

if (!function_exists('wp_unschedule_hook')) {
    function wp_unschedule_hook(string $hook): int {
        $kept    = [];
        $removed = 0;

        foreach ($GLOBALS['_mock_scheduled_events'] ?? [] as $e) {
            if ($e['hook'] === $hook) {
                ++$removed;
                continue;
            }
            $kept[] = $e;
        }

        $GLOBALS['_mock_scheduled_events'] = $kept;
        return $removed;
    }
}
```

- [ ] **Step 5: Add the remaining missing functions**

Next to the existing `add_filter()` (`:85`):

```php
if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, callable|array $callback, int $priority = 10): bool {
        $found = false;
        $kept  = [];

        foreach ($GLOBALS['_mock_filters'][$hook] ?? [] as $registered) {
            if ($registered['callback'] === $callback && $registered['priority'] === $priority) {
                $found = true;
                continue;
            }
            $kept[] = $registered;
        }

        $GLOBALS['_mock_filters'][$hook] = $kept;
        return $found;
    }
}
```

Next to the post/taxonomy mocks (near `:1172`):

```php
if (!function_exists('wp_count_posts')) {
    function wp_count_posts(string $type = 'post', string $perm = ''): object {
        return (object) ($GLOBALS['_mock_post_counts'][$type] ?? ['publish' => 0]);
    }
}

if (!function_exists('get_term')) {
    function get_term(int|\WP_Term $term, string $taxonomy = ''): \WP_Term|\WP_Error|null {
        if ($term instanceof \WP_Term) {
            return $term;
        }

        return $GLOBALS['_mock_terms_by_id'][$term] ?? null;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string {
        $GLOBALS['_mock_password_counter'] = ($GLOBALS['_mock_password_counter'] ?? 0) + 1;
        return 'mocktoken' . $GLOBALS['_mock_password_counter'];
    }
}
```

Also add `MINUTE_IN_SECONDS` / `HOUR_IN_SECONDS` near the top of the mocks file if absent (`define()` guarded by `defined()`) — `HOUR_IN_SECONDS` is used by the watchdog registration in Task 12.

- [ ] **Step 6: Run the harness test, then the whole suite**

Run: `vendor/bin/phpunit tests/Unit/Support/MockHarnessTest.php`
Expected: PASS (every test in the file).

Run: `composer test`
Expected: PASS. **If anything fails here it is almost certainly the new duplicate-event suppression** — an existing test (most likely in `BundleGeneratorTest`) that schedules the same hook twice and expects two recorded events. Fix by calling `reset_mock_scheduled_events()` between the assertions or asserting the second call returns `false`; do **not** weaken the suppression, it is the behaviour Task 11 tests against.

- [ ] **Step 7: Commit**

```bash
git add tests/mocks/wordpress-mocks.php tests/Unit/Support/MockHarnessTest.php
git commit -m "test: extend WordPress mocks for the cron job queue

Adds wpdb table properties, queue-aware get_col()/get_results(),
remove_filter(), wp_count_posts(), get_term(), wp_generate_password(),
wp_schedule_event(), wp_unschedule_hook(), and models WordPress's
duplicate-event suppression plus a forced-failure hook for
wp_schedule_single_event()."
```

---

## Task 2: `Clock` seam

Time-boxing (§3.1 of the spec) and the mutex staleness window (§3.5) both need a clock the tests can move. `time()` called directly inside `JobRunner` would force `sleep()` in tests.

**Files:**
- Create: `src/Jobs/Clock.php`, `src/Jobs/SystemClock.php`
- Create: `tests/Support/FrozenClock.php`, `tests/Unit/Jobs/ClockTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Jobs\Clock;
use Tclp\WpMarkdownForAgents\Jobs\SystemClock;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\SystemClock
 */
class ClockTest extends TestCase {

    public function test_system_clock_reports_wall_and_monotonic_time(): void {
        $clock = new SystemClock();

        $this->assertInstanceOf( Clock::class, $clock );
        $this->assertGreaterThan( 1_700_000_000, $clock->now() );
        $this->assertGreaterThan( 0.0, $clock->monotonic() );
    }

    public function test_frozen_clock_advances_only_when_told(): void {
        $clock = new FrozenClock( 1000 );

        $this->assertSame( 1000, $clock->now() );
        $this->assertSame( 1000, $clock->now() );

        $clock->advance( 45 );

        $this->assertSame( 1045, $clock->now() );
        $this->assertSame( 1045.0, $clock->monotonic() );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/ClockTest.php`
Expected: FAIL — `Class "Tclp\WpMarkdownForAgents\Jobs\SystemClock" not found`.

- [ ] **Step 3: Implement**

`src/Jobs/Clock.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Time seam for the job queue.
 *
 * Tick time-boxing and the tick-mutex staleness window are both time-driven,
 * and both need to be testable without sleeping, so every read of the clock
 * inside src/Jobs/ goes through this interface.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
interface Clock {

	/**
	 * Current Unix timestamp in whole seconds — for stored timestamps.
	 *
	 * @since  1.7.0
	 */
	public function now(): int;

	/**
	 * Fractional seconds for measuring elapsed time within one request.
	 *
	 * @since  1.7.0
	 */
	public function monotonic(): float;
}
```

`src/Jobs/SystemClock.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Production Clock: plain PHP time functions.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class SystemClock implements Clock {

	/** @since 1.7.0 */
	public function now(): int {
		return time();
	}

	/** @since 1.7.0 */
	public function monotonic(): float {
		return microtime( true );
	}
}
```

`tests/Support/FrozenClock.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Support;

use Tclp\WpMarkdownForAgents\Jobs\Clock;

/**
 * Test double: time only moves when a test moves it.
 */
final class FrozenClock implements Clock {

    public function __construct(private int $now = 1_700_000_000) {}

    public function now(): int {
        return $this->now;
    }

    public function monotonic(): float {
        return (float) $this->now;
    }

    public function advance(int $seconds): void {
        $this->now += $seconds;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Jobs/ClockTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/Clock.php src/Jobs/SystemClock.php tests/Support/FrozenClock.php tests/Unit/Jobs/ClockTest.php
git commit -m "feat: add Clock seam for the job queue"
```

---

## Task 3: `Stage` interface and `PostTypeStage`

Replaces `Generator::generate_batch()`'s `'offset' => $offset` `WP_Query` (`Generator.php:186-243`) with an explicit id-cursor query. Two behaviour changes beyond pagination: the stage returns a **`skipped`** count (today's loop distinguishes ineligible-skip from write-failure via `is_eligible()` at `Generator.php:220` and then throws the number away), and `total` moves out of the batch return into `count_total()`.

**Files:**
- Create: `src/Jobs/Stage.php`, `src/Jobs/PostTypeStage.php`
- Create: `tests/Unit/Jobs/PostTypeStageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Jobs\PostTypeStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\PostTypeStage
 */
class PostTypeStageTest extends TestCase {

    private \wpdb $wpdb;

    /** @var Generator&MockObject */
    private Generator $generator;

    /** @var array<string, mixed> */
    private array $options;

    protected function setUp(): void {
        $this->wpdb      = new \wpdb();
        $this->generator = $this->createMock( Generator::class );
        $this->options   = array_merge( Options::get_defaults(), [ 'post_types' => [ 'post' ] ] );

        $GLOBALS['_mock_post_objects'] = [];
        $GLOBALS['_mock_post_counts']  = [ 'post' => [ 'publish' => 3 ] ];
        $GLOBALS['_mock_post_meta']    = [];
    }

    /** Register a publishable mock post so get_post() returns a WP_Post. */
    private function given_post( int $id ): \WP_Post {
        $post              = new \WP_Post();
        $post->ID          = $id;
        $post->post_type   = 'post';
        $post->post_status = 'publish';
        $post->post_name   = 'post-' . $id;
        $post->post_title  = 'Post ' . $id;

        $GLOBALS['_mock_post_objects'][ $id ] = $post;

        return $post;
    }

    private function stage(): PostTypeStage {
        return new PostTypeStage( $this->wpdb, $this->generator, $this->options, 'post' );
    }

    public function test_count_total_uses_published_count(): void {
        $this->assertSame( 3, $this->stage()->count_total() );
    }

    public function test_count_total_is_zero_for_unknown_post_type(): void {
        $stage = new PostTypeStage( $this->wpdb, $this->generator, $this->options, 'nope' );

        $this->assertSame( 0, $stage->count_total() );
    }

    public function test_batch_query_is_cursor_paginated_with_no_offset(): void {
        $this->wpdb->mock_get_col_queue = [ [ '4', '5' ] ];
        $this->given_post( 4 );
        $this->given_post( 5 );
        $this->generator->method( 'generate_post' )->willReturn( true );

        $this->stage()->process_batch( 3, 2 );

        $sql = $this->wpdb->queries[0]['query'];

        $this->assertStringContainsString( 'ID > %d', $sql );
        $this->assertStringContainsString( 'ORDER BY ID ASC', $sql );
        $this->assertStringContainsString( 'LIMIT %d', $sql );
        $this->assertStringNotContainsStringIgnoringCase( 'OFFSET', $sql );
        $this->assertStringNotContainsStringIgnoringCase( 'SQL_CALC_FOUND_ROWS', $sql );
        $this->assertSame( [ 'post', 3, 2 ], $this->wpdb->queries[0]['args'] );
    }

    public function test_full_page_reports_not_done_and_advances_cursor(): void {
        $this->wpdb->mock_get_col_queue = [ [ '4', '5' ] ];
        $this->given_post( 4 );
        $this->given_post( 5 );
        $this->generator->method( 'generate_post' )->willReturn( true );

        $result = $this->stage()->process_batch( 0, 2 );

        $this->assertSame( 2, $result['processed'] );
        $this->assertFalse( $result['done'] );
        $this->assertSame( 5, $result['next_cursor'] );
    }

    public function test_short_page_reports_done(): void {
        $this->wpdb->mock_get_col_queue = [ [ '9' ] ];
        $this->given_post( 9 );
        $this->generator->method( 'generate_post' )->willReturn( true );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 9, $result['next_cursor'] );
    }

    public function test_empty_page_is_done_and_leaves_cursor_untouched(): void {
        $this->wpdb->mock_get_col_queue = [ [] ];

        $result = $this->stage()->process_batch( 77, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 77, $result['next_cursor'] );
        $this->assertSame( 0, $result['processed'] );
    }

    /** The Problem #5 regression: a full page of skips must not report done. */
    public function test_all_skipped_full_page_still_reports_not_done(): void {
        $this->wpdb->mock_get_col_queue = [ [ '1', '2' ] ];
        $excluded                       = $this->given_post( 1 );
        $this->given_post( 2 );

        // Ineligible: excluded via meta, so generate_post() returns false by design.
        $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';
        $GLOBALS['_mock_post_meta'][2]['_markdown_for_agents_excluded'] = '1';
        $this->generator->method( 'generate_post' )->willReturn( false );

        $result = $this->stage()->process_batch( 0, 2 );

        $this->assertFalse( $result['done'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( 2, $result['skipped'] );
        $this->assertSame( [], $result['errors'] );
        $this->assertSame( 2, $result['next_cursor'] );
        $this->assertNotSame( $excluded, null );
    }

    public function test_eligible_post_that_fails_to_write_is_an_error_not_a_skip(): void {
        $this->wpdb->mock_get_col_queue = [ [ '1' ] ];
        $this->given_post( 1 );
        $this->generator->method( 'generate_post' )->willReturn( false );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 0, $result['skipped'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 1, $result['errors'][0]['post_id'] );
        $this->assertStringContainsString( 'Failed to write', $result['errors'][0]['message'] );
    }

    public function test_missing_post_object_is_an_error_and_does_not_halt_the_batch(): void {
        $this->wpdb->mock_get_col_queue = [ [ '1', '2' ] ];
        $this->given_post( 2 );   // 1 is deliberately absent
        $this->generator->method( 'generate_post' )->willReturn( true );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 1, $result['processed'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 1, $result['errors'][0]['post_id'] );
    }

    public function test_thrown_error_is_collected_and_the_batch_continues(): void {
        $this->wpdb->mock_get_col_queue = [ [ '1', '2' ] ];
        $this->given_post( 1 );
        $this->given_post( 2 );
        $this->generator->method( 'generate_post' )
            ->willReturnCallback(
                static function ( \WP_Post $post ): bool {
                    if ( 1 === $post->ID ) {
                        throw new \RuntimeException( 'boom' );
                    }
                    return true;
                }
            );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( 'boom', $result['errors'][0]['message'] );
        $this->assertSame( 2, $result['next_cursor'] );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/PostTypeStageTest.php`
Expected: FAIL — `Class "…Jobs\PostTypeStage" not found`.

If instead it fails on `Undefined array key "_mock_post_meta"` inside `get_post_meta`, check the mock's global name with `grep -n "function get_post_meta" -A 6 tests/mocks/wordpress-mocks.php` and use whatever key it reads.

- [ ] **Step 3: Write the `Stage` interface**

`src/Jobs/Stage.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * One unit of bulk generation work that can be walked in cursor-paginated
 * batches across many requests.
 *
 * Two invariants every implementation must hold:
 *
 * 1. `done` is derived ONLY from rows returned this batch being fewer than
 *    $limit. Never from processed/skipped/error counts — that was the live
 *    bug this queue replaces (see the design spec, Problem #5).
 * 2. No batch query asks for a row count. Totals come from count_total(),
 *    called once when the stage becomes current, so batch cost stays flat
 *    however far into the run the cursor is.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
interface Stage {

	/**
	 * Total items this stage will walk.
	 *
	 * Called once, on stage entry; the result is stored in the job record and
	 * never recomputed — a cursor-filtered count shrinks as the cursor moves.
	 *
	 * @since  1.7.0
	 */
	public function count_total(): int;

	/**
	 * Process one batch of items after $cursor.
	 *
	 * @since  1.7.0
	 * @param  int $cursor Resume key; 0 for the first batch of the stage.
	 * @param  int $limit  Maximum items to process in this batch.
	 * @return array{
	 *     processed: int,
	 *     skipped: int,
	 *     errors: list<array{post_id?: int, term_id?: int, message: string}>,
	 *     next_cursor: int,
	 *     done: bool
	 * }
	 */
	public function process_batch( int $cursor, int $limit ): array;
}
```

- [ ] **Step 4: Write `PostTypeStage`**

`src/Jobs/PostTypeStage.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Walks every published post of one post type, in ascending ID order.
 *
 * Pagination is an explicit ID cursor rather than WP_Query's `offset`, whose
 * scan cost grows linearly with how far into the run you are. The ID query is
 * deliberately plain SQL: injecting `AND ID > n` into WP_Query through a
 * scoped posts_where filter is both harder to reason about and untestable
 * against the mock WP_Query, which never applies that filter.
 *
 * Trade-off, accepted: this collection query does not pass through
 * pre_get_posts. Everything after ID collection is unchanged — get_post()
 * then Generator::generate_post(), with all their hooks intact.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class PostTypeStage implements Stage {

	/**
	 * @since  1.7.0
	 * @param  \wpdb                $wpdb      WordPress database handle.
	 * @param  Generator            $generator Writes each post's Markdown.
	 * @param  array<string, mixed> $options   Plugin options, for eligibility checks.
	 * @param  string               $post_type Post type slug this stage walks.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Generator $generator,
		private readonly array $options,
		private readonly string $post_type,
	) {}

	/**
	 * Published count for this post type — one cached call, no table scan.
	 *
	 * @since  1.7.0
	 */
	public function count_total(): int {
		$counts = wp_count_posts( $this->post_type );

		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * @since  1.7.0
	 * @param  int $cursor Highest post ID already processed in this stage.
	 * @param  int $limit  Maximum posts to process.
	 * @return array{processed: int, skipped: int, errors: list<array{post_id?: int, message: string}>, next_cursor: int, done: bool}
	 */
	public function process_batch( int $cursor, int $limit ): array {
		$processed = 0;
		$skipped   = 0;
		$errors    = array();

		if ( $limit <= 0 ) {
			return array(
				'processed'   => 0,
				'skipped'     => 0,
				'errors'      => array(),
				'next_cursor' => $cursor,
				'done'        => true,
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberate ID-cursor pagination; see the class docblock.
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT ID FROM {$this->wpdb->posts}
				 WHERE post_type = %s AND post_status = 'publish' AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				$this->post_type,
				$cursor,
				$limit
			)
		);
		// phpcs:enable

		$ids         = array_map( 'intval', (array) $ids );
		$next_cursor = $cursor;

		foreach ( $ids as $post_id ) {
			$next_cursor = $post_id;
			$post        = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				$errors[] = array(
					'post_id' => $post_id,
					'message' => 'Post object not found; may have been deleted concurrently.',
				);
				continue;
			}

			try {
				if ( $this->generator->generate_post( $post ) ) {
					++$processed;
				} elseif ( ExportPolicy::is_eligible( $post, $this->options ) ) {
					// Eligible but nothing written means the filesystem write failed.
					$errors[] = array(
						'post_id' => $post_id,
						'message' => 'Failed to write Markdown file to disk; check export directory permissions.',
					);
				} else {
					// Ineligible: an intentional skip, counted so the UI can say so.
					++$skipped;
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'post_id' => $post_id,
					'message' => $e->getMessage(),
				);
			}

			clean_post_cache( $post );
		}

		return array(
			'processed'   => $processed,
			'skipped'     => $skipped,
			'errors'      => $errors,
			'next_cursor' => $next_cursor,
			'done'        => count( $ids ) < $limit,
		);
	}
}
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Jobs/PostTypeStageTest.php`
Expected: PASS (every test in the file).

- [ ] **Step 6: Commit**

```bash
git add src/Jobs/Stage.php src/Jobs/PostTypeStage.php tests/Unit/Jobs/PostTypeStageTest.php
git commit -m "feat: add Stage interface and cursor-paginated PostTypeStage

Replaces offset pagination with an explicit ID cursor, moves the total
out of the batch return into count_total(), and reports intentional
skips separately from write failures."
```

---

## Task 4: `TaxonomyStage`

Replaces `TaxonomyArchiveGenerator::generate_batch()` (`:188-224`), which calls `get_all_public_terms()` (`:236-249`) to load **every** term of **every** public taxonomy into memory before `array_slice()`-ing a batch out of it.

The cursor is `term_taxonomy_id`, not `term_id`. Three reasons, all load-bearing: terms today arrive grouped per taxonomy (one `get_terms()` call each, concatenated) so `term_id` is not globally ascending; `get_terms()` defaults to `orderby => name`; and a legacy shared term belongs to more than one taxonomy, so `term_id` is not unique per archive file. `term_taxonomy_id` is unique per (term, taxonomy) pair and monotonic.

**Files:**
- Create: `src/Jobs/TaxonomyStage.php`
- Create: `tests/Unit/Jobs/TaxonomyStageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Jobs\TaxonomyStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\TaxonomyStage
 */
class TaxonomyStageTest extends TestCase {

    private \wpdb $wpdb;

    /** @var TaxonomyArchiveGenerator&MockObject */
    private TaxonomyArchiveGenerator $taxonomy_generator;

    protected function setUp(): void {
        $this->wpdb               = new \wpdb();
        $this->taxonomy_generator = $this->createMock( TaxonomyArchiveGenerator::class );

        $GLOBALS['_mock_taxonomies']   = [ 'category', 'post_tag' ];
        $GLOBALS['_mock_terms_by_id']  = [];
    }

    /**
     * @param array<int, array{term_taxonomy_id: int, term_id: int, taxonomy: string}> $rows
     */
    private function given_rows( array $rows ): void {
        $objects = [];

        foreach ( $rows as $row ) {
            $objects[] = (object) $row;

            $term            = new \WP_Term();
            $term->term_id   = $row['term_id'];
            $term->taxonomy  = $row['taxonomy'];
            $term->slug      = 'term-' . $row['term_id'];
            $term->name      = 'Term ' . $row['term_id'];

            $GLOBALS['_mock_terms_by_id'][ $row['term_id'] ] = $term;
        }

        $this->wpdb->mock_get_results_queue = [ $objects ];
    }

    private function stage(): TaxonomyStage {
        return new TaxonomyStage( $this->wpdb, $this->taxonomy_generator );
    }

    public function test_count_total_runs_one_count_query(): void {
        $this->wpdb->mock_get_var = '812';

        $this->assertSame( 812, $this->stage()->count_total() );
        $this->assertStringContainsString( 'COUNT(', $this->wpdb->queries[0]['query'] );
    }

    public function test_batch_query_is_cursor_paginated_over_term_taxonomy_id(): void {
        $this->given_rows( [ [ 'term_taxonomy_id' => 5, 'term_id' => 5, 'taxonomy' => 'category' ] ] );
        $this->taxonomy_generator->method( 'generate_term' )->willReturn( true );

        $this->stage()->process_batch( 4, 50 );

        $sql = $this->wpdb->queries[0]['query'];

        $this->assertStringContainsString( 'term_taxonomy_id > %d', $sql );
        $this->assertStringContainsString( 'ORDER BY tt.term_taxonomy_id ASC', $sql );
        $this->assertStringNotContainsStringIgnoringCase( 'OFFSET', $sql );
        $this->assertSame( [ 'category', 'post_tag', 4, 50 ], $this->wpdb->queries[0]['args'] );
    }

    public function test_terms_from_multiple_taxonomies_interleave_by_term_taxonomy_id(): void {
        $this->given_rows(
            [
                [ 'term_taxonomy_id' => 1, 'term_id' => 40, 'taxonomy' => 'category' ],
                [ 'term_taxonomy_id' => 2, 'term_id' => 7,  'taxonomy' => 'post_tag' ],
                [ 'term_taxonomy_id' => 3, 'term_id' => 41, 'taxonomy' => 'category' ],
            ]
        );

        $seen = [];
        $this->taxonomy_generator->method( 'generate_term' )
            ->willReturnCallback(
                static function ( \WP_Term $term ) use ( &$seen ): bool {
                    $seen[] = $term->term_id . ':' . $term->taxonomy;
                    return true;
                }
            );

        // Limit 4 against 3 rows: a short page, so this test asserts ordering
        // without also tripping the full-page boundary that
        // test_full_page_reports_not_done owns.
        $result = $this->stage()->process_batch( 0, 4 );

        // A term_id cursor would have dropped post_tag 7 after category 40.
        $this->assertSame( [ '40:category', '7:post_tag', '41:category' ], $seen );
        $this->assertSame( 3, $result['processed'] );
        $this->assertSame( 3, $result['next_cursor'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_shared_term_in_two_taxonomies_generates_both_archives(): void {
        $shared           = new \WP_Term();
        $shared->term_id  = 9;
        $shared->taxonomy = 'category';
        $shared->slug     = 'shared';

        $this->wpdb->mock_get_results_queue = [
            [
                (object) [ 'term_taxonomy_id' => 10, 'term_id' => 9, 'taxonomy' => 'category' ],
                (object) [ 'term_taxonomy_id' => 11, 'term_id' => 9, 'taxonomy' => 'post_tag' ],
            ],
        ];
        $GLOBALS['_mock_terms_by_id'][9] = $shared;

        $this->taxonomy_generator->expects( $this->exactly( 2 ) )
            ->method( 'generate_term' )
            ->willReturn( true );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 2, $result['processed'] );
        $this->assertSame( 11, $result['next_cursor'] );
    }

    public function test_full_page_reports_not_done(): void {
        $this->given_rows(
            [
                [ 'term_taxonomy_id' => 1, 'term_id' => 1, 'taxonomy' => 'category' ],
                [ 'term_taxonomy_id' => 2, 'term_id' => 2, 'taxonomy' => 'category' ],
            ]
        );
        $this->taxonomy_generator->method( 'generate_term' )->willReturn( true );

        $this->assertFalse( $this->stage()->process_batch( 0, 2 )['done'] );
    }

    public function test_write_failure_is_an_error_with_the_term_id(): void {
        $this->given_rows( [ [ 'term_taxonomy_id' => 1, 'term_id' => 3, 'taxonomy' => 'category' ] ] );
        $this->taxonomy_generator->method( 'generate_term' )->willReturn( false );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 3, $result['errors'][0]['term_id'] );
        $this->assertSame( 0, $result['skipped'] );
    }

    public function test_thrown_error_is_collected_and_the_batch_continues(): void {
        $this->given_rows(
            [
                [ 'term_taxonomy_id' => 1, 'term_id' => 1, 'taxonomy' => 'category' ],
                [ 'term_taxonomy_id' => 2, 'term_id' => 2, 'taxonomy' => 'category' ],
            ]
        );
        $this->taxonomy_generator->method( 'generate_term' )
            ->willReturnCallback(
                static function ( \WP_Term $term ): bool {
                    if ( 1 === $term->term_id ) {
                        throw new \RuntimeException( 'term boom' );
                    }
                    return true;
                }
            );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( 'term boom', $result['errors'][0]['message'] );
    }

    public function test_missing_term_object_is_an_error(): void {
        $this->wpdb->mock_get_results_queue = [
            [ (object) [ 'term_taxonomy_id' => 1, 'term_id' => 404, 'taxonomy' => 'category' ] ],
        ];

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 404, $result['errors'][0]['term_id'] );
    }

    public function test_no_public_taxonomies_short_circuits_to_done_without_querying(): void {
        $GLOBALS['_mock_taxonomies'] = [];

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 0, $this->stage()->count_total() );
        $this->assertSame( [], $this->wpdb->queries );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/TaxonomyStageTest.php`
Expected: FAIL — `Class "…Jobs\TaxonomyStage" not found`.

Check first how the mock `get_taxonomies()` (`tests/mocks/wordpress-mocks.php:1150`) reads its global; use that global's real name in the test's `setUp()` instead of `_mock_taxonomies` if it differs.

- [ ] **Step 3: Implement**

`src/Jobs/TaxonomyStage.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;

/**
 * Walks every term of every public taxonomy, one archive file per
 * (term, taxonomy) pair.
 *
 * The cursor is `term_taxonomy_id`, not `term_id`, because:
 *
 * - the previous implementation called get_terms() once per taxonomy and
 *   concatenated the results, so term IDs were grouped per taxonomy rather
 *   than globally ascending — a term_id cursor would silently skip every
 *   later-taxonomy term with a lower ID;
 * - get_terms() orders by name by default, not by ID;
 * - a legacy shared term belongs to more than one taxonomy, so term_id is
 *   not unique per archive file, while term_taxonomy_id is.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class TaxonomyStage implements Stage {

	/**
	 * @since  1.7.0
	 * @param  \wpdb                    $wpdb               WordPress database handle.
	 * @param  TaxonomyArchiveGenerator $taxonomy_generator Writes each term archive.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly TaxonomyArchiveGenerator $taxonomy_generator,
	) {}

	/**
	 * @since  1.7.0
	 */
	public function count_total(): int {
		$taxonomies = $this->public_taxonomies();

		if ( empty( $taxonomies ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counted once per stage; see the class docblock.
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->wpdb->term_taxonomy} tt WHERE tt.taxonomy IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
				...$taxonomies
			)
		);
		// phpcs:enable
	}

	/**
	 * @since  1.7.0
	 * @param  int $cursor Highest term_taxonomy_id already processed.
	 * @param  int $limit  Maximum term archives to write.
	 * @return array{processed: int, skipped: int, errors: list<array{term_id?: int, message: string}>, next_cursor: int, done: bool}
	 */
	public function process_batch( int $cursor, int $limit ): array {
		$taxonomies = $this->public_taxonomies();

		if ( empty( $taxonomies ) || $limit <= 0 ) {
			return array(
				'processed'   => 0,
				'skipped'     => 0,
				'errors'      => array(),
				'next_cursor' => $cursor,
				'done'        => true,
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberate cursor pagination; see the class docblock.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy
				 FROM {$this->wpdb->term_taxonomy} tt
				 WHERE tt.taxonomy IN ($placeholders) AND tt.term_taxonomy_id > %d
				 ORDER BY tt.term_taxonomy_id ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
				...array_merge( $taxonomies, array( $cursor, $limit ) )
			)
		);
		// phpcs:enable

		$rows        = (array) $rows;
		$processed   = 0;
		$errors      = array();
		$next_cursor = $cursor;

		foreach ( $rows as $row ) {
			$next_cursor = (int) $row->term_taxonomy_id;
			$term_id     = (int) $row->term_id;
			$term        = get_term( $term_id, (string) $row->taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				$errors[] = array(
					'term_id' => $term_id,
					'message' => 'Term not found; may have been deleted concurrently.',
				);
				continue;
			}

			try {
				if ( $this->taxonomy_generator->generate_term( $term ) ) {
					++$processed;
				} else {
					// generate_term() has no skip path, so false means the write failed.
					$errors[] = array(
						'term_id' => $term_id,
						'message' => 'Failed to write Markdown archive to disk; check export directory permissions.',
					);
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'term_id' => $term_id,
					'message' => $e->getMessage(),
				);
			}
		}

		return array(
			'processed'   => $processed,
			'skipped'     => 0,
			'errors'      => $errors,
			'next_cursor' => $next_cursor,
			'done'        => count( $rows ) < $limit,
		);
	}

	/**
	 * The same taxonomy set TaxonomyArchiveGenerator::generate_all() uses.
	 *
	 * @since  1.7.0
	 * @return string[]
	 */
	private function public_taxonomies(): array {
		return array_values( array_keys( get_taxonomies( array( 'public' => true ) ) ) );
	}
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Jobs/TaxonomyStageTest.php`
Expected: PASS (every test in the file).

If `get_taxonomies()`'s mock returns a name-keyed map rather than a list, `array_keys()` already handles it; if it returns a plain list of slugs, swap `array_keys( ... )` for `array_values( (array) get_taxonomies( ... ) )` and keep the test as the arbiter.

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/TaxonomyStage.php tests/Unit/Jobs/TaxonomyStageTest.php
git commit -m "feat: add TaxonomyStage with term_taxonomy_id cursor pagination

Removes the whole-term-list-into-memory collection and paginates over
term_taxonomy_id, which is globally ascending and unique per
(term, taxonomy) pair — unlike term_id, which is neither."
```

---

## Task 5: `BundleStage`

The last stage of every job. Absorbs `Admin::maybe_rebuild_bundle()` (`Admin.php:276-292`) verbatim — including its two `WP_DEBUG`-guarded `error_log()` lines — so the zip/manifest rebuild runs as an ordinary cron tick instead of synchronously on the final AJAX batch's request.

**Files:**
- Create: `src/Jobs/BundleStage.php`
- Create: `tests/Unit/Jobs/BundleStageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Jobs\BundleStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\BundleStage
 */
class BundleStageTest extends TestCase {

    /** @var Generator&MockObject */
    private Generator $generator;

    /** @var BundleGenerator&MockObject */
    private BundleGenerator $bundle_generator;

    protected function setUp(): void {
        $this->generator        = $this->createMock( Generator::class );
        $this->bundle_generator = $this->createMock( BundleGenerator::class );
    }

    public function test_total_is_always_one(): void {
        $stage = new BundleStage( $this->generator, $this->bundle_generator );

        $this->assertSame( 1, $stage->count_total() );
    }

    public function test_rebuild_is_only_if_stale_and_reports_done(): void {
        $this->bundle_generator->method( 'is_stale' )->willReturn( true );
        $this->generator->expects( $this->once() )
            ->method( 'rebuild_bundle' )
            ->with( $this->bundle_generator, true )
            ->willReturn( [ 'status' => Generator::BUNDLE_BUILT, 'manifests_ok' => true ] );

        $result = ( new BundleStage( $this->generator, $this->bundle_generator ) )->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( [], $result['errors'] );
    }

    public function test_failed_rebuild_is_reported_as_an_error(): void {
        $this->generator->method( 'rebuild_bundle' )
            ->willReturn( [ 'status' => Generator::BUNDLE_FAILED, 'manifests_ok' => true ] );

        $result = ( new BundleStage( $this->generator, $this->bundle_generator ) )->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertStringContainsString( 'Bundle rebuild failed', $result['errors'][0]['message'] );
    }

    public function test_manifest_failure_is_reported_as_an_error(): void {
        $this->generator->method( 'rebuild_bundle' )
            ->willReturn( [ 'status' => Generator::BUNDLE_BUILT, 'manifests_ok' => false ] );

        $result = ( new BundleStage( $this->generator, $this->bundle_generator ) )->process_batch( 0, 50 );

        $this->assertCount( 1, $result['errors'] );
        $this->assertStringContainsString( 'Manifest write failed', $result['errors'][0]['message'] );
    }

    public function test_fresh_or_disabled_bundle_is_not_an_error(): void {
        $this->generator->method( 'rebuild_bundle' )
            ->willReturn( [ 'status' => Generator::BUNDLE_FRESH, 'manifests_ok' => true ] );

        $result = ( new BundleStage( $this->generator, $this->bundle_generator ) )->process_batch( 0, 50 );

        $this->assertSame( [], $result['errors'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_missing_bundle_generator_is_a_no_op_that_still_completes(): void {
        $this->generator->expects( $this->never() )->method( 'rebuild_bundle' );

        $result = ( new BundleStage( $this->generator, null ) )->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( [], $result['errors'] );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/BundleStageTest.php`
Expected: FAIL — `Class "…Jobs\BundleStage" not found`.

- [ ] **Step 3: Implement**

`src/Jobs/BundleStage.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Single-shot final stage: rebuild the manifests and the export zip.
 *
 * Previously this ran synchronously on the last AJAX batch's request
 * (Admin::maybe_rebuild_bundle()), which is where bulk generation most often
 * exhausted memory. As a stage it gets a cron tick to itself.
 *
 * only_if_stale is always true, preserving the existing behaviour that
 * clicking Generate twice with no content change in between does not re-zip
 * the whole tree.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class BundleStage implements Stage {

	/**
	 * @since  1.7.0
	 * @param  Generator            $generator        Writes manifests, then delegates the zip.
	 * @param  BundleGenerator|null $bundle_generator Null when bundling is not wired up.
	 */
	public function __construct(
		private readonly Generator $generator,
		private readonly ?BundleGenerator $bundle_generator,
	) {}

	/** @since 1.7.0 */
	public function count_total(): int {
		return 1;
	}

	/**
	 * @since  1.7.0
	 * @param  int $cursor Unused — this stage has exactly one item.
	 * @param  int $limit  Unused.
	 * @return array{processed: int, skipped: int, errors: list<array{message: string}>, next_cursor: int, done: bool}
	 */
	public function process_batch( int $cursor, int $limit ): array {
		$errors = array();

		if ( null !== $this->bundle_generator ) {
			$result = $this->generator->rebuild_bundle( $this->bundle_generator, true );

			if ( ! $result['manifests_ok'] ) {
				$errors[] = array( 'message' => 'Manifest write failed during bundle rebuild; bundle may be stale.' );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, guarded by WP_DEBUG.
					error_log( 'WP Markdown for Agents: manifest write failed during bundle rebuild; bundle may be stale.' );
				}
			}

			if ( Generator::BUNDLE_FAILED === $result['status'] ) {
				$errors[] = array( 'message' => 'Bundle rebuild failed after bulk generation.' );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, guarded by WP_DEBUG.
					error_log( 'WP Markdown for Agents: bundle rebuild failed after bulk generation.' );
				}
			}
		}

		return array(
			'processed'   => 1,
			'skipped'     => 0,
			'errors'      => $errors,
			'next_cursor' => 1,
			'done'        => true,
		);
	}
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Jobs/BundleStageTest.php`
Expected: PASS (every test in the file).

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/BundleStage.php tests/Unit/Jobs/BundleStageTest.php
git commit -m "feat: add BundleStage so the zip rebuild runs as a cron tick"
```

---

## Task 6: `StageFactory` — scope to stage list, descriptor to `Stage`

Two jobs in one small class: turn a scope string from the browser into the stage-descriptor list stored in the job record, and turn one stored descriptor back into a live `Stage` when a tick needs it. Descriptors are plain arrays because they round-trip through an option.

Scope strings: `all`, `post_type:{slug}`, `taxonomy`. Every scope ends in exactly one `bundle` descriptor — matching today's behaviour, where *every* completed AJAX batch run (single post type or taxonomy) already triggers a rebuild.

**Wiring constraint carried over from Task 3's review:** the `$options` array `StageFactory` passes to `PostTypeStage` **must** be the same array `Generator` was constructed with. `PostTypeStage` distinguishes an intentional skip from a write failure by re-deriving eligibility via `ExportPolicy::is_eligible( $post, $this->options )`, because `Generator::generate_post()` collapses both reasons into `false`. A divergent snapshot would misreport skips as write failures. `StageFactory` therefore takes `$options` once and hands the *same* value to every stage it builds — do not filter, merge, or re-read options inside the factory. (The structural fix — `generate_post()` returning a reason rather than a bool — touches CLI, admin single-regen and `save_post`, and is deliberately out of this plan's scope.)

**Files:**
- Create: `src/Jobs/StageFactory.php`
- Create: `tests/Unit/Jobs/StageFactoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Jobs\BundleStage;
use Tclp\WpMarkdownForAgents\Jobs\PostTypeStage;
use Tclp\WpMarkdownForAgents\Jobs\StageFactory;
use Tclp\WpMarkdownForAgents\Jobs\TaxonomyStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\StageFactory
 */
class StageFactoryTest extends TestCase {

    private StageFactory $factory;

    protected function setUp(): void {
        $options = array_merge( Options::get_defaults(), [ 'post_types' => [ 'post', 'page' ] ] );

        $this->factory = new StageFactory(
            new \wpdb(),
            $options,
            $this->createMock( Generator::class ),
            $this->createMock( TaxonomyArchiveGenerator::class ),
            $this->createMock( BundleGenerator::class )
        );
    }

    /** @return list<string> */
    private function shape( array $stages ): array {
        return array_map(
            static fn( array $stage ): string => $stage['type'] . ( isset( $stage['slug'] ) ? ':' . $stage['slug'] : '' ),
            $stages
        );
    }

    public function test_all_scope_is_every_post_type_then_taxonomy_then_bundle(): void {
        $stages = $this->factory->build_stage_list( 'all' );

        $this->assertSame( [ 'post_type:post', 'post_type:page', 'taxonomy', 'bundle' ], $this->shape( $stages ) );
    }

    public function test_single_post_type_scope_is_that_type_then_bundle(): void {
        $stages = $this->factory->build_stage_list( 'post_type:page' );

        $this->assertSame( [ 'post_type:page', 'bundle' ], $this->shape( $stages ) );
    }

    public function test_taxonomy_scope_is_taxonomy_then_bundle(): void {
        $this->assertSame( [ 'taxonomy', 'bundle' ], $this->shape( $this->factory->build_stage_list( 'taxonomy' ) ) );
    }

    public function test_every_scope_ends_in_exactly_one_bundle_stage(): void {
        foreach ( [ 'all', 'post_type:post', 'taxonomy' ] as $scope ) {
            $types = array_column( $this->factory->build_stage_list( $scope ), 'type' );

            $this->assertSame( 1, array_count_values( $types )['bundle'], $scope );
            $this->assertSame( 'bundle', end( $types ), $scope );
        }
    }

    public function test_unknown_scope_and_disabled_post_type_build_nothing(): void {
        $this->assertSame( [], $this->factory->build_stage_list( 'nonsense' ) );
        $this->assertSame( [], $this->factory->build_stage_list( 'post_type:attachment' ) );
        $this->assertSame( [], $this->factory->build_stage_list( '' ) );
    }

    public function test_descriptors_start_with_zeroed_counters_and_unknown_total(): void {
        $stage = $this->factory->build_stage_list( 'taxonomy' )[0];

        $this->assertNull( $stage['total'] );
        $this->assertSame( 0, $stage['processed'] );
        $this->assertSame( 0, $stage['skipped'] );
        $this->assertSame( 0, $stage['error_count'] );
        $this->assertSame( 'pending', $stage['state'] );
    }

    public function test_make_returns_the_matching_stage_implementation(): void {
        $this->assertInstanceOf( PostTypeStage::class, $this->factory->make( [ 'type' => 'post_type', 'slug' => 'post' ] ) );
        $this->assertInstanceOf( TaxonomyStage::class, $this->factory->make( [ 'type' => 'taxonomy' ] ) );
        $this->assertInstanceOf( BundleStage::class, $this->factory->make( [ 'type' => 'bundle' ] ) );
    }

    public function test_make_returns_null_for_an_unusable_descriptor(): void {
        $this->assertNull( $this->factory->make( [ 'type' => 'wat' ] ) );
        $this->assertNull( $this->factory->make( [ 'type' => 'post_type' ] ) );
        $this->assertNull( $this->factory->make( [ 'type' => 'post_type', 'slug' => 'attachment' ] ) );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/StageFactoryTest.php`
Expected: FAIL — `Class "…Jobs\StageFactory" not found`.

- [ ] **Step 3: Implement**

`src/Jobs/StageFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;

/**
 * Builds the stage list a job walks, and rehydrates one stage at a time.
 *
 * Descriptors are plain arrays because they are stored in the job option and
 * read back one tick at a time; nothing serialisable-unfriendly may go in them.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class StageFactory {
	// Not final: JobRunner's tick tests double this so they can return stage
	// doubles instead of stages that would hit the database. PHPUnit 9.6
	// cannot mock a final class.

	/**
	 * @since  1.7.0
	 * @param  \wpdb                    $wpdb               Database handle for cursor queries.
	 * @param  array<string, mixed>     $options            Plugin options.
	 * @param  Generator                $generator          Post Markdown writer.
	 * @param  TaxonomyArchiveGenerator $taxonomy_generator Term archive writer.
	 * @param  BundleGenerator|null     $bundle_generator   Optional bundle builder.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly array $options,
		private readonly Generator $generator,
		private readonly TaxonomyArchiveGenerator $taxonomy_generator,
		private readonly ?BundleGenerator $bundle_generator = null,
	) {}

	/**
	 * Translate a scope from the admin UI into an ordered stage list.
	 *
	 * Accepted scopes: `all`, `post_type:{slug}`, `taxonomy`. Anything else —
	 * including a post type that is not enabled for export — builds nothing,
	 * so the caller can reject the request.
	 *
	 * @since  1.7.0
	 * @param  string $scope Scope string.
	 * @return list<array{type: string, slug?: string, total: int|null, processed: int, skipped: int, error_count: int, state: string}>
	 */
	public function build_stage_list( string $scope ): array {
		$enabled = ExportPolicy::enabled_post_types( $this->options );
		$stages  = array();

		if ( 'all' === $scope ) {
			foreach ( $enabled as $post_type ) {
				$stages[] = $this->descriptor( 'post_type', (string) $post_type );
			}
			$stages[] = $this->descriptor( 'taxonomy' );
		} elseif ( 'taxonomy' === $scope ) {
			$stages[] = $this->descriptor( 'taxonomy' );
		} elseif ( str_starts_with( $scope, 'post_type:' ) ) {
			$slug = substr( $scope, strlen( 'post_type:' ) );

			if ( '' === $slug || ! in_array( $slug, $enabled, true ) ) {
				return array();
			}

			$stages[] = $this->descriptor( 'post_type', $slug );
		}

		if ( empty( $stages ) ) {
			return array();
		}

		// Every scope ends in exactly one bundle rebuild, never more.
		$stages[] = $this->descriptor( 'bundle' );

		return $stages;
	}

	/**
	 * Rehydrate one stored descriptor into a runnable Stage.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed> $descriptor Stored stage descriptor.
	 * @return Stage|null Null when the descriptor cannot be run (unknown type,
	 *                    or a post type no longer enabled for export).
	 */
	public function make( array $descriptor ): ?Stage {
		$type = (string) ( $descriptor['type'] ?? '' );

		if ( 'taxonomy' === $type ) {
			return new TaxonomyStage( $this->wpdb, $this->taxonomy_generator );
		}

		if ( 'bundle' === $type ) {
			return new BundleStage( $this->generator, $this->bundle_generator );
		}

		if ( 'post_type' === $type ) {
			$slug = (string) ( $descriptor['slug'] ?? '' );

			if ( '' === $slug || ! in_array( $slug, ExportPolicy::enabled_post_types( $this->options ), true ) ) {
				return null;
			}

			return new PostTypeStage( $this->wpdb, $this->generator, $this->options, $slug );
		}

		return null;
	}

	/**
	 * @since  1.7.0
	 * @return array{type: string, slug?: string, total: int|null, processed: int, skipped: int, error_count: int, state: string}
	 */
	private function descriptor( string $type, string $slug = '' ): array {
		$descriptor = array(
			'type'        => $type,
			'total'       => null,
			'processed'   => 0,
			'skipped'     => 0,
			'error_count' => 0,
			'state'       => 'pending',
		);

		if ( '' !== $slug ) {
			$descriptor['slug'] = $slug;
		}

		return $descriptor;
	}
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Jobs/StageFactoryTest.php`
Expected: PASS (every test in the file).

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/StageFactory.php tests/Unit/Jobs/StageFactoryTest.php
git commit -m "feat: add StageFactory mapping scopes to stage lists"
```

---

## Task 7: `NeedsRegenTracker`

`Admin::mark_post_type_regenerated()` (`Admin.php:211-228`) is `private`, so `JobRunner` cannot call it. Rather than widening `Admin`'s internals to a `Jobs\` class, lift the transient logic into its own class and have `Admin` delegate. Pure refactor — behaviour must not change.

`SettingsPage.php:317-321` also writes this transient when settings change; leave that alone for now (it is a flag-many, not a clear-one, and is out of scope).

**Files:**
- Create: `src/Core/NeedsRegenTracker.php`
- Create: `tests/Unit/Core/NeedsRegenTrackerTest.php`
- Modify: `src/Admin/Admin.php:211-228` (delete the private method, delegate)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker;

/**
 * @covers \Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker
 */
class NeedsRegenTrackerTest extends TestCase {

    private NeedsRegenTracker $tracker;

    protected function setUp(): void {
        $GLOBALS['_mock_transients'] = [];
        $this->tracker               = new NeedsRegenTracker();
    }

    public function test_clearing_the_last_pending_type_deletes_the_transient(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'post' ], 0 );

        $this->tracker->clear( 'post' );

        $this->assertFalse( get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_clearing_one_of_several_types_keeps_the_rest(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'post', 'page' ], 0 );

        $this->tracker->clear( 'post' );

        $this->assertSame( [ 'page' ], get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_clearing_an_unlisted_type_leaves_the_transient_untouched(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'page' ], 0 );

        $this->tracker->clear( 'post' );

        $this->assertSame( [ 'page' ], get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_clearing_with_no_transient_is_a_no_op(): void {
        $this->tracker->clear( 'post' );

        $this->assertFalse( get_transient( 'markdown_for_agents_needs_regen' ) );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Core/NeedsRegenTrackerTest.php`
Expected: FAIL — `Class "…Admin\NeedsRegenTracker" not found`.

- [ ] **Step 3: Implement**

`src/Core/NeedsRegenTracker.php` — move the body of `Admin::mark_post_type_regenerated()` across unchanged:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

/**
 * Owns the `markdown_for_agents_needs_regen` transient that drives the
 * "settings changed, regenerate" admin notice.
 *
 * Extracted from Admin so JobRunner can clear a post type when the job
 * finishes regenerating it — the old call site was a private Admin method
 * reachable only from the retired AJAX handler.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Admin
 */
class NeedsRegenTracker {

	/**
	 * Transient key, shared with SettingsPage's notice renderer.
	 *
	 * @since  1.7.0
	 */
	public const TRANSIENT = 'markdown_for_agents_needs_regen';

	/**
	 * Remove a post type from the pending list, deleting the transient
	 * entirely once every flagged type has been regenerated.
	 *
	 * @since  1.7.0
	 * @param  string $post_type Slug just regenerated to completion.
	 */
	public function clear( string $post_type ): void {
		$pending = get_transient( self::TRANSIENT );

		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}

		$remaining = array_values( array_diff( $pending, array( $post_type ) ) );

		if ( empty( $remaining ) ) {
			delete_transient( self::TRANSIENT );
			return;
		}

		if ( $remaining !== $pending ) {
			set_transient( self::TRANSIENT, $remaining, 0 );
		}
	}
}
```

- [ ] **Step 4: Point `Admin` at it**

In `src/Admin/Admin.php`: delete the whole `private function mark_post_type_regenerated()` (`:204-228`, including its docblock), add a `private NeedsRegenTracker $needs_regen;` property, assign `$this->needs_regen = new NeedsRegenTracker();` in the constructor body next to `$this->settings_page = …`, and change the one call site at `:196` to `$this->needs_regen->clear( $post_type );`.

(That call site disappears entirely in Task 13; changing it now keeps the suite green in between.)

- [ ] **Step 5: Run the affected tests, then everything**

Run: `vendor/bin/phpunit tests/Unit/Admin/`
Expected: PASS.

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Core/NeedsRegenTracker.php src/Admin/Admin.php tests/Unit/Core/NeedsRegenTrackerTest.php
git commit -m "refactor: extract NeedsRegenTracker from Admin

JobRunner needs to clear a regenerated post type; the old logic was a
private Admin method. Behaviour unchanged."
```

---

## Task 8: `GenerationJob` — the job record

One option, `markdown_for_agents_job`, written with **`autoload = false`** (it is rewritten every tick; autoloading it would put it in `alloptions` on every front-end request and bust that cache on every write). An option rather than a transient because a persistent object cache may evict a transient mid-run.

**Files:**
- Create: `src/Jobs/GenerationJob.php`
- Create: `tests/Unit/Jobs/GenerationJobTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\GenerationJob
 */
class GenerationJobTest extends TestCase {

    private int $now;
    private FrozenClock $clock;
    private GenerationJob $job;

    protected function setUp(): void {
        $GLOBALS['_mock_options']          = [];
        $GLOBALS['_mock_option_autoload']  = [];
        reset_mock_scheduled_events();

        // is_running() is deliberately wall-clock based, so this suite's frozen
        // clock has to sit near real time for staleness comparisons to mean
        // anything. Every timestamp assertion below is relative to $this->now.
        $this->now   = time();
        $this->clock = new FrozenClock( $this->now );
        $this->job   = new GenerationJob( $this->clock );
    }

    /** @return list<array{type: string, total: null, processed: int, skipped: int, error_count: int, state: string}> */
    private function stages(): array {
        return [
            [ 'type' => 'taxonomy', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ],
            [ 'type' => 'bundle',   'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ],
        ];
    }

    public function test_no_option_reads_as_idle(): void {
        $record = $this->job->get();

        $this->assertSame( 'idle', $record['status'] );
        $this->assertSame( [], $record['stages'] );
        $this->assertFalse( GenerationJob::is_running() );
    }

    public function test_start_writes_a_running_record_and_schedules_the_first_tick(): void {
        $result = $this->job->start( $this->stages() );

        $this->assertTrue( $result['ok'] );

        $record = $this->job->get();

        $this->assertSame( 'running', $record['status'] );
        $this->assertNotSame( '', $record['lock_token'] );
        $this->assertSame( 0, $record['stage_index'] );
        $this->assertSame( 0, $record['cursor'] );
        $this->assertSame( $this->now, $record['last_tick_at'] );
        $this->assertCount( 2, $record['stages'] );
        $this->assertSame( $this->now, wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertTrue( GenerationJob::is_running() );
    }

    public function test_job_option_is_not_autoloaded(): void {
        $this->job->start( $this->stages() );

        $this->assertSame( false, $GLOBALS['_mock_option_autoload'][ GenerationJob::OPTION ] );
    }

    public function test_start_is_rejected_while_a_fresh_job_is_running(): void {
        $this->job->start( $this->stages() );
        $first = $this->job->get()['lock_token'];

        $this->clock->advance( 30 );
        $result = $this->job->start( $this->stages() );

        $this->assertFalse( $result['ok'] );
        $this->assertNotSame( '', $result['message'] );
        $this->assertSame( $first, $this->job->get()['lock_token'] );
    }

    public function test_start_supersedes_a_job_whose_tick_went_stale(): void {
        $this->job->start( $this->stages() );
        $first = $this->job->get()['lock_token'];

        // Simulate the tick having fatalled: its last write is now old enough
        // that nothing is going to reschedule it. Staling the stored record is
        // what actually happens in production — advancing the clock instead
        // would leave last_tick_at in the future.
        $record                 = $this->job->get();
        $record['last_tick_at'] = $this->now - ( GenerationJob::STALE_AFTER + 1 );
        update_option( GenerationJob::OPTION, $record );

        $result = $this->job->start( $this->stages() );

        $this->assertTrue( $result['ok'] );
        $this->assertNotSame( $first, $this->job->get()['lock_token'] );
        // The superseding job is live.
        $this->assertTrue( GenerationJob::is_running() );
    }

    public function test_is_running_ignores_a_stale_running_record(): void {
        $this->job->start( $this->stages() );

        $record                 = $this->job->get();
        $record['last_tick_at'] = time() - ( GenerationJob::STALE_AFTER + 1 );
        update_option( GenerationJob::OPTION, $record );

        $this->assertFalse( GenerationJob::is_running() );
    }

    public function test_save_writes_only_when_the_lock_token_matches(): void {
        $this->job->start( $this->stages() );

        $record          = $this->job->get();
        $token           = $record['lock_token'];
        $record['cursor'] = 42;

        $this->clock->advance( 5 );
        $this->assertTrue( $this->job->save( $record, $token ) );
        $this->assertSame( 42, $this->job->get()['cursor'] );
        $this->assertSame( $this->now + 5, $this->job->get()['last_tick_at'] );

        $record['cursor'] = 99;
        $this->assertFalse( $this->job->save( $record, 'not-the-token' ) );
        $this->assertSame( 42, $this->job->get()['cursor'] );
    }

    public function test_append_errors_caps_the_list_but_not_the_count(): void {
        $record = [ 'errors' => [], 'error_count' => 0 ];

        for ( $i = 1; $i <= 60; $i++ ) {
            $record = GenerationJob::append_errors( $record, [ [ 'post_id' => $i, 'message' => 'e' . $i ] ] );
        }

        $this->assertCount( GenerationJob::MAX_ERRORS, $record['errors'] );
        $this->assertSame( 60, $record['error_count'] );
        // Oldest dropped, newest kept.
        $this->assertSame( 60, $record['errors'][ GenerationJob::MAX_ERRORS - 1 ]['post_id'] );
        $this->assertSame( 11, $record['errors'][0]['post_id'] );
    }

    public function test_clear_removes_the_record(): void {
        $this->job->start( $this->stages() );
        $this->job->clear();

        $this->assertSame( 'idle', $this->job->get()['status'] );
    }
}
```

- [ ] **Step 2: Teach the option mocks to record the autoload flag**

`test_job_option_is_not_autoloaded` needs the mock to remember it. In `tests/mocks/wordpress-mocks.php`, change `add_option()` to record its fourth argument:

```php
if (!function_exists('add_option')) {
    function add_option(string $option, mixed $value, string $deprecated = '', bool|string $autoload = true): bool {
        if (!isset($GLOBALS['_mock_options'][$option])) {
            $GLOBALS['_mock_options'][$option]         = $value;
            $GLOBALS['_mock_option_autoload'][$option] = is_string($autoload) ? ('yes' === $autoload) : $autoload;
            return true;
        }
        return false;
    }
}
```

- [ ] **Step 3: Run the test and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/GenerationJobTest.php`
Expected: FAIL — `Class "…Jobs\GenerationJob" not found`.

- [ ] **Step 4: Implement**

`src/Jobs/GenerationJob.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Repository for the single bulk-generation job record.
 *
 * Stored as one non-autoloaded option: it is rewritten on every tick, so
 * autoloading it would carry it into every front-end request and invalidate
 * the alloptions cache on every write. An option rather than a transient
 * because a persistent object cache may evict a transient mid-run, which
 * would strand the cron chain.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class GenerationJob {

	/** @since 1.7.0 */
	public const OPTION = 'markdown_for_agents_job';

	/**
	 * Seconds without a tick after which a `running` job is presumed dead.
	 *
	 * Comfortably longer than one tick's time budget plus the tick mutex's
	 * staleness window, so a slow-but-healthy job is never superseded.
	 *
	 * @since  1.7.0
	 */
	public const STALE_AFTER = 600;

	/** @since 1.7.0 */
	public const MAX_ERRORS = 50;

	/**
	 * @since  1.7.0
	 * @param  Clock $clock Time source.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Read the current record, or an idle skeleton when there is none.
	 *
	 * @since  1.7.0
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$record = get_option( self::OPTION );

		if ( ! is_array( $record ) || ! isset( $record['status'] ) ) {
			return self::idle_record();
		}

		return array_merge( self::idle_record(), $record );
	}

	/**
	 * Start a job, superseding a dead one.
	 *
	 * @since  1.7.0
	 * @param  list<array<string, mixed>> $stages Stage descriptors from StageFactory.
	 * @return array{ok: bool, message: string}
	 */
	public function start( array $stages ): array {
		if ( empty( $stages ) ) {
			return array(
				'ok'      => false,
				'message' => 'Nothing to generate for that scope.',
			);
		}

		$existing = $this->get();

		if ( 'running' === $existing['status'] && ! $this->is_stale( $existing ) ) {
			return array(
				'ok'      => false,
				'message' => 'A generation job is already running.',
			);
		}

		$record = array(
			'status'            => 'running',
			'lock_token'        => wp_generate_password( 20, false ),
			'stages'            => $stages,
			'stage_index'       => 0,
			'cursor'            => 0,
			'errors'            => array(),
			'error_count'       => 0,
			'schedule_failures' => 0,
			'last_tick_at'      => $this->clock->now(),
			'message'           => '',
		);

		$this->write( $record );

		wp_schedule_single_event( $this->clock->now(), JobRunner::TICK_HOOK );

		return array(
			'ok'      => true,
			'message' => '',
		);
	}

	/**
	 * Persist a record, but only if the caller still holds the job's token.
	 *
	 * Stamps last_tick_at so start() and the watchdog can spot a dead chain.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed> $record     Record to write.
	 * @param  string               $lock_token Token the caller acquired at tick start.
	 * @return bool True when written.
	 */
	public function save( array $record, string $lock_token ): bool {
		$current = $this->get();

		if ( '' === $lock_token || ( $current['lock_token'] ?? '' ) !== $lock_token ) {
			return false;
		}

		$record['lock_token']   = $lock_token;
		$record['last_tick_at'] = $this->clock->now();

		$this->write( $record );

		return true;
	}

	/**
	 * Append per-item errors, capping the stored list but not the count.
	 *
	 * Static so the runner can fold errors into an in-memory record without
	 * a round trip through the option.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed>                 $record Job record.
	 * @param  list<array<string, mixed>>           $errors Errors from one batch.
	 * @return array<string, mixed> The updated record.
	 */
	public static function append_errors( array $record, array $errors ): array {
		if ( empty( $errors ) ) {
			return $record;
		}

		$existing = isset( $record['errors'] ) && is_array( $record['errors'] ) ? $record['errors'] : array();
		$merged   = array_merge( $existing, $errors );

		$record['errors']      = array_slice( $merged, -self::MAX_ERRORS );
		$record['error_count'] = (int) ( $record['error_count'] ?? 0 ) + count( $errors );

		return $record;
	}

	/**
	 * Is a job live right now?
	 *
	 * Static and side-effect free so BundleGenerator can ask without being
	 * handed a collaborator. A `running` record with a stale last_tick_at
	 * counts as NOT running — otherwise one crashed tick would suppress
	 * bundle scheduling forever.
	 *
	 * @since  1.7.0
	 */
	public static function is_running(): bool {
		$record = get_option( self::OPTION );

		if ( ! is_array( $record ) || 'running' !== ( $record['status'] ?? '' ) ) {
			return false;
		}

		return ( time() - (int) ( $record['last_tick_at'] ?? 0 ) ) < self::STALE_AFTER;
	}

	/**
	 * Delete the record entirely (deactivation, manual reset).
	 *
	 * @since  1.7.0
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @since  1.7.0
	 * @param  array<string, mixed> $record
	 */
	private function is_stale( array $record ): bool {
		return ( $this->clock->now() - (int) ( $record['last_tick_at'] ?? 0 ) ) >= self::STALE_AFTER;
	}

	/**
	 * @since  1.7.0
	 * @param  array<string, mixed> $record
	 */
	private function write( array $record ): void {
		// add_option() sets autoload=false on first creation; update_option()
		// preserves whatever autoload flag the row already has.
		if ( ! add_option( self::OPTION, $record, '', false ) ) {
			update_option( self::OPTION, $record );
		}
	}

	/**
	 * @since  1.7.0
	 * @return array<string, mixed>
	 */
	private static function idle_record(): array {
		return array(
			'status'            => 'idle',
			'lock_token'        => '',
			'stages'            => array(),
			'stage_index'       => 0,
			'cursor'            => 0,
			'errors'            => array(),
			'error_count'       => 0,
			'schedule_failures' => 0,
			'last_tick_at'      => 0,
			'message'           => '',
		);
	}
}
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Jobs/GenerationJobTest.php`
Expected: FAIL on the first run with `Class "…Jobs\JobRunner" not found` (the `TICK_HOOK` constant). Create `src/Jobs/JobRunner.php` now as a stub holding only the two hook constants — Task 10 fills in the body:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Processes one time-boxed run of batches per cron tick. See Task 10.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class JobRunner {

	/** @since 1.7.0 */
	public const TICK_HOOK = 'markdown_for_agents_process_batch';

	/** @since 1.7.0 */
	public const WATCHDOG_HOOK = 'markdown_for_agents_job_watchdog';
}
```

Re-run: `vendor/bin/phpunit tests/Unit/Jobs/GenerationJobTest.php`
Expected: PASS (every test in the file).

- [ ] **Step 6: Run the whole suite**

Run: `composer test`
Expected: PASS. The `add_option()` signature change is source-compatible with every existing caller.

- [ ] **Step 7: Commit**

```bash
git add src/Jobs/GenerationJob.php src/Jobs/JobRunner.php tests/Unit/Jobs/GenerationJobTest.php tests/mocks/wordpress-mocks.php
git commit -m "feat: add GenerationJob record with staleness-aware start()

Single non-autoloaded option, optimistic lock-token writes, capped error
list with an uncapped counter, and a stale-job supersede path so a
fatalled tick cannot block every future run."
```

---

## Task 9: `TickMutex`

Two ticks can run *simultaneously* even with the `wp_next_scheduled()` guards: two admin tabs whose `admin_init` nudges overlap both see no pending event and both call the tick directly, holding the same still-valid `lock_token`. Hence a mutex option.

Two details that are the whole point of this task:

- The value is `{token, acquired_at}`, **not** a bare timestamp. With a bare timestamp, two ticks that both find a stale lock both `delete_option()` and both `add_option()` — the second delete kills the first tick's fresh lock and both proceed. The token lets the loser detect it lost.
- The staleness window is `max( 300, 2 × max_execution_time )`, not 30–60s. A tick legitimately slower than its budget (heavy ACF on slow hosting — the exact problem this queue exists to fix) must not be declared abandoned. The heartbeat keeps a long healthy tick's lock fresh.

**Files:**
- Create: `src/Jobs/TickMutex.php`
- Create: `tests/Unit/Jobs/TickMutexTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\TickMutex
 */
class TickMutexTest extends TestCase {

    private FrozenClock $clock;
    private TickMutex $mutex;

    protected function setUp(): void {
        $GLOBALS['_mock_options'] = [];
        $this->clock              = new FrozenClock( 1_000_000 );
        $this->mutex              = new TickMutex( $this->clock );
    }

    public function test_first_acquire_wins_and_stores_token_with_timestamp(): void {
        $token = $this->mutex->acquire();

        $this->assertNotNull( $token );

        $stored = get_option( TickMutex::OPTION );

        $this->assertSame( $token, $stored['token'] );
        $this->assertSame( 1_000_000, $stored['acquired_at'] );
    }

    public function test_second_acquire_fails_while_the_lock_is_fresh(): void {
        $this->mutex->acquire();

        $this->clock->advance( 10 );

        $this->assertNull( ( new TickMutex( $this->clock ) )->acquire() );
    }

    public function test_stale_lock_is_stolen(): void {
        $first = $this->mutex->acquire();

        $this->clock->advance( $this->mutex->window() + 1 );
        $second = ( new TickMutex( $this->clock ) )->acquire();

        $this->assertNotNull( $second );
        $this->assertNotSame( $first, $second );
        $this->assertSame( $second, get_option( TickMutex::OPTION )['token'] );
    }

    /**
     * The stale-recovery race: if a competing tick steals the lock between our
     * delete_option() and our confirming read, we must back off rather than
     * run concurrently. Simulated by overwriting the option during add_option().
     */
    public function test_losing_a_concurrent_steal_backs_off(): void {
        $this->mutex->acquire();
        $this->clock->advance( $this->mutex->window() + 1 );

        $GLOBALS['_mock_add_option_side_effect'] = static function (): void {
            $GLOBALS['_mock_options'][ TickMutex::OPTION ] = [ 'token' => 'rival', 'acquired_at' => 1_000_000 ];
        };

        try {
            $this->assertNull( ( new TickMutex( $this->clock ) )->acquire() );
        } finally {
            unset( $GLOBALS['_mock_add_option_side_effect'] );
        }
    }

    public function test_heartbeat_refreshes_only_our_own_lock(): void {
        $token = $this->mutex->acquire();

        $this->clock->advance( 120 );
        $this->mutex->heartbeat( (string) $token );

        $this->assertSame( 1_000_120, get_option( TickMutex::OPTION )['acquired_at'] );

        $this->mutex->heartbeat( 'someone-else' );

        $this->assertSame( 1_000_120, get_option( TickMutex::OPTION )['acquired_at'] );
    }

    public function test_release_deletes_only_our_own_lock(): void {
        $token = $this->mutex->acquire();

        $this->mutex->release( 'someone-else' );
        $this->assertIsArray( get_option( TickMutex::OPTION ) );

        $this->mutex->release( (string) $token );
        $this->assertFalse( get_option( TickMutex::OPTION ) );
    }

    public function test_window_is_never_shorter_than_five_minutes(): void {
        $this->assertGreaterThanOrEqual( 300, $this->mutex->window() );
    }
}
```

- [ ] **Step 2: Add the `add_option` side-effect hook to the mocks**

The concurrent-steal test needs a way to interleave a rival write. Extend the mock `add_option()` from Task 8 by firing an optional callback *after* the insert:

```php
if (!function_exists('add_option')) {
    function add_option(string $option, mixed $value, string $deprecated = '', bool|string $autoload = true): bool {
        if (!isset($GLOBALS['_mock_options'][$option])) {
            $GLOBALS['_mock_options'][$option]         = $value;
            $GLOBALS['_mock_option_autoload'][$option] = is_string($autoload) ? ('yes' === $autoload) : $autoload;

            // Test seam: lets a test simulate another process writing between
            // our insert and our confirming read.
            if (isset($GLOBALS['_mock_add_option_side_effect']) && is_callable($GLOBALS['_mock_add_option_side_effect'])) {
                ($GLOBALS['_mock_add_option_side_effect'])($option, $value);
            }

            return true;
        }
        return false;
    }
}
```

- [ ] **Step 3: Run the test and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/TickMutexTest.php`
Expected: FAIL — `Class "…Jobs\TickMutex" not found`.

- [ ] **Step 4: Implement**

`src/Jobs/TickMutex.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Short-lived mutual exclusion for a job tick.
 *
 * The wp_next_scheduled() guards elsewhere stop duplicate *pending cron
 * events*; they do not stop two ticks physically running at once — two admin
 * tabs whose admin_init nudges overlap will both see no scheduled event and
 * both hold the same valid job lock_token. This mutex stops that.
 *
 * The stored value is {token, acquired_at} rather than a bare timestamp: two
 * ticks that both find a stale lock would otherwise both delete and both
 * insert, the second delete removing the first tick's fresh lock, and both
 * would proceed. The token lets the loser of that race detect it and back off.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class TickMutex {

	/** @since 1.7.0 */
	public const OPTION = 'markdown_for_agents_job_tick_lock';

	/**
	 * @since  1.7.0
	 * @param  Clock $clock Time source.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Try to take the lock.
	 *
	 * @since  1.7.0
	 * @return string|null The token on success; null when another tick holds a
	 *                     fresh lock, or when we lost a race to steal a stale one.
	 */
	public function acquire(): ?string {
		$token = wp_generate_password( 20, false );

		// Atomic insert: the wp_options.option_name unique key makes this safe
		// against two requests racing past a PHP-level existence check.
		if ( $this->insert( $token ) ) {
			return $token;
		}

		$held = get_option( self::OPTION );

		if ( is_array( $held ) && ( $this->clock->now() - (int) ( $held['acquired_at'] ?? 0 ) ) < $this->window() ) {
			// Another tick is legitimately running. Do nothing, schedule
			// nothing — it will reschedule when it finishes.
			return null;
		}

		// The lock looks abandoned: its owner fatalled before releasing.
		delete_option( self::OPTION );

		if ( ! $this->insert( $token ) ) {
			return null;
		}

		// Confirm we actually own it — a rival steal may have landed in between.
		$confirm = get_option( self::OPTION );

		return ( is_array( $confirm ) && ( $confirm['token'] ?? '' ) === $token ) ? $token : null;
	}

	/**
	 * Refresh our lock's timestamp so a long healthy tick is not mistaken for
	 * an abandoned one. No-op when we no longer hold the lock.
	 *
	 * @since  1.7.0
	 * @param  string $token Token from acquire().
	 */
	public function heartbeat( string $token ): void {
		if ( ! $this->holds( $token ) ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'token'       => $token,
				'acquired_at' => $this->clock->now(),
			)
		);
	}

	/**
	 * Release the lock. Never deletes a lock that is not ours.
	 *
	 * @since  1.7.0
	 * @param  string $token Token from acquire().
	 */
	public function release( string $token ): void {
		if ( ! $this->holds( $token ) ) {
			return;
		}

		delete_option( self::OPTION );
	}

	/**
	 * Seconds before a held lock is treated as abandoned.
	 *
	 * Deliberately generous: a tick slower than its own budget is exactly the
	 * scenario this queue exists to survive, so it must not be stolen from.
	 *
	 * @since  1.7.0
	 */
	public function window(): int {
		$max_exec = (int) ini_get( 'max_execution_time' );

		return max( 300, $max_exec * 2 );
	}

	/**
	 * @since  1.7.0
	 */
	private function insert( string $token ): bool {
		return (bool) add_option(
			self::OPTION,
			array(
				'token'       => $token,
				'acquired_at' => $this->clock->now(),
			),
			'',
			false
		);
	}

	/**
	 * @since  1.7.0
	 */
	private function holds( string $token ): bool {
		$held = get_option( self::OPTION );

		return '' !== $token && is_array( $held ) && ( $held['token'] ?? '' ) === $token;
	}
}
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Jobs/TickMutexTest.php`
Expected: PASS (every test in the file).

- [ ] **Step 6: Commit**

```bash
git add src/Jobs/TickMutex.php tests/Unit/Jobs/TickMutexTest.php tests/mocks/wordpress-mocks.php
git commit -m "feat: add token-based TickMutex for job ticks

Token-in-value so a stale-lock steal can be confirmed, a heartbeat so a
slow healthy tick is not stolen from, and a window of at least five
minutes rather than seconds."
```

---

## Task 10: `JobRunner` — the time-boxed tick

The core of the design. One tick: take the mutex, loop `process_batch()` calls until a wall-clock budget is spent, advance stages, persist after every batch so the poller sees live progress.

**Why a loop and not one batch per tick:** WP-Cron only runs on request traffic and `spawn_cron()` is rate-limited by `WP_CRON_LOCK_TIMEOUT` (60s default), so a one-batch-per-tick chain advances at roughly one batch a minute — 40,000 posts would take about 13 hours. The budget is `min( 30, 0.6 × max_execution_time )` seconds (30 when `max_execution_time` is 0/unlimited), checked only *between* batches, filterable via `markdown_for_agents_tick_budget`.

**Files:**
- Modify: `src/Jobs/JobRunner.php` (replace the Task 8 stub)
- Create: `tests/Support/FakeStage.php`
- Create: `tests/Unit/Jobs/JobRunnerTest.php`

- [ ] **Step 1: Write the test double**

`tests/Support/FakeStage.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Support;

use Tclp\WpMarkdownForAgents\Jobs\Stage;

/**
 * Stage double: returns queued batch results, records the cursors it was
 * called with, and optionally burns clock time per batch so time-boxing can
 * be tested without sleeping.
 */
final class FakeStage implements Stage {

    /** @var list<int> */
    public array $cursors = [];

    public int $count_total_calls = 0;

    /**
     * @param list<array<string, mixed>> $pages             Batch results, consumed in order.
     * @param int                        $total             count_total() return value.
     * @param FrozenClock|null           $clock             Advanced by $seconds_per_batch per batch.
     * @param int                        $seconds_per_batch Simulated batch duration.
     * @param \Throwable|null            $count_error       Thrown by count_total() when set.
     */
    public function __construct(
        private array $pages,
        private int $total = 0,
        private ?FrozenClock $clock = null,
        private int $seconds_per_batch = 0,
        private ?\Throwable $count_error = null,
    ) {}

    public function count_total(): int {
        ++$this->count_total_calls;

        if ( null !== $this->count_error ) {
            throw $this->count_error;
        }

        return $this->total;
    }

    public function process_batch( int $cursor, int $limit ): array {
        $this->cursors[] = $cursor;

        if ( null !== $this->clock && $this->seconds_per_batch > 0 ) {
            $this->clock->advance( $this->seconds_per_batch );
        }

        $page = array_shift( $this->pages );

        return $page ?? [
            'processed'   => 0,
            'skipped'     => 0,
            'errors'      => [],
            'next_cursor' => $cursor,
            'done'        => true,
        ];
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Jobs\StageFactory;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;
use Tclp\WpMarkdownForAgents\Tests\Support\FakeStage;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\JobRunner
 */
class JobRunnerTest extends TestCase {

    private FrozenClock $clock;
    private GenerationJob $job;
    private TickMutex $mutex;

    /** @var StageFactory&MockObject */
    private StageFactory $factory;

    protected function setUp(): void {
        $GLOBALS['_mock_options']         = [];
        $GLOBALS['_mock_option_autoload'] = [];
        $GLOBALS['_mock_transients']      = [];
        reset_mock_scheduled_events();
        unset( $GLOBALS['_mock_schedule_single_event_return'] );

        // Pin the tick budget: the default is derived from this machine's
        // max_execution_time, so unpinned batch-count assertions are
        // environment-dependent.
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] = static fn( $value ) => 30;

        $this->clock   = new FrozenClock( 1_000_000 );
        $this->job     = new GenerationJob( $this->clock );
        $this->mutex   = new TickMutex( $this->clock );
        $this->factory = $this->createMock( StageFactory::class );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] );
    }

    /** @param list<array<string, mixed>> $stages */
    private function given_job( array $stages ): void {
        $this->job->start( $stages );
        reset_mock_scheduled_events();   // drop start()'s first tick event
    }

    /** @return array<string, mixed> */
    private function descriptor( string $type, string $slug = '' ): array {
        $descriptor = [ 'type' => $type, 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ];

        if ( '' !== $slug ) {
            $descriptor['slug'] = $slug;
        }

        return $descriptor;
    }

    private function runner(): JobRunner {
        return new JobRunner( $this->job, $this->mutex, $this->factory, new NeedsRegenTracker(), $this->clock );
    }

    /** @param list<array<string, mixed>> $pages */
    private function page( int $processed, int $next_cursor, bool $done, array $errors = [] ): array {
        return [ 'processed' => $processed, 'skipped' => 0, 'errors' => $errors, 'next_cursor' => $next_cursor, 'done' => $done ];
    }

    public function test_tick_records_total_once_progress_and_cursor(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        $stage = new FakeStage( [ $this->page( 5, 5, false ), $this->page( 5, 10, false ) ], 40, $this->clock, 20 );
        $this->factory->method( 'make' )->willReturn( $stage );

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 1, $stage->count_total_calls );
        $this->assertSame( 40, $record['stages'][0]['total'] );
        $this->assertSame( 10, $record['stages'][0]['processed'] );
        $this->assertSame( 10, $record['cursor'] );
        $this->assertSame( 'running', $record['status'] );
        $this->assertSame( $this->clock->now(), $record['last_tick_at'] );
    }

    public function test_tick_processes_several_batches_and_stops_at_the_budget(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        // 10s per batch: with a 30s ceiling the third batch takes us to the
        // boundary, so exactly three batches run.
        $stage = new FakeStage(
            [ $this->page( 1, 1, false ), $this->page( 1, 2, false ), $this->page( 1, 3, false ), $this->page( 1, 4, false ) ],
            100,
            $this->clock,
            10
        );
        $this->factory->method( 'make' )->willReturn( $stage );

        $this->runner()->run_tick();

        $this->assertSame( [ 0, 1, 2 ], $stage->cursors );
        $this->assertSame( 3, $this->job->get()['cursor'] );
    }

    public function test_budget_is_filterable(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] = static fn( $value ) => 5;
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        $stage = new FakeStage( [ $this->page( 1, 1, false ), $this->page( 1, 2, false ) ], 100, $this->clock, 10 );
        $this->factory->method( 'make' )->willReturn( $stage );

        try {
            $this->runner()->run_tick();
        } finally {
            unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] );
        }

        $this->assertSame( [ 0 ], $stage->cursors );
    }

    public function test_finishing_a_stage_resets_the_cursor_for_the_next_one(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ), $this->descriptor( 'taxonomy' ) ] );

        $first  = new FakeStage( [ $this->page( 2, 900, true ) ], 2 );
        $second = new FakeStage( [ $this->page( 1, 7, true ) ], 1 );
        $this->factory->method( 'make' )
            ->willReturnOnConsecutiveCalls( $first, $second );

        $this->runner()->run_tick();

        // The regression assert: stage two starts from 0, not the post-ID 900.
        $this->assertSame( [ 0 ], $second->cursors );
        $this->assertSame( 'done', $this->job->get()['stages'][0]['state'] );
    }

    public function test_finishing_a_post_type_stage_clears_the_regen_transient(): void {
        set_transient( NeedsRegenTracker::TRANSIENT, [ 'post', 'page' ], 0 );
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 1, 1, true ) ], 1 ) );

        $this->runner()->run_tick();

        $this->assertSame( [ 'page' ], get_transient( NeedsRegenTracker::TRANSIENT ) );
    }

    public function test_finishing_the_last_stage_marks_the_job_done(): void {
        $this->given_job( [ $this->descriptor( 'bundle' ) ] );
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 1, 1, true ) ], 1 ) );

        $this->runner()->run_tick();

        $this->assertSame( 'done', $this->job->get()['status'] );
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
    }

    public function test_bundle_stage_ends_the_tick_even_with_budget_left(): void {
        $this->given_job( [ $this->descriptor( 'bundle' ), $this->descriptor( 'taxonomy' ) ] );

        $bundle = new FakeStage( [ $this->page( 1, 1, true ) ], 1 );
        $after  = new FakeStage( [ $this->page( 1, 1, true ) ], 1 );
        $this->factory->method( 'make' )->willReturnOnConsecutiveCalls( $bundle, $after );

        $this->runner()->run_tick();

        $this->assertSame( [], $after->cursors );
        $this->assertSame( 1, $this->job->get()['stage_index'] );
    }

    public function test_per_item_errors_are_capped_counted_and_do_not_halt_the_job(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        $errors = [ [ 'post_id' => 1, 'message' => 'bad' ], [ 'post_id' => 2, 'message' => 'worse' ] ];
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 0, 2, true, $errors ) ], 2 ) );

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 2, $record['error_count'] );
        $this->assertSame( 2, $record['stages'][0]['error_count'] );
        $this->assertCount( 2, $record['errors'] );
        $this->assertSame( 'done', $record['status'] );
    }

    public function test_a_held_mutex_makes_the_tick_a_no_op(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );
        ( new TickMutex( $this->clock ) )->acquire();

        $stage = new FakeStage( [ $this->page( 1, 1, false ) ], 10 );
        $this->factory->method( 'make' )->willReturn( $stage );

        $this->runner()->run_tick();

        $this->assertSame( [], $stage->cursors );
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertSame( 0, $this->job->get()['cursor'] );
    }

    public function test_tick_releases_the_mutex_when_it_finishes(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 1, 1, true ) ], 1 ) );

        $this->runner()->run_tick();

        $this->assertFalse( get_option( TickMutex::OPTION ) );
    }

    public function test_tick_releases_the_mutex_when_a_stage_throws(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );
        $this->factory->method( 'make' )
            ->willReturn( new FakeStage( [], 0, null, 0, new \RuntimeException( 'count exploded' ) ) );

        $this->runner()->run_tick();

        $this->assertFalse( get_option( TickMutex::OPTION ) );

        $record = $this->job->get();

        $this->assertSame( 'failed', $record['status'] );
        $this->assertStringContainsString( 'count exploded', $record['message'] );
    }

    public function test_a_superseded_job_stops_without_rescheduling(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        // A save() refused mid-tick means start() superseded this chain with a
        // new lock token. The tick must stop dead and schedule nothing.
        // Mocked rather than staged through the option: the runner reads the
        // token from the record it loads, so the token can only diverge
        // *after* that read — which no test can reach from outside.
        $job = $this->createMock( GenerationJob::class );
        $job->method( 'get' )->willReturn(
            [
                'status'            => 'running',
                'lock_token'        => 'stale-token',
                'stages'            => [ $this->descriptor( 'post_type', 'post' ) ],
                'stage_index'       => 0,
                'cursor'            => 0,
                'errors'            => [],
                'error_count'       => 0,
                'schedule_failures' => 0,
                'last_tick_at'      => 1_000_000,
                'message'           => '',
            ]
        );
        $job->method( 'save' )->willReturn( false );

        $stage = new FakeStage( [ $this->page( 1, 1, false ) ], 10 );
        $this->factory->method( 'make' )->willReturn( $stage );

        $runner = new JobRunner( $job, $this->mutex, $this->factory, new NeedsRegenTracker(), $this->clock );
        $runner->run_tick();

        // One batch may already have run before the refused save; what matters
        // is that nothing was scheduled and no second batch was attempted.
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertCount( 1, $stage->cursors );
        $this->assertFalse( get_option( TickMutex::OPTION ) );
    }

    public function test_an_unusable_stage_descriptor_is_skipped_not_fatal(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'gone' ), $this->descriptor( 'taxonomy' ) ] );

        $second = new FakeStage( [ $this->page( 1, 1, true ) ], 1 );
        $this->factory->method( 'make' )->willReturnOnConsecutiveCalls( null, $second );

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 'unavailable', $record['stages'][0]['state'] );
        $this->assertSame( [ 0 ], $second->cursors );
        $this->assertSame( 'done', $record['status'] );
    }

    public function test_an_idle_job_does_no_work(): void {
        $stage = new FakeStage( [ $this->page( 1, 1, false ) ], 5 );
        $this->factory->method( 'make' )->willReturn( $stage );

        $this->runner()->run_tick();

        $this->assertSame( [], $stage->cursors );
    }
}
```

Note: `test_a_superseded_job_stops_without_rescheduling` relies on the runner reading `lock_token` **before** the first `save()` and on `save()` refusing a mismatch — the same guard, exercised from the runner's side.

- [ ] **Step 3: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/JobRunnerTest.php`
Expected: FAIL — `Too few arguments to function …JobRunner::__construct()` / `Call to undefined method …JobRunner::run_tick()`.

- [ ] **Step 4: Implement the tick**

Replace `src/Jobs/JobRunner.php` wholesale (keeping the two hook constants):

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;

/**
 * Runs bulk generation as a self-rescheduling chain of time-boxed cron ticks.
 *
 * A tick processes as many batches as its wall-clock budget allows rather than
 * exactly one: WP-Cron only fires on request traffic and spawn_cron() is
 * rate-limited by WP_CRON_LOCK_TIMEOUT (60s by default), so one batch per tick
 * would cap throughput at roughly 50 items a minute.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class JobRunner {

	/** @since 1.7.0 */
	public const TICK_HOOK = 'markdown_for_agents_process_batch';

	/** @since 1.7.0 */
	public const WATCHDOG_HOOK = 'markdown_for_agents_job_watchdog';

	/** @since 1.7.0 */
	public const BATCH_LIMIT = 50;

	/** Seconds of work per tick, before max_execution_time is considered. */
	private const MAX_BUDGET = 30;

	/**
	 * @since  1.7.0
	 * @param  GenerationJob        $job              Job record repository.
	 * @param  TickMutex            $mutex            Guards against two concurrent ticks.
	 * @param  StageFactory         $stage_factory    Rehydrates stored stage descriptors.
	 * @param  NeedsRegenTracker    $needs_regen      Clears the regenerate notice per post type.
	 * @param  Clock                $clock            Time source.
	 * @param  BundleGenerator|null $bundle_generator Used only to spot a tree that went stale after the bundle stage ran.
	 */
	public function __construct(
		private readonly GenerationJob $job,
		private readonly TickMutex $mutex,
		private readonly StageFactory $stage_factory,
		private readonly NeedsRegenTracker $needs_regen,
		private readonly Clock $clock,
		private readonly ?BundleGenerator $bundle_generator = null,
	) {}

	/**
	 * One cron tick.
	 *
	 * Hooked to self::TICK_HOOK, and called directly by the admin_init nudge.
	 *
	 * @since  1.7.0
	 */
	public function run_tick(): void {
		$mutex_token = $this->mutex->acquire();

		if ( null === $mutex_token ) {
			// Another tick holds the lock. It will reschedule when it finishes,
			// so do nothing at all here — including no scheduling.
			return;
		}

		try {
			$this->process( $mutex_token );
		} finally {
			$this->mutex->release( $mutex_token );
		}
	}

	/**
	 * @since  1.7.0
	 * @param  string $mutex_token Token held for this tick.
	 */
	private function process( string $mutex_token ): void {
		$record = $this->job->get();

		if ( 'running' !== $record['status'] ) {
			return;
		}

		$job_token = (string) $record['lock_token'];
		$started   = $this->clock->monotonic();
		$budget    = $this->budget();

		while ( true ) {
			$stage_count = count( $record['stages'] );

			if ( $record['stage_index'] >= $stage_count ) {
				$record['status'] = 'done';
				break;
			}

			$index      = (int) $record['stage_index'];
			$descriptor = $record['stages'][ $index ];
			$stage      = $this->stage_factory->make( $descriptor );

			if ( null === $stage ) {
				// A post type disabled since the job started, say. Skip it
				// rather than failing the whole job.
				$descriptor['state']           = 'unavailable';
				$record['stages'][ $index ]    = $descriptor;
				$record['stage_index']         = $index + 1;
				$record['cursor']              = 0;
				continue;
			}

			if ( null === $descriptor['total'] ) {
				try {
					$descriptor['total'] = $stage->count_total();
				} catch ( \Throwable $e ) {
					$record['status']  = 'failed';
					$record['message'] = 'Could not count items for this stage: ' . $e->getMessage();
					break;
				}
			}

			$descriptor['state']        = 'running';
			$record['stages'][ $index ] = $descriptor;

			$batch = $stage->process_batch( (int) $record['cursor'], self::BATCH_LIMIT );

			$descriptor['processed']   += (int) $batch['processed'];
			$descriptor['skipped']     += (int) $batch['skipped'];
			$descriptor['error_count'] += count( $batch['errors'] );
			$record['stages'][ $index ] = $descriptor;
			$record['cursor']           = (int) $batch['next_cursor'];
			$record                     = GenerationJob::append_errors( $record, $batch['errors'] );

			$was_bundle = 'bundle' === $descriptor['type'];

			if ( $batch['done'] ) {
				$descriptor['state']        = 'done';
				$record['stages'][ $index ] = $descriptor;

				if ( 'post_type' === $descriptor['type'] && ! empty( $descriptor['slug'] ) ) {
					$this->needs_regen->clear( (string) $descriptor['slug'] );
				}

				// The cursor is scoped to one stage: carrying a post-ID or
				// term_taxonomy_id cursor into the next stage would make its
				// first query return nothing and mark it complete having done
				// no work.
				$record['stage_index'] = $index + 1;
				$record['cursor']      = 0;

				if ( $record['stage_index'] >= $stage_count ) {
					$record['status'] = 'done';
				}
			}

			if ( ! $this->job->save( $record, $job_token ) ) {
				// Superseded by a newer job: stop, and schedule nothing.
				return;
			}

			$this->mutex->heartbeat( $mutex_token );

			if ( 'running' !== $record['status'] || $was_bundle ) {
				break;
			}

			if ( ( $this->clock->monotonic() - $started ) >= $budget ) {
				break;
			}
		}

		if ( ! $this->job->save( $record, $job_token ) ) {
			return;
		}

		if ( 'done' === $record['status'] ) {
			$this->after_completion();
			return;
		}

		if ( 'failed' === $record['status'] ) {
			return;
		}

		$this->maybe_reschedule( $record, $job_token );
	}

	/**
	 * Seconds of work one tick may do.
	 *
	 * Checked only between batches — a batch is never interrupted.
	 *
	 * @since  1.7.0
	 */
	private function budget(): int {
		$max_exec = (int) ini_get( 'max_execution_time' );
		$budget   = $max_exec > 0 ? max( 10, (int) ( $max_exec * 0.6 ) ) : self::MAX_BUDGET;
		$budget   = min( $budget, self::MAX_BUDGET );

		/**
		 * Filter the wall-clock seconds one generation tick may spend.
		 *
		 * @since  1.7.0
		 * @param  int $budget Seconds.
		 */
		return max( 1, (int) apply_filters( 'markdown_for_agents_tick_budget', $budget ) );
	}

	/**
	 * Placeholder — filled in by the scheduling task.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed> $record
	 */
	private function maybe_reschedule( array $record, string $job_token ): void {
		if ( false === wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( $this->clock->now() + 1, self::TICK_HOOK );
		}
	}

	/**
	 * Placeholder — filled in by the bundle-staleness task.
	 *
	 * @since  1.7.0
	 */
	private function after_completion(): void {
	}
}
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit tests/Unit/Jobs/JobRunnerTest.php`
Expected: PASS (every test in the file).

If `test_tick_processes_several_batches_and_stops_at_the_budget` gives 2 or 4 batches instead of 3, the local `max_execution_time` is capping the budget below 30. Assert against the runner's own budget rather than hard-coding 30: set `$GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget']` to a fixed 30 in that test, as `test_budget_is_filterable` does with 5.

- [ ] **Step 6: Commit**

```bash
git add src/Jobs/JobRunner.php tests/Support/FakeStage.php tests/Unit/Jobs/JobRunnerTest.php
git commit -m "feat: add time-boxed JobRunner tick

Loops batches until a wall-clock budget is spent rather than one batch
per tick, since WP_CRON_LOCK_TIMEOUT caps cron spawns at roughly one a
minute. Resets the cursor on every stage transition, records per-stage
counters, and holds the tick mutex across the whole run."
```

---

## Task 11: scheduling, watchdog, and the `admin_init` nudge

Fills in `maybe_reschedule()` and adds the two recovery entry points. The failure mode being closed: `wp_schedule_single_event()` returns `false` when a duplicate hook+args event exists within 10 minutes of the requested timestamp (or when `pre_schedule_event` vetoes it), so a chain that ignores the return value can die with `status` stuck on `running` forever.

Three ways in, deliberately non-overlapping:

| Entry | When | Action |
|-------|------|--------|
| `TICK_HOOK` (cron) | normal chain | run a tick |
| `admin_init` nudge | `running`, no pending tick event, `last_tick_at` > 60s old | run a tick **inline** (never schedules — that is what would race cron) |
| `WATCHDOG_HOOK` (cron, hourly) | `running`, no pending tick, `last_tick_at` > `STALE_AFTER` | **schedule** a tick |

The nudge deliberately fires on *any* admin request, not just the plugin's settings screen: a chain that has lost its event should recover from anywhere in wp-admin, and the guard is two cheap reads.

**Files:**
- Modify: `src/Jobs/JobRunner.php`
- Create: `tests/Unit/Jobs/JobRunnerSchedulingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Jobs\StageFactory;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;
use Tclp\WpMarkdownForAgents\Tests\Support\FakeStage;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\JobRunner
 */
class JobRunnerSchedulingTest extends TestCase {

    private FrozenClock $clock;
    private GenerationJob $job;

    /** @var StageFactory&MockObject */
    private StageFactory $factory;

    protected function setUp(): void {
        $GLOBALS['_mock_options']         = [];
        $GLOBALS['_mock_option_autoload'] = [];
        $GLOBALS['_mock_transients']      = [];
        reset_mock_scheduled_events();
        unset( $GLOBALS['_mock_schedule_single_event_return'] );

        $this->clock   = new FrozenClock( 1_000_000 );
        $this->job     = new GenerationJob( $this->clock );
        $this->factory = $this->createMock( StageFactory::class );
    }

    private function runner(): JobRunner {
        return new JobRunner( $this->job, new TickMutex( $this->clock ), $this->factory, new NeedsRegenTracker(), $this->clock );
    }

    /** Start a running job with one unfinished stage and no pending events. */
    private function given_unfinished_job(): FakeStage {
        $this->job->start( [ [ 'type' => 'post_type', 'slug' => 'post', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );
        reset_mock_scheduled_events();

        $stage = new FakeStage(
            [ [ 'processed' => 1, 'skipped' => 0, 'errors' => [], 'next_cursor' => 1, 'done' => false ] ],
            100,
            $this->clock,
            60   // one batch spends the whole budget, so exactly one runs
        );
        $this->factory->method( 'make' )->willReturn( $stage );

        return $stage;
    }

    public function test_unfinished_job_reschedules_one_tick(): void {
        $this->given_unfinished_job();

        $this->runner()->run_tick();

        $this->assertSame( 1_000_060 + 1, wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertCount( 1, $GLOBALS['_mock_scheduled_events'] );
    }

    public function test_no_duplicate_tick_is_scheduled_when_one_is_pending(): void {
        $this->given_unfinished_job();
        wp_schedule_single_event( 1_000_500, JobRunner::TICK_HOOK );

        $this->runner()->run_tick();

        $this->assertCount( 1, $GLOBALS['_mock_scheduled_events'] );
        $this->assertSame( 1_000_500, wp_next_scheduled( JobRunner::TICK_HOOK ) );
    }

    public function test_a_failed_schedule_is_counted_and_the_job_keeps_running(): void {
        $this->given_unfinished_job();
        $GLOBALS['_mock_schedule_single_event_return'] = false;

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 1, $record['schedule_failures'] );
        $this->assertSame( 'running', $record['status'] );
    }

    public function test_three_consecutive_schedule_failures_fail_the_job(): void {
        $this->job->start( [ [ 'type' => 'post_type', 'slug' => 'post', 'total' => 100, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'running' ] ] );
        reset_mock_scheduled_events();

        $record                      = $this->job->get();
        $record['schedule_failures'] = JobRunner::MAX_SCHEDULE_FAILURES - 1;
        update_option( GenerationJob::OPTION, $record );

        $this->factory->method( 'make' )->willReturn(
            new FakeStage( [ [ 'processed' => 1, 'skipped' => 0, 'errors' => [], 'next_cursor' => 1, 'done' => false ] ], 100, $this->clock, 60 )
        );
        $GLOBALS['_mock_schedule_single_event_return'] = false;

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 'failed', $record['status'] );
        $this->assertStringContainsString( 'schedule', strtolower( $record['message'] ) );
    }

    public function test_a_successful_schedule_resets_the_failure_counter(): void {
        $this->given_unfinished_job();

        $record                      = $this->job->get();
        $record['schedule_failures'] = 2;
        update_option( GenerationJob::OPTION, $record );

        $this->runner()->run_tick();

        $this->assertSame( 0, $this->job->get()['schedule_failures'] );
    }

    public function test_nudge_runs_a_tick_when_the_event_is_missing_and_the_job_is_stale_by_a_minute(): void {
        $stage = $this->given_unfinished_job();
        $this->clock->advance( 61 );

        $this->runner()->maybe_nudge();

        $this->assertNotSame( [], $stage->cursors );
    }

    public function test_nudge_does_nothing_when_a_tick_is_already_pending(): void {
        $stage = $this->given_unfinished_job();
        wp_schedule_single_event( 1_000_500, JobRunner::TICK_HOOK );
        $this->clock->advance( 61 );

        $this->runner()->maybe_nudge();

        $this->assertSame( [], $stage->cursors );
    }

    public function test_nudge_does_nothing_for_a_recent_tick_or_an_idle_job(): void {
        $stage = $this->given_unfinished_job();
        $this->clock->advance( 30 );

        $this->runner()->maybe_nudge();
        $this->assertSame( [], $stage->cursors );

        $this->job->clear();
        $this->clock->advance( 600 );

        $this->runner()->maybe_nudge();
        $this->assertSame( [], $stage->cursors );
    }

    public function test_watchdog_schedules_a_tick_for_a_stale_running_job(): void {
        $this->given_unfinished_job();
        $this->clock->advance( GenerationJob::STALE_AFTER + 1 );

        $this->runner()->watchdog();

        $this->assertSame( $this->clock->now(), wp_next_scheduled( JobRunner::TICK_HOOK ) );
    }

    public function test_watchdog_ignores_fresh_jobs_pending_events_and_idle_jobs(): void {
        $this->given_unfinished_job();

        $this->runner()->watchdog();
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );

        $this->clock->advance( GenerationJob::STALE_AFTER + 1 );
        wp_schedule_single_event( 2_000_000, JobRunner::TICK_HOOK );
        $this->runner()->watchdog();
        $this->assertSame( 2_000_000, wp_next_scheduled( JobRunner::TICK_HOOK ) );

        reset_mock_scheduled_events();
        $this->job->clear();
        $this->runner()->watchdog();
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Jobs/JobRunnerSchedulingTest.php`
Expected: FAIL — `Call to undefined method …JobRunner::maybe_nudge()`.

- [ ] **Step 3: Implement**

In `src/Jobs/JobRunner.php`, add the constant, replace `maybe_reschedule()`, and add the two entry points:

```php
	/** Consecutive failed reschedules before the job is declared failed. */
	public const MAX_SCHEDULE_FAILURES = 3;

	/** Seconds without a tick before an admin request may nudge the chain. */
	private const NUDGE_AFTER = 60;
```

```php
	/**
	 * Same-process fallback for a chain whose scheduled event has gone missing.
	 *
	 * Hooked to `admin_init` on every admin request, deliberately not only the
	 * plugin's settings screen — a lost chain should recover from anywhere in
	 * wp-admin, and the guard below is two cheap reads. Runs a tick inline and
	 * never schedules; scheduling here is what would race the cron path.
	 *
	 * @since  1.7.0
	 */
	public function maybe_nudge(): void {
		$record = $this->job->get();

		if ( 'running' !== $record['status'] ) {
			return;
		}

		if ( false !== wp_next_scheduled( self::TICK_HOOK ) ) {
			return;
		}

		if ( ( $this->clock->now() - (int) $record['last_tick_at'] ) <= self::NUDGE_AFTER ) {
			return;
		}

		$this->run_tick();
	}

	/**
	 * Hourly backstop for a chain that died where no admin request will notice.
	 *
	 * Schedules a tick rather than running one inline: cron already has a
	 * request to spend, and scheduling keeps this cheap.
	 *
	 * @since  1.7.0
	 */
	public function watchdog(): void {
		$record = $this->job->get();

		if ( 'running' !== $record['status'] ) {
			return;
		}

		if ( false !== wp_next_scheduled( self::TICK_HOOK ) ) {
			return;
		}

		if ( ( $this->clock->now() - (int) $record['last_tick_at'] ) < GenerationJob::STALE_AFTER ) {
			return;
		}

		wp_schedule_single_event( $this->clock->now(), self::TICK_HOOK );
	}

	/**
	 * Queue the next tick, treating a refused schedule as a real failure.
	 *
	 * wp_schedule_single_event() returns false when a duplicate hook+args event
	 * exists within 10 minutes of the requested time, or when pre_schedule_event
	 * vetoes it. Ignoring that return value is how a chain dies silently with
	 * status stuck on `running`.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed> $record    Current job record.
	 * @param  string               $job_token Token this tick holds.
	 */
	private function maybe_reschedule( array $record, string $job_token ): void {
		if ( false !== wp_next_scheduled( self::TICK_HOOK ) ) {
			return;
		}

		if ( wp_schedule_single_event( $this->clock->now() + 1, self::TICK_HOOK ) ) {
			if ( 0 !== (int) $record['schedule_failures'] ) {
				$record['schedule_failures'] = 0;
				$this->job->save( $record, $job_token );
			}

			return;
		}

		$record['schedule_failures'] = (int) $record['schedule_failures'] + 1;

		if ( $record['schedule_failures'] >= self::MAX_SCHEDULE_FAILURES ) {
			$record['status']  = 'failed';
			$record['message'] = 'Could not schedule the next batch. WP-Cron may be disabled or blocked on this site; check the cron configuration and start the job again.';
		}

		$this->job->save( $record, $job_token );
	}
```

- [ ] **Step 4: Run both runner suites**

Run: `vendor/bin/phpunit tests/Unit/Jobs/`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/JobRunner.php tests/Unit/Jobs/JobRunnerSchedulingTest.php
git commit -m "feat: recover a broken cron chain via nudge and watchdog

Checks wp_schedule_single_event()'s return value, fails the job after
three consecutive refusals, nudges inline from any admin request when the
event is missing, and adds an hourly watchdog backstop."
```

---

## Task 12: bundle staleness — mark always, schedule conditionally

`BundleGenerator::mark_stale_and_schedule()` (`:267-277`) does two separable things: `delete_option( HASH_OPTION )` marks the tree stale, then it schedules a rebuild five minutes out. During a job, the rebuild must not be scheduled (the job's own `BundleStage` covers it) — but the **staleness marking must still happen**. Skipping the whole method would leave a post saved *after* `BundleStage` ran, while `status` is still `running`, neither marked stale nor scheduled: a permanently wrong zip until some unrelated later change.

`JobRunner::after_completion()` closes the remaining sliver: on marking the job `done`, if the tree is stale again, schedule a normal debounced rebuild.

**Files:**
- Modify: `src/Generator/BundleGenerator.php:267-277`
- Modify: `src/Jobs/JobRunner.php` (`after_completion()`)
- Modify: `tests/Unit/Generator/BundleGeneratorTest.php`
- Modify: `tests/Unit/Jobs/JobRunnerTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Generator/BundleGeneratorTest.php` (match that file's existing `setUp()` conventions for options and globals):

```php
    public function test_marks_stale_but_does_not_schedule_while_a_job_is_running(): void {
        update_option(
            \Tclp\WpMarkdownForAgents\Jobs\GenerationJob::OPTION,
            [ 'status' => 'running', 'last_tick_at' => time() ]
        );
        update_option( BundleGenerator::HASH_OPTION, 'stale-me' );
        reset_mock_scheduled_events();

        $this->generator_with_bundle_enabled()->mark_stale_and_schedule();

        $this->assertFalse( get_option( BundleGenerator::HASH_OPTION ) );
        $this->assertFalse( wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) );
    }

    public function test_marks_stale_and_schedules_when_no_job_is_running(): void {
        delete_option( \Tclp\WpMarkdownForAgents\Jobs\GenerationJob::OPTION );
        update_option( BundleGenerator::HASH_OPTION, 'stale-me' );
        reset_mock_scheduled_events();

        $this->generator_with_bundle_enabled()->mark_stale_and_schedule();

        $this->assertFalse( get_option( BundleGenerator::HASH_OPTION ) );
        $this->assertNotFalse( wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) );
    }

    public function test_a_stale_running_job_does_not_suppress_scheduling(): void {
        update_option(
            \Tclp\WpMarkdownForAgents\Jobs\GenerationJob::OPTION,
            [ 'status' => 'running', 'last_tick_at' => time() - 100_000 ]
        );
        reset_mock_scheduled_events();

        $this->generator_with_bundle_enabled()->mark_stale_and_schedule();

        $this->assertNotFalse( wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) );
    }
```

Add a small `private function generator_with_bundle_enabled(): BundleGenerator` helper if the file does not already have an equivalent, and confirm `BundleGenerator::HASH_OPTION`'s real visibility with `grep -n "HASH_OPTION" src/Generator/BundleGenerator.php` — if it is private, assert on the behaviour through the class's own `is_stale()` instead of the raw option.

Append to `tests/Unit/Jobs/JobRunnerTest.php`:

```php
    public function test_completion_schedules_a_rebuild_when_the_tree_went_stale_again(): void {
        $bundle = $this->createMock( \Tclp\WpMarkdownForAgents\Generator\BundleGenerator::class );
        $bundle->method( 'is_stale' )->willReturn( true );

        $this->given_job( [ $this->descriptor( 'bundle' ) ] );
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 1, 1, true ) ], 1 ) );

        $runner = new JobRunner( $this->job, $this->mutex, $this->factory, new NeedsRegenTracker(), $this->clock, $bundle );
        $runner->run_tick();

        $this->assertSame( 'done', $this->job->get()['status'] );
        $this->assertNotFalse( wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) );
    }

    public function test_completion_schedules_nothing_when_the_tree_is_fresh(): void {
        $bundle = $this->createMock( \Tclp\WpMarkdownForAgents\Generator\BundleGenerator::class );
        $bundle->method( 'is_stale' )->willReturn( false );

        $this->given_job( [ $this->descriptor( 'bundle' ) ] );
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 1, 1, true ) ], 1 ) );

        $runner = new JobRunner( $this->job, $this->mutex, $this->factory, new NeedsRegenTracker(), $this->clock, $bundle );
        $runner->run_tick();

        $this->assertFalse( wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) );
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `vendor/bin/phpunit tests/Unit/Generator/BundleGeneratorTest.php tests/Unit/Jobs/JobRunnerTest.php`
Expected: FAIL — the guard schedules anyway; `after_completion()` is still empty.

- [ ] **Step 3: Add the guard**

`src/Generator/BundleGenerator.php`, replacing the body of `mark_stale_and_schedule()`:

```php
	public function mark_stale_and_schedule(): void {
		if ( empty( $this->options['bundle_enabled'] ) ) {
			return;
		}

		// Staleness is marked unconditionally, even during a job. Skipping this
		// too would leave a change landing after the job's BundleStage neither
		// marked nor scheduled, so the zip would stay wrong until some
		// unrelated later change.
		delete_option( self::HASH_OPTION );

		if ( GenerationJob::is_running() ) {
			// The running job's own BundleStage will rebuild; a debounced event
			// here would rebuild the same zip a second time.
			return;
		}

		if ( ! wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) ) {
			wp_schedule_single_event( time() + 300, 'markdown_for_agents_rebuild_bundle' );
		}
	}
```

Add `use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;` to the file's imports.

- [ ] **Step 4: Fill in `after_completion()`**

In `src/Jobs/JobRunner.php`:

```php
	/**
	 * Called once, as the job flips to `done`.
	 *
	 * While the job ran, BundleGenerator::mark_stale_and_schedule() marked the
	 * tree stale but deliberately scheduled nothing. Anything that changed
	 * after the bundle stage ran therefore has no rebuild queued, so queue one
	 * now through the normal debounced path.
	 *
	 * @since  1.7.0
	 */
	private function after_completion(): void {
		if ( null === $this->bundle_generator || ! $this->bundle_generator->is_stale() ) {
			return;
		}

		if ( false === wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) ) {
			wp_schedule_single_event( $this->clock->now() + 300, 'markdown_for_agents_rebuild_bundle' );
		}
	}
```

- [ ] **Step 5: Run the tests, then the suite**

Run: `vendor/bin/phpunit tests/Unit/Generator/BundleGeneratorTest.php tests/Unit/Jobs/`
Expected: PASS.

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Generator/BundleGenerator.php src/Jobs/JobRunner.php tests/Unit/Generator/BundleGeneratorTest.php tests/Unit/Jobs/JobRunnerTest.php
git commit -m "fix: keep bundle staleness marking during a generation job

Only the debounced scheduling is suppressed while a job runs; the tree is
still marked stale, and the job schedules a rebuild on completion if
anything changed after its bundle stage ran."
```

---

## Task 13: Admin AJAX — start a job, poll its status

Replaces three methods on `Admin` (`handle_generate_batch_ajax` `:170`, `handle_generate_taxonomy_batch_ajax` `:236`, `maybe_rebuild_bundle` `:276`) with two: one that starts a job from a scope, one read-only status endpoint for the poller. The nonce action becomes `mfa_generation_job` (the old name described batches that no longer exist); `enqueue_scripts` at `:348-355` is updated to match.

`Admin`'s constructor gains two **optional trailing** parameters so the existing three-argument construction in tests keeps working; the handlers return a 500 if they are absent.

**Files:**
- Modify: `src/Admin/Admin.php`
- Modify: `tests/Unit/Admin/AdminAjaxTest.php`

- [ ] **Step 1: Write the failing test**

In `tests/Unit/Admin/AdminAjaxTest.php`: delete every `handle_generate_batch_ajax` / `handle_generate_taxonomy_batch_ajax` test (they cover retired methods — the behaviour they asserted now lives in `PostTypeStageTest` / `TaxonomyStageTest` / `JobRunnerTest`), update the `@covers` block, and add:

```php
    /** Build an Admin wired to a real GenerationJob and a mocked StageFactory. */
    private function admin_with_job( ?StageFactory $factory = null ): Admin {
        $this->clock   = new FrozenClock( 1_000_000 );
        $this->job     = new GenerationJob( $this->clock );
        $this->factory = $factory ?? $this->createMock( StageFactory::class );

        return new Admin(
            Options::get_defaults(),
            $this->generator,
            $this->taxonomy_generator,
            null,
            null,
            $this->job,
            $this->factory
        );
    }

    public function test_start_job_requires_capability(): void {
        $GLOBALS['_mock_current_user_can'] = false;
        $_POST                             = [ 'nonce' => 'test', 'scope' => 'all' ];

        $this->admin_with_job()->handle_start_generation_job_ajax();

        $this->assertFalse( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 403, $GLOBALS['_mock_json_response']['status'] );
    }

    public function test_start_job_rejects_an_unknown_scope(): void {
        $_POST   = [ 'nonce' => 'test', 'scope' => 'nonsense' ];
        $factory = $this->createMock( StageFactory::class );
        $factory->method( 'build_stage_list' )->willReturn( [] );

        $this->admin_with_job( $factory )->handle_start_generation_job_ajax();

        $this->assertFalse( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 400, $GLOBALS['_mock_json_response']['status'] );
    }

    public function test_start_job_writes_a_running_record_and_returns_it(): void {
        $_POST   = [ 'nonce' => 'test', 'scope' => 'taxonomy' ];
        $factory = $this->createMock( StageFactory::class );
        $factory->expects( $this->once() )
            ->method( 'build_stage_list' )
            ->with( 'taxonomy' )
            ->willReturn( [ [ 'type' => 'taxonomy', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );

        $this->admin_with_job( $factory )->handle_start_generation_job_ajax();

        $this->assertTrue( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 'running', $GLOBALS['_mock_json_response']['data']['status'] );
        $this->assertSame( 'running', $this->job->get()['status'] );
    }

    public function test_starting_a_second_job_returns_409_with_the_live_record(): void {
        $_POST   = [ 'nonce' => 'test', 'scope' => 'taxonomy' ];
        $factory = $this->createMock( StageFactory::class );
        $factory->method( 'build_stage_list' )
            ->willReturn( [ [ 'type' => 'taxonomy', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );

        $admin = $this->admin_with_job( $factory );
        $admin->handle_start_generation_job_ajax();
        $admin->handle_start_generation_job_ajax();

        $this->assertFalse( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 409, $GLOBALS['_mock_json_response']['status'] );
        $this->assertSame( 'running', $GLOBALS['_mock_json_response']['data']['job']['status'] );
    }

    public function test_job_status_returns_the_record_without_the_lock_token(): void {
        $_POST   = [ 'nonce' => 'test', 'scope' => 'taxonomy' ];
        $factory = $this->createMock( StageFactory::class );
        $factory->method( 'build_stage_list' )
            ->willReturn( [ [ 'type' => 'taxonomy', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );

        $admin = $this->admin_with_job( $factory );
        $admin->handle_start_generation_job_ajax();
        $admin->handle_job_status_ajax();

        $data = $GLOBALS['_mock_json_response']['data'];

        $this->assertTrue( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 'running', $data['status'] );
        $this->assertArrayNotHasKey( 'lock_token', $data );
    }

    public function test_job_status_requires_capability(): void {
        $GLOBALS['_mock_current_user_can'] = false;
        $_POST                             = [ 'nonce' => 'test' ];

        $this->admin_with_job()->handle_job_status_ajax();

        $this->assertSame( 403, $GLOBALS['_mock_json_response']['status'] );
    }

    public function test_enqueue_localises_the_new_nonce_action(): void {
        $GLOBALS['_mock_nonces'] = [];

        $this->admin_with_job()->enqueue_scripts( 'settings_page_markdown-for-agents' );

        $localised = $GLOBALS['_mock_localized_scripts']['mfa-bulk-generate']['markdownForAgentsBulkGenerate'];

        $this->assertArrayHasKey( 'nonce', $localised );
        $this->assertArrayHasKey( 'ajaxurl', $localised );
    }
```

Add the matching `use` statements (`GenerationJob`, `StageFactory`, `FrozenClock`) and the `$clock` / `$job` / `$factory` properties. In `setUp()`, add `$GLOBALS['_mock_options'] = [];` and `reset_mock_scheduled_events();` so job state does not leak between tests.

Check how the mock `wp_send_json_success()` / `wp_send_json_error()` record their payload (`grep -n "_mock_json_response" -B 4 -A 8 tests/mocks/wordpress-mocks.php`) and match the assertion shape to it; the shape above (`success`, `data`, `status`) is the expected one but the key names are whatever the mock already uses.

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Admin/AdminAjaxTest.php`
Expected: FAIL — `Call to undefined method …Admin::handle_start_generation_job_ajax()`.

- [ ] **Step 3: Implement**

In `src/Admin/Admin.php`: add the imports (`use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;`, `use Tclp\WpMarkdownForAgents\Jobs\StageFactory;`), add two optional constructor parameters after `$ard_catalog`:

```php
		?ArdCatalog $ard_catalog = null,
		private readonly ?GenerationJob $generation_job = null,
		private readonly ?StageFactory $stage_factory = null,
```

Delete `handle_generate_batch_ajax()`, `handle_generate_taxonomy_batch_ajax()` and `maybe_rebuild_bundle()` in full, and add:

```php
	/**
	 * Start a bulk generation job.
	 *
	 * Hooked to `wp_ajax_mfa_start_generation_job`. Returns immediately — all
	 * work happens in the WP-Cron tick chain, so closing the tab is harmless.
	 *
	 * @since  1.7.0
	 */
	public function handle_start_generation_job_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorised' ), 403 );
			return;
		}

		check_ajax_referer( 'mfa_generation_job', 'nonce' );

		if ( null === $this->generation_job || null === $this->stage_factory ) {
			wp_send_json_error( array( 'message' => 'Generation queue unavailable.' ), 500 );
			return;
		}

		$scope  = sanitize_text_field( (string) ( $_POST['scope'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stages = $this->stage_factory->build_stage_list( $scope );

		if ( empty( $stages ) ) {
			wp_send_json_error( array( 'message' => 'Nothing to generate for that scope.' ), 400 );
			return;
		}

		$result = $this->generation_job->start( $stages );

		if ( ! $result['ok'] ) {
			// 409: a job is already live. The UI switches to that job's progress
			// rather than showing an error.
			wp_send_json_error(
				array(
					'message' => $result['message'],
					'job'     => $this->public_job_record(),
				),
				409
			);
			return;
		}

		wp_send_json_success( $this->public_job_record() );
	}

	/**
	 * Return the current job record for polling.
	 *
	 * Hooked to `wp_ajax_mfa_job_status`. Read-only.
	 *
	 * @since  1.7.0
	 */
	public function handle_job_status_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorised' ), 403 );
			return;
		}

		check_ajax_referer( 'mfa_generation_job', 'nonce' );

		if ( null === $this->generation_job ) {
			wp_send_json_error( array( 'message' => 'Generation queue unavailable.' ), 500 );
			return;
		}

		wp_send_json_success( $this->public_job_record() );
	}

	/**
	 * The job record minus its internal lock token.
	 *
	 * @since  1.7.0
	 * @return array<string, mixed>
	 */
	private function public_job_record(): array {
		$record = null !== $this->generation_job ? $this->generation_job->get() : array();

		unset( $record['lock_token'] );

		return $record;
	}
```

Then update `enqueue_scripts()` (`:352`) to `'nonce' => wp_create_nonce( 'mfa_generation_job' ),`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/Admin/`
Expected: PASS.

Run: `composer test`
Expected: FAIL only in `tests/Unit/Core/` if a test asserts the retired hook registrations — that is Task 14's job. Note which failures you see and move on.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/Admin.php tests/Unit/Admin/AdminAjaxTest.php
git commit -m "feat: replace batch AJAX handlers with job start and status

Two endpoints — mfa_start_generation_job (scope in, job record out) and
mfa_job_status (read-only polling) — replace the two batch handlers and the
synchronous bundle rebuild that ran on the final batch's request."
```

---

## Task 14: wiring and deactivation

`src/Core/Plugin.php` is the only place that knows what runs when; read its existing comments before editing. Note the ordering constraint already documented there: the meta-box save at priority 5 must precede `Generator::on_save_post` at 10.

The watchdog is registered on `init` rather than activation so it self-heals on sites that were already running the plugin when it was updated.

**Files:**
- Modify: `src/Core/Plugin.php` (`define_generator()` ~`:144-158`, `define_admin_hooks()` `:190-218`)
- Modify: `src/Core/Deactivator.php`
- Create: `tests/Unit/Core/DeactivatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Deactivator;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;

/**
 * @covers \Tclp\WpMarkdownForAgents\Core\Deactivator
 */
class DeactivatorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_mock_options'] = [];
        reset_mock_scheduled_events();
    }

    public function test_deactivation_clears_job_state_and_cron_events(): void {
        update_option( GenerationJob::OPTION, [ 'status' => 'running', 'last_tick_at' => time() ] );
        update_option( TickMutex::OPTION, [ 'token' => 't', 'acquired_at' => time() ] );
        wp_schedule_single_event( time() + 1, JobRunner::TICK_HOOK );
        wp_schedule_event( time() + 3600, 'hourly', JobRunner::WATCHDOG_HOOK );

        Deactivator::deactivate();

        $this->assertFalse( get_option( GenerationJob::OPTION ) );
        $this->assertFalse( get_option( TickMutex::OPTION ) );
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertFalse( wp_next_scheduled( JobRunner::WATCHDOG_HOOK ) );
    }

    public function test_deactivation_is_safe_with_no_job_state(): void {
        Deactivator::deactivate();

        $this->assertFalse( get_option( GenerationJob::OPTION ) );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/Unit/Core/DeactivatorTest.php`
Expected: FAIL — the job option survives deactivation.

- [ ] **Step 3: Update `Deactivator`**

`src/Core/Deactivator.php`:

```php
	public static function deactivate(): void {
		// A half-finished job must not survive deactivation: its cron events
		// would be orphaned and its tick lock would block the next run.
		delete_option( GenerationJob::OPTION );
		delete_option( TickMutex::OPTION );
		wp_unschedule_hook( JobRunner::TICK_HOOK );
		wp_unschedule_hook( JobRunner::WATCHDOG_HOOK );

		flush_rewrite_rules();
	}
```

with `use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;`, `use Tclp\WpMarkdownForAgents\Jobs\JobRunner;`, `use Tclp\WpMarkdownForAgents\Jobs\TickMutex;` added.

- [ ] **Step 4: Wire the queue in `Plugin.php`**

Add the imports (`Jobs\GenerationJob`, `Jobs\JobRunner`, `Jobs\StageFactory`, `Jobs\SystemClock`, `Core\NeedsRegenTracker`) and build the queue at the end of `define_generator()`, after `$bundle_generator` exists:

```php
		global $wpdb;

		$clock          = new SystemClock();
		$generation_job = new GenerationJob( $clock );
		$stage_factory  = new StageFactory( $wpdb, $options, $generator, $taxonomy_generator, $bundle_generator );
		$job_runner     = new JobRunner(
			$generation_job,
			new TickMutex( $clock ),
			$stage_factory,
			new NeedsRegenTracker(),
			$clock,
			$bundle_generator
		);

		$this->generation_job = $generation_job;
		$this->stage_factory  = $stage_factory;

		// The cron chain. Registered unconditionally: a job started in wp-admin
		// is processed by whatever request happens to spawn cron next.
		$this->loader->add_action( JobRunner::TICK_HOOK, $job_runner, 'run_tick' );
		$this->loader->add_action( JobRunner::WATCHDOG_HOOK, $job_runner, 'watchdog' );

		// Hourly backstop for a chain that lost its event. Registered here
		// rather than on activation so existing installs pick it up on update.
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( JobRunner::WATCHDOG_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', JobRunner::WATCHDOG_HOOK );
				}
			}
		);

		$this->job_runner = $job_runner;
```

Add the matching private properties beside the existing ones at the bottom of the class:

```php
	private GenerationJob $generation_job;
	private StageFactory $stage_factory;
	private JobRunner $job_runner;
```

In `define_admin_hooks()`: pass the two new collaborators when constructing `Admin`:

```php
		$admin = new Admin( $options, $this->generator, $this->taxonomy_generator, $this->bundle_generator, $ard_catalog, $this->generation_job, $this->stage_factory );
```

and inside the `is_admin()` block, replace the two retired AJAX registrations (`:209-210`) with:

```php
		$this->loader->add_action( 'wp_ajax_mfa_start_generation_job', $admin, 'handle_start_generation_job_ajax' );
		$this->loader->add_action( 'wp_ajax_mfa_job_status', $admin, 'handle_job_status_ajax' );
		// Same-process fallback when a running job has no pending cron event.
		$this->loader->add_action( 'admin_init', $this->job_runner, 'maybe_nudge' );
```

- [ ] **Step 5: Run everything**

Run: `composer test`
Expected: PASS.

Run: `composer phpcs`
Expected: no errors. Fix anything reported with `composer phpcbf` first, then by hand.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Plugin.php src/Core/Deactivator.php tests/Unit/Core/DeactivatorTest.php
git commit -m "feat: wire the generation queue and clean up on deactivation"
```

---

## Task 15: SettingsPage buttons and the rewritten JS

The browser stops driving batches. It POSTs a scope, then polls. Three behaviours the old loop did not have:

- **Reconnect on load.** The page polls once on load, so a job started before the tab was closed shows its live progress.
- **Skipped counts shown.** With the processed-vs-total stop condition gone, a finished run legitimately ends with `processed < total`; without an explicit skipped figure that reads as a silent failure.
- **Poll failures are not job failures.** Three consecutive failed polls show "lost contact — reload", not "generation failed". A long run can outlive the AJAX nonce (12–24h) while the job itself is perfectly healthy.

**Files:**
- Modify: `src/Admin/SettingsPage.php:415-448` (`render_generate_buttons()`)
- Rewrite: `assets/js/bulk-generate.js`

- [ ] **Step 1: Update the button markup**

In `render_generate_buttons()`, keep the copy and layout, and change the three buttons to carry a scope:

```php
			<button type="button" class="button button-primary" data-mfa-scope="all">
```

```php
				<button type="button" class="button button-secondary" data-mfa-scope="post_type:<?php echo esc_attr( $post_type ); ?>">
```

```php
			<button type="button" class="button button-secondary" data-mfa-scope="taxonomy">
```

Then add the progress container immediately before the final `<?php` of the method:

```php
		<div id="mfa-job-progress" class="mfa-job-progress" aria-live="polite"></div>
```

Remove the now-meaningless `data-post-type` / `data-action` / `data-generate-all` attributes as you go — the JS keys off `data-mfa-scope` alone.

If `tests/Unit/Admin/SettingsPageTest.php` asserts on the old attributes, update those assertions to the new ones in this task.

- [ ] **Step 2: Rewrite the JS**

Replace `assets/js/bulk-generate.js` entirely:

```javascript
/* global markdownForAgentsBulkGenerate */
/* WordPress admin bulk-generation control.
 *
 * The browser no longer drives batches. Clicking a button POSTs a scope to
 * start a server-side WP-Cron job, then this polls a read-only status endpoint
 * until the job reports done or failed. Closing the tab does not stop the job,
 * and reloading the page reconnects to whatever is running.
 */
(function () {
    'use strict';

    var POLL_INTERVAL   = 5000;
    var MAX_POLL_ERRORS = 3;

    var pollTimer  = null;
    var pollErrors = 0;

    function container() {
        return document.getElementById('mfa-job-progress');
    }

    function post(action, extra, onSuccess, onError) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', markdownForAgentsBulkGenerate.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            var response = null;

            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                response = null;
            }

            if (response && response.success) {
                onSuccess(response.data || {}, xhr.status);
                return;
            }

            onError(response && response.data ? response.data : {}, xhr.status);
        };

        xhr.onerror = function () {
            onError({}, 0);
        };

        var params = 'action=' + encodeURIComponent(action)
            + '&nonce=' + encodeURIComponent(markdownForAgentsBulkGenerate.nonce);

        Object.keys(extra || {}).forEach(function (key) {
            params += '&' + key + '=' + encodeURIComponent(extra[key]);
        });

        xhr.send(params);
    }

    function setButtonsDisabled(disabled) {
        document.querySelectorAll('button[data-mfa-scope]').forEach(function (button) {
            button.disabled = disabled;
        });
    }

    function stageLabel(stage) {
        if ('post_type' === stage.type) {
            return 'Post type: ' + stage.slug;
        }
        if ('taxonomy' === stage.type) {
            return 'Taxonomy archives';
        }
        if ('bundle' === stage.type) {
            return 'Export bundle';
        }
        return stage.type;
    }

    function stageLine(stage, index, currentIndex) {
        var total  = (null === stage.total || undefined === stage.total) ? '…' : stage.total;
        var parts  = [stage.processed + ' / ' + total + ' processed'];
        var marker = '';

        if (parseInt(stage.skipped, 10) > 0) {
            parts.push(stage.skipped + ' skipped');
        }
        if (parseInt(stage.error_count, 10) > 0) {
            parts.push(stage.error_count + ' error(s)');
        }
        if ('unavailable' === stage.state) {
            parts.push('unavailable — skipped');
        }
        if (index === currentIndex) {
            marker = ' ← running';
        }

        return stageLabel(stage) + ': ' + parts.join(', ') + marker;
    }

    function render(job, notice) {
        var target = container();

        if (!target) {
            return;
        }

        target.textContent = '';

        if (notice) {
            var warning = document.createElement('p');
            warning.className   = 'notice notice-warning';
            warning.textContent = notice;
            target.appendChild(warning);
        }

        if (!job || 'idle' === job.status || !job.stages || !job.stages.length) {
            return;
        }

        var stages  = job.stages;
        var current = Math.min(parseInt(job.stage_index, 10) || 0, stages.length - 1);
        var heading = document.createElement('p');

        if ('running' === job.status) {
            heading.innerHTML = '<strong>Generating…</strong> stage ' + (current + 1) + ' of ' + stages.length
                + ' — this continues on the server, so you can close this tab.';
        } else if ('done' === job.status) {
            heading.innerHTML = '<strong>Generation complete.</strong>';
        } else {
            heading.innerHTML = '<strong>Generation failed.</strong> ' + (job.message || '');
        }

        target.appendChild(heading);

        var list = document.createElement('ul');
        list.style.margin    = '0.5em 0 0 1.5em';
        list.style.listStyle = 'disc';

        stages.forEach(function (stage, index) {
            var item = document.createElement('li');
            item.textContent = stageLine(stage, index, current);
            list.appendChild(item);
        });

        target.appendChild(list);

        var errorCount = parseInt(job.error_count, 10) || 0;

        if (errorCount && job.errors && job.errors.length) {
            var details = document.createElement('details');
            details.className = 'mfa-error-details';

            var summary = document.createElement('summary');
            summary.textContent = 'Show ' + errorCount + ' error' + (1 === errorCount ? '' : 's');
            details.appendChild(summary);

            var errorList = document.createElement('ul');
            errorList.style.margin    = '0.5em 0 0 1.5em';
            errorList.style.listStyle = 'disc';

            if (errorCount > job.errors.length) {
                var capped = document.createElement('li');
                capped.textContent = '+' + (errorCount - job.errors.length) + ' earlier error(s) not shown';
                errorList.appendChild(capped);
            }

            job.errors.forEach(function (error) {
                var id   = error.post_id || error.term_id || '';
                var item = document.createElement('li');
                item.textContent = (id ? '#' + id + ': ' : '') + (error.message || 'Unknown error');
                errorList.appendChild(item);
            });

            details.appendChild(errorList);
            target.appendChild(details);
        }
    }

    function stopPolling() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePoll() {
        stopPolling();
        pollTimer = window.setTimeout(poll, POLL_INTERVAL);
    }

    function handleJob(job) {
        render(job);

        if (job && 'running' === job.status) {
            setButtonsDisabled(true);
            schedulePoll();
            return;
        }

        setButtonsDisabled(false);
        stopPolling();
    }

    function poll() {
        post(
            'mfa_job_status',
            {},
            function (job) {
                pollErrors = 0;
                handleJob(job);
            },
            function () {
                pollErrors += 1;

                if (pollErrors >= MAX_POLL_ERRORS) {
                    // The job itself is very likely still running — a long run
                    // can outlive this page's nonce. Do not report it as failed.
                    stopPolling();
                    setButtonsDisabled(false);
                    render(null, 'Lost contact with the server. Reload this page to see current progress; any running job continues.');
                    return;
                }

                schedulePoll();
            }
        );
    }

    function start(scope) {
        setButtonsDisabled(true);
        render(null, '');

        post(
            'mfa_start_generation_job',
            { scope: scope },
            function (job) {
                pollErrors = 0;
                handleJob(job);
            },
            function (data, status) {
                if (409 === status && data && data.job) {
                    // A job is already running: show that one instead of an error.
                    handleJob(data.job);
                    return;
                }

                setButtonsDisabled(false);
                render(null, (data && data.message) ? data.message : 'Could not start generation.');
            }
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('button[data-mfa-scope]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                start(event.currentTarget.dataset.mfaScope);
            });
        });

        // Reconnect to a job started before this page load.
        poll();
    });
}());
```

- [ ] **Step 3: Verify by hand in ddev**

There is no JS test harness in this project, so this task's verification is manual. In a ddev site with the plugin active and at least a few hundred posts:

```bash
ddev wp option delete markdown_for_agents_job
ddev wp option delete markdown_for_agents_job_tick_lock
```

1. Open **Settings → Markdown for Agents**, click **Generate everything**. The button set disables and a stage list appears within ~5s.
2. Reload the page mid-run — progress reappears (the load-time poll), still counting up.
3. Close the tab, wait a minute, reopen: `ddev wp option get markdown_for_agents_job --format=json` shows an advancing `cursor` / `processed`.
4. Let it finish: status flips to `done`, the buttons re-enable, and `ddev wp markdown-agents status` reports the expected file counts.
5. Click **Generate everything** twice in quick succession — the second click shows the first job's progress, not an error.

Record what you observed in the commit message.

- [ ] **Step 4: Run the suite**

Run: `composer test`
Expected: PASS (`SettingsPageTest` may need the attribute assertions updated — do it here).

- [ ] **Step 5: Commit**

```bash
git add assets/js/bulk-generate.js src/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: replace the browser batch loop with start-and-poll UI

Buttons POST a scope and the page polls job status, reconnecting to a
running job on load. Shows per-stage progress including skipped counts,
and treats repeated poll failures as lost contact rather than a failed job."
```

---

## Task 16: retire the old batch methods

Now that nothing calls them, delete `Generator::generate_batch()` (`Generator.php:186-243`) and `TaxonomyArchiveGenerator::generate_batch()` (`:177-224`) together with the now-unused `get_all_public_terms()` (`:230-249`). Leaving them would leave two offset-paginated whole-site collectors as an attractive nuisance.

Confirm nothing references them before deleting: `grep -rn "generate_batch\|get_all_public_terms" src assets` should return nothing but the definitions themselves.

**Files:**
- Modify: `src/Generator/Generator.php`, `src/Generator/TaxonomyArchiveGenerator.php`
- Modify: `tests/Unit/Generator/GeneratorTest.php:485-580`, `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php:381-440`

- [ ] **Step 1: Delete the tests for the retired methods**

Remove the `generate_batch` test blocks from both files (`GeneratorTest.php:485-580`, `TaxonomyArchiveGeneratorTest.php:381-440`), and remove `generate_batch` from any `@covers` annotations. Their behaviour is now covered by `PostTypeStageTest` and `TaxonomyStageTest`; check each deleted test has a counterpart there before deleting it, and add the counterpart if it does not:

| Old test | New home |
|----------|----------|
| `test_generate_batch_returns_processed_count` | `PostTypeStageTest::test_full_page_reports_not_done_and_advances_cursor` |
| `test_generate_batch_collects_error_and_continues` | `PostTypeStageTest::test_thrown_error_is_collected_and_the_batch_continues` |
| `test_generate_batch_silently_skips_ineligible_post` | `PostTypeStageTest::test_all_skipped_full_page_still_reports_not_done` |
| `test_generate_batch_records_error_when_write_fails` | `PostTypeStageTest::test_eligible_post_that_fails_to_write_is_an_error_not_a_skip` |
| `test_generate_batch_with_zero_limit_returns_empty` | add to `PostTypeStageTest` / `TaxonomyStageTest` if missing (`process_batch( 0, 0 )` returns `done: true`, no query) |
| `test_generate_batch_paginates_correctly` | `TaxonomyStageTest::test_batch_query_is_cursor_paginated_over_term_taxonomy_id` |

- [ ] **Step 2: Run the two suites and watch them fail**

Run: `vendor/bin/phpunit tests/Unit/Generator/GeneratorTest.php tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php`
Expected: PASS (you have only deleted tests). This step's "failure" is the reminder that the production methods are still present — confirm with the `grep` above that nothing but their own definitions references them.

- [ ] **Step 3: Delete the production methods**

Remove `Generator::generate_batch()`, `TaxonomyArchiveGenerator::generate_batch()`, and `TaxonomyArchiveGenerator::get_all_public_terms()`, including docblocks. Leave `generate_all()`, `generate_post()`, `generate_term()`, `write_manifests()` and everything else untouched — `generate_all()` still backs the WP-CLI commands.

- [ ] **Step 4: Run everything**

Run: `composer test`
Expected: PASS.

Run: `composer phpcs`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add src/Generator/Generator.php src/Generator/TaxonomyArchiveGenerator.php tests/Unit/Generator/GeneratorTest.php tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php
git commit -m "refactor: remove the offset-paginated batch methods

Superseded by PostTypeStage and TaxonomyStage. Also drops
get_all_public_terms(), which loaded every term of every public taxonomy
into memory before slicing a batch out of it."
```

---

## Task 17: docs, version bump, and final verification

Per `CLAUDE.md`, the version lives in three places and they are bumped together. This adds one public filter, so `README.md` needs it.

**Files:**
- Modify: `markdown-for-agents.php:7` (header) and `:27` (`MARKDOWN_FOR_AGENTS_VERSION`)
- Modify: `readme.txt:6` (`Stable tag`) and its changelog section
- Modify: `README.md` (filters/actions list)

- [ ] **Step 1: Bump the version to 1.7.0 in all three places**

`markdown-for-agents.php` line 7 (`Version:           1.7.0`) and line 27 (`define( 'MARKDOWN_FOR_AGENTS_VERSION', '1.7.0' );`), and `readme.txt` line 6 (`Stable tag: 1.7.0`).

- [ ] **Step 2: Add the changelog entry**

In `readme.txt`, under `== Changelog ==`, above the 1.6.1 entry:

```
= 1.7.0 =
* Bulk generation now runs as a server-side WP-Cron job: starting a run returns immediately and it continues after you close the tab.
* Fixed an endless run when a post type contained skipped posts — batch completion is now decided by query exhaustion, not by counting processed items.
* Bulk generation no longer slows down as it progresses: pagination uses an ID cursor instead of a growing OFFSET.
* Taxonomy archive generation no longer loads every term of every public taxonomy into memory before starting.
* The export bundle rebuild now runs as its own background tick instead of synchronously on the final batch's request.
* Progress now reports skipped items separately from errors, and a run that loses contact with the browser is no longer reported as failed.
* Added the `markdown_for_agents_tick_budget` filter for the wall-clock seconds one generation tick may spend.
```

- [ ] **Step 3: Document the new filter**

In `README.md`'s filters/actions section, matching the surrounding format:

```markdown
### `markdown_for_agents_tick_budget`

Wall-clock seconds a single bulk-generation cron tick may spend processing
batches. Defaults to 30, or 60% of `max_execution_time` when that is lower.
The budget is checked between batches, never mid-batch, so a single very slow
item can still exceed it.

```php
add_filter( 'markdown_for_agents_tick_budget', fn( int $seconds ): int => 15 );
```
```

- [ ] **Step 4: Full verification**

Run: `composer test`
Expected: PASS, no skipped tests. Paste the summary line into the commit body.

Run: `composer phpcs`
Expected: no errors.

Run: `vendor/bin/phpunit tests/Unit/StaticImportCheckTest.php`
Expected: PASS (guards the Strauss prefixing rule).

Then the manual acceptance pass in ddev, against the spec's success criteria that unit tests cannot reach:

```bash
# 3 — constant batch cost: compare an early and a late tick on a large site
ddev wp option get markdown_for_agents_job --format=json

# 8 — a crashed tick does not wedge future runs
ddev wp option update markdown_for_agents_job_tick_lock '{"token":"stale","acquired_at":1}' --format=json
# start a job from the settings page; it should proceed within one tick

# 11 — watchdog recovery
ddev wp cron event list | grep markdown_for_agents
```

Confirm and record: a job survives closing the tab; a second start attempt is refused with a clear message; a run with excluded posts finishes rather than looping; the needs-regen notice clears after a run.

- [ ] **Step 5: Commit**

```bash
git add markdown-for-agents.php readme.txt README.md
git commit -m "docs: bump to 1.7.0 and document markdown_for_agents_tick_budget"
```

---

## Open follow-ups raised during execution

- **BLOCKING BEFORE MERGE — `TickMutex::acquire()` steal is TOCTOU (Task 9 review, Critical).** The confirm-after-insert step only defends the window inside a single `acquire()` call. Two racers that both read the *same* stale lock can still both end up holding a token: A deletes the stale row, inserts and confirms its own token, and starts ticking; D — whose staleness decision was made from the earlier snapshot — then fires its own **unconditional** `delete_option()`, which removes A's *fresh* row, inserts and confirms `token_D`, and proceeds. Both believe they hold the lock. It bites harder than a bare mutex violation because `GenerationJob`'s `lock_token` does not rotate per tick, so both ticks call `save()` with the same valid token and neither is rejected. Consequence is duplicated work and inflated counters after a crashed tick (files are rewritten identically, so not corruption). Requires a genuinely stale lock plus two overlapping steals — narrow, but exactly the multiple-admin-tabs threat model the class docblock names. Fix before merge: re-read immediately before the delete and abandon the steal unless the stored record is still the same stale identity (token *and* `acquired_at`) that was judged stale, plus a regression test for a rival's fresh lock landing between the staleness read and the delete, plus an honest docblock stating the residual risk. The Options API exposes no atomic compare-and-delete, so a `$wpdb` CAS (`DELETE ... WHERE option_name = %s AND option_value = %s`, checking affected rows) is the only complete fix and is deliberately not being taken: it would need the `wpdb` mock to model the options table and serialisation.
- **BLOCKING BEFORE MERGE — the two staleness thresholds can invert (Task 9 review, Important).** `GenerationJob::STALE_AFTER` is a fixed 600s; `TickMutex::window()` is `max( 300, 2 × max_execution_time )`, which scales with hosting. `STALE_AFTER`'s docblock *asserts* an ordering that nothing enforces. Both timers are reset together by every batch (each batch heartbeats the mutex and `save()`s, stamping `last_tick_at`), so only a single hung batch can expose the ordering — but the exposure differs by direction, and the **default configuration is the unsafe one**: with `window()` 300 and `STALE_AFTER` 600, a batch hanging past 300s lets a second tick steal the lock while the job is still considered live, so it reads the same still-valid `lock_token` and both ticks write. With `window() ≥ STALE_AFTER` the job is declared dead first, the superseding `start()` rotates the token, the old tick's saves are rejected, and the new tick still cannot acquire — progress is discarded but nothing runs twice. Fix before merge: make `window()` return `max( GenerationJob::STALE_AFTER, 2 × max_execution_time )` so the invariant "the mutex never expires before the job is considered dead" is enforced in code rather than asserted in a comment.
- **BLOCKING BEFORE MERGE — an uncaught `process_batch()` throw leaves the job wedged as `running` (Task 10 review).** Only `count_total()` is wrapped in `try/catch` inside `JobRunner::process()`. A throw from `process_batch()` itself propagates out of `run_tick()`: the mutex is released by the `finally`, but the record keeps `status: running` and nothing reschedules, so the chain is dead. This is exactly what the plan specifies, so it is not a Task 10 defect — but once Task 11's hourly watchdog exists, a permanently-throwing batch becomes an hourly retry loop behind a UI that reads "running" forever. Per-item failures are already caught *inside* each stage, so a throw at this level is structural (a `$wpdb` error, say). Fix before merge: wrap the `process_batch()` call in `try/catch ( \Throwable )`, append the message to the job's errors, and set `status: failed` with a message — matching both `count_total()`'s existing treatment and the old AJAX loop's fail-fast UX ("Error — generation stopped"). Must land *after* Tasks 11–12, which edit the same method.

- **Minor, same review:** `Clock`'s docblock claims *every* clock read in `src/Jobs/` goes through the interface, which `GenerationJob::is_running()` deliberately does not — note the exception on `Clock` itself. `GenerationJob::save()`'s docblock should state the caller's obligation on `false` ("stop, schedule nothing") the way `TickMutex` does at its equivalent decision point. And `wp_schedule_single_event()` living inside `start()` is a deliberate exception to Task 11's "scheduling lives in JobRunner" convention — it keeps "flip to running" and "guarantee a tick exists" in one place so no caller can leave a phantom running job — but that trade-off is undocumented, so record it before someone "fixes" it.

- **Object-cache growth inside a time-boxed tick (Task 4 review, Minor).** `PostTypeStage` calls `clean_post_cache()` after each post because that was a known memory-growth path. `TaxonomyStage` has no equivalent, and `TaxonomyArchiveGenerator::generate_term()` reaches `get_term_posts()`, which loads a term's posts in batches of 100 — those post objects stay in the object cache. Previously each AJAX batch was its own request, so growth was bounded by one batch; a time-boxed tick (Task 10) now runs many batches in a single PHP process, so the ceiling is higher. Batches are still bounded by `$limit` and each tick is a fresh process, so this is unlikely to bite, but the asymmetry with `PostTypeStage` is undocumented. Action taken: documented in `TaxonomyStage`'s docblock. If a large site reports tick-level memory growth, the fix is a cache purge inside `get_term_posts()`, not in the stage.
- **`Generator::generate_post()`'s two-reasons-one-bool return (Task 3 review, Important).** Recorded as a wiring constraint in Task 6 above rather than fixed; the structural fix touches CLI, admin single-regen and `save_post`.
- **Loop-body duplication between `PostTypeStage` and `TaxonomyStage` (Task 4 review).** Reviewed and deliberately **not** extracted: the two loops share only their outer shape and diverge in skip semantics, error key, and cache handling, so a shared helper would be parameter-passing scaffolding around two already-short bodies. Revisit only if a third loop-shaped stage appears.

## Deliberately out of scope

- **`src/CLI/Commands.php` reuse.** The spec allows `generate_incremental()` (`:519`) to call `Stage::process_batch()` opportunistically. It is not required, changes CLI behaviour for no user-visible gain, and CLI **must never** touch the job option or the tick mutex (a synchronous CLI run interleaving with a cron chain over the same cursor is the one way to corrupt a run). Leave it alone unless a separate ticket asks for it.
- **`wp markdown-agents job-status` / `job-clear`.** Recovery is covered by `start()`'s staleness check and the watchdog.
- **Mid-run cancel.** Deferred by explicit choice in the spec; it would roughly double the state machine's test surface.
- **`SettingsPage`'s needs-regen flagging** (`:317-321`). Only the *clearing* side moved to `NeedsRegenTracker`; the flag-many-on-settings-save path is unchanged.

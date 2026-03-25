# Stats Scaling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the synchronous per-request DB write with an INSERT-only buffer table, add a 5-minute WP-Cron flush, and add a daily retention/rollup cron that archives old daily stats into monthly summaries.

**Architecture:** Incoming agent requests write a plain row to `wp_mfa_write_buffer` (no contention). A WP-Cron job every 5 minutes aggregates the buffer into the existing `wp_mfa_access_stats` daily table. A second daily cron prunes daily rows older than `retention_days` into `wp_mfa_access_stats_monthly` and cleans up orphaned buffer rows. Both cron jobs use `SELECT ... FOR UPDATE` inside transactions to serialise concurrent cron instances.

**Tech Stack:** PHP 8.0+, WordPress (WP-Cron, Settings API, wpdb), PHPUnit 9, InnoDB MySQL.

**Spec:** `docs/superpowers/specs/2026-03-25-stats-scaling-design.md`

---

## File Map

| Action | File | What changes |
|--------|------|-------------|
| Modify | `src/Stats/StatsRepository.php` | Add 9 new methods: `buffer_access`, `get_buffer_rows_for_update`, `delete_buffer_rows_up_to`, `upsert_daily_count`, `upsert_monthly`, `get_old_daily_rows_for_update`, `delete_daily_rows`, `delete_orphaned_buffer_rows`, `begin_transaction`, `commit`, `rollback`; add 2 static table SQL methods |
| Modify | `src/Stats/AccessLogger.php` | Call `buffer_access()` instead of `record_access()` |
| Create | `src/Stats/BufferFlusher.php` | Drains write buffer in time-budgeted batches |
| Create | `src/Stats/StatsPruner.php` | Prunes old daily rows to monthly, cleans orphaned buffer rows |
| Modify | `src/Core/Activator.php` | Create 2 new tables; schedule cron events |
| Modify | `src/Core/Deactivator.php` | Clear cron events on deactivation |
| Modify | `src/Core/Options.php` | Add `retention_days` default (90) |
| Modify | `src/Core/Plugin.php` | Register custom cron interval; wire flush/prune hooks |
| Modify | `src/Admin/SettingsPage.php` | Add Stats section with `retention_days` field |
| Modify | `src/Stats/StatsPage.php` | Show "records older than N days not shown" notice |
| Modify | `tests/Unit/Stats/AccessLoggerTest.php` | Replace `record_access` assertions with `buffer_access` |
| Create | `tests/Unit/Stats/BufferFlusherTest.php` | Unit tests for BufferFlusher |
| Create | `tests/Unit/Stats/StatsPrunerTest.php` | Unit tests for StatsPruner |

---

## Task 1: Add table SQL methods and new StatsRepository methods

**Files:**
- Modify: `src/Stats/StatsRepository.php`
- Test: *(no new test file needed yet — tests come in later tasks that use mocks)*

### Background

`StatsRepository` is the single data access layer. Everything goes through it. Two new tables need SQL definitions. The flush and prune logic needs 11 new methods plus 2 static helpers. Transaction control (begin/commit/rollback) is added here so `BufferFlusher` and `StatsPruner` only depend on `StatsRepository`, not on `\wpdb` directly.

- [ ] **Step 1: Add table name constants and static SQL methods**

Open `src/Stats/StatsRepository.php`. After `private const TABLE_SUFFIX = 'mfa_access_stats';`, add:

```php
private const WRITE_BUFFER_SUFFIX    = 'mfa_write_buffer';
private const MONTHLY_TABLE_SUFFIX   = 'mfa_access_stats_monthly';
```

After `get_create_table_sql()`, add:

```php
/**
 * Return CREATE TABLE SQL for the write buffer.
 *
 * @since 1.4.0
 */
public static function get_create_write_buffer_sql( \wpdb $wpdb ): string {
    $table   = $wpdb->prefix . self::WRITE_BUFFER_SUFFIX;
    $charset = $wpdb->get_charset_collate();

    return "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        agent varchar(100) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id)
    ) {$charset};";
}

/**
 * Return CREATE TABLE SQL for the monthly rollup table.
 *
 * @since 1.4.0
 */
public static function get_create_monthly_table_sql( \wpdb $wpdb ): string {
    $table   = $wpdb->prefix . self::MONTHLY_TABLE_SUFFIX;
    $charset = $wpdb->get_charset_collate();

    return "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        agent varchar(100) NOT NULL,
        year smallint(4) unsigned NOT NULL,
        month tinyint(2) unsigned NOT NULL,
        count int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY post_agent_year_month (post_id, agent, year, month)
    ) {$charset};";
}
```

- [ ] **Step 2: Add `buffer_access()`**

After `record_access()`, add:

```php
/**
 * Write a raw access event to the write buffer.
 *
 * No upsert — plain INSERT only, so concurrent requests never contend
 * on the same row. Failures are silently swallowed.
 *
 * @since 1.4.0
 * @param int    $post_id The accessed post ID.
 * @param string $agent   Agent identifier.
 */
public function buffer_access( int $post_id, string $agent ): void {
    $table = $this->wpdb->prefix . self::WRITE_BUFFER_SUFFIX;

    $this->wpdb->query(
        $this->wpdb->prepare(
            "INSERT INTO {$table} (post_id, agent, created_at) VALUES (%d, %s, %s)",
            $post_id,
            $agent,
            gmdate( 'Y-m-d H:i:s' )
        )
    );
}
```

- [ ] **Step 3: Add buffer read/delete methods**

```php
/**
 * Read up to $limit rows from the write buffer, locking them for update.
 *
 * Must be called inside an open transaction.
 *
 * @since 1.4.0
 * @param  int $limit Maximum rows to return.
 * @return array<int, object>
 */
public function get_buffer_rows_for_update( int $limit ): array {
    $table = $this->wpdb->prefix . self::WRITE_BUFFER_SUFFIX;

    return $this->wpdb->get_results(
        $this->wpdb->prepare(
            "SELECT id, post_id, agent, created_at FROM {$table} ORDER BY id ASC LIMIT %d FOR UPDATE",
            $limit
        )
    ) ?: array();
}

/**
 * Delete all write buffer rows with id <= $max_id.
 *
 * Safe with AUTO_INCREMENT: rows inserted after the preceding SELECT
 * always receive IDs greater than any existing ID.
 *
 * @since 1.4.0
 * @param  int $max_id Highest ID returned by the preceding SELECT.
 * @return int|false Rows deleted, or false on error.
 */
public function delete_buffer_rows_up_to( int $max_id ): int|false {
    $table = $this->wpdb->prefix . self::WRITE_BUFFER_SUFFIX;

    return $this->wpdb->query(
        $this->wpdb->prepare(
            "DELETE FROM {$table} WHERE id <= %d",
            $max_id
        )
    );
}

/**
 * Delete buffer rows older than the given UTC date string (Y-m-d).
 *
 * Used by StatsPruner to remove orphaned rows from cron-outage periods.
 *
 * @since 1.4.0
 * @param  string $cutoff_date Y-m-d date string.
 * @return void
 */
public function delete_orphaned_buffer_rows( string $cutoff_date ): void {
    $table = $this->wpdb->prefix . self::WRITE_BUFFER_SUFFIX;

    $this->wpdb->query(
        $this->wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff_date
        )
    );
}
```

- [ ] **Step 4: Add `upsert_daily_count()`**

This is the upsert used by `BufferFlusher` — unlike `record_access()` it accepts a specific date and a pre-aggregated count:

```php
/**
 * Upsert an aggregated daily count into wp_mfa_access_stats.
 *
 * Used by BufferFlusher after aggregating a batch from the write buffer.
 *
 * @since 1.4.0
 * @param  int    $post_id     Post ID.
 * @param  string $agent       Agent identifier.
 * @param  string $access_date Y-m-d date string.
 * @param  int    $count       Number of accesses to add.
 * @return int|false Rows affected, or false on error.
 */
public function upsert_daily_count( int $post_id, string $agent, string $access_date, int $count ): int|false {
    $table = self::get_table_name( $this->wpdb );

    return $this->wpdb->query(
        $this->wpdb->prepare(
            "INSERT INTO {$table} (post_id, agent, access_date, `count`)
             VALUES (%d, %s, %s, %d)
             ON DUPLICATE KEY UPDATE `count` = `count` + %d",
            $post_id,
            $agent,
            $access_date,
            $count,
            $count
        )
    );
}
```

- [ ] **Step 5: Add monthly upsert and old daily row methods**

```php
/**
 * Upsert an aggregated count into the monthly rollup table.
 *
 * @since 1.4.0
 * @param  int    $post_id Post ID.
 * @param  string $agent   Agent identifier.
 * @param  int    $year    4-digit year.
 * @param  int    $month   Month 1–12.
 * @param  int    $count   Number of accesses to add.
 * @return int|false Rows affected, or false on error.
 */
public function upsert_monthly( int $post_id, string $agent, int $year, int $month, int $count ): int|false {
    $table = $this->wpdb->prefix . self::MONTHLY_TABLE_SUFFIX;

    return $this->wpdb->query(
        $this->wpdb->prepare(
            "INSERT INTO {$table} (post_id, agent, year, month, count)
             VALUES (%d, %s, %d, %d, %d)
             ON DUPLICATE KEY UPDATE count = count + %d",
            $post_id,
            $agent,
            $year,
            $month,
            $count,
            $count
        )
    );
}

/**
 * Read up to $limit daily rows older than the retention cutoff, locking them.
 *
 * Must be called inside an open transaction.
 *
 * @since 1.4.0
 * @param  int $retention_days Days of daily detail to retain.
 * @param  int $limit          Maximum rows to return.
 * @return array<int, object>
 */
public function get_old_daily_rows_for_update( int $retention_days, int $limit ): array {
    $table = self::get_table_name( $this->wpdb );

    return $this->wpdb->get_results(
        $this->wpdb->prepare(
            "SELECT id, post_id, agent, access_date, `count` FROM {$table}
             WHERE access_date < DATE_SUB( CURDATE(), INTERVAL %d DAY )
             ORDER BY id ASC LIMIT %d FOR UPDATE",
            $retention_days,
            $limit
        )
    ) ?: array();
}

/**
 * Delete daily rows by explicit ID list.
 *
 * @since 1.4.0
 * @param  int[] $ids Row IDs to delete.
 * @return int|false Rows deleted, or false on error.
 */
public function delete_daily_rows( array $ids ): int|false {
    if ( empty( $ids ) ) {
        return 0;
    }

    $table        = self::get_table_name( $this->wpdb );
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

    return $this->wpdb->query(
        $this->wpdb->prepare(
            "DELETE FROM {$table} WHERE id IN ({$placeholders})",
            ...$ids
        )
    );
}
```

- [ ] **Step 6: Add transaction control methods**

```php
/**
 * Open a database transaction.
 *
 * @since 1.4.0
 */
public function begin_transaction(): void {
    $this->wpdb->query( 'START TRANSACTION' );
}

/**
 * Commit the current transaction.
 *
 * @since 1.4.0
 */
public function commit(): void {
    $this->wpdb->query( 'COMMIT' );
}

/**
 * Roll back the current transaction.
 *
 * @since 1.4.0
 */
public function rollback(): void {
    $this->wpdb->query( 'ROLLBACK' );
}
```

- [ ] **Step 7: Run existing tests to make sure nothing broke**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all existing tests pass (no new tests yet).

- [ ] **Step 8: Commit**

```bash
git add src/Stats/StatsRepository.php
git commit -m "feat: add buffer/monthly table SQL and new StatsRepository methods"
```

---

## Task 2: Update database activation

**Files:**
- Modify: `src/Core/Activator.php`

- [ ] **Step 1: Add dbDelta calls for new tables**

In `Activator::activate()`, after the existing `dbDelta( StatsRepository::get_create_table_sql( $wpdb ) );` line, add:

```php
dbDelta( StatsRepository::get_create_write_buffer_sql( $wpdb ) );
dbDelta( StatsRepository::get_create_monthly_table_sql( $wpdb ) );
```

- [ ] **Step 2: Schedule cron events on activation**

Still in `activate()`, after the dbDelta calls, add:

```php
if ( ! wp_next_scheduled( 'wp_mfa_flush_buffer' ) ) {
    wp_schedule_event( time(), 'wp_mfa_five_minutes', 'wp_mfa_flush_buffer' );
}

if ( ! wp_next_scheduled( 'wp_mfa_prune_stats' ) ) {
    wp_schedule_event( time(), 'daily', 'wp_mfa_prune_stats' );
}
```

- [ ] **Step 3: Run tests**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add src/Core/Activator.php
git commit -m "feat: create write buffer and monthly tables on activation; schedule cron events"
```

---

## Task 3: Clear cron events on deactivation

**Files:**
- Modify: `src/Core/Deactivator.php`

- [ ] **Step 1: Read the file**

Open `src/Core/Deactivator.php` and find the `deactivate()` method.

- [ ] **Step 2: Add cron cleanup**

Add to the `deactivate()` method:

```php
wp_clear_scheduled_hook( 'wp_mfa_flush_buffer' );
wp_clear_scheduled_hook( 'wp_mfa_prune_stats' );
```

- [ ] **Step 3: Run tests, commit**

```bash
ddev exec vendor/bin/phpunit
git add src/Core/Deactivator.php
git commit -m "feat: clear buffer flush and prune cron events on plugin deactivation"
```

---

## Task 4: Update AccessLogger to use buffer

**Files:**
- Modify: `src/Stats/AccessLogger.php`
- Modify: `tests/Unit/Stats/AccessLoggerTest.php`

- [ ] **Step 1: Write the failing tests**

Replace the two test methods that assert `record_access` in `tests/Unit/Stats/AccessLoggerTest.php`:

```php
public function test_log_access_calls_buffer_access(): void {
    $this->repository->expects( $this->once() )
        ->method( 'buffer_access' )
        ->with( 42, 'GPTBot' );

    $this->logger->log_access( 42, 'GPTBot' );
}

public function test_log_access_passes_accept_header_agent(): void {
    $this->repository->expects( $this->once() )
        ->method( 'buffer_access' )
        ->with( 10, 'accept-header' );

    $this->logger->log_access( 10, 'accept-header' );
}
```

Also update the two "does nothing" tests to assert `buffer_access` is never called:

```php
public function test_log_access_does_nothing_for_zero_post_id(): void {
    $this->repository->expects( $this->never() )
        ->method( 'buffer_access' );

    $this->logger->log_access( 0, 'GPTBot' );
}

public function test_log_access_does_nothing_for_negative_post_id(): void {
    $this->repository->expects( $this->never() )
        ->method( 'buffer_access' );

    $this->logger->log_access( -1, 'GPTBot' );
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/AccessLoggerTest.php
```

Expected: FAIL — `buffer_access` method not expected to be called / `record_access` still called.

- [ ] **Step 3: Update AccessLogger**

In `src/Stats/AccessLogger.php`, change `log_access()` to call `buffer_access` instead of `record_access`:

```php
public function log_access( int $post_id, string $agent ): void {
    if ( $post_id <= 0 ) {
        return;
    }

    $this->repository->buffer_access( $post_id, mb_substr( $agent, 0, 100 ) );
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/AccessLoggerTest.php
```

Expected: all 4 tests PASS.

- [ ] **Step 5: Run full test suite**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Stats/AccessLogger.php tests/Unit/Stats/AccessLoggerTest.php
git commit -m "feat: route access logging through write buffer instead of direct upsert"
```

---

## Task 5: Add `retention_days` setting

**Files:**
- Modify: `src/Core/Options.php`
- Modify: `src/Admin/SettingsPage.php`

### Background

The settings page uses the WordPress Settings API. `sanitize_options()` is the single point where all values are validated on save. A new "Stats" section is added after the "Agent Detection" section.

- [ ] **Step 1: Add default to Options**

In `src/Core/Options.php`, add to the array returned by `get_defaults()`:

```php
'retention_days' => 90,
```

- [ ] **Step 2: Register the settings section and field**

In `SettingsPage::register()`, after the `add_settings_field` for `wp_mfa_ua_agent_strings`, add:

```php
add_settings_section(
    'wp_mfa_stats',
    __( 'Statistics', 'wp-markdown-for-agents' ),
    '__return_false',
    self::PAGE_SLUG
);

add_settings_field(
    'wp_mfa_retention_days',
    __( 'Retention period (days)', 'wp-markdown-for-agents' ),
    array( $this, 'field_retention_days' ),
    self::PAGE_SLUG,
    'wp_mfa_stats'
);
```

- [ ] **Step 3: Add the field renderer**

Add this public method to `SettingsPage`:

```php
/** @since 1.4.0 */
public function field_retention_days(): void {
    $val = (int) ( $this->options['retention_days'] ?? 90 );
    echo '<input type="number" name="' . esc_attr( Options::OPTION_KEY ) . '[retention_days]" value="' . esc_attr( (string) $val ) . '" min="1" class="small-text">';
    echo '<p class="description">' . esc_html__( 'Keep daily access records for this many days. Older records are archived as monthly totals.', 'wp-markdown-for-agents' ) . '</p>';
}
```

- [ ] **Step 4: Add sanitization**

In `SettingsPage::sanitize_options()`, before the final `return $clean;`, add:

```php
$retention_days          = (int) ( $input['retention_days'] ?? 90 );
$clean['retention_days'] = max( 1, $retention_days );
```

- [ ] **Step 5: Run tests**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Options.php src/Admin/SettingsPage.php
git commit -m "feat: add retention_days setting with default 90 days"
```

---

## Task 6: Implement BufferFlusher

**Files:**
- Create: `src/Stats/BufferFlusher.php`
- Create: `tests/Unit/Stats/BufferFlusherTest.php`

### Background

`BufferFlusher::flush()` reads from the write buffer in batches of 500, aggregates by `(post_id, agent, date)`, upserts into the daily stats table, and deletes the processed buffer rows — all inside a transaction per batch. The `$start_time` parameter is optional (defaults to `microtime(true)`) and is injectable for testing without needing to mock built-in functions.

- [ ] **Step 1: Create the test file with a failing test for the empty-buffer case**

Create `tests/Unit/Stats/BufferFlusherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Stats\BufferFlusher;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\BufferFlusher
 */
class BufferFlusherTest extends TestCase {

    /** @var StatsRepository&MockObject */
    private StatsRepository $repository;
    private BufferFlusher $flusher;

    protected function setUp(): void {
        $this->repository = $this->createMock( StatsRepository::class );
        $this->flusher    = new BufferFlusher( $this->repository );
    }

    public function test_flush_exits_immediately_on_empty_buffer(): void {
        $this->repository->expects( $this->once() )->method( 'begin_transaction' );
        $this->repository->expects( $this->once() )
            ->method( 'get_buffer_rows_for_update' )
            ->willReturn( [] );
        $this->repository->expects( $this->once() )->method( 'commit' );
        $this->repository->expects( $this->never() )->method( 'upsert_daily_count' );
        $this->repository->expects( $this->never() )->method( 'delete_buffer_rows_up_to' );

        $this->flusher->flush();
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/BufferFlusherTest.php
```

Expected: FAIL — `BufferFlusher` class not found.

- [ ] **Step 3: Create BufferFlusher with empty-buffer behaviour**

Create `src/Stats/BufferFlusher.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Drains the write buffer into the daily stats table.
 *
 * Processes rows in batches, uses SELECT ... FOR UPDATE inside transactions
 * to prevent double-processing by concurrent cron instances.
 *
 * @since  1.4.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class BufferFlusher {

    private const BATCH_SIZE           = 500;
    private const TIME_BUDGET_SECONDS  = 20;

    /**
     * @since 1.4.0
     * @param StatsRepository $repository Data access layer.
     */
    public function __construct( private readonly StatsRepository $repository ) {}

    /**
     * Drain the write buffer into wp_mfa_access_stats.
     *
     * Loops until the buffer is empty or the time budget is exceeded.
     * Pass $start_time in tests to control the time budget.
     *
     * @since 1.4.0
     * @param float|null $start_time Unix timestamp when the flush began. Defaults to now.
     */
    public function flush( ?float $start_time = null ): void {
        $started_at = $start_time ?? microtime( true );

        while ( true ) {
            if ( ( microtime( true ) - $started_at ) >= self::TIME_BUDGET_SECONDS ) {
                break;
            }

            $this->repository->begin_transaction();

            $rows = $this->repository->get_buffer_rows_for_update( self::BATCH_SIZE );

            if ( empty( $rows ) ) {
                $this->repository->commit();
                break;
            }

            $max_id = (int) max( array_column( $rows, 'id' ) );
            $groups = $this->aggregate_buffer_rows( $rows );
            $failed = false;

            foreach ( $groups as $group ) {
                $result = $this->repository->upsert_daily_count(
                    $group['post_id'],
                    $group['agent'],
                    $group['date'],
                    $group['count']
                );

                if ( false === $result ) {
                    $failed = true;
                    break;
                }
            }

            if ( $failed ) {
                $this->repository->rollback();
                break;
            }

            if ( false === $this->repository->delete_buffer_rows_up_to( $max_id ) ) {
                $this->repository->rollback();
                break;
            }

            $this->repository->commit();

            if ( count( $rows ) < self::BATCH_SIZE ) {
                break;
            }
        }
    }

    /**
     * Aggregate raw buffer rows by (post_id, agent, date).
     *
     * @param  object[] $rows
     * @return array<string, array{post_id: int, agent: string, date: string, count: int}>
     */
    private function aggregate_buffer_rows( array $rows ): array {
        $groups = array();

        foreach ( $rows as $row ) {
            $date = substr( (string) $row->created_at, 0, 10 );
            $key  = $row->post_id . '|' . $row->agent . '|' . $date;

            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array(
                    'post_id' => (int) $row->post_id,
                    'agent'   => (string) $row->agent,
                    'date'    => $date,
                    'count'   => 0,
                );
            }

            $groups[ $key ]['count']++;
        }

        return $groups;
    }
}
```

- [ ] **Step 4: Run the empty-buffer test**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/BufferFlusherTest.php
```

Expected: 1 test PASS.

- [ ] **Step 5: Write tests for single-batch processing**

Add to `BufferFlusherTest`:

```php
public function test_flush_aggregates_rows_and_upserts_then_deletes(): void {
    $rows = [
        (object) [ 'id' => 1, 'post_id' => 10, 'agent' => 'GPTBot', 'created_at' => '2026-03-25 10:00:00' ],
        (object) [ 'id' => 2, 'post_id' => 10, 'agent' => 'GPTBot', 'created_at' => '2026-03-25 11:00:00' ],
        (object) [ 'id' => 3, 'post_id' => 20, 'agent' => 'ClaudeBot', 'created_at' => '2026-03-25 12:00:00' ],
    ];

    $this->repository->expects( $this->exactly( 2 ) )->method( 'begin_transaction' );

    $this->repository->expects( $this->exactly( 2 ) )
        ->method( 'get_buffer_rows_for_update' )
        ->willReturnOnConsecutiveCalls( $rows, [] );

    // GPTBot on 2026-03-25 = 2 accesses; ClaudeBot on 2026-03-25 = 1.
    $this->repository->expects( $this->exactly( 2 ) )
        ->method( 'upsert_daily_count' )
        ->willReturn( 1 );

    $this->repository->expects( $this->once() )
        ->method( 'delete_buffer_rows_up_to' )
        ->with( 3 ) // max id
        ->willReturn( 3 );

    $this->repository->expects( $this->exactly( 2 ) )->method( 'commit' );
    $this->repository->expects( $this->never() )->method( 'rollback' );

    $this->flusher->flush();
}

public function test_flush_rolls_back_and_stops_on_upsert_failure(): void {
    $rows = [
        (object) [ 'id' => 1, 'post_id' => 10, 'agent' => 'GPTBot', 'created_at' => '2026-03-25 10:00:00' ],
    ];

    $this->repository->method( 'get_buffer_rows_for_update' )->willReturn( $rows );
    $this->repository->method( 'upsert_daily_count' )->willReturn( false );

    $this->repository->expects( $this->once() )->method( 'rollback' );
    $this->repository->expects( $this->never() )->method( 'delete_buffer_rows_up_to' );
    $this->repository->expects( $this->never() )->method( 'commit' );

    $this->flusher->flush();
}

public function test_flush_exits_loop_when_time_budget_exceeded(): void {
    // Pass a start_time 21 seconds in the past — budget is 20s.
    $this->repository->expects( $this->never() )->method( 'begin_transaction' );

    $this->flusher->flush( microtime( true ) - 21.0 );
}

public function test_flush_does_not_delete_rows_beyond_batch_max_id(): void {
    // Rows 1-3 returned; row 4 inserted concurrently after the SELECT.
    // The DELETE WHERE id <= 3 must not touch row 4.
    $rows = [
        (object) [ 'id' => 1, 'post_id' => 10, 'agent' => 'GPTBot', 'created_at' => '2026-03-25 10:00:00' ],
        (object) [ 'id' => 2, 'post_id' => 10, 'agent' => 'GPTBot', 'created_at' => '2026-03-25 10:01:00' ],
        (object) [ 'id' => 3, 'post_id' => 20, 'agent' => 'ClaudeBot', 'created_at' => '2026-03-25 10:02:00' ],
    ];

    $this->repository->expects( $this->exactly( 2 ) )
        ->method( 'get_buffer_rows_for_update' )
        ->willReturnOnConsecutiveCalls( $rows, [] );
    $this->repository->method( 'upsert_daily_count' )->willReturn( 1 );

    $this->repository->expects( $this->once() )
        ->method( 'delete_buffer_rows_up_to' )
        ->with( 3 ); // Must be exactly 3, not 4

    $this->repository->method( 'delete_buffer_rows_up_to' )->willReturn( 3 );

    $this->flusher->flush();
}
```

- [ ] **Step 6: Run all BufferFlusher tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/BufferFlusherTest.php
```

Expected: all 5 tests PASS.

- [ ] **Step 7: Run full suite**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add src/Stats/BufferFlusher.php tests/Unit/Stats/BufferFlusherTest.php
git commit -m "feat: add BufferFlusher with time-budgeted batch drain and transaction safety"
```

---

## Task 7: Implement StatsPruner

**Files:**
- Create: `src/Stats/StatsPruner.php`
- Create: `tests/Unit/Stats/StatsPrunerTest.php`

### Background

`StatsPruner::prune()` reads old daily rows in batches of 500, aggregates by calendar month, upserts into the monthly table, deletes the daily rows — all inside a transaction per batch. After the loop it deletes orphaned write buffer rows. Takes `retention_days` from the options array (falls back to 90 if invalid). The `$start_time` parameter is injectable for testing.

- [ ] **Step 1: Create failing test file**

Create `tests/Unit/Stats/StatsPrunerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Stats\StatsPruner;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\StatsPruner
 */
class StatsPrunerTest extends TestCase {

    /** @var StatsRepository&MockObject */
    private StatsRepository $repository;

    protected function setUp(): void {
        $this->repository = $this->createMock( StatsRepository::class );
    }

    private function make_pruner( array $options = [] ): StatsPruner {
        return new StatsPruner( $this->repository, array_merge( [ 'retention_days' => 90 ], $options ) );
    }

    public function test_prune_exits_immediately_on_empty_result(): void {
        $this->repository->expects( $this->once() )->method( 'begin_transaction' );
        $this->repository->expects( $this->once() )
            ->method( 'get_old_daily_rows_for_update' )
            ->willReturn( [] );
        $this->repository->expects( $this->once() )->method( 'commit' );
        $this->repository->expects( $this->never() )->method( 'upsert_monthly' );
        $this->repository->expects( $this->never() )->method( 'delete_daily_rows' );
        // Orphan cleanup still runs.
        $this->repository->expects( $this->once() )->method( 'delete_orphaned_buffer_rows' );

        $this->make_pruner()->prune();
    }
}
```

- [ ] **Step 2: Run failing test**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/StatsPrunerTest.php
```

Expected: FAIL — `StatsPruner` not found.

- [ ] **Step 3: Create StatsPruner**

Create `src/Stats/StatsPruner.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Prunes old daily stats rows and archives them as monthly summaries.
 *
 * Processes batches of 500 rows within explicit transactions. Uses SELECT
 * FOR UPDATE to prevent concurrent cron instances from double-processing.
 * Also cleans orphaned write-buffer rows from cron-outage periods.
 *
 * @since  1.4.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class StatsPruner {

    private const BATCH_SIZE          = 500;
    private const TIME_BUDGET_SECONDS = 20;

    private int $retention_days;

    /**
     * @since 1.4.0
     * @param StatsRepository      $repository Data access layer.
     * @param array<string, mixed> $options    Plugin options (uses retention_days).
     */
    public function __construct(
        private readonly StatsRepository $repository,
        array $options
    ) {
        $days                 = (int) ( $options['retention_days'] ?? 90 );
        $this->retention_days = $days >= 1 ? $days : 90;
    }

    /**
     * Run the prune cycle.
     *
     * @since 1.4.0
     * @param float|null $start_time Unix timestamp when pruning began. Defaults to now.
     */
    public function prune( ?float $start_time = null ): void {
        $started_at = $start_time ?? microtime( true );

        while ( true ) {
            if ( ( microtime( true ) - $started_at ) >= self::TIME_BUDGET_SECONDS ) {
                break;
            }

            $this->repository->begin_transaction();

            $rows = $this->repository->get_old_daily_rows_for_update(
                $this->retention_days,
                self::BATCH_SIZE
            );

            if ( empty( $rows ) ) {
                $this->repository->commit();
                break;
            }

            $ids    = array_map( fn( object $r ) => (int) $r->id, $rows );
            $groups = $this->aggregate_monthly( $rows );
            $failed = false;

            foreach ( $groups as $group ) {
                $result = $this->repository->upsert_monthly(
                    $group['post_id'],
                    $group['agent'],
                    $group['year'],
                    $group['month'],
                    $group['count']
                );

                if ( false === $result ) {
                    $failed = true;
                    break;
                }
            }

            if ( $failed ) {
                $this->repository->rollback();
                break;
            }

            if ( false === $this->repository->delete_daily_rows( $ids ) ) {
                $this->repository->rollback();
                break;
            }

            $this->repository->commit();

            if ( count( $rows ) < self::BATCH_SIZE ) {
                break;
            }
        }

        // Remove orphaned write-buffer rows from cron-outage periods.
        $cutoff = gmdate( 'Y-m-d', (int) strtotime( "-{$this->retention_days} days" ) );
        $this->repository->delete_orphaned_buffer_rows( $cutoff );
    }

    /**
     * Aggregate daily rows by (post_id, agent, year, month).
     *
     * @param  object[] $rows
     * @return array<string, array{post_id: int, agent: string, year: int, month: int, count: int}>
     */
    private function aggregate_monthly( array $rows ): array {
        $groups = array();

        foreach ( $rows as $row ) {
            $dt    = \DateTime::createFromFormat( 'Y-m-d', (string) $row->access_date );
            $year  = $dt ? (int) $dt->format( 'Y' ) : 1970;
            $month = $dt ? (int) $dt->format( 'n' ) : 1;
            $key   = $row->post_id . '|' . $row->agent . '|' . $year . '|' . $month;

            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array(
                    'post_id' => (int) $row->post_id,
                    'agent'   => (string) $row->agent,
                    'year'    => $year,
                    'month'   => $month,
                    'count'   => 0,
                );
            }

            $groups[ $key ]['count'] += (int) $row->count;
        }

        return $groups;
    }
}
```

- [ ] **Step 4: Run the first test**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/StatsPrunerTest.php
```

Expected: 1 test PASS.

- [ ] **Step 5: Add remaining tests**

Add to `StatsPrunerTest`:

```php
public function test_prune_aggregates_rows_into_monthly_upserts_and_deletes(): void {
    $rows = [
        (object) [ 'id' => 1, 'post_id' => 10, 'agent' => 'GPTBot', 'access_date' => '2025-11-15', 'count' => 5 ],
        (object) [ 'id' => 2, 'post_id' => 10, 'agent' => 'GPTBot', 'access_date' => '2025-11-20', 'count' => 3 ],
        (object) [ 'id' => 3, 'post_id' => 20, 'agent' => 'ClaudeBot', 'access_date' => '2025-12-01', 'count' => 2 ],
    ];

    $this->repository->expects( $this->exactly( 2 ) )
        ->method( 'get_old_daily_rows_for_update' )
        ->willReturnOnConsecutiveCalls( $rows, [] );

    // GPTBot Nov 2025 = 5+3 = 8; ClaudeBot Dec 2025 = 2.
    $this->repository->expects( $this->exactly( 2 ) )
        ->method( 'upsert_monthly' )
        ->willReturn( 1 );

    $this->repository->expects( $this->once() )
        ->method( 'delete_daily_rows' )
        ->with( [ 1, 2, 3 ] )
        ->willReturn( 3 );

    $this->repository->expects( $this->once() )->method( 'delete_orphaned_buffer_rows' );

    $this->make_pruner()->prune();
}

public function test_prune_rolls_back_and_stops_on_upsert_failure(): void {
    $rows = [
        (object) [ 'id' => 1, 'post_id' => 10, 'agent' => 'GPTBot', 'access_date' => '2025-11-01', 'count' => 1 ],
    ];

    $this->repository->method( 'get_old_daily_rows_for_update' )->willReturn( $rows );
    $this->repository->method( 'upsert_monthly' )->willReturn( false );

    $this->repository->expects( $this->once() )->method( 'rollback' );
    $this->repository->expects( $this->never() )->method( 'delete_daily_rows' );
    $this->repository->expects( $this->never() )->method( 'commit' );
    // Orphan cleanup still runs after the loop.
    $this->repository->expects( $this->once() )->method( 'delete_orphaned_buffer_rows' );

    $this->make_pruner()->prune();
}

public function test_prune_uses_retention_days_from_options(): void {
    $pruner = new StatsPruner( $this->repository, [ 'retention_days' => 30 ] );

    $this->repository->expects( $this->once() )
        ->method( 'get_old_daily_rows_for_update' )
        ->with( 30, $this->anything() )
        ->willReturn( [] );
    $this->repository->method( 'delete_orphaned_buffer_rows' );

    $pruner->prune();
}

public function test_prune_falls_back_to_90_days_for_invalid_retention(): void {
    $pruner = new StatsPruner( $this->repository, [ 'retention_days' => 0 ] );

    $this->repository->expects( $this->once() )
        ->method( 'get_old_daily_rows_for_update' )
        ->with( 90, $this->anything() )
        ->willReturn( [] );
    $this->repository->method( 'delete_orphaned_buffer_rows' );

    $pruner->prune();
}

public function test_prune_exits_loop_when_time_budget_exceeded(): void {
    $this->repository->expects( $this->never() )->method( 'begin_transaction' );
    // Orphan cleanup still runs.
    $this->repository->expects( $this->once() )->method( 'delete_orphaned_buffer_rows' );

    $this->make_pruner()->prune( microtime( true ) - 21.0 );
}
```

- [ ] **Step 6: Run all StatsPruner tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/StatsPrunerTest.php
```

Expected: all 6 tests PASS.

- [ ] **Step 7: Run full suite**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add src/Stats/StatsPruner.php tests/Unit/Stats/StatsPrunerTest.php
git commit -m "feat: add StatsPruner with monthly rollup, time budget, and orphan cleanup"
```

---

## Task 8: Wire cron events in Plugin

**Files:**
- Modify: `src/Core/Plugin.php`

### Background

WordPress does not have a built-in 5-minute cron interval. We add one via the `cron_schedules` filter. The `BufferFlusher` and `StatsPruner` cron callbacks need to be registered on every page load (so the hooks are in place when WordPress triggers the events). The scheduling itself (when the event next runs) is set up in `Activator`.

- [ ] **Step 1: Import new classes at the top of Plugin.php**

Add to the `use` block in `src/Core/Plugin.php`:

```php
use Tclp\WpMarkdownForAgents\Stats\BufferFlusher;
use Tclp\WpMarkdownForAgents\Stats\StatsPruner;
```

- [ ] **Step 2: Add custom cron interval and register cron handlers**

In `Plugin::define_hooks()`, add a call to a new private method **before** the `if ( empty( $options['enabled'] ) ) { return; }` guard — cron handlers must be registered on every request regardless of whether the plugin is enabled, so buffered rows written before disabling still get flushed:

```php
$this->define_generator( $options );
$this->define_stats_cron( $options );  // ← before the enabled guard

if ( empty( $options['enabled'] ) ) {
    return;
}
```

Add the method:

```php
/**
 * Register the 5-minute cron schedule and wire flush/prune handlers.
 *
 * Called on every request so handlers are available when WP-Cron fires,
 * even when the plugin is disabled.
 *
 * @since  1.4.0
 * @param  array<string, mixed> $options Plugin options.
 */
private function define_stats_cron( array $options ): void {
    // Add a 5-minute cron interval if not already defined.
    add_filter(
        'cron_schedules',
        static function ( array $schedules ): array {
            if ( ! isset( $schedules['wp_mfa_five_minutes'] ) ) {
                $schedules['wp_mfa_five_minutes'] = array(
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 5 minutes', 'wp-markdown-for-agents' ),
                );
            }

            return $schedules;
        }
    );

    global $wpdb;
    $buffer_flusher = new BufferFlusher( new StatsRepository( $wpdb ) );
    $stats_pruner   = new StatsPruner( new StatsRepository( $wpdb ), $options );

    $this->loader->add_action( 'wp_mfa_flush_buffer', $buffer_flusher, 'flush' );
    $this->loader->add_action( 'wp_mfa_prune_stats',  $stats_pruner,   'prune' );
}
```

- [ ] **Step 3: Run tests**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add src/Core/Plugin.php
git commit -m "feat: register 5-minute cron interval and wire BufferFlusher/StatsPruner hooks"
```

---

## Task 9: Add archival notice to StatsPage

**Files:**
- Modify: `src/Stats/StatsPage.php`

- [ ] **Step 1: Update `StatsPageTest::setUp()` to pass options**

`StatsPage` will gain an `$options` constructor parameter. The existing `setUp()` passes no options, which is fine (the parameter has a default of `[]`). However, the new notice test needs a specific `retention_days` value to make a precise assertion. Update `StatsPageTest::setUp()` to use a shared options property:

In `tests/Unit/Stats/StatsPageTest.php`, add a property and update `setUp()`:

```php
private array $options = [ 'retention_days' => 60 ];

protected function setUp(): void {
    $_GET = [];
    $GLOBALS['_mock_menu_pages']       = [];
    $GLOBALS['_mock_current_user_can'] = true;

    $this->repository = $this->createMock( StatsRepository::class );
    $this->page       = new StatsPage( $this->repository, $this->options );
}
```

- [ ] **Step 2: Inject options into StatsPage**

`StatsPage` currently takes only `StatsRepository`. Update the constructor to also accept options:

In `src/Stats/StatsPage.php`, change the constructor:

```php
/**
 * @since  1.1.0
 * @param  StatsRepository      $repository Stats query layer.
 * @param  array<string, mixed> $options    Plugin options.
 */
public function __construct(
    private readonly StatsRepository $repository,
    private readonly array $options = array()
) {}
```

- [ ] **Step 3: Update Plugin.php to pass options**

In `Plugin::define_admin_hooks()`, change:

```php
$stats_page = new StatsPage( new StatsRepository( $wpdb ) );
```

to:

```php
$stats_page = new StatsPage( new StatsRepository( $wpdb ), $options );
```

- [ ] **Step 4: Write failing test for the archival notice**

The notice must render the text: `"Records older than 60 days are not shown."` (using `retention_days=60` from setUp options). The test asserts the retention number and the fixed phrase `'are not shown'` appear in the output.

Add to `tests/Unit/Stats/StatsPageTest.php`:

```php
public function test_render_page_shows_archival_notice_when_no_date_filter(): void {
    // setUp passes retention_days=60 to StatsPage.
    // Expected notice text: "Records older than 60 days are not shown."
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( '60', $output );
    $this->assertStringContainsString( 'are not shown', $output );
}
```

Run to confirm it fails (constructor not yet updated):

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/StatsPageTest.php --filter test_render_page_shows_archival_notice
```

- [ ] **Step 5: Add the notice to StatsPage**

In `StatsPage::render_page()`, locate the block that renders the preset links (the `<p>` containing "Last 7 days", "Last 30 days" etc.) and add this immediately after it.

The notice text must contain `"are not shown"` to satisfy the test. The canonical copy is: `"Records older than %d days are not shown. Use the date filter to view daily detail within the last %d days."`:

```php
<?php if ( $date_from === '' && $date_to === '' ) : ?>
    <?php
    $retention = max( 1, (int) ( $this->options['retention_days'] ?? 90 ) );
    ?>
    <p class="description">
        <?php
        printf(
            /* translators: %d: number of days */
            esc_html__( 'Records older than %d days are not shown. Use the date filter to view daily detail within the last %d days.', 'wp-markdown-for-agents' ),
            $retention,
            $retention
        );
        ?>
    </p>
<?php endif; ?>
```

- [ ] **Step 6: Run all StatsPage tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/Stats/StatsPageTest.php
```

Expected: all pass including the new notice test.

- [ ] **Step 7: Run full suite**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add src/Stats/StatsPage.php src/Core/Plugin.php tests/Unit/Stats/StatsPageTest.php
git commit -m "feat: show archival notice on stats page when no date filter active"
```

---

## Task 10: Final verification

- [ ] **Step 1: Run the full test suite**

```bash
ddev exec vendor/bin/phpunit
```

Expected: all tests PASS, no failures.

- [ ] **Step 2: Run code style checks**

```bash
ddev exec vendor/bin/phpcs
```

Fix any reported issues before the final commit.

- [ ] **Step 3: Verify new files are autoloaded**

```bash
ddev exec composer dump-autoload
```

- [ ] **Step 4: Manual smoke test on dev site**

1. Visit a post with a known agent UA — confirm no PHP errors.
2. Visit the Stats admin page — confirm the archival notice appears.
3. Check the settings page — confirm "Retention period" field appears under "Statistics".
4. Run the buffer flush manually: `ddev exec wp eval "do_action('wp_mfa_flush_buffer');"` — confirm stats row appears in DB.
5. Check `wp_mfa_write_buffer` is empty after the flush.

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "chore: final verification pass for stats scaling feature"
```

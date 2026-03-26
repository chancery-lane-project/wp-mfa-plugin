# Request Method Stats Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `access_method` as a separate dimension to the stats table so per-agent trends across UA, Accept-header, and query-param requests can be tracked.

**Architecture:** Six sequential tasks — `AgentDetector` first (additive), then `StatsRepository` (schema + queries), then `AccessLogger` and `Negotiator` (signature cascade), then `Migrator` (new class + wiring), then `StatsPage` (UI). Each task is self-contained and committed individually.

**Tech Stack:** PHP 8.1+, PHPUnit, MySQL/wpdb, WordPress plugin conventions.

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `src/Negotiate/AgentDetector.php` | Modify | Add `detect_agent()`, refactor `get_matched_agent()` to delegate |
| `src/Stats/StatsRepository.php` | Modify | Add `DB_VERSION`, `access_method` column, update all queries |
| `src/Stats/AccessLogger.php` | Modify | Add `$access_method` parameter |
| `src/Negotiate/Negotiator.php` | Modify | Use `detect_agent()` for stats label; determine `$access_method` |
| `src/Core/Migrator.php` | Create | Version-check runner: drop index, dbDelta, UPDATE rows, store version |
| `src/Core/Plugin.php` | Modify | Hook `Migrator::maybe_migrate()` before `enabled` guard |
| `src/Core/Activator.php` | Modify | Call `Migrator::maybe_migrate()` instead of bare `dbDelta()` |
| `src/Stats/StatsPage.php` | Modify | Add method column, method filter dropdown, `(unknown)` display |
| `tests/Unit/Negotiate/AgentDetectorTest.php` | Modify | Add `detect_agent()` tests |
| `tests/Unit/Stats/StatsRepositoryTest.php` | Modify | Update signature tests; add `access_method` query tests |
| `tests/Unit/Stats/AccessLoggerTest.php` | Modify | Update all expectations to include `$access_method` |
| `tests/Unit/Negotiate/NegotiatorTest.php` | Modify | Update `log_access` `->with()` expectations; add method tests |
| `tests/Unit/Core/MigratorTest.php` | Create | Test migration logic via mock wpdb |
| `tests/Unit/Stats/StatsPageTest.php` | Modify | Add method column/filter tests |

---

## Task 1: `AgentDetector::detect_agent()`

**Files:**
- Modify: `src/Negotiate/AgentDetector.php`
- Modify: `tests/Unit/Negotiate/AgentDetectorTest.php`

- [ ] **Write the failing tests**

Add to `AgentDetectorTest.php`:

```php
public function test_detect_agent_returns_match_when_ua_force_disabled(): void {
    $detector = $this->make_detector( [ 'ua_force_enabled' => false ] );
    $this->assertSame( 'GPTBot', $detector->detect_agent( 'GPTBot/1.0' ) );
}

public function test_detect_agent_returns_match_when_ua_force_enabled(): void {
    $this->assertSame( 'GPTBot', $this->make_detector()->detect_agent( 'GPTBot/1.0' ) );
}

public function test_detect_agent_returns_null_for_unknown_ua(): void {
    $this->assertNull( $this->make_detector()->detect_agent( 'Mozilla/5.0 Chrome/120' ) );
}

public function test_detect_agent_returns_null_for_empty_ua(): void {
    $this->assertNull( $this->make_detector()->detect_agent( '' ) );
}

public function test_get_matched_agent_still_returns_null_when_disabled(): void {
    $detector = $this->make_detector( [ 'ua_force_enabled' => false ] );
    $this->assertNull( $detector->get_matched_agent( 'GPTBot/1.0' ) );
}
```

- [ ] **Run to verify they fail**

```bash
composer test:unit -- --filter AgentDetectorTest
```

Expected: 5 failures — `detect_agent` method not found.

- [ ] **Implement `detect_agent()` and refactor `get_matched_agent()`**

Replace the body of `AgentDetector.php`:

```php
/**
 * Return the first matching UA substring regardless of ua_force_enabled.
 *
 * Use this for stats labelling. For the serving gate, use get_matched_agent().
 *
 * @since  1.2.0
 * @param  string $ua The HTTP User-Agent header value.
 * @return string|null The matched substring, or null.
 */
public function detect_agent( string $ua ): ?string {
    if ( '' === $ua ) {
        return null;
    }

    $substrings = (array) ( $this->options['ua_agent_strings'] ?? array() );

    foreach ( $substrings as $substring ) {
        if ( '' !== $substring && false !== stripos( $ua, $substring ) ) {
            return $substring;
        }
    }

    return null;
}

/**
 * Return the first matching UA substring, or null if none matches.
 *
 * Returns null when ua_force_enabled is off — this controls whether a UA
 * match alone triggers serving. For stats, use detect_agent() instead.
 *
 * @since  1.1.0
 * @param  string $ua The HTTP User-Agent header value.
 * @return string|null The matched substring, or null.
 */
public function get_matched_agent( string $ua ): ?string {
    if ( empty( $this->options['ua_force_enabled'] ) ) {
        return null;
    }

    return $this->detect_agent( $ua );
}
```

- [ ] **Run to verify they pass**

```bash
composer test:unit -- --filter AgentDetectorTest
```

Expected: all pass.

- [ ] **Commit**

```bash
git add src/Negotiate/AgentDetector.php tests/Unit/Negotiate/AgentDetectorTest.php
git commit -m "feat: add AgentDetector::detect_agent() decoupled from ua_force_enabled"
```

---

## Task 2: `StatsRepository` — schema and query updates

**Files:**
- Modify: `src/Stats/StatsRepository.php`
- Modify: `tests/Unit/Stats/StatsRepositoryTest.php`

- [ ] **Write the failing tests**

Add to `StatsRepositoryTest.php`:

```php
public function test_get_create_table_sql_contains_access_method_column(): void {
    $sql = StatsRepository::get_create_table_sql( $this->wpdb );
    $this->assertStringContainsString( 'access_method', $sql );
}

public function test_get_create_table_sql_unique_index_includes_access_method(): void {
    $sql = StatsRepository::get_create_table_sql( $this->wpdb );
    $this->assertMatchesRegularExpression( '/UNIQUE KEY post_agent_date \(post_id, agent, access_method, access_date\)/', $sql );
}

public function test_record_access_includes_access_method_in_query(): void {
    $this->repo->record_access( 42, 'GPTBot', 'ua' );

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'access_method', $last['query'] );
    $this->assertStringContainsString( 'ua', $last['query'] );
}

public function test_get_distinct_agents_excludes_empty_string_and_old_method_labels(): void {
    $this->repo->get_distinct_agents();

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'NOT IN', $last['query'] );
    $this->assertStringContainsString( "''", $last['query'] );
    $this->assertStringContainsString( 'accept-header', $last['query'] );
    $this->assertStringContainsString( 'query-param', $last['query'] );
}

public function test_get_agent_summary_groups_by_agent_and_access_method(): void {
    $this->wpdb->mock_get_results = [];
    $this->repo->get_agent_summary();

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'GROUP BY agent, access_method', $last['query'] );
    $this->assertStringContainsString( 'access_method', $last['query'] );
}

public function test_get_stats_with_access_method_filter(): void {
    $this->wpdb->mock_get_results = [];
    $this->repo->get_stats( [ 'access_method' => 'ua' ] );

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'access_method', $last['query'] );
    $this->assertStringContainsString( 'ua', $last['query'] );
}

public function test_get_stats_selects_access_method_column(): void {
    $this->wpdb->mock_get_results = [];
    $this->repo->get_stats();

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'access_method', $last['query'] );
}

public function test_db_version_constant_is_defined(): void {
    $this->assertNotEmpty( StatsRepository::DB_VERSION );
}
```

Update the existing `test_record_access_builds_upsert_query` and `test_record_access_includes_post_id_and_agent` to pass a third argument:

```php
public function test_record_access_builds_upsert_query(): void {
    $this->repo->record_access( 42, 'GPTBot', 'ua' );
    // ... rest unchanged
}

public function test_record_access_includes_post_id_and_agent(): void {
    $this->repo->record_access( 99, 'ClaudeBot', 'accept-header' );
    // ... rest unchanged
}
```

- [ ] **Run to verify they fail**

```bash
composer test:unit -- --filter StatsRepositoryTest
```

Expected: failures on new tests; `record_access` calls now fail with wrong arg count.

- [ ] **Update `StatsRepository.php`**

Add the constant after the `TABLE_SUFFIX` constant:

```php
/** DB schema version. Increment when the table structure changes. */
public const DB_VERSION = '1.1';
```

Replace `get_create_table_sql()`:

```php
public static function get_create_table_sql( \wpdb $wpdb ): string {
    $table   = self::get_table_name( $wpdb );
    $charset = $wpdb->get_charset_collate();

    return "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        agent varchar(100) NOT NULL DEFAULT '',
        access_method varchar(20) NOT NULL DEFAULT '',
        access_date date NOT NULL,
        count int unsigned NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY post_agent_date (post_id, agent, access_method, access_date),
        KEY access_date (access_date)
    ) {$charset};";
}
```

Replace `record_access()`:

```php
/**
 * Record a single access — upserts the daily counter.
 *
 * @since  1.1.0
 * @param  int    $post_id       The accessed post ID.
 * @param  string $agent         Agent identity substring, or '' for unknown.
 * @param  string $access_method How the request arrived: 'ua', 'accept-header', or 'query-param'.
 */
public function record_access( int $post_id, string $agent, string $access_method ): void {
    $table = self::get_table_name( $this->wpdb );
    $date  = gmdate( 'Y-m-d' );

    $sql = $this->wpdb->prepare(
        "INSERT INTO {$table} (post_id, agent, access_method, access_date, count)
         VALUES (%d, %s, %s, %s, 1)
         ON DUPLICATE KEY UPDATE count = count + 1",
        $post_id,
        $agent,
        $access_method,
        $date
    );

    $this->wpdb->query( $sql );
}
```

Replace `get_stats()` SELECT line:

```php
$sql = "SELECT post_id, agent, access_method, access_date, count FROM {$table} {$where_sql} ORDER BY access_date DESC LIMIT {$limit} OFFSET {$offset}";
```

Replace `get_distinct_agents()`:

```php
public function get_distinct_agents(): array {
    $table = self::get_table_name( $this->wpdb );
    $sql   = $this->wpdb->prepare(
        "SELECT DISTINCT agent FROM {$table} WHERE agent NOT IN ('', %s, %s) ORDER BY agent ASC",
        'accept-header',
        'query-param'
    );
    $rows  = $this->wpdb->get_results( $sql );

    return array_map( fn( object $row ) => $row->agent, $rows );
}
```

Replace `get_agent_summary()`:

```php
/**
 * Return per-agent-per-method totals for the given filters.
 *
 * @since  1.1.0
 * @param  array<string, mixed> $filters  Supports post_id, agent, access_method, date_from, date_to.
 * @return array<int, object>             Each object has agent (string), access_method (string),
 *                                        total (int), unique_posts (int).
 */
public function get_agent_summary( array $filters = array() ): array {
    $table  = self::get_table_name( $this->wpdb );
    $clause = $this->build_where( $filters );

    $where_sql = $clause['sql'];
    $values    = $clause['values'];

    $sql = "SELECT agent, access_method, SUM(`count`) AS total, COUNT(DISTINCT post_id) AS unique_posts FROM {$table} {$where_sql} GROUP BY agent, access_method ORDER BY total DESC";

    if ( ! empty( $values ) ) {
        $sql = $this->wpdb->prepare( $sql, ...$values );
    }

    return $this->wpdb->get_results( $sql );
}
```

Add `access_method` to `build_where()` after the existing `agent` block:

```php
if ( ! empty( $filters['access_method'] ) ) {
    $where[]  = 'access_method = %s';
    $values[] = (string) $filters['access_method'];
}
```

- [ ] **Run to verify they pass**

```bash
composer test:unit -- --filter StatsRepositoryTest
```

Expected: all pass.

- [ ] **Commit**

```bash
git add src/Stats/StatsRepository.php tests/Unit/Stats/StatsRepositoryTest.php
git commit -m "feat: add access_method column to StatsRepository schema and queries"
```

---

## Task 3: `AccessLogger` — add `$access_method` parameter

**Files:**
- Modify: `src/Stats/AccessLogger.php`
- Modify: `tests/Unit/Stats/AccessLoggerTest.php`

- [ ] **Update tests to include `$access_method`**

Replace the full test file content with updated expectations — all calls to `record_access` now expect three args, and all calls to `log_access` now pass three args:

```php
public function test_log_access_calls_record_access(): void {
    $this->repository->expects( $this->once() )
        ->method( 'record_access' )
        ->with( 42, 'GPTBot', 'ua' );

    $this->logger->log_access( 42, 'GPTBot', 'ua' );
}

public function test_log_access_passes_accept_header_method(): void {
    $this->repository->expects( $this->once() )
        ->method( 'record_access' )
        ->with( 10, '', 'accept-header' );

    $this->logger->log_access( 10, '', 'accept-header' );
}

public function test_log_access_does_nothing_for_zero_post_id(): void {
    $this->repository->expects( $this->never() )
        ->method( 'record_access' );

    $this->logger->log_access( 0, 'GPTBot', 'ua' );
}

public function test_log_access_does_nothing_for_negative_post_id(): void {
    $this->repository->expects( $this->never() )
        ->method( 'record_access' );

    $this->logger->log_access( -1, 'GPTBot', 'ua' );
}

public function test_log_access_truncates_long_agent_string(): void {
    $long_agent = str_repeat( 'a', 150 );

    $this->repository->expects( $this->once() )
        ->method( 'record_access' )
        ->with( 1, str_repeat( 'a', 100 ), 'ua' );

    $this->logger->log_access( 1, $long_agent, 'ua' );
}
```

- [ ] **Run to verify they fail**

```bash
composer test:unit -- --filter AccessLoggerTest
```

Expected: failures — `log_access` has wrong number of parameters.

- [ ] **Update `AccessLogger.php`**

Replace `log_access()`:

```php
/**
 * Record a Markdown access event.
 *
 * @since  1.1.0
 * @param  int    $post_id       The accessed post ID.
 * @param  string $agent         Agent identity substring, or '' for unknown.
 * @param  string $access_method How the request arrived: 'ua', 'accept-header', or 'query-param'.
 */
public function log_access( int $post_id, string $agent, string $access_method ): void {
    if ( $post_id <= 0 ) {
        return;
    }

    $this->repository->record_access( $post_id, mb_substr( $agent, 0, 100 ), $access_method );
}
```

- [ ] **Run to verify they pass**

```bash
composer test:unit -- --filter AccessLoggerTest
```

Expected: all pass.

- [ ] **Commit**

```bash
git add src/Stats/AccessLogger.php tests/Unit/Stats/AccessLoggerTest.php
git commit -m "feat: add access_method parameter to AccessLogger::log_access()"
```

---

## Task 4: `Negotiator` — detection logic and logging

**Files:**
- Modify: `src/Negotiate/Negotiator.php`
- Modify: `tests/Unit/Negotiate/NegotiatorTest.php`

- [ ] **Update existing `log_access` expectations in NegotiatorTest**

Find `test_serves_markdown_via_output_format_md_query_param` and `test_serves_markdown_via_output_format_markdown_query_param` — both have `->with( 1, 'query-param' )` — and update both to:

```php
$this->logger->expects( $this->once() )
    ->method( 'log_access' )
    ->with( 1, '', 'query-param' );
```

- [ ] **Write the new failing tests**

Add after the existing query-param tests:

```php
// -----------------------------------------------------------------------
// maybe_serve_markdown — access_method and agent label (B5)
// -----------------------------------------------------------------------

public function test_log_access_called_with_ua_method_when_ua_only(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );
    $this->logger->expects( $this->once() )
        ->method( 'log_access' )
        ->with( 1, 'GPTBot', 'ua' );

    $neg = $this->make_negotiator( [
        'ua_force_enabled' => true,
        'ua_agent_strings' => [ 'GPTBot' ],
    ] );
    try { $neg->maybe_serve_markdown(); } catch ( \Exception $e ) {}
}

public function test_log_access_called_with_accept_header_method_even_when_ua_matches(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';
    $_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );
    $this->logger->expects( $this->once() )
        ->method( 'log_access' )
        ->with( 1, 'GPTBot', 'accept-header' );

    $neg = $this->make_negotiator( [
        'ua_force_enabled' => true,
        'ua_agent_strings' => [ 'GPTBot' ],
    ] );
    try { $neg->maybe_serve_markdown(); } catch ( \Exception $e ) {}
}

public function test_log_access_called_with_query_param_method_even_when_ua_matches(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';
    $_GET['output_format']           = 'md';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );
    $this->logger->expects( $this->once() )
        ->method( 'log_access' )
        ->with( 1, 'GPTBot', 'query-param' );

    $neg = $this->make_negotiator( [
        'ua_force_enabled' => true,
        'ua_agent_strings' => [ 'GPTBot' ],
    ] );
    try { $neg->maybe_serve_markdown(); } catch ( \Exception $e ) {}
}

public function test_log_access_detects_agent_even_when_ua_force_disabled(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';
    $_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );
    $this->logger->expects( $this->once() )
        ->method( 'log_access' )
        ->with( 1, 'GPTBot', 'accept-header' );

    // ua_force_enabled is off — serving triggered by Accept header, not UA
    $neg = $this->make_negotiator( [
        'ua_force_enabled' => false,
        'ua_agent_strings' => [ 'GPTBot' ],
    ] );
    try { $neg->maybe_serve_markdown(); } catch ( \Exception $e ) {}
}
```

- [ ] **Run to verify they fail**

```bash
composer test:unit -- --filter NegotiatorTest
```

Expected: existing `->with( 1, 'query-param' )` tests fail (wrong arg count), new tests fail.

- [ ] **Update `Negotiator::maybe_serve_markdown()`**

Replace lines 52–60 (the detection block through `$agent_label`):

```php
$matched_agent = $this->agent_detector->get_matched_agent( $ua );  // serving gate
$via_accept    = str_contains( $accept, 'text/markdown' );
$via_query     = in_array( $format_qp, array( 'md', 'markdown' ), true );

if ( ! $via_accept && ! $via_query && null === $matched_agent ) {
    return;
}

// Method precedence: query-param > accept-header > ua
if ( $via_query ) {
    $access_method = 'query-param';
} elseif ( $via_accept ) {
    $access_method = 'accept-header';
} else {
    $access_method = 'ua';
}

// Agent detection for stats: always tries UA match, ignores ua_force_enabled
$agent = $this->agent_detector->detect_agent( $ua ) ?? '';
```

Replace the `log_access` call on line 89:

```php
$this->access_logger->log_access( $post->ID, $agent, $access_method );
```

Remove the old `$agent_label` variable entirely — it is replaced by `$agent` + `$access_method`.

- [ ] **Run to verify they pass**

```bash
composer test:unit -- --filter NegotiatorTest
```

Expected: all pass.

- [ ] **Run full test suite**

```bash
composer test:unit
```

Expected: all pass.

- [ ] **Commit**

```bash
git add src/Negotiate/Negotiator.php tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "feat: split Negotiator agent label into agent identity + access_method"
```

---

## Task 5: `Migrator` — new class and wiring

**Files:**
- Create: `src/Core/Migrator.php`
- Create: `tests/Unit/Core/MigratorTest.php`
- Modify: `src/Core/Plugin.php`
- Modify: `src/Core/Activator.php`

- [ ] **Write the failing tests**

Create `tests/Unit/Core/MigratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Migrator;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * @covers \Tclp\WpMarkdownForAgents\Core\Migrator
 */
class MigratorTest extends TestCase {

    private \wpdb $wpdb;

    protected function setUp(): void {
        $this->wpdb = new \wpdb();
        $GLOBALS['_mock_options']        = [];
        $GLOBALS['_mock_dbdelta_queries'] = [];
    }

    public function test_maybe_migrate_does_nothing_when_version_matches(): void {
        $GLOBALS['_mock_options'][ Migrator::OPTION_KEY ] = StatsRepository::DB_VERSION;

        Migrator::maybe_migrate( $this->wpdb );

        $this->assertEmpty( $this->wpdb->queries );
    }

    public function test_maybe_migrate_calls_dbdelta_when_version_differs(): void {
        Migrator::maybe_migrate( $this->wpdb );

        $this->assertNotEmpty( $GLOBALS['_mock_dbdelta_queries'] );
        $this->assertStringContainsString( 'access_method', $GLOBALS['_mock_dbdelta_queries'][0] );
    }

    public function test_maybe_migrate_drops_old_index_when_present(): void {
        // Simulate old index found
        $this->wpdb->mock_get_var = '1';

        Migrator::maybe_migrate( $this->wpdb );

        $queries = array_column( $this->wpdb->queries, 'query' );
        $drop    = array_filter( $queries, fn( string $q ) => str_contains( $q, 'DROP INDEX' ) );
        $this->assertNotEmpty( $drop );
    }

    public function test_maybe_migrate_skips_drop_when_index_absent(): void {
        $this->wpdb->mock_get_var = '0';

        Migrator::maybe_migrate( $this->wpdb );

        $queries = array_column( $this->wpdb->queries, 'query' );
        $drop    = array_filter( $queries, fn( string $q ) => str_contains( $q, 'DROP INDEX' ) );
        $this->assertEmpty( $drop );
    }

    public function test_maybe_migrate_runs_method_conversion_update(): void {
        Migrator::maybe_migrate( $this->wpdb );

        $queries = implode( ' ', array_column( $this->wpdb->queries, 'query' ) );
        $this->assertStringContainsString( "agent IN ('accept-header', 'query-param')", $queries );
    }

    public function test_maybe_migrate_runs_ua_backfill_update(): void {
        Migrator::maybe_migrate( $this->wpdb );

        $queries = implode( ' ', array_column( $this->wpdb->queries, 'query' ) );
        $this->assertStringContainsString( "SET access_method = 'ua'", $queries );
    }

    public function test_maybe_migrate_updates_stored_version_after_success(): void {
        Migrator::maybe_migrate( $this->wpdb );

        $this->assertSame(
            StatsRepository::DB_VERSION,
            $GLOBALS['_mock_options'][ Migrator::OPTION_KEY ]
        );
    }

    public function test_maybe_migrate_is_idempotent(): void {
        // First run
        Migrator::maybe_migrate( $this->wpdb );
        $query_count = count( $this->wpdb->queries );

        // Second run — version now matches, no queries should be added
        Migrator::maybe_migrate( $this->wpdb );
        $this->assertSame( $query_count, count( $this->wpdb->queries ) );
    }
}
```

- [ ] **Run to verify they fail**

```bash
composer test:unit -- --filter MigratorTest
```

Expected: class not found.

- [ ] **Create `src/Core/Migrator.php`**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * Handles database schema migrations.
 *
 * Compares the stored DB version against StatsRepository::DB_VERSION
 * and runs incremental migrations as needed. Safe to call on every
 * plugins_loaded — returns early if no migration is required.
 *
 * @since  1.2.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class Migrator {

    public const OPTION_KEY = 'wp_mfa_db_version';

    /**
     * Run any pending DB migrations.
     *
     * @since  1.2.0
     * @param  \wpdb $wpdb WordPress database abstraction.
     */
    public static function maybe_migrate( \wpdb $wpdb ): void {
        if ( get_option( self::OPTION_KEY ) === StatsRepository::DB_VERSION ) {
            return;
        }

        $table = StatsRepository::get_table_name( $wpdb );

        // Drop the old 3-column unique index if it exists so dbDelta can
        // create the new 4-column version. dbDelta will not alter an existing
        // index — it only adds indexes whose name is entirely absent.
        $old_index = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE()
                 AND table_name = %s
                 AND index_name = 'post_agent_date'
                 AND seq_in_index = 3
                 AND column_name = 'access_date'",
                $table
            )
        );

        if ( $old_index > 0 ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX post_agent_date" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( StatsRepository::get_create_table_sql( $wpdb ) );

        // Convert old rows where agent stored the method for unknown agents.
        $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "UPDATE {$table} SET access_method = agent, agent = ''
             WHERE agent IN ('accept-header', 'query-param')"
        );

        // Back-fill remaining named-agent rows — these could only have arrived via UA.
        // After column addition with DEFAULT '', un-migrated rows have access_method = ''.
        $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "UPDATE {$table} SET access_method = 'ua'
             WHERE access_method IS NULL OR access_method = ''"
        );

        update_option( self::OPTION_KEY, StatsRepository::DB_VERSION );
    }
}
```

- [ ] **Run to verify they pass**

```bash
composer test:unit -- --filter MigratorTest
```

Expected: all pass.

- [ ] **Wire `Migrator` into `Plugin.php`**

Add the use statement at the top of `Plugin.php`:

```php
use Tclp\WpMarkdownForAgents\Core\Migrator;
```

Add a migration hook in `define_hooks()` **before** the `if ( empty( $options['enabled'] ) ) { return; }` guard, after the i18n block:

```php
// DB migration — must run unconditionally regardless of 'enabled' state.
add_action(
    'plugins_loaded',
    static function (): void {
        global $wpdb;
        Migrator::maybe_migrate( $wpdb );
    }
);
```

- [ ] **Wire `Migrator` into `Activator.php`**

Add the use statement:

```php
use Tclp\WpMarkdownForAgents\Core\Migrator;
```

Replace the `dbDelta` block in `activate()`:

```php
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
Migrator::maybe_migrate( $wpdb );
```

- [ ] **Run full test suite**

```bash
composer test:unit
```

Expected: all pass.

- [ ] **Commit**

```bash
git add src/Core/Migrator.php src/Core/Plugin.php src/Core/Activator.php \
        tests/Unit/Core/MigratorTest.php
git commit -m "feat: add Migrator class for access_method schema migration"
```

---

## Task 6: `StatsPage` — UI updates

**Files:**
- Modify: `src/Stats/StatsPage.php`
- Modify: `tests/Unit/Stats/StatsPageTest.php`

- [ ] **Write the failing tests**

Add to `StatsPageTest.php` (look at the existing `test_render_page_shows_heading` for the pattern — it calls `render_page()` with repository mocked, then captures output with `ob_start()`/`ob_get_clean()`):

```php
public function test_render_page_shows_method_filter_dropdown(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'name="access_method"', $output );
    $this->assertStringContainsString( 'accept-header', $output );
    $this->assertStringContainsString( 'query-param', $output );
}

public function test_render_page_shows_access_method_column_in_results(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [
        (object) [
            'post_id'       => 1,
            'agent'         => 'GPTBot',
            'access_method' => 'ua',
            'access_date'   => '2026-03-26',
            'count'         => 5,
        ],
    ] );
    $this->repository->method( 'get_total_count' )->willReturn( 1 );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'Access Method', $output );
    $this->assertStringContainsString( 'ua', $output );
}

public function test_render_page_displays_unknown_for_empty_agent(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [
        (object) [
            'post_id'       => 1,
            'agent'         => '',
            'access_method' => 'query-param',
            'access_date'   => '2026-03-26',
            'count'         => 3,
        ],
    ] );
    $this->repository->method( 'get_total_count' )->willReturn( 1 );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( '(unknown)', $output );
}

public function test_render_page_shows_method_column_in_summary(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );
    $this->repository->method( 'get_agent_summary' )->willReturn( [
        (object) [
            'agent'         => 'GPTBot',
            'access_method' => 'ua',
            'total'         => 42,
            'unique_posts'  => 3,
        ],
    ] );

    $_GET['date_from'] = '2026-03-01';

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'Method', $output );
    $this->assertStringContainsString( 'ua', $output );
}
```

- [ ] **Run to verify they fail**

```bash
composer test:unit -- --filter StatsPageTest
```

Expected: new tests fail (no method filter dropdown / column in output yet).

- [ ] **Update `StatsPage::render_page()`**

Add `$filter_access_method` input reading after the existing `$filter_agent` line:

```php
$filter_access_method = isset( $_GET['access_method'] ) ? sanitize_key( (string) $_GET['access_method'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
```

Add it to `$count_filters` (used for `get_total_count()` and `get_agent_summary()`) **and** ensure the existing `$filters` merge picks it up (since `$filters` is built from `$count_filters`, this happens automatically via `$filters = $count_filters;`):

```php
if ( '' !== $filter_access_method ) {
    $count_filters['access_method'] = $filter_access_method;
}
```

The `get_stats()` call uses `$filters` (which is `$count_filters` plus `limit`/`offset`), so the method filter propagates to the results table without a separate step.

Add the method filter dropdown in the `<form>` after the agent `<select>`:

```php
<select name="access_method">
    <option value=""><?php esc_html_e( 'All methods', 'wp-markdown-for-agents' ); ?></option>
    <?php foreach ( [ 'ua', 'accept-header', 'query-param' ] as $method ) : ?>
        <option value="<?php echo esc_attr( $method ); ?>" <?php selected( $filter_access_method, $method ); ?>>
            <?php echo esc_html( $method ); ?>
        </option>
    <?php endforeach; ?>
</select>
```

Add `Method` column to the summary table `<thead>` after `Agent`:

```html
<th><?php esc_html_e( 'Method', 'wp-markdown-for-agents' ); ?></th>
```

Add the method value in each summary `<tr>` after the agent `<td>`:

```php
<td><?php echo esc_html( $row->access_method ); ?></td>
```

Update the summary `colspan` on the empty-data row from `"3"` to `"4"`.

Add `Access Method` column to the main results `<thead>` after `Agent`:

```html
<th><?php esc_html_e( 'Access Method', 'wp-markdown-for-agents' ); ?></th>
```

Add the method cell in each main results `<tr>` after the agent `<td>`:

```php
<td><?php echo esc_html( $row->access_method ); ?></td>
```

Replace the agent display in both tables — use a helper inline expression to show `(unknown)` for empty string:

```php
// In summary table:
<td><?php echo esc_html( '' !== $row->agent ? $row->agent : '(unknown)' ); ?></td>

// In main results table:
<td><?php echo esc_html( get_the_title( (int) $row->post_id ) ); ?></td>
<td><?php echo esc_html( '' !== $row->agent ? $row->agent : '(unknown)' ); ?></td>
<td><?php echo esc_html( $row->access_method ); ?></td>
```

- [ ] **Run to verify they pass**

```bash
composer test:unit -- --filter StatsPageTest
```

Expected: all pass.

- [ ] **Run full test suite**

```bash
composer test:unit
```

Expected: all pass.

- [ ] **Commit**

```bash
git add src/Stats/StatsPage.php tests/Unit/Stats/StatsPageTest.php
git commit -m "feat: add access_method column and filter to stats admin page"
```

---

## Verification

- [ ] **Run full test suite one final time**

```bash
composer test:unit
```

Expected: all pass, zero failures.

- [ ] **Run code style checks**

```bash
composer phpcs
```

Expected: no errors.

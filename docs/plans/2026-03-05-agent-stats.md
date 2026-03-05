# Agent Access Statistics Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Track, store, and display daily aggregated statistics for Markdown file access by LLM agents, filterable by post and agent, in a custom database table with a dedicated admin page.

**Architecture:** A `Stats\StatsRepository` handles all DB access (upsert and queries) against a custom table. A thin `Stats\AccessLogger` is called by `Negotiator` before serving. A `Stats\StatsPage` renders a top-level admin page with filters and a paginated table. `AgentDetector` gains `get_matched_agent()` so the negotiator knows _which_ agent matched.

**Tech Stack:** PHP 8.0+, PHPUnit 9.6, WordPress `$wpdb`, `dbDelta()`, custom admin page.

**Prerequisite:** The [UA Detection plan](2026-03-05-ua-detection.md) MUST be fully implemented before starting this plan. This plan assumes `AgentDetector` exists and is injected into `Negotiator`.

---

### Task 1: Add `$wpdb` mock and `add_menu_page` / `dbDelta` stubs

**Files:**
- Modify: `wp-markdown-for-agents/tests/mocks/wordpress-mocks.php`

**Step 1: Add `$wpdb` mock class and WordPress stubs**

Append to `tests/mocks/wordpress-mocks.php`:

```php
// ---------------------------------------------------------------------------
// wpdb mock
// ---------------------------------------------------------------------------

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $charset = 'utf8mb4';

        /** @var list<array{query: string, args: list<mixed>}> */
        public array $queries = [];
        /** @var mixed */
        public $last_result = [];
        /** @var mixed */
        public $mock_get_results = [];
        /** @var mixed */
        public $mock_get_var = null;

        public function prepare(string $query, mixed ...$args): string {
            $this->queries[] = ['query' => $query, 'args' => $args];
            $prepared = $query;
            foreach ($args as $arg) {
                $pos = strpos($prepared, '%');
                if (false !== $pos) {
                    $end = $pos + 2; // skip %d, %s, etc.
                    $prepared = substr($prepared, 0, $pos) . (is_string($arg) ? "'" . $arg . "'" : (string) $arg) . substr($prepared, $end);
                }
            }
            return $prepared;
        }

        public function query(string $query): int|bool {
            $this->queries[] = ['query' => $query, 'args' => []];
            return true;
        }

        public function get_results(string $query, string $output = 'OBJECT'): array {
            $this->queries[] = ['query' => $query, 'args' => []];
            return $this->mock_get_results;
        }

        public function get_var(string $query): mixed {
            $this->queries[] = ['query' => $query, 'args' => []];
            return $this->mock_get_var;
        }

        public function get_charset_collate(): string {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }
}

// ---------------------------------------------------------------------------
// Admin menu stubs
// ---------------------------------------------------------------------------

$GLOBALS['_mock_menu_pages'] = [];

if (!function_exists('add_menu_page')) {
    function add_menu_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback = null, string $icon_url = '', ?int $position = null): string {
        $GLOBALS['_mock_menu_pages'][$menu_slug] = compact('page_title', 'menu_title', 'capability', 'icon_url');
        return 'toplevel_page_' . $menu_slug;
    }
}

// ---------------------------------------------------------------------------
// dbDelta stub
// ---------------------------------------------------------------------------

if (!function_exists('dbDelta')) {
    function dbDelta(string|array $queries = '', bool $execute = true): array {
        $GLOBALS['_mock_dbdelta_queries'] = is_array($queries) ? $queries : [$queries];
        return [];
    }
}

// ---------------------------------------------------------------------------
// absint stub
// ---------------------------------------------------------------------------

if (!function_exists('absint')) {
    function absint(mixed $maybeint): int {
        return abs((int) $maybeint);
    }
}

// ---------------------------------------------------------------------------
// get_the_title stub
// ---------------------------------------------------------------------------

if (!function_exists('get_the_title')) {
    function get_the_title(int|\WP_Post $post = 0): string {
        $id = $post instanceof \WP_Post ? $post->ID : $post;
        return $GLOBALS['_mock_post_titles'][$id] ?? 'Post ' . $id;
    }
}
```

**Step 2: Run full suite to confirm stubs don't break anything**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage
```

Expected: all existing tests pass.

**Step 3: Commit**

```bash
git add wp-markdown-for-agents/tests/mocks/wordpress-mocks.php
git commit -m "test: add wpdb mock, add_menu_page, dbDelta, and helper stubs"
```

---

### Task 2: Create StatsRepository

**Files:**
- Create: `wp-markdown-for-agents/src/Stats/StatsRepository.php`
- Create: `wp-markdown-for-agents/tests/Unit/Stats/StatsRepositoryTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/Stats/StatsRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\StatsRepository
 */
class StatsRepositoryTest extends TestCase {

    private \wpdb $wpdb;
    private StatsRepository $repo;

    protected function setUp(): void {
        $this->wpdb = new \wpdb();
        $this->repo = new StatsRepository( $this->wpdb );
    }

    public function test_get_table_name_uses_prefix(): void {
        $this->assertSame( 'wp_wp_mfa_access_stats', StatsRepository::get_table_name( $this->wpdb ) );
    }

    public function test_get_create_table_sql_contains_columns(): void {
        $sql = StatsRepository::get_create_table_sql( $this->wpdb );
        $this->assertStringContainsString( 'post_id', $sql );
        $this->assertStringContainsString( 'agent', $sql );
        $this->assertStringContainsString( 'access_date', $sql );
        $this->assertStringContainsString( 'count', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY', $sql );
    }

    public function test_record_access_builds_upsert_query(): void {
        $this->repo->record_access( 42, 'GPTBot' );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'INSERT INTO', $last['query'] );
        $this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $last['query'] );
    }

    public function test_record_access_includes_post_id_and_agent(): void {
        $this->repo->record_access( 99, 'ClaudeBot' );

        $queries_str = implode( ' ', array_column( $this->wpdb->queries, 'query' ) );
        $this->assertStringContainsString( '99', $queries_str );
        $this->assertStringContainsString( 'ClaudeBot', $queries_str );
    }

    public function test_get_stats_returns_mock_results(): void {
        $this->wpdb->mock_get_results = [
            (object) [ 'post_id' => 1, 'agent' => 'GPTBot', 'access_date' => '2026-03-05', 'count' => 10 ],
        ];

        $results = $this->repo->get_stats();
        $this->assertCount( 1, $results );
        $this->assertSame( 'GPTBot', $results[0]->agent );
    }

    public function test_get_stats_with_post_id_filter(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_stats( [ 'post_id' => 42 ] );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( '42', $last['query'] );
    }

    public function test_get_stats_with_agent_filter(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_stats( [ 'agent' => 'GPTBot' ] );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'GPTBot', $last['query'] );
    }

    public function test_get_stats_with_limit_and_offset(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_stats( [ 'limit' => 25, 'offset' => 50 ] );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'LIMIT', $last['query'] );
    }

    public function test_get_total_count_returns_integer(): void {
        $this->wpdb->mock_get_var = '5';
        $count = $this->repo->get_total_count();
        $this->assertSame( 5, $count );
    }

    public function test_get_distinct_agents_returns_array(): void {
        $this->wpdb->mock_get_results = [
            (object) [ 'agent' => 'GPTBot' ],
            (object) [ 'agent' => 'ClaudeBot' ],
        ];

        $agents = $this->repo->get_distinct_agents();
        $this->assertSame( [ 'GPTBot', 'ClaudeBot' ], $agents );
    }

    public function test_get_posts_with_stats_returns_id_title_pairs(): void {
        $GLOBALS['_mock_post_titles'] = [ 1 => 'Hello World', 2 => 'Another Post' ];
        $this->wpdb->mock_get_results = [
            (object) [ 'post_id' => 1 ],
            (object) [ 'post_id' => 2 ],
        ];

        $posts = $this->repo->get_posts_with_stats();
        $this->assertSame( [ 1 => 'Hello World', 2 => 'Another Post' ], $posts );
    }
}
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Stats/StatsRepositoryTest.php
```

Expected: error — class not found.

**Step 3: Create `src/Stats/StatsRepository.php`**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Database access layer for agent access statistics.
 *
 * All queries against the custom `wp_mfa_access_stats` table go through
 * this class. Counters are aggregated daily per post + agent combination.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class StatsRepository {

    private const TABLE_SUFFIX = 'wp_mfa_access_stats';

    /**
     * @since  1.1.0
     * @param  \wpdb $wpdb WordPress database abstraction.
     */
    public function __construct( private readonly \wpdb $wpdb ) {}

    /**
     * Return the full table name including prefix.
     *
     * @since  1.1.0
     */
    public static function get_table_name( \wpdb $wpdb ): string {
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Return the CREATE TABLE SQL for use with dbDelta().
     *
     * @since  1.1.0
     */
    public static function get_create_table_sql( \wpdb $wpdb ): string {
        $table   = self::get_table_name( $wpdb );
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            agent varchar(100) NOT NULL,
            access_date date NOT NULL,
            count int unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY post_agent_date (post_id, agent, access_date),
            KEY access_date (access_date)
        ) {$charset};";
    }

    /**
     * Record a single access — upserts the daily counter.
     *
     * @since  1.1.0
     * @param  int    $post_id The accessed post ID.
     * @param  string $agent   The matched UA substring or "accept-header".
     */
    public function record_access( int $post_id, string $agent ): void {
        $table = self::get_table_name( $this->wpdb );
        $date  = gmdate( 'Y-m-d' );

        $sql = $this->wpdb->prepare(
            "INSERT INTO {$table} (post_id, agent, access_date, count)
             VALUES (%d, %s, %s, 1)
             ON DUPLICATE KEY UPDATE count = count + 1",
            $post_id,
            $agent,
            $date
        );

        $this->wpdb->query( $sql );
    }

    /**
     * Query stats rows with optional filters.
     *
     * @since  1.1.0
     * @param  array<string, mixed> $filters Optional: post_id, agent, limit, offset.
     * @return array<int, object>
     */
    public function get_stats( array $filters = [] ): array {
        $table  = self::get_table_name( $this->wpdb );
        $where  = [];
        $values = [];

        if ( ! empty( $filters['post_id'] ) ) {
            $where[]  = 'post_id = %d';
            $values[] = (int) $filters['post_id'];
        }

        if ( ! empty( $filters['agent'] ) ) {
            $where[]  = 'agent = %s';
            $values[] = (string) $filters['agent'];
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $limit     = (int) ( $filters['limit'] ?? 50 );
        $offset    = (int) ( $filters['offset'] ?? 0 );

        $sql = "SELECT post_id, agent, access_date, count FROM {$table} {$where_sql} ORDER BY access_date DESC LIMIT {$limit} OFFSET {$offset}";

        if ( ! empty( $values ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$values );
        }

        return $this->wpdb->get_results( $sql );
    }

    /**
     * Count total rows matching the given filters (for pagination).
     *
     * @since  1.1.0
     * @param  array<string, mixed> $filters Optional: post_id, agent.
     * @return int
     */
    public function get_total_count( array $filters = [] ): int {
        $table  = self::get_table_name( $this->wpdb );
        $where  = [];
        $values = [];

        if ( ! empty( $filters['post_id'] ) ) {
            $where[]  = 'post_id = %d';
            $values[] = (int) $filters['post_id'];
        }

        if ( ! empty( $filters['agent'] ) ) {
            $where[]  = 'agent = %s';
            $values[] = (string) $filters['agent'];
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT COUNT(*) FROM {$table} {$where_sql}";

        if ( ! empty( $values ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$values );
        }

        return (int) $this->wpdb->get_var( $sql );
    }

    /**
     * Return all distinct agent strings that have recorded stats.
     *
     * @since  1.1.0
     * @return string[]
     */
    public function get_distinct_agents(): array {
        $table = self::get_table_name( $this->wpdb );
        $rows  = $this->wpdb->get_results( "SELECT DISTINCT agent FROM {$table} ORDER BY agent ASC" );

        return array_map( fn( object $row ) => $row->agent, $rows );
    }

    /**
     * Return post IDs and titles for posts that have at least one stat row.
     *
     * @since  1.1.0
     * @return array<int, string> Map of post_id => title.
     */
    public function get_posts_with_stats(): array {
        $table = self::get_table_name( $this->wpdb );
        $rows  = $this->wpdb->get_results( "SELECT DISTINCT post_id FROM {$table} ORDER BY post_id ASC" );

        $result = [];
        foreach ( $rows as $row ) {
            $id           = (int) $row->post_id;
            $result[ $id ] = get_the_title( $id );
        }

        return $result;
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Stats/StatsRepositoryTest.php
```

Expected: all pass.

**Step 5: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Stats/StatsRepository.php wp-markdown-for-agents/tests/Unit/Stats/StatsRepositoryTest.php
git commit -m "feat: add StatsRepository for agent access statistics"
```

---

### Task 3: Create AccessLogger

**Files:**
- Create: `wp-markdown-for-agents/src/Stats/AccessLogger.php`
- Create: `wp-markdown-for-agents/tests/Unit/Stats/AccessLoggerTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/Stats/AccessLoggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\AccessLogger
 */
class AccessLoggerTest extends TestCase {

    /** @var StatsRepository&MockObject */
    private StatsRepository $repository;

    private AccessLogger $logger;

    protected function setUp(): void {
        $this->repository = $this->createMock( StatsRepository::class );
        $this->logger     = new AccessLogger( $this->repository );
    }

    public function test_log_access_calls_record_access(): void {
        $this->repository->expects( $this->once() )
            ->method( 'record_access' )
            ->with( 42, 'GPTBot' );

        $this->logger->log_access( 42, 'GPTBot' );
    }

    public function test_log_access_passes_accept_header_agent(): void {
        $this->repository->expects( $this->once() )
            ->method( 'record_access' )
            ->with( 10, 'accept-header' );

        $this->logger->log_access( 10, 'accept-header' );
    }

    public function test_log_access_does_nothing_for_zero_post_id(): void {
        $this->repository->expects( $this->never() )
            ->method( 'record_access' );

        $this->logger->log_access( 0, 'GPTBot' );
    }
}
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Stats/AccessLoggerTest.php
```

Expected: error — class not found.

**Step 3: Create `src/Stats/AccessLogger.php`**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Logs Markdown access events to the stats repository.
 *
 * Called by the Negotiator when serving a Markdown file. Determines
 * the agent identifier and delegates to StatsRepository for storage.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class AccessLogger {

    /**
     * @since  1.1.0
     * @param  StatsRepository $repository Stats storage layer.
     */
    public function __construct( private readonly StatsRepository $repository ) {}

    /**
     * Record a Markdown access event.
     *
     * @since  1.1.0
     * @param  int    $post_id The accessed post ID.
     * @param  string $agent   The matched UA substring or "accept-header".
     */
    public function log_access( int $post_id, string $agent ): void {
        if ( $post_id <= 0 ) {
            return;
        }

        $this->repository->record_access( $post_id, $agent );
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Stats/AccessLoggerTest.php
```

Expected: all pass.

**Step 5: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Stats/AccessLogger.php wp-markdown-for-agents/tests/Unit/Stats/AccessLoggerTest.php
git commit -m "feat: add AccessLogger for recording Markdown access events"
```

---

### Task 4: Add `get_matched_agent()` to AgentDetector

This task modifies the `AgentDetector` created by the UA detection plan.

**Files:**
- Modify: `wp-markdown-for-agents/src/Negotiate/AgentDetector.php`
- Modify: `wp-markdown-for-agents/tests/Unit/Negotiate/AgentDetectorTest.php`

**Step 1: Add failing tests**

Add to `tests/Unit/Negotiate/AgentDetectorTest.php`:

```php
public function test_get_matched_agent_returns_matched_substring(): void {
    $result = $this->make_detector()->get_matched_agent( 'GPTBot/1.0' );
    $this->assertSame( 'GPTBot', $result );
}

public function test_get_matched_agent_returns_null_for_unknown_ua(): void {
    $result = $this->make_detector()->get_matched_agent( 'Mozilla/5.0 Chrome/120' );
    $this->assertNull( $result );
}

public function test_get_matched_agent_is_case_insensitive(): void {
    $result = $this->make_detector()->get_matched_agent( 'gptbot/1.0' );
    $this->assertSame( 'GPTBot', $result );
}

public function test_get_matched_agent_returns_null_when_disabled(): void {
    $detector = $this->make_detector( [ 'ua_force_enabled' => false ] );
    $result   = $detector->get_matched_agent( 'GPTBot/1.0' );
    $this->assertNull( $result );
}

public function test_get_matched_agent_returns_null_for_empty_ua(): void {
    $this->assertNull( $this->make_detector()->get_matched_agent( '' ) );
}
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Negotiate/AgentDetectorTest.php
```

Expected: 5 failures — method does not exist.

**Step 3: Add `get_matched_agent()` and refactor `is_known_agent()`**

In `src/Negotiate/AgentDetector.php`, replace the `is_known_agent()` method body and add `get_matched_agent()`:

```php
/**
 * Return the first matching UA substring, or null if none matches.
 *
 * @since  1.1.0
 * @param  string $ua The HTTP User-Agent header value.
 * @return string|null The matched substring, or null.
 */
public function get_matched_agent( string $ua ): ?string {
    if ( empty( $this->options['ua_force_enabled'] ) ) {
        return null;
    }

    if ( '' === $ua ) {
        return null;
    }

    $substrings = (array) ( $this->options['ua_agent_strings'] ?? [] );

    foreach ( $substrings as $substring ) {
        if ( '' !== $substring && false !== stripos( $ua, $substring ) ) {
            return $substring;
        }
    }

    return null;
}

/**
 * Return true if the given UA string contains a known agent substring.
 *
 * @since  1.1.0
 * @param  string $ua The HTTP User-Agent header value.
 * @return bool
 */
public function is_known_agent( string $ua ): bool {
    return null !== $this->get_matched_agent( $ua );
}
```

**Step 4: Run tests to verify they all pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Negotiate/AgentDetectorTest.php
```

Expected: all pass (existing + new).

**Step 5: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Negotiate/AgentDetector.php wp-markdown-for-agents/tests/Unit/Negotiate/AgentDetectorTest.php
git commit -m "feat: add get_matched_agent() to AgentDetector"
```

---

### Task 5: Integrate AccessLogger into Negotiator

This modifies the `Negotiator` after the UA detection plan's Task 3 changes are in place.

**Files:**
- Modify: `wp-markdown-for-agents/src/Negotiate/Negotiator.php`
- Modify: `wp-markdown-for-agents/tests/Unit/Negotiate/NegotiatorTest.php`

**Step 1: Add failing tests**

Add imports to `NegotiatorTest.php`:

```php
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;
```

Add a `$logger` property and mock setup in `setUp()`:

```php
/** @var AccessLogger&MockObject */
private AccessLogger $logger;
```

In `setUp()`, add:

```php
$this->logger = $this->createMock( AccessLogger::class );
```

Update `make_negotiator()` to pass `AccessLogger` as fourth argument:

```php
private function make_negotiator( array $options = [] ): Negotiator {
    $merged = array_merge( [
        'post_types'       => [ 'post', 'page' ],
        'export_dir'       => 'wp-mfa-exports',
        'ua_force_enabled' => false,
        'ua_agent_strings' => [],
    ], $options );
    return new Negotiator( $merged, $this->generator, new AgentDetector( $merged ), $this->logger );
}
```

Add new tests:

```php
// -----------------------------------------------------------------------
// maybe_serve_markdown — access logging
// -----------------------------------------------------------------------

public function test_log_access_called_with_accept_header_agent(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );

    // The test can't reach readfile/exit, so we verify log_access is called
    // by testing a path where the file exists but is_safe_filepath fails.
    // Instead, we test via a mock expectation on a path that returns early
    // after the log call. Since readfile/exit can't be tested, we test the
    // inverse: log_access should NOT be called when the file doesn't exist.
    $this->logger->expects( $this->never() )->method( 'log_access' );

    $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent.md' );

    $neg = $this->make_negotiator();
    $neg->maybe_serve_markdown();
}

public function test_log_access_not_called_when_file_missing(): void {
    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    $this->generator->method( 'get_export_path' )
        ->willReturn( '/nonexistent/path/post.md' );

    $this->logger->expects( $this->never() )->method( 'log_access' );

    $neg = $this->make_negotiator();
    $neg->maybe_serve_markdown();
}

public function test_log_access_not_called_when_no_match(): void {
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $this->make_post();
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 Chrome/120';

    $this->logger->expects( $this->never() )->method( 'log_access' );

    $neg = $this->make_negotiator( [
        'ua_force_enabled' => true,
        'ua_agent_strings' => [ 'GPTBot' ],
    ] );
    $neg->maybe_serve_markdown();
}
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Negotiate/NegotiatorTest.php
```

Expected: failures — `Negotiator` constructor doesn't accept `AccessLogger` yet.

**Step 3: Update `Negotiator`**

In `src/Negotiate/Negotiator.php`:

1. Add import:
```php
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;
```

2. Update constructor to add fourth parameter:
```php
public function __construct(
    private readonly array $options,
    private readonly Generator $generator,
    private readonly AgentDetector $agent_detector,
    private readonly AccessLogger $access_logger
) {}
```

3. Refactor `maybe_serve_markdown()` to use `get_matched_agent()` and call `log_access()`. Replace the Accept/UA check block and the serve block:

```php
public function maybe_serve_markdown(): void {
    if ( ! $this->is_eligible_singular() ) {
        return;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';          // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

    $matched_agent = $this->agent_detector->get_matched_agent( $ua );
    $via_accept    = str_contains( $accept, 'text/markdown' );

    if ( ! $via_accept && null === $matched_agent ) {
        return;
    }

    $post = get_queried_object();
    if ( ! $post instanceof \WP_Post ) {
        return;
    }

    $filepath = $this->generator->get_export_path( $post );

    if ( ! file_exists( $filepath ) ) {
        return;
    }

    // Validate path stays within export base before serving.
    if ( ! $this->is_safe_filepath( $filepath ) ) {
        return;
    }

    $agent_label = $matched_agent ?? 'accept-header';
    $this->access_logger->log_access( $post->ID, $agent_label );

    header( 'Content-Type: text/markdown; charset=utf-8' );
    header( 'Vary: Accept' );

    readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    exit;
}
```

**Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Negotiate/NegotiatorTest.php
```

Expected: all pass.

**Step 5: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Negotiate/Negotiator.php wp-markdown-for-agents/tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "feat: integrate AccessLogger into Negotiator for stats recording"
```

---

### Task 6: Create StatsPage

**Files:**
- Create: `wp-markdown-for-agents/src/Stats/StatsPage.php`
- Create: `wp-markdown-for-agents/tests/Unit/Stats/StatsPageTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/Stats/StatsPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Stats\StatsPage;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\StatsPage
 */
class StatsPageTest extends TestCase {

    /** @var StatsRepository&MockObject */
    private StatsRepository $repository;

    private StatsPage $page;

    protected function setUp(): void {
        $GLOBALS['_mock_menu_pages']      = [];
        $GLOBALS['_mock_current_user_can'] = true;

        $this->repository = $this->createMock( StatsRepository::class );
        $this->page       = new StatsPage( $this->repository );
    }

    public function test_add_page_registers_menu_page(): void {
        $this->page->add_page();
        $this->assertArrayHasKey( 'wp-mfa-stats', $GLOBALS['_mock_menu_pages'] );
    }

    public function test_add_page_uses_chart_icon(): void {
        $this->page->add_page();
        $this->assertSame( 'dashicons-chart-bar', $GLOBALS['_mock_menu_pages']['wp-mfa-stats']['icon_url'] );
    }

    public function test_render_page_shows_heading(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Agent Access Statistics', $output );
    }

    public function test_render_page_shows_table_rows(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [ 'GPTBot' ] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [ 1 => 'Hello' ] );
        $this->repository->method( 'get_total_count' )->willReturn( 1 );
        $this->repository->method( 'get_stats' )->willReturn( [
            (object) [ 'post_id' => 1, 'agent' => 'GPTBot', 'access_date' => '2026-03-05', 'count' => 10 ],
        ] );

        $GLOBALS['_mock_post_titles'] = [ 1 => 'Hello' ];

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'GPTBot', $output );
        $this->assertStringContainsString( 'Hello', $output );
        $this->assertStringContainsString( '10', $output );
    }

    public function test_render_page_shows_empty_state(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'No access data recorded yet', $output );
    }

    public function test_render_page_returns_early_without_permission(): void {
        $GLOBALS['_mock_current_user_can'] = false;

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }
}
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Stats/StatsPageTest.php
```

Expected: error — class not found.

**Step 3: Create `src/Stats/StatsPage.php`**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Admin page for displaying agent access statistics.
 *
 * Registered as a top-level menu item. Shows a filterable, paginated
 * table of daily access counts by post and agent.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class StatsPage {

    private const PAGE_SLUG   = 'wp-mfa-stats';
    private const PER_PAGE    = 50;

    /**
     * @since  1.1.0
     * @param  StatsRepository $repository Stats query layer.
     */
    public function __construct( private readonly StatsRepository $repository ) {}

    /**
     * Register the admin menu page.
     *
     * @since  1.1.0
     */
    public function add_page(): void {
        add_menu_page(
            __( 'Agent Access Statistics', 'wp-markdown-for-agents' ),
            __( 'Agent Stats', 'wp-markdown-for-agents' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            'dashicons-chart-bar'
        );
    }

    /**
     * Render the stats page.
     *
     * @since  1.1.0
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $filter_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;    // phpcs:ignore WordPress.Security.NonceVerification
        $filter_agent   = isset( $_GET['agent'] ) ? sanitize_file_name( (string) $_GET['agent'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $paged          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;             // phpcs:ignore WordPress.Security.NonceVerification

        $filters = [];
        if ( $filter_post_id > 0 ) {
            $filters['post_id'] = $filter_post_id;
        }
        if ( '' !== $filter_agent ) {
            $filters['agent'] = $filter_agent;
        }

        $filters['limit']  = self::PER_PAGE;
        $filters['offset'] = ( $paged - 1 ) * self::PER_PAGE;

        $rows        = $this->repository->get_stats( $filters );
        $total       = $this->repository->get_total_count( $filters );
        $agents      = $this->repository->get_distinct_agents();
        $posts       = $this->repository->get_posts_with_stats();
        $total_pages = (int) ceil( $total / self::PER_PAGE );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Agent Access Statistics', 'wp-markdown-for-agents' ); ?></h1>

            <form method="get" action="">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                <div class="tablenav top">
                    <select name="post_id">
                        <option value=""><?php esc_html_e( 'All posts', 'wp-markdown-for-agents' ); ?></option>
                        <?php foreach ( $posts as $id => $title ) : ?>
                            <option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $filter_post_id, $id ); ?>>
                                <?php echo esc_html( $title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="agent">
                        <option value=""><?php esc_html_e( 'All agents', 'wp-markdown-for-agents' ); ?></option>
                        <?php foreach ( $agents as $agent ) : ?>
                            <option value="<?php echo esc_attr( $agent ); ?>" <?php selected( $filter_agent, $agent ); ?>>
                                <?php echo esc_html( $agent ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button( __( 'Filter', 'wp-markdown-for-agents' ), 'secondary', 'filter', false ); ?>
                </div>
            </form>

            <?php if ( empty( $rows ) ) : ?>
                <p><?php esc_html_e( 'No access data recorded yet.', 'wp-markdown-for-agents' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Post', 'wp-markdown-for-agents' ); ?></th>
                            <th><?php esc_html_e( 'Agent', 'wp-markdown-for-agents' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'wp-markdown-for-agents' ); ?></th>
                            <th><?php esc_html_e( 'Count', 'wp-markdown-for-agents' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( get_the_title( (int) $row->post_id ) ); ?></td>
                                <td><?php echo esc_html( $row->agent ); ?></td>
                                <td><?php echo esc_html( $row->access_date ); ?></td>
                                <td><?php echo esc_html( (string) $row->count ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                                <?php if ( $i === $paged ) : ?>
                                    <span class="tablenav-pages-navspan button disabled"><?php echo esc_html( (string) $i ); ?></span>
                                <?php else : ?>
                                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"><?php echo esc_html( (string) $i ); ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
```

**Step 4: Add missing WP stubs to `wordpress-mocks.php`**

Append to `tests/mocks/wordpress-mocks.php`:

```php
// ---------------------------------------------------------------------------
// Form helper stubs for StatsPage
// ---------------------------------------------------------------------------

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $echo = true): string {
        $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(string|array $key, mixed $value = null, string $url = ''): string {
        if (is_string($key)) {
            return '?page=wp-mfa-stats&' . $key . '=' . $value;
        }
        return $url;
    }
}
```

**Step 5: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Stats/StatsPageTest.php
```

Expected: all pass.

**Step 6: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 7: Commit**

```bash
git add wp-markdown-for-agents/src/Stats/StatsPage.php wp-markdown-for-agents/tests/Unit/Stats/StatsPageTest.php wp-markdown-for-agents/tests/mocks/wordpress-mocks.php
git commit -m "feat: add StatsPage admin page for agent access statistics"
```

---

### Task 7: Update Activator to create stats table

**Files:**
- Modify: `wp-markdown-for-agents/src/Core/Activator.php`

**Step 1: Update `Activator::activate()`**

Add import at the top:

```php
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;
```

Add table creation at the end of `activate()`:

```php
// Create the access stats table.
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( StatsRepository::get_create_table_sql( $wpdb ) );
```

Note: In the test environment, `ABSPATH` won't exist. Add a guard to `wordpress-mocks.php`:

```php
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}
```

Add this near the top of `wordpress-mocks.php`, after the `declare(strict_types=1);` line.

**Step 2: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 3: Commit**

```bash
git add wp-markdown-for-agents/src/Core/Activator.php wp-markdown-for-agents/tests/mocks/wordpress-mocks.php
git commit -m "feat: create access stats table on plugin activation"
```

---

### Task 8: Wire stats classes into Plugin

**Files:**
- Modify: `wp-markdown-for-agents/src/Core/Plugin.php`

**Step 1: Add imports**

```php
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;
use Tclp\WpMarkdownForAgents\Stats\StatsPage;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;
```

Note: `AgentDetector` import is already added by the UA detection plan.

**Step 2: Update `define_negotiate_hooks()`**

Replace (after UA detection plan changes):
```php
$agent_detector = new AgentDetector( $options );
$negotiator     = new Negotiator( $options, $this->generator, $agent_detector );
```

With:
```php
global $wpdb;
$agent_detector  = new AgentDetector( $options );
$stats_repo      = new StatsRepository( $wpdb );
$access_logger   = new AccessLogger( $stats_repo );
$negotiator      = new Negotiator( $options, $this->generator, $agent_detector, $access_logger );
```

**Step 3: Update `define_admin_hooks()`**

After the existing Admin hook registrations, add:

```php
global $wpdb;
$stats_page = new StatsPage( new StatsRepository( $wpdb ) );
$this->loader->add_action( 'admin_menu', $stats_page, 'add_page' );
```

**Step 4: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 5: Commit**

```bash
git add wp-markdown-for-agents/src/Core/Plugin.php
git commit -m "feat: wire stats classes into Plugin for access tracking and admin page"
```

---

### Task 9: Final verification

**Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

**Step 2: Invoke finishing-a-development-branch skill**

Use `superpowers:finishing-a-development-branch` to merge, push, or discard as appropriate.

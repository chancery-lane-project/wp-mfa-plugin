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

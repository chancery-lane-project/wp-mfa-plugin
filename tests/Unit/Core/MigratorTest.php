<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Migrator;
use Tclp\WpMarkdownForAgents\Core\Options;
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

    // -----------------------------------------------------------------------
    // maybe_sync_ua_defaults
    // -----------------------------------------------------------------------

    private const BASELINE = [
        'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-Web', 'anthropic-ai', 'PerplexityBot',
        'Google-Extended', 'Amazonbot', 'cohere-ai', 'meta-externalagent', 'Bytespider', 'CCBot',
        'Applebot-Extended',
    ];

    private function default_ua_strings(): array {
        return Options::get_defaults()['ua_agent_strings'];
    }

    private function saved_ua_strings(): array {
        return $GLOBALS['_mock_options'][ Options::OPTION_KEY ]['ua_agent_strings'];
    }

    public function test_sync_appends_post_baseline_defaults_to_a_baseline_only_list(): void {
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ] = [ 'ua_agent_strings' => self::BASELINE ];

        Migrator::maybe_sync_ua_defaults();

        $this->assertSame( $this->default_ua_strings(), $this->saved_ua_strings() );
    }

    public function test_sync_keeps_existing_order_and_custom_strings(): void {
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ] = [ 'ua_agent_strings' => [ 'MyCustomBot', 'GPTBot' ] ];

        Migrator::maybe_sync_ua_defaults();

        $saved = $this->saved_ua_strings();
        $this->assertSame( [ 'MyCustomBot', 'GPTBot' ], array_slice( $saved, 0, 2 ) );
        $this->assertContains( 'OAI-SearchBot', $saved );
    }

    public function test_sync_does_not_restore_a_removed_baseline_string(): void {
        $without_bytespider = array_values( array_diff( self::BASELINE, [ 'Bytespider' ] ) );
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ] = [ 'ua_agent_strings' => $without_bytespider ];

        Migrator::maybe_sync_ua_defaults();

        $this->assertNotContains( 'Bytespider', $this->saved_ua_strings() );
    }

    public function test_sync_does_not_restore_a_removed_string_already_seen(): void {
        $GLOBALS['_mock_options'][ Migrator::UA_SEEN_OPTION_KEY ] = $this->default_ua_strings();
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ]          = [
            'ua_agent_strings' => array_values( array_diff( $this->default_ua_strings(), [ 'Devin' ] ) ),
        ];

        Migrator::maybe_sync_ua_defaults();

        $this->assertNotContains( 'Devin', $this->saved_ua_strings() );
    }

    public function test_sync_adds_a_default_not_yet_seen(): void {
        $GLOBALS['_mock_options'][ Migrator::UA_SEEN_OPTION_KEY ] = array_values( array_diff( $this->default_ua_strings(), [ 'Devin' ] ) );
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ]          = [
            'ua_agent_strings' => array_values( array_diff( $this->default_ua_strings(), [ 'Devin' ] ) ),
        ];

        Migrator::maybe_sync_ua_defaults();

        $this->assertContains( 'Devin', $this->saved_ua_strings() );
    }

    public function test_sync_matches_existing_strings_case_insensitively(): void {
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ] = [ 'ua_agent_strings' => array_merge( self::BASELINE, [ 'oai-searchbot' ] ) ];

        Migrator::maybe_sync_ua_defaults();

        $matches = array_filter( $this->saved_ua_strings(), fn( string $s ) => 0 === strcasecmp( $s, 'OAI-SearchBot' ) );
        $this->assertCount( 1, $matches );
    }

    public function test_sync_records_seen_defaults(): void {
        Migrator::maybe_sync_ua_defaults();

        $this->assertSame( $this->default_ua_strings(), $GLOBALS['_mock_options'][ Migrator::UA_SEEN_OPTION_KEY ] );
    }

    public function test_sync_does_not_create_plugin_options_on_a_fresh_install(): void {
        Migrator::maybe_sync_ua_defaults();

        $this->assertArrayNotHasKey( Options::OPTION_KEY, $GLOBALS['_mock_options'] );
    }

    public function test_sync_leaves_saved_options_without_a_ua_list_untouched(): void {
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ] = [ 'auto_generate' => true ];

        Migrator::maybe_sync_ua_defaults();

        $this->assertSame( [ 'auto_generate' => true ], $GLOBALS['_mock_options'][ Options::OPTION_KEY ] );
    }

    public function test_sync_is_idempotent(): void {
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ] = [ 'ua_agent_strings' => self::BASELINE ];

        Migrator::maybe_sync_ua_defaults();
        $after_first = $GLOBALS['_mock_options'];
        Migrator::maybe_sync_ua_defaults();

        $this->assertSame( $after_first, $GLOBALS['_mock_options'] );
    }
}

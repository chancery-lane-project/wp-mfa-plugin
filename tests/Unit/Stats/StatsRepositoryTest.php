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
        $GLOBALS['_mock_post_titles'] = [];
    }

    public function test_get_table_name_uses_prefix(): void {
        $this->assertSame( 'wp_mfa_access_stats', StatsRepository::get_table_name( $this->wpdb ) );
    }

    public function test_get_create_table_sql_contains_columns(): void {
        $sql = StatsRepository::get_create_table_sql( $this->wpdb );
        $this->assertStringContainsString( 'post_id', $sql );
        $this->assertStringContainsString( 'agent', $sql );
        $this->assertStringContainsString( 'access_date', $sql );
        $this->assertStringContainsString( 'count', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY', $sql );
    }

    public function test_get_create_table_sql_contains_access_method_column(): void {
        $sql = StatsRepository::get_create_table_sql( $this->wpdb );
        $this->assertStringContainsString( 'access_method', $sql );
    }

    public function test_get_create_table_sql_unique_index_includes_access_method(): void {
        $sql = StatsRepository::get_create_table_sql( $this->wpdb );
        $this->assertMatchesRegularExpression( '/UNIQUE KEY post_agent_date \(post_id, agent, access_method, access_date\)/', $sql );
    }

    public function test_record_access_builds_upsert_query(): void {
        $this->repo->record_access( 42, 'GPTBot', 'ua' );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'INSERT INTO', $last['query'] );
        $this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $last['query'] );
    }

    public function test_record_access_includes_post_id_and_agent(): void {
        $this->repo->record_access( 99, 'ClaudeBot', 'accept-header' );

        $queries_str = implode( ' ', array_column( $this->wpdb->queries, 'query' ) );
        $this->assertStringContainsString( '99', $queries_str );
        $this->assertStringContainsString( 'ClaudeBot', $queries_str );
    }

    public function test_record_access_includes_access_method_in_query(): void {
        $this->repo->record_access( 42, 'GPTBot', 'ua' );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'access_method', $last['query'] );
        $this->assertStringContainsString( 'ua', $last['query'] );
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
        $this->assertStringContainsString( 'LIMIT 25', $last['query'] );
        $this->assertStringContainsString( 'OFFSET 50', $last['query'] );
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

    public function test_get_distinct_agents_excludes_empty_string_and_old_method_labels(): void {
        $this->repo->get_distinct_agents();

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'NOT IN', $last['query'] );
        $this->assertStringContainsString( "''", $last['query'] );
        $this->assertStringContainsString( 'accept-header', $last['query'] );
        $this->assertStringContainsString( 'query-param', $last['query'] );
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

    public function test_get_stats_with_post_id_and_agent_filters(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_stats( [ 'post_id' => 42, 'agent' => 'GPTBot' ] );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( '42', $last['query'] );
        $this->assertStringContainsString( 'GPTBot', $last['query'] );
    }

    public function test_get_stats_with_date_from_filter(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_stats( [ 'date_from' => '2026-03-01' ] );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'access_date >=', $last['query'] );
    }

    public function test_get_stats_with_date_to_filter(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_stats( [ 'date_to' => '2026-03-25' ] );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'access_date <=', $last['query'] );
    }

    public function test_get_stats_with_full_date_range(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_stats( [ 'date_from' => '2026-03-01', 'date_to' => '2026-03-25' ] );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'access_date >=', $last['query'] );
        $this->assertStringContainsString( 'access_date <=', $last['query'] );
    }

    public function test_get_agent_summary_builds_grouped_query(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_agent_summary();

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'GROUP BY', $last['query'] );
        $this->assertStringContainsString( 'SUM', $last['query'] );
        $this->assertStringContainsString( 'COUNT(DISTINCT', $last['query'] );
    }

    public function test_get_agent_summary_groups_by_agent_and_access_method(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_agent_summary();

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'GROUP BY agent, access_method', $last['query'] );
        $this->assertStringContainsString( 'access_method', $last['query'] );
    }

    public function test_get_agent_summary_with_date_filter(): void {
        $this->wpdb->mock_get_results = [];
        $this->repo->get_agent_summary( [ 'date_from' => '2026-03-01' ] );

        $last = end( $this->wpdb->queries );
        $this->assertStringContainsString( 'access_date >=', $last['query'] );
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
        $this->assertSame( '1.1', StatsRepository::DB_VERSION );
    }
}

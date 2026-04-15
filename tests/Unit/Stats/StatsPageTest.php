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
        $_GET = [];
        $GLOBALS['_mock_menu_pages']       = [];
        $GLOBALS['_mock_current_user_can'] = true;

        $this->repository = $this->createMock( StatsRepository::class );
        $this->page       = new StatsPage( $this->repository );
    }

    protected function tearDown(): void {
        $_GET = [];
    }

    public function test_add_page_registers_menu_page(): void {
        $this->page->add_page();
        $this->assertArrayHasKey( 'markdown-for-agents-stats', $GLOBALS['_mock_menu_pages'] );
    }

    public function test_add_page_uses_chart_icon(): void {
        $this->page->add_page();
        $this->assertSame( 'dashicons-chart-bar', $GLOBALS['_mock_menu_pages']['markdown-for-agents-stats']['icon_url'] );
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
            (object) [ 'post_id' => 1, 'agent' => 'GPTBot', 'access_method' => 'ua', 'access_date' => '2026-03-05', 'count' => 10 ],
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

    public function test_render_page_shows_date_inputs_in_form(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'name="date_from"', $output );
        $this->assertStringContainsString( 'name="date_to"', $output );
    }

    public function test_render_page_shows_preset_links(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Last 7 days', $output );
        $this->assertStringContainsString( 'Last 30 days', $output );
        $this->assertStringContainsString( 'This month', $output );
        $this->assertStringContainsString( 'All time', $output );
    }

    public function test_render_page_shows_headline_table_when_date_set(): void {
        $_GET['date_from'] = '2026-03-01';

        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        $this->repository->expects( $this->once() )
            ->method( 'get_agent_summary' )
            ->willReturn( [
                (object) [ 'agent' => 'GPTBot', 'access_method' => 'ua', 'total' => 10, 'unique_posts' => 3 ],
            ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Total accesses', $output );
        $this->assertStringContainsString( 'GPTBot', $output );
    }

    public function test_render_page_hides_headline_table_without_date(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        $this->repository->expects( $this->never() )->method( 'get_agent_summary' );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'Total accesses', $output );
    }

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

    public function test_render_page_displays_unknown_in_summary_for_empty_agent(): void {
        $_GET['date_from'] = '2026-03-01';

        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        $this->repository->method( 'get_agent_summary' )->willReturn( [
            (object) [
                'agent'         => '',
                'access_method' => 'query-param',
                'total'         => 5,
                'unique_posts'  => 2,
            ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( '(unknown)', $output );
    }

    public function test_render_page_shows_method_column_in_summary(): void {
        $_GET['date_from'] = '2026-03-01';

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

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Access Method', $output );
        $this->assertStringContainsString( 'ua', $output );
    }

    public function test_preset_links_rendered_as_subsubsub(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'class="subsubsub"', $output );
    }

    public function test_preset_links_order_all_time_first(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $pos_all  = strpos( $output, 'All time' );
        $pos_7d   = strpos( $output, 'Last 7 days' );
        $this->assertLessThan( $pos_7d, $pos_all, 'All time should appear before Last 7 days' );
    }

    public function test_active_preset_link_has_current_class(): void {
        $_GET['date_from'] = gmdate( 'Y-m-d', strtotime( '-6 days' ) );
        $_GET['date_to']   = gmdate( 'Y-m-d' );
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'class="current"', $output );
    }

    public function test_preset_links_have_no_inline_styles(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'font-weight:bold', $output );
    }

    public function test_filter_controls_wrapped_in_alignleft_actions(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'class="alignleft actions"', $output );
    }

    public function test_date_inputs_have_ids(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'id="date_from"', $output );
        $this->assertStringContainsString( 'id="date_to"', $output );
    }

    public function test_date_labels_have_for_attributes(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'for="date_from"', $output );
        $this->assertStringContainsString( 'for="date_to"', $output );
    }

    public function test_main_table_has_wp_list_table_classes(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'wp-list-table widefat fixed striped', $output );
    }

    public function test_column_headers_have_scope_col(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'scope="col"', $output );
    }

    public function test_count_column_header_has_num_class(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'column-count num', $output );
    }

    public function test_count_cell_has_num_class(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 1 );
        $this->repository->method( 'get_stats' )->willReturn( [
            (object) [ 'post_id' => 1, 'agent' => 'GPTBot', 'access_method' => 'ua', 'access_date' => '2026-03-26', 'count' => 5 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        // The count <td> should carry class="num"
        $this->assertMatchesRegularExpression( '/<td class="num">\s*5\s*<\/td>/', $output );
    }

    public function test_summary_table_numeric_columns_have_num_class(): void {
        $_GET['date_from'] = '2026-03-01';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        $this->repository->method( 'get_agent_summary' )->willReturn( [
            (object) [ 'agent' => 'GPTBot', 'access_method' => 'ua', 'total' => 42, 'unique_posts' => 3 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        // Summary table header for Total accesses should carry num class
        $this->assertStringContainsString( 'column-total num', $output );
        // Summary table data cells for total and unique_posts should carry class="num"
        $this->assertMatchesRegularExpression( '/<td class="num">\s*42\s*<\/td>/', $output );
        $this->assertMatchesRegularExpression( '/<td class="num">\s*3\s*<\/td>/', $output );
    }

    public function test_main_table_thead_always_rendered(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        // Even with no rows, the column headers must be present
        $this->assertStringContainsString( 'column-date', $output );
        $this->assertStringContainsString( 'column-count', $output );
    }

    public function test_empty_state_rendered_inside_table(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'colspan="5"', $output );
        $this->assertStringContainsString( 'No access data recorded yet', $output );
        // Should NOT be a bare <p> outside a table
        $this->assertStringNotContainsString( '<p>No access data recorded yet', $output );
    }

    public function test_pagination_shows_displaying_num(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        // 51 total rows → 2 pages → pagination renders
        $this->repository->method( 'get_total_count' )->willReturn( 51 );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'displaying-num', $output );
        $this->assertStringContainsString( '51 items', $output );
    }

    public function test_pagination_shows_pagination_links(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 51 );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'pagination-links', $output );
        $this->assertStringContainsString( 'tablenav-pages', $output );
    }

    public function test_pagination_first_prev_disabled_on_page_one(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 51 );
        // No $_GET['paged'] set → defaults to page 1. Disabled spans have no first-page class —
        // that class only appears on the active <a> element on pages 2+.

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'tablenav-pages-navspan button disabled', $output );
        $this->assertStringNotContainsString( 'class="prev-page button"', $output );
    }

    public function test_pagination_first_prev_active_on_page_two(): void {
        $_GET['paged'] = '2';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 101 ); // 3 pages

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'class="first-page button"', $output );
        $this->assertStringContainsString( 'class="prev-page button"', $output );
    }

    public function test_pagination_shows_x_of_y_label(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 51 );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( '1 of 2', $output );
    }

    public function test_pagination_not_shown_for_single_page(): void {
        $this->stub_empty_repository(); // 0 total → 1 page (ceil(0/50)=0, but logic uses >1)

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'pagination-links', $output );
    }

    private function stub_empty_repository(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
    }
}

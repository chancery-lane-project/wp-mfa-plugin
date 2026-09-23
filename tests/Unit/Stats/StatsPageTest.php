<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Stats\StatsPage;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;

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
        $detector         = new AgentDetector( [ 'ua_force_enabled' => true, 'ua_agent_strings' => [] ] );
        $this->page       = new StatsPage( $this->repository, $detector );
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

    public function test_render_page_hides_headline_table_for_all_time(): void {
        $_GET['range'] = 'all';
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

    public function test_render_page_renders_intent_chart_card(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'mfa-chart-card', $output );
        $this->assertStringContainsString( '<svg', $output );
        $this->assertStringContainsString( 'Requests by purpose', $output );
    }

    public function test_chart_plots_unknown_so_bars_sum_to_summary_total(): void {
        $today = gmdate( 'Y-m-d' );
        $this->stub_dashboard_repository( [
            (object) [ 'access_date' => $today, 'agent' => 'GPTBot', 'total' => 6 ],
            (object) [ 'access_date' => $today, 'agent' => 'curl', 'total' => 4 ],
        ] );

        $output = $this->render();

        // Legend lists all four categories, Unknown last (top of the stack).
        $this->assertMatchesRegularExpression( '/mfa-legend.*?Training.*?Search.*?On-demand.*?Unknown/s', $output );

        // Today's bar stacks GPTBot (training, 6) and curl (unknown, 4): segment
        // heights must add up to the full bar for the 10-request summary total.
        preg_match_all( '/<rect x="[^"]*" y="[^"]*" width="[^"]*" height="([^"]*)" fill="(#B3B8C8|#D9DCE3)"\/>/', $output, $m );
        $heights = array_combine( $m[2], array_map( 'floatval', $m[1] ) );
        $this->assertEqualsWithDelta( 6 / 4, $heights['#B3B8C8'] / $heights['#D9DCE3'], 0.01 );
        $this->assertMatchesRegularExpression( '/Recorded Markdown requests<\/div>\s*<div class="num">10</s', $output );
    }

    public function test_purpose_section_defines_every_category(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( '<h2 class="mfa-section-title">Purpose</h2>', $output );
        foreach ( [ 'On-demand', 'Search', 'Training', 'Unknown' ] as $label ) {
            $this->assertStringContainsString( '<strong>' . $label . '</strong>: ', $output );
        }
        $this->assertStringContainsString( "<strong>Unknown</strong>: agents whose purpose we can&#039;t identify.", $output );
        $this->assertStringContainsString( "<strong>Unattributed</strong>: agents whose operator we haven&#039;t identified.", $output );
        // Section order: Summary, Purpose (heading then chart), Operators, daily records.
        $summary   = strpos( $output, 'Summary · ' );
        $purpose   = strpos( $output, '>Purpose</h2>' );
        $chart     = strpos( $output, '<div class="postbox mfa-chart-card">' );
        $operators = strpos( $output, '>Operators</h2>' );
        $records   = strpos( $output, 'column-post' );
        $this->assertLessThan( $purpose, $summary );
        $this->assertLessThan( $chart, $purpose );
        $this->assertLessThan( $operators, $chart );
        $this->assertLessThan( $records, $operators );
    }

    public function test_render_page_shows_on_demand_headline_as_estimate(): void {
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'estimated AI-mediated human reads', $output );
    }

    public function test_render_page_shows_total_card_summing_all_categories(): void {
        $_GET['range'] = 'all';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        // 40 training + 7 on-demand + 3 unknown = 50 across all types.
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => '2026-06-10', 'agent' => 'GPTBot', 'total' => 40 ],
            (object) [ 'access_date' => '2026-06-10', 'agent' => 'ChatGPT-User', 'total' => 7 ],
            (object) [ 'access_date' => '2026-06-10', 'agent' => '', 'total' => 3 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Total agent visits', $output );
        $this->assertMatchesRegularExpression( '/Total agent visits.*?>\s*50\s*</s', $output );
    }

    public function test_render_page_total_trend_rising_is_green(): void {
        $_GET['range'] = 'all';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        // Rising day-on-day → positive PMCC → green "rising" trend.
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => '2026-06-10', 'agent' => 'GPTBot', 'total' => 10 ],
            (object) [ 'access_date' => '2026-06-11', 'agent' => 'GPTBot', 'total' => 20 ],
            (object) [ 'access_date' => '2026-06-12', 'agent' => 'GPTBot', 'total' => 30 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression( '/Total agent visits.*?mfa-trend rising/s', $output );
    }

    public function test_render_page_total_trend_falling_is_red(): void {
        $_GET['range'] = 'all';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        // Falling day-on-day → negative PMCC → red "falling" trend.
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => '2026-06-10', 'agent' => 'GPTBot', 'total' => 30 ],
            (object) [ 'access_date' => '2026-06-11', 'agent' => 'GPTBot', 'total' => 20 ],
            (object) [ 'access_date' => '2026-06-12', 'agent' => 'GPTBot', 'total' => 10 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression( '/Total agent visits.*?mfa-trend falling/s', $output );
    }

    public function test_render_page_trend_neutral_for_zero_variance_category(): void {
        $_GET['range'] = 'all';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        // Multi-bucket window, but the Unknown series is entirely zero →
        // zero variance → neutral trend, never NaN nor a coloured arrow.
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => '2026-06-10', 'agent' => 'GPTBot', 'total' => 10 ],
            (object) [ 'access_date' => '2026-06-11', 'agent' => 'GPTBot', 'total' => 20 ],
            (object) [ 'access_date' => '2026-06-12', 'agent' => 'GPTBot', 'total' => 30 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression( '/Unknown.*?mfa-trend none/s', $output );
        $this->assertStringNotContainsString( 'NaN', $output );
    }

    public function test_render_page_trend_neutral_when_rounds_to_zero(): void {
        $_GET['range'] = 'all';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        // r ≈ 0.0038 → rounds to 0.00. The shown number and colour must agree:
        // neutral, not a green "▲ r = 0.00".
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => '2026-06-10', 'agent' => 'GPTBot', 'total' => 100 ],
            (object) [ 'access_date' => '2026-06-11', 'agent' => 'GPTBot', 'total' => 200 ],
            (object) [ 'access_date' => '2026-06-12', 'agent' => 'GPTBot', 'total' => 300 ],
            (object) [ 'access_date' => '2026-06-13', 'agent' => 'GPTBot', 'total' => 200 ],
            (object) [ 'access_date' => '2026-06-14', 'agent' => 'GPTBot', 'total' => 101 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression( '/Total agent visits.*?mfa-trend none/s', $output );
        $this->assertStringNotContainsString( 'mfa-trend rising', $output );
        $this->assertStringNotContainsString( 'mfa-trend falling', $output );
    }

    public function test_render_page_trend_neutral_for_single_bucket(): void {
        $_GET['range'] = 'all';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        // Single day → n < 2 → no trend computable → neutral everywhere.
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => '2026-06-10', 'agent' => 'GPTBot', 'total' => 40 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression( '/Total agent visits.*?mfa-trend none/s', $output );
        $this->assertStringNotContainsString( 'mfa-trend rising', $output );
        $this->assertStringNotContainsString( 'mfa-trend falling', $output );
    }

    public function test_render_page_buckets_daily_totals_by_intent(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        $today = gmdate( 'Y-m-d' );
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => $today, 'agent' => 'ChatGPT-User', 'total' => 7 ],
            (object) [ 'access_date' => $today, 'agent' => 'GPTBot', 'total' => 40 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        // on-demand headline = 7, training card = 40, both rendered.
        $this->assertMatchesRegularExpression( '/On-demand reads.*?>\s*7\s/s', $output );
        $this->assertMatchesRegularExpression( '/Training crawls.*?>\s*40\s*</s', $output );
    }

    public function test_render_page_defaults_to_last_7_days(): void {
        // No $_GET → "Last 7 days" is the active preset, not "All time" or "Last 30 days".
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'class="current">Last 7 days', $output );
        $this->assertStringNotContainsString( 'class="current">Last 30 days', $output );
        $this->assertStringNotContainsString( 'class="current">All time', $output );
        // Default chart grain is daily.
        $this->assertStringContainsString( 'daily', $output );
    }

    public function test_default_range_queries_last_7_days_inclusive_of_today(): void {
        $today     = gmdate( 'Y-m-d' );
        $seven_ago = gmdate( 'Y-m-d', strtotime( '-6 days' ) );

        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->expects( $this->once() )
            ->method( 'get_total_count' )
            ->with( [ 'date_from' => $seven_ago, 'date_to' => $today ] )
            ->willReturn( 0 );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        // Date inputs echo the default window so the form matches the report.
        $this->assertStringContainsString( 'value="' . $seven_ago . '"', $output );
        $this->assertStringContainsString( 'value="' . $today . '"', $output );
    }

    public function test_explicit_30_day_preset_still_active(): void {
        $_GET['date_from'] = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
        $_GET['date_to']   = gmdate( 'Y-m-d' );
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'class="current">Last 30 days', $output );
        $this->assertStringNotContainsString( 'class="current">Last 7 days', $output );
    }

    public function test_render_page_all_time_uses_range_param(): void {
        $_GET['range'] = 'all';
        $this->stub_empty_repository();

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'class="current">All time', $output );
        $this->assertStringNotContainsString( 'class="current">Last 30 days', $output );
    }

    public function test_render_page_uses_monthly_buckets_for_long_span(): void {
        $_GET['range'] = 'all';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        // Span Jan→May 2026 (~125 days) → monthly grain.
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => '2026-01-05', 'agent' => 'GPTBot', 'total' => 5 ],
            (object) [ 'access_date' => '2026-05-10', 'agent' => 'ChatGPT-User', 'total' => 9 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Jan 2026', $output );
        $this->assertStringContainsString( 'May 2026', $output );
        $this->assertStringContainsString( 'monthly', $output );
    }

    public function test_render_page_uses_yearly_buckets_beyond_five_years(): void {
        $_GET['range'] = 'all';
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
        // ~7-year span → yearly grain; bars cover the whole window, no truncation.
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( [
            (object) [ 'access_date' => '2019-03-01', 'agent' => 'GPTBot', 'total' => 3 ],
            (object) [ 'access_date' => '2026-03-01', 'agent' => 'ChatGPT-User', 'total' => 8 ],
        ] );

        ob_start();
        $this->page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'yearly', $output );
        $this->assertStringContainsString( '2019', $output );
        $this->assertStringNotContainsString( 'chart truncated', $output );
    }

    private function stub_empty_repository(): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturn( 0 );
    }

    // ---------------------------------------------------------------------
    // Dashboard summary and operator cards (1.7.2)
    // ---------------------------------------------------------------------

    private function render(): string {
        ob_start();
        $this->page->render_page();
        return (string) ob_get_clean();
    }

    /**
     * Stub a repository whose distinct labels span two operators plus an
     * unattributed one, and capture the count filters the page applies.
     */
    private function stub_dashboard_repository( array $daily = [], array $posts = [], ?array &$captured = null ): void {
        $this->repository->method( 'get_distinct_agents' )->willReturn( [ 'ChatGPT-User', 'ClaudeBot', 'GPTBot', 'curl' ] );
        $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
        $this->repository->method( 'get_stats' )->willReturn( [] );
        $this->repository->method( 'get_total_count' )->willReturnCallback(
            function ( array $filters ) use ( &$captured ): int {
                $captured = $filters;
                return 0;
            }
        );
        $this->repository->method( 'get_daily_agent_totals' )->willReturn( $daily );
        $this->repository->method( 'get_post_totals' )->willReturn( $posts );
    }

    public function test_summary_shows_range_and_headline_values(): void {
        $today = gmdate( 'Y-m-d' );
        $GLOBALS['_mock_post_titles'] = [ 7 => 'Clause library' ];
        $this->stub_dashboard_repository(
            [
                (object) [ 'access_date' => $today, 'agent' => 'GPTBot', 'total' => 40 ],
                (object) [ 'access_date' => $today, 'agent' => 'ClaudeBot', 'total' => 12 ],
                (object) [ 'access_date' => $today, 'agent' => 'curl', 'total' => 3 ],
            ],
            [ (object) [ 'post_id' => 7, 'total' => 30 ], (object) [ 'post_id' => 3, 'total' => 25 ] ]
        );

        $output = $this->render();

        $range = gmdate( 'j M Y', strtotime( '-6 days' ) ) . ' – ' . gmdate( 'j M Y' );
        $this->assertStringContainsString( 'Summary · ' . $range, $output );
        $this->assertMatchesRegularExpression( '/Recorded Markdown requests<\/div>\s*<div class="num">55</s', $output );
        $this->assertMatchesRegularExpression( '/Most requested page.*?post_id=7.*?>Clause library<.*?30 requests/s', $output );
        $this->assertMatchesRegularExpression( '/Leading agent.*?agent=GPTBot.*?>GPTBot<.*?40 requests/s', $output );
        $this->assertMatchesRegularExpression( '/Leading operator.*?operator=openai.*?>OpenAI<.*?40 requests/s', $output );
        $this->assertStringContainsString( 'CDN or static cache', $output );
        $GLOBALS['_mock_post_titles'] = [];
    }

    public function test_summary_total_matches_intent_total_card(): void {
        $today = gmdate( 'Y-m-d' );
        $this->stub_dashboard_repository( [
            (object) [ 'access_date' => $today, 'agent' => 'ChatGPT-User', 'total' => 7 ],
            (object) [ 'access_date' => $today, 'agent' => 'curl', 'total' => 4 ],
        ] );

        $output = $this->render();

        $this->assertMatchesRegularExpression( '/Recorded Markdown requests<\/div>\s*<div class="num">11</s', $output );
        $this->assertMatchesRegularExpression( '/Total agent visits.*?>\s*11\s/s', $output );
    }

    public function test_summary_shows_ties_and_deleted_posts(): void {
        $today = gmdate( 'Y-m-d' );
        $GLOBALS['_mock_post_titles'] = [ 4 => '', 9 => 'Glossary' ];
        $this->stub_dashboard_repository(
            [ (object) [ 'access_date' => $today, 'agent' => 'GPTBot', 'total' => 8 ] ],
            [ (object) [ 'post_id' => 4, 'total' => 4 ], (object) [ 'post_id' => 9, 'total' => 4 ] ]
        );

        $output = $this->render();
        $GLOBALS['_mock_post_titles'] = [];

        $this->assertStringContainsString( 'Tied: 2 pages', $output );
        $this->assertStringContainsString( '4 requests each', $output );
        $this->assertStringContainsString( '(deleted post #4), Glossary', $output );
    }

    public function test_summary_empty_state(): void {
        $this->stub_dashboard_repository();

        $output = $this->render();

        $this->assertStringContainsString( 'No pages requested in this range', $output );
        $this->assertStringContainsString( 'No identified agents in this range', $output );
        $this->assertStringContainsString( 'No attributed operators in this range', $output );
        $this->assertStringContainsString( 'No requests recorded for these filters.', $output );
    }

    public function test_operator_cards_list_agents_and_link_to_filter(): void {
        $today = gmdate( 'Y-m-d' );
        $this->stub_dashboard_repository( [
            (object) [ 'access_date' => $today, 'agent' => 'GPTBot', 'total' => 40 ],
            (object) [ 'access_date' => $today, 'agent' => 'ChatGPT-User', 'total' => 7 ],
            (object) [ 'access_date' => $today, 'agent' => 'curl', 'total' => 3 ],
        ] );

        $output = $this->render();

        $this->assertMatchesRegularExpression( '/operator=openai[^>]*aria-label="OpenAI: filter report by this operator">OpenAI<\/a>.*?47.*?GPTBot.*?40.*?ChatGPT-User.*?7/s', $output );
        $this->assertMatchesRegularExpression( '/operator=unattributed[^>]*>Unattributed<\/a>.*?3.*?curl/s', $output );
        // Unattributed card comes after the reviewed operators.
        $this->assertLessThan( strpos( $output, '>Unattributed</a>' ), strpos( $output, '>OpenAI</a>' ) );
        $this->assertStringNotContainsString( 'Clear operator filter', $output );
    }

    public function test_operator_filter_restricts_every_query_to_its_agents(): void {
        $_GET['operator'] = 'openai';
        $captured = null;
        $this->stub_dashboard_repository( [], [], $captured );
        $this->repository->expects( $this->once() )
            ->method( 'get_daily_agent_totals' )
            ->with( $this->callback( fn( array $f ) => [ 'ChatGPT-User', 'GPTBot' ] === $f['agents_in'] ) );
        $this->repository->expects( $this->once() )
            ->method( 'get_post_totals' )
            ->with( $this->callback( fn( array $f ) => [ 'ChatGPT-User', 'GPTBot' ] === $f['agents_in'] ) );

        $output = $this->render();

        $this->assertSame( [ 'ChatGPT-User', 'GPTBot' ], $captured['agents_in'] );
        $this->assertArrayNotHasKey( 'agents_not_in', $captured );
        $this->assertStringContainsString( 'Showing OpenAI only.', $output );
        $this->assertStringContainsString( 'Clear operator filter', $output );
        // Agent dropdown narrows to the operator's agents.
        $this->assertStringContainsString( '<option value="GPTBot"', $output );
        $this->assertStringNotContainsString( '<option value="ClaudeBot"', $output );
    }

    public function test_unattributed_filter_excludes_attributed_agents(): void {
        $_GET['operator'] = 'unattributed';
        $captured = null;
        $this->stub_dashboard_repository( [], [], $captured );

        $this->render();

        $this->assertSame( [ 'ChatGPT-User', 'ClaudeBot', 'GPTBot' ], $captured['agents_not_in'] );
        $this->assertArrayNotHasKey( 'agents_in', $captured );
    }

    public function test_operator_filter_drops_agent_from_another_operator(): void {
        $_GET['operator'] = 'openai';
        $_GET['agent']    = 'ClaudeBot';
        $captured = null;
        $this->stub_dashboard_repository( [], [], $captured );

        $this->render();

        $this->assertArrayNotHasKey( 'agent', $captured );
        $this->assertSame( [ 'ChatGPT-User', 'GPTBot' ], $captured['agents_in'] );
    }

    public function test_operator_filter_keeps_agent_it_runs(): void {
        $_GET['operator']      = 'openai';
        $_GET['agent']         = 'GPTBot';
        $_GET['access_method'] = 'ua';
        $captured = null;
        $this->stub_dashboard_repository( [], [], $captured );

        $this->render();

        $this->assertSame( 'GPTBot', $captured['agent'] );
        $this->assertSame( 'ua', $captured['access_method'] );
    }

    public function test_unknown_operator_param_is_ignored(): void {
        $_GET['operator'] = 'not-a-real-operator';
        $captured = null;
        $this->stub_dashboard_repository( [], [], $captured );

        $output = $this->render();

        $this->assertArrayNotHasKey( 'agents_in', $captured );
        $this->assertArrayNotHasKey( 'agents_not_in', $captured );
        $this->assertStringNotContainsString( 'Clear operator filter', $output );
    }

    public function test_active_operator_card_is_marked_and_toggles_off(): void {
        $_GET['operator'] = 'openai';
        $today = gmdate( 'Y-m-d' );
        $this->stub_dashboard_repository( [ (object) [ 'access_date' => $today, 'agent' => 'GPTBot', 'total' => 5 ] ] );

        $output = $this->render();

        $this->assertStringContainsString( 'mfa-operator is-active', $output );
        $this->assertStringContainsString( 'aria-current="true"', $output );
        $this->assertStringContainsString( 'OpenAI, filtered: remove operator filter', $output );
        $this->assertStringContainsString( '>Filtered<', $output );
        $this->assertMatchesRegularExpression( '/<option value="openai"\s+selected/', $output );
    }

    public function test_filter_selects_have_accessible_labels(): void {
        $this->stub_dashboard_repository();

        $output = $this->render();

        foreach ( [ 'Filter by post', 'Filter by operator', 'Filter by agent', 'Filter by access method' ] as $label ) {
            $this->assertStringContainsString( 'aria-label="' . $label . '"', $output );
        }
    }
}

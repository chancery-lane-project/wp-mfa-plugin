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
}

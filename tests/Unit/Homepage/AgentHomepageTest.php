<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Homepage;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Homepage\AgentHomepage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Homepage\AgentHomepage
 */
class AgentHomepageTest extends TestCase {

    private const OPTIONS = [ 'agent_homepage_id' => 42, 'post_types' => [ 'post', 'page' ] ];

    protected function setUp(): void {
        $GLOBALS['_mock_post_objects'] = [
            42 => new \WP_Post( [ 'ID' => 42, 'post_type' => 'page', 'post_name' => 'for-agents' ] ),
        ];
        $GLOBALS['_mock_post_meta'] = [];
    }

    protected function tearDown(): void {
        $GLOBALS['_mock_post_objects'] = [];
        $GLOBALS['_mock_post_meta']    = [];
        unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_agent_homepage'] );
    }

    public function test_id_defaults_to_zero(): void {
        $this->assertSame( 0, AgentHomepage::id( [] ) );
        $this->assertNull( AgentHomepage::post( [] ) );
        $this->assertSame( [], AgentHomepage::problems( [] ) );
    }

    public function test_filter_overrides_setting_and_negative_ids_are_zero(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_agent_homepage'] = static fn( int $id ): int => 99;
        $this->assertSame( 99, AgentHomepage::id( self::OPTIONS ) );

        $GLOBALS['_mock_apply_filters']['markdown_for_agents_agent_homepage'] = static fn( int $id ): int => -3;
        $this->assertSame( 0, AgentHomepage::id( self::OPTIONS ) );
    }

    public function test_post_returns_eligible_page(): void {
        $this->assertSame( 42, AgentHomepage::post( self::OPTIONS )?->ID );
    }

    public function test_post_is_null_when_page_not_exportable(): void {
        $GLOBALS['_mock_post_objects'][42]->post_status = 'draft';
        $this->assertNull( AgentHomepage::post( self::OPTIONS ) );
    }

    public function test_problems_empty_when_servable_and_file_exists(): void {
        $file = tempnam( sys_get_temp_dir(), 'mfa-home' );
        try {
            $this->assertSame( [], AgentHomepage::problems( self::OPTIONS, $file ) );
        } finally {
            unlink( $file );
        }
    }

    public function test_problems_lists_every_reason(): void {
        $page                = $GLOBALS['_mock_post_objects'][42];
        $page->post_status   = 'draft';
        $page->post_password = 'secret';
        $GLOBALS['_mock_post_meta'][42]['_markdown_for_agents_excluded'] = '1';

        $problems = AgentHomepage::problems( [ 'agent_homepage_id' => 42, 'post_types' => [ 'post' ] ] );

        $this->assertCount( 4, $problems );
    }

    public function test_problems_reports_missing_file_only_when_otherwise_servable(): void {
        $problems = AgentHomepage::problems( self::OPTIONS, '/nonexistent/for-agents.md' );
        $this->assertCount( 1, $problems );
        $this->assertStringContainsString( 'not been generated', $problems[0] );
    }

    public function test_problems_reports_deleted_page(): void {
        $GLOBALS['_mock_post_objects'] = [];
        $this->assertCount( 1, AgentHomepage::problems( self::OPTIONS ) );
    }
}

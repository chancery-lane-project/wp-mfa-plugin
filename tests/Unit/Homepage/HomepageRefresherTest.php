<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Homepage;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Homepage\HomepageRefresher;

/**
 * @covers \Tclp\WpMarkdownForAgents\Homepage\HomepageRefresher
 */
class HomepageRefresherTest extends TestCase {

    private const HOMEPAGE_ID = 42;

    /** @var Generator&MockObject */
    private Generator $generator;

    protected function setUp(): void {
        $this->generator = $this->createMock( Generator::class );

        $GLOBALS['_mock_post_objects'] = [
            self::HOMEPAGE_ID => new \WP_Post( [
                'ID'           => self::HOMEPAGE_ID,
                'post_type'    => 'page',
                'post_content' => "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->\n<!-- wp:latest-posts {\"postsToShow\":5,\"displayPostDate\":true} /-->",
            ] ),
        ];
    }

    protected function tearDown(): void {
        $GLOBALS['_mock_post_objects'] = [];
        unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_refresh_pages'], $GLOBALS['_mock_apply_filters']['markdown_for_agents_agent_homepage'] );
    }

    private function make( array $options = [ 'agent_homepage_id' => self::HOMEPAGE_ID ] ): HomepageRefresher {
        return new HomepageRefresher( $options, $this->generator );
    }

    private function news( array $props = [] ): \WP_Post {
        return new \WP_Post( array_merge( [ 'ID' => 7, 'post_type' => 'post', 'post_title' => 'News' ], $props ) );
    }

    /**
     * @dataProvider status_change_provider
     */
    public function test_marks_homepage_on_publish_status_changes( string $new, string $old, bool $marked ): void {
        $refresher = $this->make();
        $refresher->on_transition_post_status( $new, $old, $this->news() );

        $this->assertSame( $marked ? [ self::HOMEPAGE_ID ] : [], $refresher->pending() );
    }

    public function status_change_provider(): array {
        return [
            'published'           => [ 'publish', 'draft', true ],
            'scheduled goes live' => [ 'publish', 'future', true ],
            'unpublished'         => [ 'draft', 'publish', true ],
            'trashed'             => [ 'trash', 'publish', true ],
            'update while live'   => [ 'publish', 'publish', false ],
            'draft saved'         => [ 'draft', 'draft', false ],
            'draft to pending'    => [ 'pending', 'draft', false ],
        ];
    }

    public function test_ignores_post_types_other_than_post(): void {
        $refresher = $this->make();
        $refresher->on_transition_post_status( 'publish', 'draft', $this->news( [ 'post_type' => 'guide' ] ) );

        $this->assertSame( [], $refresher->pending() );
    }

    public function test_ignores_homepage_without_the_block(): void {
        $GLOBALS['_mock_post_objects'][ self::HOMEPAGE_ID ]->post_content = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->';

        $refresher = $this->make();
        $refresher->on_transition_post_status( 'publish', 'draft', $this->news() );

        $this->assertSame( [], $refresher->pending() );
    }

    public function test_does_nothing_without_agent_homepage(): void {
        $refresher = $this->make( [] );
        $refresher->on_transition_post_status( 'publish', 'draft', $this->news() );

        $this->assertSame( [], $refresher->pending() );
    }

    public function test_homepage_own_status_change_is_ignored(): void {
        $refresher = $this->make();
        $refresher->on_transition_post_status( 'publish', 'draft', $GLOBALS['_mock_post_objects'][ self::HOMEPAGE_ID ] );

        $this->assertSame( [], $refresher->pending() );
    }

    public function test_refresh_pages_filter_adds_other_pages(): void {
        $GLOBALS['_mock_post_objects'][50] = new \WP_Post( [
            'ID'           => 50,
            'post_type'    => 'page',
            'post_content' => '<!-- wp:group --><div><!-- wp:latest-posts /--></div><!-- /wp:group -->',
        ] );
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_refresh_pages'] = static fn( array $ids ): array => array_merge( $ids, [ 50, '50', 0 ] );

        $refresher = $this->make();
        $refresher->on_transition_post_status( 'publish', 'draft', $this->news() );

        $this->assertSame( [ self::HOMEPAGE_ID, 50 ], $refresher->pending() );
    }

    public function test_post_updated_marks_homepage_when_listed_fields_change(): void {
        $before = $this->news();

        foreach ( [ 'post_title' => 'Renamed', 'post_date' => '2026-11-01 10:00:00', 'post_name' => 'renamed', 'post_password' => 'x' ] as $field => $value ) {
            $refresher = $this->make();
            $refresher->on_post_updated( 7, $this->news( [ $field => $value ] ), $before );
            $this->assertSame( [ self::HOMEPAGE_ID ], $refresher->pending(), $field );
        }
    }

    public function test_post_updated_ignores_content_only_edits_and_unpublished_posts(): void {
        $refresher = $this->make();
        $refresher->on_post_updated( 7, $this->news( [ 'post_content' => 'changed' ] ), $this->news() );
        $refresher->on_post_updated( 7, $this->news( [ 'post_status' => 'draft', 'post_title' => 'X' ] ), $this->news( [ 'post_status' => 'draft' ] ) );

        $this->assertSame( [], $refresher->pending() );
    }

    public function test_after_delete_post_marks_homepage_for_published_posts_only(): void {
        $refresher = $this->make();
        $refresher->on_after_delete_post( 7, $this->news( [ 'post_status' => 'draft' ] ) );
        $this->assertSame( [], $refresher->pending() );

        $refresher->on_after_delete_post( 7, $this->news() );
        $this->assertSame( [ self::HOMEPAGE_ID ], $refresher->pending() );
    }

    public function test_flush_regenerates_each_marked_page_once_and_clears(): void {
        $refresher = $this->make();
        $refresher->on_transition_post_status( 'publish', 'draft', $this->news() );
        $refresher->on_transition_post_status( 'publish', 'draft', $this->news( [ 'ID' => 8 ] ) );

        $this->generator->expects( $this->once() )
            ->method( 'generate_post' )
            ->with( $GLOBALS['_mock_post_objects'][ self::HOMEPAGE_ID ] )
            ->willReturn( true );

        $refresher->flush();
        $refresher->flush();

        $this->assertSame( [], $refresher->pending() );
    }

    public function test_flush_swallows_generation_errors(): void {
        $refresher = $this->make();
        $refresher->on_transition_post_status( 'publish', 'draft', $this->news() );

        $this->generator->method( 'generate_post' )->willThrowException( new \RuntimeException( 'boom' ) );

        $refresher->flush();
        $this->assertSame( [], $refresher->pending() );
    }
}

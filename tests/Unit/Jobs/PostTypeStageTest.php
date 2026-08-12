<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Jobs\PostTypeStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\PostTypeStage
 */
class PostTypeStageTest extends TestCase {

    private \wpdb $wpdb;

    /** @var Generator&MockObject */
    private Generator $generator;

    /** @var array<string, mixed> */
    private array $options;

    protected function setUp(): void {
        $this->wpdb      = new \wpdb();
        $this->generator = $this->createMock( Generator::class );
        $this->options   = array_merge( Options::get_defaults(), [ 'post_types' => [ 'post' ] ] );

        $GLOBALS['_mock_post_objects'] = [];
        $GLOBALS['_mock_post_counts']  = [ 'post' => [ 'publish' => 3 ] ];
        $GLOBALS['_mock_post_meta']    = [];
    }

    /** Register a publishable mock post so get_post() returns a WP_Post. */
    private function given_post( int $id ): \WP_Post {
        $post              = new \WP_Post();
        $post->ID          = $id;
        $post->post_type   = 'post';
        $post->post_status = 'publish';
        $post->post_name   = 'post-' . $id;
        $post->post_title  = 'Post ' . $id;

        $GLOBALS['_mock_post_objects'][ $id ] = $post;

        return $post;
    }

    private function stage(): PostTypeStage {
        return new PostTypeStage( $this->wpdb, $this->generator, $this->options, 'post' );
    }

    public function test_count_total_uses_published_count(): void {
        $this->assertSame( 3, $this->stage()->count_total() );
    }

    public function test_count_total_is_zero_for_unknown_post_type(): void {
        $stage = new PostTypeStage( $this->wpdb, $this->generator, $this->options, 'nope' );

        $this->assertSame( 0, $stage->count_total() );
    }

    public function test_batch_query_is_cursor_paginated_with_no_offset(): void {
        $this->wpdb->mock_get_col_queue = [ [ '4', '5' ] ];
        $this->given_post( 4 );
        $this->given_post( 5 );
        $this->generator->method( 'generate_post' )->willReturn( true );

        $this->stage()->process_batch( 3, 2 );

        $sql = $this->wpdb->queries[0]['query'];

        $this->assertStringContainsString( 'post_type = %s', $sql );
        $this->assertStringContainsString( "post_status = 'publish'", $sql );
        $this->assertStringContainsString( 'ID > %d', $sql );
        $this->assertStringContainsString( 'ORDER BY ID ASC', $sql );
        $this->assertStringContainsString( 'LIMIT %d', $sql );
        $this->assertStringNotContainsStringIgnoringCase( 'OFFSET', $sql );
        $this->assertStringNotContainsStringIgnoringCase( 'SQL_CALC_FOUND_ROWS', $sql );
        $this->assertSame( [ 'post', 3, 2 ], $this->wpdb->queries[0]['args'] );
    }

    public function test_zero_limit_returns_done_with_no_query(): void {
        $result = $this->stage()->process_batch( 0, 0 );

        $this->assertSame(
            [ 'processed' => 0, 'skipped' => 0, 'errors' => [], 'next_cursor' => 0, 'done' => true ],
            $result
        );
        $this->assertSame( [], $this->wpdb->queries );
    }

    public function test_full_page_reports_not_done_and_advances_cursor(): void {
        $this->wpdb->mock_get_col_queue = [ [ '4', '5' ] ];
        $this->given_post( 4 );
        $this->given_post( 5 );
        $this->generator->method( 'generate_post' )->willReturn( true );

        $result = $this->stage()->process_batch( 0, 2 );

        $this->assertSame( 2, $result['processed'] );
        $this->assertFalse( $result['done'] );
        $this->assertSame( 5, $result['next_cursor'] );
    }

    public function test_short_page_reports_done(): void {
        $this->wpdb->mock_get_col_queue = [ [ '9' ] ];
        $this->given_post( 9 );
        $this->generator->method( 'generate_post' )->willReturn( true );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 9, $result['next_cursor'] );
    }

    public function test_empty_page_is_done_and_leaves_cursor_untouched(): void {
        $this->wpdb->mock_get_col_queue = [ [] ];

        $result = $this->stage()->process_batch( 77, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 77, $result['next_cursor'] );
        $this->assertSame( 0, $result['processed'] );
    }

    /** The Problem #5 regression: a full page of skips must not report done. */
    public function test_all_skipped_full_page_still_reports_not_done(): void {
        $this->wpdb->mock_get_col_queue = [ [ '1', '2' ] ];
        $excluded                       = $this->given_post( 1 );
        $this->given_post( 2 );

        // Ineligible: excluded via meta, so generate_post() returns false by design.
        $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';
        $GLOBALS['_mock_post_meta'][2]['_markdown_for_agents_excluded'] = '1';
        $this->generator->method( 'generate_post' )->willReturn( false );

        $result = $this->stage()->process_batch( 0, 2 );

        $this->assertFalse( $result['done'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( 2, $result['skipped'] );
        $this->assertSame( [], $result['errors'] );
        $this->assertSame( 2, $result['next_cursor'] );
        $this->assertNotSame( $excluded, null );
    }

    public function test_eligible_post_that_fails_to_write_is_an_error_not_a_skip(): void {
        $this->wpdb->mock_get_col_queue = [ [ '1' ] ];
        $this->given_post( 1 );
        $this->generator->method( 'generate_post' )->willReturn( false );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 0, $result['skipped'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 1, $result['errors'][0]['post_id'] );
        $this->assertStringContainsString( 'Failed to write', $result['errors'][0]['message'] );
    }

    public function test_missing_post_object_is_an_error_and_does_not_halt_the_batch(): void {
        $this->wpdb->mock_get_col_queue = [ [ '1', '2' ] ];
        $this->given_post( 2 );   // 1 is deliberately absent
        $this->generator->method( 'generate_post' )->willReturn( true );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 1, $result['processed'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 1, $result['errors'][0]['post_id'] );
    }

    public function test_thrown_error_is_collected_and_the_batch_continues(): void {
        $this->wpdb->mock_get_col_queue = [ [ '1', '2' ] ];
        $this->given_post( 1 );
        $this->given_post( 2 );
        $this->generator->method( 'generate_post' )
            ->willReturnCallback(
                static function ( \WP_Post $post ): bool {
                    if ( 1 === $post->ID ) {
                        throw new \RuntimeException( 'boom' );
                    }
                    return true;
                }
            );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( 'boom', $result['errors'][0]['message'] );
        $this->assertSame( 2, $result['next_cursor'] );
    }
}

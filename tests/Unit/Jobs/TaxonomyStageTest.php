<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Jobs\TaxonomyStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\TaxonomyStage
 */
class TaxonomyStageTest extends TestCase {

    private \wpdb $wpdb;

    /** @var TaxonomyArchiveGenerator&MockObject */
    private TaxonomyArchiveGenerator $taxonomy_generator;

    protected function setUp(): void {
        $this->wpdb               = new \wpdb();
        $this->taxonomy_generator = $this->createMock( TaxonomyArchiveGenerator::class );

        $GLOBALS['_mock_taxonomies']   = [ 'category', 'post_tag' ];
        $GLOBALS['_mock_terms_by_id']  = [];
    }

    /**
     * @param array<int, array{term_taxonomy_id: int, term_id: int, taxonomy: string}> $rows
     */
    private function given_rows( array $rows ): void {
        $objects = [];

        foreach ( $rows as $row ) {
            $objects[] = (object) $row;

            $term            = new \WP_Term();
            $term->term_id   = $row['term_id'];
            $term->taxonomy  = $row['taxonomy'];
            $term->slug      = 'term-' . $row['term_id'];
            $term->name      = 'Term ' . $row['term_id'];

            $GLOBALS['_mock_terms_by_id'][ $row['term_id'] ] = $term;
        }

        $this->wpdb->mock_get_results_queue = [ $objects ];
    }

    private function stage(): TaxonomyStage {
        return new TaxonomyStage( $this->wpdb, $this->taxonomy_generator );
    }

    public function test_count_total_runs_one_count_query(): void {
        $this->wpdb->mock_get_var = '812';

        $this->assertSame( 812, $this->stage()->count_total() );
        $this->assertStringContainsString( 'COUNT(', $this->wpdb->queries[0]['query'] );
    }

    public function test_zero_limit_returns_done_with_no_query(): void {
        $result = $this->stage()->process_batch( 0, 0 );

        $this->assertSame(
            [ 'processed' => 0, 'skipped' => 0, 'errors' => [], 'next_cursor' => 0, 'done' => true ],
            $result
        );
        $this->assertSame( [], $this->wpdb->queries );
    }

    public function test_batch_query_is_cursor_paginated_over_term_taxonomy_id(): void {
        $this->given_rows( [ [ 'term_taxonomy_id' => 5, 'term_id' => 5, 'taxonomy' => 'category' ] ] );
        $this->taxonomy_generator->method( 'generate_term' )->willReturn( true );

        $this->stage()->process_batch( 4, 50 );

        $sql = $this->wpdb->queries[0]['query'];

        $this->assertStringContainsString( 'term_taxonomy_id > %d', $sql );
        $this->assertStringContainsString( 'ORDER BY tt.term_taxonomy_id ASC', $sql );
        $this->assertStringNotContainsStringIgnoringCase( 'OFFSET', $sql );
        $this->assertSame( [ 'category', 'post_tag', 4, 50 ], $this->wpdb->queries[0]['args'] );
    }

    public function test_terms_from_multiple_taxonomies_interleave_by_term_taxonomy_id(): void {
        $this->given_rows(
            [
                [ 'term_taxonomy_id' => 1, 'term_id' => 40, 'taxonomy' => 'category' ],
                [ 'term_taxonomy_id' => 2, 'term_id' => 7,  'taxonomy' => 'post_tag' ],
                [ 'term_taxonomy_id' => 3, 'term_id' => 41, 'taxonomy' => 'category' ],
            ]
        );

        $seen = [];
        $this->taxonomy_generator->method( 'generate_term' )
            ->willReturnCallback(
                static function ( \WP_Term $term ) use ( &$seen ): bool {
                    $seen[] = $term->term_id . ':' . $term->taxonomy;
                    return true;
                }
            );

        // Limit 4 against 3 rows: a short page, so this test asserts ordering
        // without also tripping the full-page boundary that
        // test_full_page_reports_not_done owns.
        $result = $this->stage()->process_batch( 0, 4 );

        // A term_id cursor would have dropped post_tag 7 after category 40.
        $this->assertSame( [ '40:category', '7:post_tag', '41:category' ], $seen );
        $this->assertSame( 3, $result['processed'] );
        $this->assertSame( 3, $result['next_cursor'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_shared_term_in_two_taxonomies_generates_both_archives(): void {
        $shared           = new \WP_Term();
        $shared->term_id  = 9;
        $shared->taxonomy = 'category';
        $shared->slug     = 'shared';

        $this->wpdb->mock_get_results_queue = [
            [
                (object) [ 'term_taxonomy_id' => 10, 'term_id' => 9, 'taxonomy' => 'category' ],
                (object) [ 'term_taxonomy_id' => 11, 'term_id' => 9, 'taxonomy' => 'post_tag' ],
            ],
        ];
        $GLOBALS['_mock_terms_by_id'][9] = $shared;

        $this->taxonomy_generator->expects( $this->exactly( 2 ) )
            ->method( 'generate_term' )
            ->willReturn( true );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 2, $result['processed'] );
        $this->assertSame( 11, $result['next_cursor'] );
    }

    public function test_full_page_reports_not_done(): void {
        $this->given_rows(
            [
                [ 'term_taxonomy_id' => 1, 'term_id' => 1, 'taxonomy' => 'category' ],
                [ 'term_taxonomy_id' => 2, 'term_id' => 2, 'taxonomy' => 'category' ],
            ]
        );
        $this->taxonomy_generator->method( 'generate_term' )->willReturn( true );

        $this->assertFalse( $this->stage()->process_batch( 0, 2 )['done'] );
    }

    public function test_write_failure_is_an_error_with_the_term_id(): void {
        $this->given_rows( [ [ 'term_taxonomy_id' => 1, 'term_id' => 3, 'taxonomy' => 'category' ] ] );
        $this->taxonomy_generator->method( 'generate_term' )->willReturn( false );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 3, $result['errors'][0]['term_id'] );
        $this->assertSame( 0, $result['skipped'] );
    }

    public function test_thrown_error_is_collected_and_the_batch_continues(): void {
        $this->given_rows(
            [
                [ 'term_taxonomy_id' => 1, 'term_id' => 1, 'taxonomy' => 'category' ],
                [ 'term_taxonomy_id' => 2, 'term_id' => 2, 'taxonomy' => 'category' ],
            ]
        );
        $this->taxonomy_generator->method( 'generate_term' )
            ->willReturnCallback(
                static function ( \WP_Term $term ): bool {
                    if ( 1 === $term->term_id ) {
                        throw new \RuntimeException( 'term boom' );
                    }
                    return true;
                }
            );

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( 'term boom', $result['errors'][0]['message'] );
    }

    public function test_missing_term_object_is_an_error(): void {
        $this->wpdb->mock_get_results_queue = [
            [ (object) [ 'term_taxonomy_id' => 1, 'term_id' => 404, 'taxonomy' => 'category' ] ],
        ];

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 404, $result['errors'][0]['term_id'] );
    }

    public function test_no_public_taxonomies_short_circuits_to_done_without_querying(): void {
        $GLOBALS['_mock_taxonomies'] = [];

        $result = $this->stage()->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 0, $this->stage()->count_total() );
        $this->assertSame( [], $this->wpdb->queries );
    }
}

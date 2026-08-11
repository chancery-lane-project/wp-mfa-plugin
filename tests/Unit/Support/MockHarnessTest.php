<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Guards the mock harness itself. These mocks are load-bearing for every
 * Jobs test; a silent regression here shows up as a confusing failure
 * three suites away.
 */
class MockHarnessTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_mock_options'] = [];
        reset_mock_scheduled_events();
        unset( $GLOBALS['_mock_schedule_single_event_return'] );
    }

    public function test_wpdb_exposes_table_properties(): void {
        $wpdb = new \wpdb();

        $this->assertSame( 'wp_posts', $wpdb->posts );
        $this->assertSame( 'wp_term_taxonomy', $wpdb->term_taxonomy );
    }

    public function test_get_col_returns_queued_slices_in_order(): void {
        $wpdb                     = new \wpdb();
        $wpdb->mock_get_col_queue = [ [ 1, 2 ], [ 3 ], [] ];

        $this->assertSame( [ 1, 2 ], $wpdb->get_col( 'SELECT ID …' ) );
        $this->assertSame( [ 3 ], $wpdb->get_col( 'SELECT ID …' ) );
        $this->assertSame( [], $wpdb->get_col( 'SELECT ID …' ) );
        $this->assertCount( 3, $wpdb->queries );
    }

    public function test_get_results_prefers_queue_then_falls_back(): void {
        $wpdb                         = new \wpdb();
        $wpdb->mock_get_results       = [ 'fallback' ];
        $wpdb->mock_get_results_queue = [ [ 'first' ] ];

        $this->assertSame( [ 'first' ], $wpdb->get_results( 'SELECT …' ) );
        $this->assertSame( [ 'fallback' ], $wpdb->get_results( 'SELECT …' ) );
    }

    public function test_schedule_single_event_suppresses_near_duplicates(): void {
        $this->assertTrue( wp_schedule_single_event( 1000, 'mfa_hook' ) );
        $this->assertFalse( wp_schedule_single_event( 1001, 'mfa_hook' ) );
        $this->assertTrue( wp_schedule_single_event( 5000, 'mfa_hook' ) );
    }

    public function test_schedule_single_event_return_can_be_forced(): void {
        $GLOBALS['_mock_schedule_single_event_return'] = false;

        $this->assertFalse( wp_schedule_single_event( 1000, 'mfa_hook' ) );
        $this->assertSame( [], $GLOBALS['_mock_scheduled_events'] );
    }

    public function test_unschedule_hook_clears_every_event_for_that_hook(): void {
        wp_schedule_single_event( 1000, 'mfa_a' );
        wp_schedule_single_event( 1000, 'mfa_b' );

        $this->assertSame( 1, wp_unschedule_hook( 'mfa_a' ) );
        $this->assertFalse( wp_next_scheduled( 'mfa_a' ) );
        $this->assertSame( 1000, wp_next_scheduled( 'mfa_b' ) );
    }

    public function test_remove_filter_drops_only_the_matching_callback(): void {
        $keep = static fn( $v ) => $v;
        $drop = static fn( $v ) => $v;

        $GLOBALS['_mock_filters'] = [];
        add_filter( 'mfa_hook', $keep );
        add_filter( 'mfa_hook', $drop );

        $this->assertTrue( remove_filter( 'mfa_hook', $drop ) );
        $this->assertCount( 1, $GLOBALS['_mock_filters']['mfa_hook'] );
    }

    public function test_count_posts_and_get_term_read_their_globals(): void {
        $GLOBALS['_mock_post_counts']  = [ 'post' => [ 'publish' => 7 ] ];
        $term                          = new \WP_Term();
        $term->term_id                 = 12;
        $term->taxonomy                = 'category';
        $GLOBALS['_mock_terms_by_id']  = [ 12 => $term ];

        $this->assertSame( 7, (int) wp_count_posts( 'post' )->publish );
        $this->assertSame( 0, (int) wp_count_posts( 'page' )->publish );
        $this->assertSame( 'category', get_term( 12, 'category' )->taxonomy );
        $this->assertNull( get_term( 99, 'category' ) );
    }

    public function test_generate_password_returns_distinct_tokens(): void {
        $this->assertNotSame( wp_generate_password( 20, false ), wp_generate_password( 20, false ) );
    }
}

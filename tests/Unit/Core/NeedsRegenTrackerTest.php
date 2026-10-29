<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker;

/**
 * @covers \Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker
 */
class NeedsRegenTrackerTest extends TestCase {

    private NeedsRegenTracker $tracker;

    protected function setUp(): void {
        $GLOBALS['_mock_transients'] = [];
        $GLOBALS['_mock_options']    = [];
        $this->tracker               = new NeedsRegenTracker();
    }

    public function test_clearing_the_last_pending_type_deletes_the_transient(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'post' ], 0 );

        $this->tracker->clear( 'post' );

        $this->assertFalse( get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_clearing_one_of_several_types_keeps_the_rest(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'post', 'page' ], 0 );

        $this->tracker->clear( 'post' );

        $this->assertSame( [ 'page' ], get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_clearing_an_unlisted_type_leaves_the_transient_untouched(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'page' ], 0 );

        $this->tracker->clear( 'post' );

        $this->assertSame( [ 'page' ], get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_clearing_with_no_transient_is_a_no_op(): void {
        $this->tracker->clear( 'post' );

        $this->assertFalse( get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_flag_upgrade_merges_types_and_records_the_version(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'page' ], 0 );

        $this->tracker->flag_upgrade( [ 'post', 'page' ], '1.7.3' );

        $this->assertSame( [ 'page', 'post' ], get_transient( 'markdown_for_agents_needs_regen' ) );
        $this->assertSame( '1.7.3', get_option( NeedsRegenTracker::UPGRADE_OPTION ) );
    }

    public function test_flag_upgrade_with_no_types_does_nothing(): void {
        $this->tracker->flag_upgrade( [], '1.7.3' );

        $this->assertFalse( get_transient( 'markdown_for_agents_needs_regen' ) );
        $this->assertFalse( get_option( NeedsRegenTracker::UPGRADE_OPTION ) );
    }

    public function test_clearing_the_last_pending_type_forgets_the_upgrade(): void {
        $this->tracker->flag_upgrade( [ 'post' ], '1.7.3' );

        $this->tracker->clear( 'post' );

        $this->assertFalse( get_option( NeedsRegenTracker::UPGRADE_OPTION ) );
    }

    public function test_clearing_one_of_several_types_keeps_the_upgrade(): void {
        $this->tracker->flag_upgrade( [ 'post', 'page' ], '1.7.3' );

        $this->tracker->clear( 'post' );

        $this->assertSame( '1.7.3', get_option( NeedsRegenTracker::UPGRADE_OPTION ) );
    }
}

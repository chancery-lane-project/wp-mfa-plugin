<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Admin\NeedsRegenTracker;

/**
 * @covers \Tclp\WpMarkdownForAgents\Admin\NeedsRegenTracker
 */
class NeedsRegenTrackerTest extends TestCase {

    private NeedsRegenTracker $tracker;

    protected function setUp(): void {
        $GLOBALS['_mock_transients'] = [];
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
}

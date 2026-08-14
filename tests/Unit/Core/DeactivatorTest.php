<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Deactivator;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;

/**
 * @covers \Tclp\WpMarkdownForAgents\Core\Deactivator
 */
class DeactivatorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_mock_options'] = [];
        reset_mock_scheduled_events();
    }

    public function test_deactivation_clears_job_state_and_cron_events(): void {
        update_option( GenerationJob::OPTION, [ 'status' => 'running', 'last_tick_at' => time() ] );
        update_option( TickMutex::OPTION, [ 'token' => 't', 'acquired_at' => time() ] );
        wp_schedule_single_event( time() + 1, JobRunner::TICK_HOOK );
        wp_schedule_event( time() + 3600, 'hourly', JobRunner::WATCHDOG_HOOK );

        Deactivator::deactivate();

        $this->assertFalse( get_option( GenerationJob::OPTION ) );
        $this->assertFalse( get_option( TickMutex::OPTION ) );
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertFalse( wp_next_scheduled( JobRunner::WATCHDOG_HOOK ) );
    }

    public function test_deactivation_is_safe_with_no_job_state(): void {
        Deactivator::deactivate();

        $this->assertFalse( get_option( GenerationJob::OPTION ) );
    }
}

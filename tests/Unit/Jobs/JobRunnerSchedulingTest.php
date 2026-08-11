<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Jobs\StageFactory;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;
use Tclp\WpMarkdownForAgents\Tests\Support\FakeStage;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\JobRunner
 */
class JobRunnerSchedulingTest extends TestCase {

    private FrozenClock $clock;
    private GenerationJob $job;

    /** @var StageFactory&MockObject */
    private StageFactory $factory;

    protected function setUp(): void {
        $GLOBALS['_mock_options']         = [];
        $GLOBALS['_mock_option_autoload'] = [];
        $GLOBALS['_mock_transients']      = [];
        reset_mock_scheduled_events();
        unset( $GLOBALS['_mock_schedule_single_event_return'] );

        $this->clock   = new FrozenClock( 1_000_000 );
        $this->job     = new GenerationJob( $this->clock );
        $this->factory = $this->createMock( StageFactory::class );
    }

    private function runner(): JobRunner {
        return new JobRunner( $this->job, new TickMutex( $this->clock ), $this->factory, new NeedsRegenTracker(), $this->clock );
    }

    /** Start a running job with one unfinished stage and no pending events. */
    private function given_unfinished_job(): FakeStage {
        $this->job->start( [ [ 'type' => 'post_type', 'slug' => 'post', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );
        reset_mock_scheduled_events();

        $stage = new FakeStage(
            [ [ 'processed' => 1, 'skipped' => 0, 'errors' => [], 'next_cursor' => 1, 'done' => false ] ],
            100,
            $this->clock,
            60   // one batch spends the whole budget, so exactly one runs
        );
        $this->factory->method( 'make' )->willReturn( $stage );

        return $stage;
    }

    public function test_unfinished_job_reschedules_one_tick(): void {
        $this->given_unfinished_job();

        $this->runner()->run_tick();

        $this->assertSame( 1_000_060 + 1, wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertCount( 1, $GLOBALS['_mock_scheduled_events'] );
    }

    public function test_no_duplicate_tick_is_scheduled_when_one_is_pending(): void {
        $this->given_unfinished_job();
        wp_schedule_single_event( 1_000_500, JobRunner::TICK_HOOK );

        $this->runner()->run_tick();

        $this->assertCount( 1, $GLOBALS['_mock_scheduled_events'] );
        $this->assertSame( 1_000_500, wp_next_scheduled( JobRunner::TICK_HOOK ) );
    }

    public function test_a_failed_schedule_is_counted_and_the_job_keeps_running(): void {
        $this->given_unfinished_job();
        $GLOBALS['_mock_schedule_single_event_return'] = false;

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 1, $record['schedule_failures'] );
        $this->assertSame( 'running', $record['status'] );
    }

    public function test_three_consecutive_schedule_failures_fail_the_job(): void {
        $this->job->start( [ [ 'type' => 'post_type', 'slug' => 'post', 'total' => 100, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'running' ] ] );
        reset_mock_scheduled_events();

        $record                      = $this->job->get();
        $record['schedule_failures'] = JobRunner::MAX_SCHEDULE_FAILURES - 1;
        update_option( GenerationJob::OPTION, $record );

        $this->factory->method( 'make' )->willReturn(
            new FakeStage( [ [ 'processed' => 1, 'skipped' => 0, 'errors' => [], 'next_cursor' => 1, 'done' => false ] ], 100, $this->clock, 60 )
        );
        $GLOBALS['_mock_schedule_single_event_return'] = false;

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 'failed', $record['status'] );
        $this->assertStringContainsString( 'schedule', strtolower( $record['message'] ) );
    }

    public function test_a_successful_schedule_resets_the_failure_counter(): void {
        $this->given_unfinished_job();

        $record                      = $this->job->get();
        $record['schedule_failures'] = 2;
        update_option( GenerationJob::OPTION, $record );

        $this->runner()->run_tick();

        $this->assertSame( 0, $this->job->get()['schedule_failures'] );
    }

    public function test_nudge_runs_a_tick_when_the_event_is_missing_and_the_job_is_stale_by_a_minute(): void {
        $stage = $this->given_unfinished_job();
        $this->clock->advance( 61 );

        $this->runner()->maybe_nudge();

        $this->assertNotSame( [], $stage->cursors );
    }

    public function test_nudge_does_nothing_when_a_tick_is_already_pending(): void {
        $stage = $this->given_unfinished_job();
        wp_schedule_single_event( 1_000_500, JobRunner::TICK_HOOK );
        $this->clock->advance( 61 );

        $this->runner()->maybe_nudge();

        $this->assertSame( [], $stage->cursors );
    }

    public function test_nudge_does_nothing_for_a_recent_tick_or_an_idle_job(): void {
        $stage = $this->given_unfinished_job();
        $this->clock->advance( 30 );

        $this->runner()->maybe_nudge();
        $this->assertSame( [], $stage->cursors );

        $this->job->clear();
        $this->clock->advance( 600 );

        $this->runner()->maybe_nudge();
        $this->assertSame( [], $stage->cursors );
    }

    public function test_nudge_spends_its_own_short_budget_not_the_cron_one(): void {
        $this->job->start( [ [ 'type' => 'post_type', 'slug' => 'post', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );
        reset_mock_scheduled_events();

        $stage = new FakeStage(
            array_fill( 0, 10, [ 'processed' => 1, 'skipped' => 0, 'errors' => [], 'next_cursor' => 1, 'done' => false ] ),
            100,
            $this->clock,
            3   // 3s per batch
        );
        $this->factory->method( 'make' )->willReturn( $stage );
        $this->clock->advance( 61 );

        $this->runner()->maybe_nudge();

        // NUDGE_BUDGET is 5s: after one 3s batch elapsed is 3s (< 5, continue),
        // after a second it is 6s (>= 5, stop) — two batches, not the six a
        // 30s cron-style budget would allow before an admin page finally
        // renders.
        $this->assertCount( 2, $stage->cursors );
    }

    public function test_the_tick_budget_filter_receives_the_context(): void {
        $this->given_unfinished_job();

        $seen = [];
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] = static function ( $value, string $context ) use ( &$seen ) {
            $seen[] = $context;
            return $value;
        };

        try {
            $this->runner()->run_tick();
            reset_mock_scheduled_events(); // simulate the tick's own event going missing
            $this->clock->advance( 61 );
            $this->runner()->maybe_nudge();
        } finally {
            unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] );
        }

        $this->assertSame( [ 'cron', 'nudge' ], $seen );
    }

    public function test_watchdog_schedules_a_tick_for_a_stale_running_job(): void {
        $this->given_unfinished_job();
        $this->clock->advance( GenerationJob::STALE_AFTER + 1 );

        $this->runner()->watchdog();

        $this->assertSame( $this->clock->now(), wp_next_scheduled( JobRunner::TICK_HOOK ) );
    }

    public function test_watchdog_rescue_resets_the_failure_counter(): void {
        $this->given_unfinished_job();

        $record                      = $this->job->get();
        $record['schedule_failures'] = 2;
        update_option( GenerationJob::OPTION, $record );

        $this->clock->advance( GenerationJob::STALE_AFTER + 1 );

        $this->runner()->watchdog();

        $this->assertSame( 0, $this->job->get()['schedule_failures'] );
    }

    public function test_watchdog_ignores_fresh_jobs_pending_events_and_idle_jobs(): void {
        $this->given_unfinished_job();

        $this->runner()->watchdog();
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );

        $this->clock->advance( GenerationJob::STALE_AFTER + 1 );
        wp_schedule_single_event( 2_000_000, JobRunner::TICK_HOOK );
        $this->runner()->watchdog();
        $this->assertSame( 2_000_000, wp_next_scheduled( JobRunner::TICK_HOOK ) );

        reset_mock_scheduled_events();
        $this->job->clear();
        $this->runner()->watchdog();
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
    }
}

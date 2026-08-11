<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\GenerationJob
 */
class GenerationJobTest extends TestCase {

    private int $now;
    private FrozenClock $clock;
    private GenerationJob $job;

    protected function setUp(): void {
        $GLOBALS['_mock_options']          = [];
        $GLOBALS['_mock_option_autoload']  = [];
        reset_mock_scheduled_events();

        // is_running() is deliberately wall-clock based (see GenerationJob),
        // so this suite's frozen clock has to sit near real time for staleness
        // comparisons to mean anything.
        $this->now   = time();
        $this->clock = new FrozenClock( $this->now );
        $this->job   = new GenerationJob( $this->clock );
    }

    /** @return list<array{type: string, total: null, processed: int, skipped: int, error_count: int, state: string}> */
    private function stages(): array {
        return [
            [ 'type' => 'taxonomy', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ],
            [ 'type' => 'bundle',   'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ],
        ];
    }

    public function test_no_option_reads_as_idle(): void {
        $record = $this->job->get();

        $this->assertSame( 'idle', $record['status'] );
        $this->assertSame( [], $record['stages'] );
        $this->assertFalse( GenerationJob::is_running() );
    }

    public function test_start_writes_a_running_record_and_schedules_the_first_tick(): void {
        $result = $this->job->start( $this->stages() );

        $this->assertTrue( $result['ok'] );

        $record = $this->job->get();

        $this->assertSame( 'running', $record['status'] );
        $this->assertNotSame( '', $record['lock_token'] );
        $this->assertSame( 0, $record['stage_index'] );
        $this->assertSame( 0, $record['cursor'] );
        $this->assertSame( $this->now, $record['last_tick_at'] );
        $this->assertCount( 2, $record['stages'] );
        $this->assertSame( $this->now, wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertTrue( GenerationJob::is_running() );
    }

    public function test_job_option_is_not_autoloaded(): void {
        $this->job->start( $this->stages() );

        $this->assertSame( false, $GLOBALS['_mock_option_autoload'][ GenerationJob::OPTION ] );
    }

    public function test_start_is_rejected_while_a_fresh_job_is_running(): void {
        $this->job->start( $this->stages() );
        $first = $this->job->get()['lock_token'];

        $this->clock->advance( 30 );
        $result = $this->job->start( $this->stages() );

        $this->assertFalse( $result['ok'] );
        $this->assertNotSame( '', $result['message'] );
        $this->assertSame( $first, $this->job->get()['lock_token'] );
    }

    public function test_start_supersedes_a_job_whose_tick_went_stale(): void {
        $this->job->start( $this->stages() );
        $first = $this->job->get()['lock_token'];

        // Simulate the tick having fatalled: its last write is now old enough
        // that nothing is going to reschedule it.
        $record                 = $this->job->get();
        $record['last_tick_at'] = $this->now - ( GenerationJob::STALE_AFTER + 1 );
        update_option( GenerationJob::OPTION, $record );

        $result = $this->job->start( $this->stages() );

        $this->assertTrue( $result['ok'] );
        $this->assertNotSame( $first, $this->job->get()['lock_token'] );
        // The superseding job is live.
        $this->assertTrue( GenerationJob::is_running() );
    }

    public function test_is_running_ignores_a_stale_running_record(): void {
        $this->job->start( $this->stages() );

        $record                 = $this->job->get();
        $record['last_tick_at'] = time() - ( GenerationJob::STALE_AFTER + 1 );
        update_option( GenerationJob::OPTION, $record );

        $this->assertFalse( GenerationJob::is_running() );
    }

    public function test_save_writes_only_when_the_lock_token_matches(): void {
        $this->job->start( $this->stages() );

        $record          = $this->job->get();
        $token           = $record['lock_token'];
        $record['cursor'] = 42;

        $this->clock->advance( 5 );
        $this->assertTrue( $this->job->save( $record, $token ) );
        $this->assertSame( 42, $this->job->get()['cursor'] );
        $this->assertSame( $this->now + 5, $this->job->get()['last_tick_at'] );

        $record['cursor'] = 99;
        $this->assertFalse( $this->job->save( $record, 'not-the-token' ) );
        $this->assertSame( 42, $this->job->get()['cursor'] );
    }

    public function test_append_errors_caps_the_list_but_not_the_count(): void {
        $record = [ 'errors' => [], 'error_count' => 0 ];

        for ( $i = 1; $i <= 60; $i++ ) {
            $record = GenerationJob::append_errors( $record, [ [ 'post_id' => $i, 'message' => 'e' . $i ] ] );
        }

        $this->assertCount( GenerationJob::MAX_ERRORS, $record['errors'] );
        $this->assertSame( 60, $record['error_count'] );
        // Oldest dropped, newest kept.
        $this->assertSame( 60, $record['errors'][ GenerationJob::MAX_ERRORS - 1 ]['post_id'] );
        $this->assertSame( 11, $record['errors'][0]['post_id'] );
    }

    public function test_clear_removes_the_record(): void {
        $this->job->start( $this->stages() );
        $this->job->clear();

        $this->assertSame( 'idle', $this->job->get()['status'] );
    }
}

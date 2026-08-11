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
class JobRunnerTest extends TestCase {

    private FrozenClock $clock;
    private GenerationJob $job;
    private TickMutex $mutex;

    /** @var StageFactory&MockObject */
    private StageFactory $factory;

    protected function setUp(): void {
        $GLOBALS['_mock_options']         = [];
        $GLOBALS['_mock_option_autoload'] = [];
        $GLOBALS['_mock_transients']      = [];
        reset_mock_scheduled_events();
        unset( $GLOBALS['_mock_schedule_single_event_return'] );

        // Pin the tick budget: the default is derived from this machine's
        // max_execution_time, so unpinned batch-count assertions are
        // environment-dependent.
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] = static fn( $value ) => 30;

        $this->clock   = new FrozenClock( 1_000_000 );
        $this->job     = new GenerationJob( $this->clock );
        $this->mutex   = new TickMutex( $this->clock );
        $this->factory = $this->createMock( StageFactory::class );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] );
    }

    /** @param list<array<string, mixed>> $stages */
    private function given_job( array $stages ): void {
        $this->job->start( $stages );
        reset_mock_scheduled_events();   // drop start()'s first tick event
    }

    /** @return array<string, mixed> */
    private function descriptor( string $type, string $slug = '' ): array {
        $descriptor = [ 'type' => $type, 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ];

        if ( '' !== $slug ) {
            $descriptor['slug'] = $slug;
        }

        return $descriptor;
    }

    private function runner(): JobRunner {
        return new JobRunner( $this->job, $this->mutex, $this->factory, new NeedsRegenTracker(), $this->clock );
    }

    /** @param list<array<string, mixed>> $pages */
    private function page( int $processed, int $next_cursor, bool $done, array $errors = [] ): array {
        return [ 'processed' => $processed, 'skipped' => 0, 'errors' => $errors, 'next_cursor' => $next_cursor, 'done' => $done ];
    }

    public function test_tick_records_total_once_progress_and_cursor(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        $stage = new FakeStage( [ $this->page( 5, 5, false ), $this->page( 5, 10, false ) ], 40, $this->clock, 20 );
        $this->factory->method( 'make' )->willReturn( $stage );

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 1, $stage->count_total_calls );
        $this->assertSame( 40, $record['stages'][0]['total'] );
        $this->assertSame( 10, $record['stages'][0]['processed'] );
        $this->assertSame( 10, $record['cursor'] );
        $this->assertSame( 'running', $record['status'] );
        $this->assertSame( $this->clock->now(), $record['last_tick_at'] );
    }

    public function test_tick_processes_several_batches_and_stops_at_the_budget(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        // 10s per batch: with a 30s ceiling the third batch takes us to the
        // boundary, so exactly three batches run.
        $stage = new FakeStage(
            [ $this->page( 1, 1, false ), $this->page( 1, 2, false ), $this->page( 1, 3, false ), $this->page( 1, 4, false ) ],
            100,
            $this->clock,
            10
        );
        $this->factory->method( 'make' )->willReturn( $stage );

        $this->runner()->run_tick();

        $this->assertSame( [ 0, 1, 2 ], $stage->cursors );
        $this->assertSame( 3, $this->job->get()['cursor'] );
    }

    public function test_budget_is_filterable(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] = static fn( $value ) => 5;
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        $stage = new FakeStage( [ $this->page( 1, 1, false ), $this->page( 1, 2, false ) ], 100, $this->clock, 10 );
        $this->factory->method( 'make' )->willReturn( $stage );

        try {
            $this->runner()->run_tick();
        } finally {
            unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_tick_budget'] );
        }

        $this->assertSame( [ 0 ], $stage->cursors );
    }

    public function test_finishing_a_stage_resets_the_cursor_for_the_next_one(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ), $this->descriptor( 'taxonomy' ) ] );

        $first  = new FakeStage( [ $this->page( 2, 900, true ) ], 2 );
        $second = new FakeStage( [ $this->page( 1, 7, true ) ], 1 );
        $this->factory->method( 'make' )
            ->willReturnOnConsecutiveCalls( $first, $second );

        $this->runner()->run_tick();

        // The regression assert: stage two starts from 0, not the post-ID 900.
        $this->assertSame( [ 0 ], $second->cursors );
        $this->assertSame( 'done', $this->job->get()['stages'][0]['state'] );
    }

    public function test_finishing_a_post_type_stage_clears_the_regen_transient(): void {
        set_transient( NeedsRegenTracker::TRANSIENT, [ 'post', 'page' ], 0 );
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 1, 1, true ) ], 1 ) );

        $this->runner()->run_tick();

        $this->assertSame( [ 'page' ], get_transient( NeedsRegenTracker::TRANSIENT ) );
    }

    public function test_finishing_the_last_stage_marks_the_job_done(): void {
        $this->given_job( [ $this->descriptor( 'bundle' ) ] );
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 1, 1, true ) ], 1 ) );

        $this->runner()->run_tick();

        $this->assertSame( 'done', $this->job->get()['status'] );
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
    }

    public function test_bundle_stage_ends_the_tick_even_with_budget_left(): void {
        $this->given_job( [ $this->descriptor( 'bundle' ), $this->descriptor( 'taxonomy' ) ] );

        $bundle = new FakeStage( [ $this->page( 1, 1, true ) ], 1 );
        $after  = new FakeStage( [ $this->page( 1, 1, true ) ], 1 );
        $this->factory->method( 'make' )->willReturnOnConsecutiveCalls( $bundle, $after );

        $this->runner()->run_tick();

        $this->assertSame( [], $after->cursors );
        $this->assertSame( 1, $this->job->get()['stage_index'] );
    }

    public function test_per_item_errors_are_capped_counted_and_do_not_halt_the_job(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        $errors = [ [ 'post_id' => 1, 'message' => 'bad' ], [ 'post_id' => 2, 'message' => 'worse' ] ];
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 0, 2, true, $errors ) ], 2 ) );

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 2, $record['error_count'] );
        $this->assertSame( 2, $record['stages'][0]['error_count'] );
        $this->assertCount( 2, $record['errors'] );
        $this->assertSame( 'done', $record['status'] );
    }

    public function test_a_held_mutex_makes_the_tick_a_no_op(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );
        ( new TickMutex( $this->clock ) )->acquire();

        $stage = new FakeStage( [ $this->page( 1, 1, false ) ], 10 );
        $this->factory->method( 'make' )->willReturn( $stage );

        $this->runner()->run_tick();

        $this->assertSame( [], $stage->cursors );
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertSame( 0, $this->job->get()['cursor'] );
    }

    public function test_tick_releases_the_mutex_when_it_finishes(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );
        $this->factory->method( 'make' )->willReturn( new FakeStage( [ $this->page( 1, 1, true ) ], 1 ) );

        $this->runner()->run_tick();

        $this->assertFalse( get_option( TickMutex::OPTION ) );
    }

    public function test_tick_releases_the_mutex_when_a_stage_throws(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );
        $this->factory->method( 'make' )
            ->willReturn( new FakeStage( [], 0, null, 0, new \RuntimeException( 'count exploded' ) ) );

        $this->runner()->run_tick();

        $this->assertFalse( get_option( TickMutex::OPTION ) );

        $record = $this->job->get();

        $this->assertSame( 'failed', $record['status'] );
        $this->assertStringContainsString( 'count exploded', $record['message'] );
    }

    public function test_a_superseded_job_stops_without_rescheduling(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'post' ) ] );

        // A save() refused mid-tick means start() superseded this chain with a
        // new lock token. The tick must stop dead and schedule nothing.
        // Mocked rather than staged through the option: the runner reads the
        // token from the record it loads, so the token can only diverge
        // *after* that read — which no test can reach from outside.
        $job = $this->createMock( GenerationJob::class );
        $job->method( 'get' )->willReturn(
            [
                'status'            => 'running',
                'lock_token'        => 'stale-token',
                'stages'            => [ $this->descriptor( 'post_type', 'post' ) ],
                'stage_index'       => 0,
                'cursor'            => 0,
                'errors'            => [],
                'error_count'       => 0,
                'schedule_failures' => 0,
                'last_tick_at'      => 1_000_000,
                'message'           => '',
            ]
        );
        $job->method( 'save' )->willReturn( false );

        $stage = new FakeStage( [ $this->page( 1, 1, false ) ], 10 );
        $this->factory->method( 'make' )->willReturn( $stage );

        $runner = new JobRunner( $job, $this->mutex, $this->factory, new NeedsRegenTracker(), $this->clock );
        $runner->run_tick();

        // One batch may already have run before the refused save; what matters
        // is that nothing was scheduled and no second batch was attempted.
        $this->assertFalse( wp_next_scheduled( JobRunner::TICK_HOOK ) );
        $this->assertCount( 1, $stage->cursors );
        $this->assertFalse( get_option( TickMutex::OPTION ) );
    }

    public function test_an_unusable_stage_descriptor_is_skipped_not_fatal(): void {
        $this->given_job( [ $this->descriptor( 'post_type', 'gone' ), $this->descriptor( 'taxonomy' ) ] );

        $second = new FakeStage( [ $this->page( 1, 1, true ) ], 1 );
        $this->factory->method( 'make' )->willReturnOnConsecutiveCalls( null, $second );

        $this->runner()->run_tick();

        $record = $this->job->get();

        $this->assertSame( 'unavailable', $record['stages'][0]['state'] );
        $this->assertSame( [ 0 ], $second->cursors );
        $this->assertSame( 'done', $record['status'] );
    }

    public function test_an_idle_job_does_no_work(): void {
        $stage = new FakeStage( [ $this->page( 1, 1, false ) ], 5 );
        $this->factory->method( 'make' )->willReturn( $stage );

        $this->runner()->run_tick();

        $this->assertSame( [], $stage->cursors );
    }
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;

/**
 * Runs bulk generation as a self-rescheduling chain of time-boxed cron ticks.
 *
 * A tick processes as many batches as its wall-clock budget allows rather than
 * exactly one: WP-Cron only fires on request traffic and spawn_cron() is
 * rate-limited by WP_CRON_LOCK_TIMEOUT (60s by default), so one batch per tick
 * would cap throughput at roughly 50 items a minute.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class JobRunner {

	/** @since 1.7.0 */
	public const TICK_HOOK = 'markdown_for_agents_process_batch';

	/** @since 1.7.0 */
	public const WATCHDOG_HOOK = 'markdown_for_agents_job_watchdog';

	/** @since 1.7.0 */
	public const BATCH_LIMIT = 50;

	/** Seconds of work per tick, before max_execution_time is considered. */
	private const MAX_BUDGET = 30;

	/** Consecutive failed reschedules before the job is declared failed. */
	public const MAX_SCHEDULE_FAILURES = 3;

	/** Seconds without a tick before an admin request may nudge the chain. */
	private const NUDGE_AFTER = 60;

	/**
	 * @since  1.7.0
	 * @param  GenerationJob        $job              Job record repository.
	 * @param  TickMutex            $mutex            Guards against two concurrent ticks.
	 * @param  StageFactory         $stage_factory    Rehydrates stored stage descriptors.
	 * @param  NeedsRegenTracker    $needs_regen      Clears the regenerate notice per post type.
	 * @param  Clock                $clock            Time source.
	 * @param  BundleGenerator|null $bundle_generator Used only to spot a tree that went stale after the bundle stage ran.
	 */
	public function __construct(
		private readonly GenerationJob $job,
		private readonly TickMutex $mutex,
		private readonly StageFactory $stage_factory,
		private readonly NeedsRegenTracker $needs_regen,
		private readonly Clock $clock,
		private readonly ?BundleGenerator $bundle_generator = null,
	) {}

	/**
	 * One cron tick.
	 *
	 * Hooked to self::TICK_HOOK, and called directly by the admin_init nudge.
	 *
	 * @since  1.7.0
	 */
	public function run_tick(): void {
		$mutex_token = $this->mutex->acquire();

		if ( null === $mutex_token ) {
			// Another tick holds the lock. It will reschedule when it finishes,
			// so do nothing at all here — including no scheduling.
			return;
		}

		try {
			$this->process( $mutex_token );
		} finally {
			$this->mutex->release( $mutex_token );
		}
	}

	/**
	 * @since  1.7.0
	 * @param  string $mutex_token Token held for this tick.
	 */
	private function process( string $mutex_token ): void {
		$record = $this->job->get();

		if ( 'running' !== $record['status'] ) {
			return;
		}

		$job_token = (string) $record['lock_token'];
		$started   = $this->clock->monotonic();
		$budget    = $this->budget();

		while ( true ) {
			$stage_count = count( $record['stages'] );

			if ( $record['stage_index'] >= $stage_count ) {
				$record['status'] = 'done';
				break;
			}

			$index      = (int) $record['stage_index'];
			$descriptor = $record['stages'][ $index ];
			$stage      = $this->stage_factory->make( $descriptor );

			if ( null === $stage ) {
				// A post type disabled since the job started, say. Skip it
				// rather than failing the whole job.
				$descriptor['state']           = 'unavailable';
				$record['stages'][ $index ]    = $descriptor;
				$record['stage_index']         = $index + 1;
				$record['cursor']              = 0;
				continue;
			}

			if ( null === $descriptor['total'] ) {
				try {
					$descriptor['total'] = $stage->count_total();
				} catch ( \Throwable $e ) {
					$record['status']  = 'failed';
					$record['message'] = 'Could not count items for this stage: ' . $e->getMessage();
					break;
				}
			}

			$descriptor['state']        = 'running';
			$record['stages'][ $index ] = $descriptor;

			$batch = $stage->process_batch( (int) $record['cursor'], self::BATCH_LIMIT );

			$descriptor['processed']   += (int) $batch['processed'];
			$descriptor['skipped']     += (int) $batch['skipped'];
			$descriptor['error_count'] += count( $batch['errors'] );
			$record['stages'][ $index ] = $descriptor;
			$record['cursor']           = (int) $batch['next_cursor'];
			$record                     = GenerationJob::append_errors( $record, $batch['errors'] );

			$was_bundle = 'bundle' === $descriptor['type'];

			if ( $batch['done'] ) {
				$descriptor['state']        = 'done';
				$record['stages'][ $index ] = $descriptor;

				if ( 'post_type' === $descriptor['type'] && ! empty( $descriptor['slug'] ) ) {
					$this->needs_regen->clear( (string) $descriptor['slug'] );
				}

				// The cursor is scoped to one stage: carrying a post-ID or
				// term_taxonomy_id cursor into the next stage would make its
				// first query return nothing and mark it complete having done
				// no work.
				$record['stage_index'] = $index + 1;
				$record['cursor']      = 0;

				if ( $record['stage_index'] >= $stage_count ) {
					$record['status'] = 'done';
				}
			}

			if ( ! $this->job->save( $record, $job_token ) ) {
				// Superseded by a newer job: stop, and schedule nothing.
				return;
			}

			$this->mutex->heartbeat( $mutex_token );

			if ( 'running' !== $record['status'] || $was_bundle ) {
				break;
			}

			if ( ( $this->clock->monotonic() - $started ) >= $budget ) {
				break;
			}
		}

		if ( ! $this->job->save( $record, $job_token ) ) {
			return;
		}

		if ( 'done' === $record['status'] ) {
			$this->after_completion();
			return;
		}

		if ( 'failed' === $record['status'] ) {
			return;
		}

		$this->maybe_reschedule( $record, $job_token );
	}

	/**
	 * Seconds of work one tick may do.
	 *
	 * Checked only between batches — a batch is never interrupted.
	 *
	 * @since  1.7.0
	 */
	private function budget(): int {
		$max_exec = (int) ini_get( 'max_execution_time' );
		$budget   = $max_exec > 0 ? max( 10, (int) ( $max_exec * 0.6 ) ) : self::MAX_BUDGET;
		$budget   = min( $budget, self::MAX_BUDGET );

		/**
		 * Filter the wall-clock seconds one generation tick may spend.
		 *
		 * @since  1.7.0
		 * @param  int $budget Seconds.
		 */
		return max( 1, (int) apply_filters( 'markdown_for_agents_tick_budget', $budget ) );
	}

	/**
	 * Same-process fallback for a chain whose scheduled event has gone missing.
	 *
	 * Hooked to `admin_init` on every admin request, deliberately not only the
	 * plugin's settings screen — a lost chain should recover from anywhere in
	 * wp-admin, and the guard below is two cheap reads. Runs a tick inline and
	 * never schedules; scheduling here is what would race the cron path.
	 *
	 * @since  1.7.0
	 */
	public function maybe_nudge(): void {
		$record = $this->job->get();

		if ( 'running' !== $record['status'] ) {
			return;
		}

		if ( false !== wp_next_scheduled( self::TICK_HOOK ) ) {
			return;
		}

		if ( ( $this->clock->now() - (int) $record['last_tick_at'] ) <= self::NUDGE_AFTER ) {
			return;
		}

		$this->run_tick();
	}

	/**
	 * Hourly backstop for a chain that died where no admin request will notice.
	 *
	 * Schedules a tick rather than running one inline: cron already has a
	 * request to spend, and scheduling keeps this cheap.
	 *
	 * @since  1.7.0
	 */
	public function watchdog(): void {
		$record = $this->job->get();

		if ( 'running' !== $record['status'] ) {
			return;
		}

		if ( false !== wp_next_scheduled( self::TICK_HOOK ) ) {
			return;
		}

		if ( ( $this->clock->now() - (int) $record['last_tick_at'] ) < GenerationJob::STALE_AFTER ) {
			return;
		}

		wp_schedule_single_event( $this->clock->now(), self::TICK_HOOK );
	}

	/**
	 * Queue the next tick, treating a refused schedule as a real failure.
	 *
	 * A call to wp_schedule_single_event() returns false when a duplicate
	 * hook+args event exists within 10 minutes of the requested time, or
	 * when pre_schedule_event vetoes it. Ignoring that return value is how
	 * a chain dies silently with status stuck on `running`.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed> $record    Current job record.
	 * @param  string               $job_token Token this tick holds.
	 */
	private function maybe_reschedule( array $record, string $job_token ): void {
		if ( false !== wp_next_scheduled( self::TICK_HOOK ) ) {
			return;
		}

		if ( wp_schedule_single_event( $this->clock->now() + 1, self::TICK_HOOK ) ) {
			if ( 0 !== (int) $record['schedule_failures'] ) {
				$record['schedule_failures'] = 0;
				$this->job->save( $record, $job_token );
			}

			return;
		}

		$record['schedule_failures'] = (int) $record['schedule_failures'] + 1;

		if ( $record['schedule_failures'] >= self::MAX_SCHEDULE_FAILURES ) {
			$record['status']  = 'failed';
			$record['message'] = 'Could not schedule the next batch. WP-Cron may be disabled or blocked on this site; check the cron configuration and start the job again.';
		}

		$this->job->save( $record, $job_token );
	}

	/**
	 * Placeholder — filled in by the bundle-staleness task.
	 *
	 * @since  1.7.0
	 */
	private function after_completion(): void {
	}
}

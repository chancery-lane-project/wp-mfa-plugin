<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Repository for the single bulk-generation job record.
 *
 * Stored as one non-autoloaded option: it is rewritten on every tick, so
 * autoloading it would carry it into every front-end request and invalidate
 * the alloptions cache on every write. An option rather than a transient
 * because a persistent object cache may evict a transient mid-run, which
 * would strand the cron chain.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class GenerationJob {

	/** @since 1.7.0 */
	public const OPTION = 'markdown_for_agents_job';

	/**
	 * Seconds without a tick after which a `running` job is presumed dead.
	 *
	 * Comfortably longer than one tick's time budget plus the tick mutex's
	 * staleness window, so a slow-but-healthy job is never superseded.
	 *
	 * @since  1.7.0
	 */
	public const STALE_AFTER = 600;

	/** @since 1.7.0 */
	public const MAX_ERRORS = 50;

	/**
	 * @since  1.7.0
	 * @param  Clock $clock Time source.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Read the current record, or an idle skeleton when there is none.
	 *
	 * @since  1.7.0
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$record = get_option( self::OPTION );

		if ( ! is_array( $record ) || ! isset( $record['status'] ) ) {
			return self::idle_record();
		}

		return array_merge( self::idle_record(), $record );
	}

	/**
	 * Start a job, superseding a dead one.
	 *
	 * @since  1.7.0
	 * @param  list<array<string, mixed>> $stages Stage descriptors from StageFactory.
	 * @return array{ok: bool, message: string}
	 */
	public function start( array $stages ): array {
		if ( empty( $stages ) ) {
			return array(
				'ok'      => false,
				'message' => 'Nothing to generate for that scope.',
			);
		}

		$existing = $this->get();

		if ( 'running' === $existing['status'] && ! $this->is_stale( $existing ) ) {
			return array(
				'ok'      => false,
				'message' => 'A generation job is already running.',
			);
		}

		$record = array(
			'status'            => 'running',
			'lock_token'        => wp_generate_password( 20, false ),
			'stages'            => $stages,
			'stage_index'       => 0,
			'cursor'            => 0,
			'errors'            => array(),
			'error_count'       => 0,
			'schedule_failures' => 0,
			'last_tick_at'      => $this->clock->now(),
			'message'           => '',
		);

		$this->write( $record );

		wp_schedule_single_event( $this->clock->now(), JobRunner::TICK_HOOK );

		return array(
			'ok'      => true,
			'message' => '',
		);
	}

	/**
	 * Persist a record, but only if the caller still holds the job's token.
	 *
	 * Stamps last_tick_at so start() and the watchdog can spot a dead chain.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed> $record     Record to write.
	 * @param  string               $lock_token Token the caller acquired at tick start.
	 * @return bool True when written.
	 */
	public function save( array $record, string $lock_token ): bool {
		$current = $this->get();

		if ( '' === $lock_token || ( $current['lock_token'] ?? '' ) !== $lock_token ) {
			return false;
		}

		$record['lock_token']   = $lock_token;
		$record['last_tick_at'] = $this->clock->now();

		$this->write( $record );

		return true;
	}

	/**
	 * Append per-item errors, capping the stored list but not the count.
	 *
	 * Static so the runner can fold errors into an in-memory record without
	 * a round trip through the option.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed>       $record Job record.
	 * @param  list<array<string, mixed>> $errors Errors from one batch.
	 * @return array<string, mixed> The updated record.
	 */
	public static function append_errors( array $record, array $errors ): array {
		if ( empty( $errors ) ) {
			return $record;
		}

		$existing = isset( $record['errors'] ) && is_array( $record['errors'] ) ? $record['errors'] : array();
		$merged   = array_merge( $existing, $errors );

		$record['errors']      = array_slice( $merged, -self::MAX_ERRORS );
		$record['error_count'] = (int) ( $record['error_count'] ?? 0 ) + count( $errors );

		return $record;
	}

	/**
	 * Is a job live right now?
	 *
	 * Static and side-effect free so BundleGenerator can ask without being
	 * handed a collaborator. A `running` record with a stale last_tick_at
	 * counts as NOT running — otherwise one crashed tick would suppress
	 * bundle scheduling forever.
	 *
	 * @since  1.7.0
	 */
	public static function is_running(): bool {
		$record = get_option( self::OPTION );

		if ( ! is_array( $record ) || 'running' !== ( $record['status'] ?? '' ) ) {
			return false;
		}

		return ( time() - (int) ( $record['last_tick_at'] ?? 0 ) ) < self::STALE_AFTER;
	}

	/**
	 * Delete the record entirely (deactivation, manual reset).
	 *
	 * @since  1.7.0
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @since  1.7.0
	 * @param  array<string, mixed> $record
	 */
	private function is_stale( array $record ): bool {
		return ( $this->clock->now() - (int) ( $record['last_tick_at'] ?? 0 ) ) >= self::STALE_AFTER;
	}

	/**
	 * @since  1.7.0
	 * @param  array<string, mixed> $record
	 */
	private function write( array $record ): void {
		// add_option() sets autoload=false on first creation; update_option()
		// preserves whatever autoload flag the row already has.
		if ( ! add_option( self::OPTION, $record, '', false ) ) {
			update_option( self::OPTION, $record );
		}
	}

	/**
	 * @since  1.7.0
	 * @return array<string, mixed>
	 */
	private static function idle_record(): array {
		return array(
			'status'            => 'idle',
			'lock_token'        => '',
			'stages'            => array(),
			'stage_index'       => 0,
			'cursor'            => 0,
			'errors'            => array(),
			'error_count'       => 0,
			'schedule_failures' => 0,
			'last_tick_at'      => 0,
			'message'           => '',
		);
	}
}

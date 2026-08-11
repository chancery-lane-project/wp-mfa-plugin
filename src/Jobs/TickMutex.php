<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Short-lived mutual exclusion for a job tick.
 *
 * The wp_next_scheduled() guards elsewhere stop duplicate *pending cron
 * events*; they do not stop two ticks physically running at once — two admin
 * tabs whose admin_init nudges overlap will both see no scheduled event and
 * both hold the same valid job lock_token. This mutex narrows that window but
 * does not close it.
 *
 * The stored value is {token, acquired_at} rather than a bare timestamp, and
 * acquire() re-reads the lock immediately before stealing a stale one, so a
 * rival that has already renewed or re-acquired it by then is detected and
 * backed off from. That re-read only protects the gap between the re-read and
 * the delete, though: two overlapping steals that both judge the *same* stale
 * identity, both re-read it unchanged, and only diverge afterwards can still
 * both end up believing they hold the lock. The Options API has no atomic
 * compare-and-delete to close this fully — that would need a $wpdb
 * `DELETE ... WHERE option_name = %s AND option_value = %s`, checked by
 * affected row count, which is deliberately not implemented here: the wpdb
 * test mock would need to model the options table and its serialisation.
 * The consequence of losing this narrow residual race is duplicated work and
 * inflated counters after a crashed tick, not corruption — both ticks
 * rewrite the same files with the same content.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class TickMutex {

	/** @since 1.7.0 */
	public const OPTION = 'markdown_for_agents_job_tick_lock';

	/**
	 * @since  1.7.0
	 * @param  Clock $clock Time source.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Try to take the lock.
	 *
	 * @since  1.7.0
	 * @return string|null The token on success; null when another tick holds a
	 *                     fresh lock, or when we lost a race to steal a stale one.
	 */
	public function acquire(): ?string {
		$token = wp_generate_password( 20, false );

		// Atomic insert: the wp_options.option_name unique key makes this safe
		// against two requests racing past a PHP-level existence check.
		if ( $this->insert( $token ) ) {
			return $token;
		}

		$held = get_option( self::OPTION );

		if ( is_array( $held ) && ( $this->clock->now() - (int) ( $held['acquired_at'] ?? 0 ) ) < $this->window() ) {
			// Another tick is legitimately running. Do nothing, schedule
			// nothing — it will reschedule when it finishes.
			return null;
		}

		// Re-read immediately before stealing: the staleness judgement above
		// came from a snapshot, and if a rival has since renewed or
		// re-acquired this same lock, deleting now would remove a lock that
		// is not the one we judged abandoned. This narrows, but — per the
		// class docblock — does not close, the race between two overlapping
		// steals of the same stale lock.
		if ( get_option( self::OPTION ) !== $held ) {
			return null;
		}

		// The lock looks abandoned: its owner fatalled before releasing.
		delete_option( self::OPTION );

		if ( ! $this->insert( $token ) ) {
			return null;
		}

		// Confirm we actually own it — a rival steal may have landed in between.
		$confirm = get_option( self::OPTION );

		return ( is_array( $confirm ) && ( $confirm['token'] ?? '' ) === $token ) ? $token : null;
	}

	/**
	 * Refresh our lock's timestamp so a long healthy tick is not mistaken for
	 * an abandoned one. No-op when we no longer hold the lock.
	 *
	 * @since  1.7.0
	 * @param  string $token Token from acquire().
	 */
	public function heartbeat( string $token ): void {
		if ( ! $this->holds( $token ) ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'token'       => $token,
				'acquired_at' => $this->clock->now(),
			)
		);
	}

	/**
	 * Release the lock. Never deletes a lock that is not ours.
	 *
	 * @since  1.7.0
	 * @param  string $token Token from acquire().
	 */
	public function release( string $token ): void {
		if ( ! $this->holds( $token ) ) {
			return;
		}

		delete_option( self::OPTION );
	}

	/**
	 * Seconds before a held lock is treated as abandoned.
	 *
	 * Deliberately generous: a tick slower than its own budget is exactly the
	 * scenario this queue exists to survive, so it must not be stolen from.
	 *
	 * The floor is GenerationJob::STALE_AFTER, on every host: the mutex must
	 * never expire before the job is considered dead, or a second tick could
	 * steal the lock while the first still holds a valid lock_token, and both
	 * would write. This is a flat floor, not a scaled one — a host with
	 * max_execution_time of 30s, say, would otherwise compute a 60s window
	 * here and go on to hit exactly that race, so recovery from a genuinely
	 * wedged tick on that host is pushed from a 5-minute worst case out to
	 * STALE_AFTER's 10 minutes. Discarding a wedged tick's progress for twice
	 * as long is the accepted price for never risking it running concurrently
	 * with a new one.
	 *
	 * @since  1.7.0
	 */
	public function window(): int {
		$max_exec = (int) ini_get( 'max_execution_time' );

		return max( GenerationJob::STALE_AFTER, $max_exec * 2 );
	}

	/**
	 * @since  1.7.0
	 */
	private function insert( string $token ): bool {
		return (bool) add_option(
			self::OPTION,
			array(
				'token'       => $token,
				'acquired_at' => $this->clock->now(),
			),
			'',
			false
		);
	}

	/**
	 * @since  1.7.0
	 */
	private function holds( string $token ): bool {
		$held = get_option( self::OPTION );

		return '' !== $token && is_array( $held ) && ( $held['token'] ?? '' ) === $token;
	}
}

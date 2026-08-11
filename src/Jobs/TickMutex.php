<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Short-lived mutual exclusion for a job tick.
 *
 * The wp_next_scheduled() guards elsewhere stop duplicate *pending cron
 * events*; they do not stop two ticks physically running at once — two admin
 * tabs whose admin_init nudges overlap will both see no scheduled event and
 * both hold the same valid job lock_token. This mutex stops that.
 *
 * The stored value is {token, acquired_at} rather than a bare timestamp: two
 * ticks that both find a stale lock would otherwise both delete and both
 * insert, the second delete removing the first tick's fresh lock, and both
 * would proceed. The token lets the loser of that race detect it and back off.
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
	 * @since  1.7.0
	 */
	public function window(): int {
		$max_exec = (int) ini_get( 'max_execution_time' );

		return max( 300, $max_exec * 2 );
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

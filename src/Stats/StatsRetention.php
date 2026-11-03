<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

use Tclp\WpMarkdownForAgents\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the access-stats table to the period chosen in settings.
 *
 * The table holds one row per object, agent, access method and day, so
 * without a clean-up it grows for as long as the plugin is active. A daily
 * WP-Cron event deletes rows older than `stats_retention_days`; 0 keeps
 * everything.
 *
 * @since 1.8.0
 */
class StatsRetention {

	/** WP-Cron hook. uninstall.php clears it by this literal name. */
	public const HOOK = 'markdown_for_agents_prune_stats';

	/**
	 * Allowed periods in days; 0 keeps everything.
	 *
	 * @var int[]
	 */
	public const CHOICES = array( 30, 90, 180, 365, 0 );

	/**
	 * A year: stats shipped in March 2026, so upgrading sites lose nothing.
	 */
	public const DEFAULT_DAYS = 365;

	/**
	 * @param StatsRepository $repository Access stats storage.
	 */
	public function __construct( private readonly StatsRepository $repository ) {}

	/**
	 * Coerce a submitted or stored value to one of CHOICES.
	 *
	 * @param  mixed $value Raw value.
	 * @return int          A listed period, or DEFAULT_DAYS for anything else.
	 */
	public static function sanitize( mixed $value ): int {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$days = (int) $value;
			if ( in_array( $days, self::CHOICES, true ) ) {
				return $days;
			}
		}

		return self::DEFAULT_DAYS;
	}

	/**
	 * Schedule the daily clean-up if it is not already scheduled.
	 *
	 * Hooked to `init` rather than activation, so updated sites pick it up.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Delete rows older than the saved period. Runs on HOOK.
	 */
	public function run(): void {
		$days = self::sanitize( Options::get()['stats_retention_days'] ?? self::DEFAULT_DAYS );

		if ( 0 === $days ) {
			return;
		}

		$this->repository->delete_before_date( $days );
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * Handles database schema migrations.
 *
 * Compares the stored DB version against StatsRepository::DB_VERSION
 * and runs incremental migrations as needed. Safe to call on every
 * plugins_loaded — returns early if no migration is required.
 *
 * @since  1.2.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class Migrator {

	public const OPTION_KEY = 'wp_mfa_db_version';

	/**
	 * Run any pending DB migrations.
	 *
	 * @since  1.2.0
	 * @param  \wpdb $wpdb WordPress database abstraction.
	 */
	public static function maybe_migrate( \wpdb $wpdb ): void {
		if ( get_option( self::OPTION_KEY ) === StatsRepository::DB_VERSION ) {
			return;
		}

		$table = StatsRepository::get_table_name( $wpdb );

		// Drop the old 3-column unique index if it exists so dbDelta can
		// create the new 4-column version. dbDelta will not alter an existing
		// index — it only adds indexes whose name is entirely absent.
		$old_index = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM information_schema.STATISTICS
				 WHERE table_schema = DATABASE()
				 AND table_name = %s
				 AND index_name = 'post_agent_date'
				 AND seq_in_index = 3
				 AND column_name = 'access_date'",
				$table
			)
		);

		if ( $old_index > 0 ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX post_agent_date" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( StatsRepository::get_create_table_sql( $wpdb ) );

		// Convert old rows where agent stored the method for unknown agents.
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"UPDATE {$table} SET access_method = agent, agent = ''
			 WHERE agent IN ('accept-header', 'query-param')"
		);

		// Back-fill remaining named-agent rows — these could only have arrived via UA.
		// After column addition with DEFAULT '', un-migrated rows have access_method = ''.
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"UPDATE {$table} SET access_method = 'ua'
			 WHERE access_method IS NULL OR access_method = ''"
		);

		update_option( self::OPTION_KEY, StatsRepository::DB_VERSION );
	}
}

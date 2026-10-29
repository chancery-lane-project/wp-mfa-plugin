<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;
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

	public const OPTION_KEY = 'markdown_for_agents_db_version';

	/**
	 * Option holding every default UA string this site has already been offered.
	 *
	 * @since 1.7.3
	 */
	public const UA_SEEN_OPTION_KEY = 'markdown_for_agents_ua_defaults_seen';

	/**
	 * Last release whose upgrade check has run (see maybe_flag_upgrade_regen()).
	 *
	 * @since 1.7.3
	 */
	public const REGEN_VERSION_OPTION_KEY = 'markdown_for_agents_regen_version';

	/**
	 * Most recent release that changed existing exports, so sites upgrading
	 * from an earlier version must regenerate. Raise it in a release that
	 * changes paths, links or frontmatter of files already on disk.
	 *
	 * @since 1.7.3
	 */
	private const REGEN_REQUIRED_VERSION = '1.7.3';

	/**
	 * The UA defaults shipped in 1.1.0, before the seen-list existed.
	 *
	 * A site with no seen-list is assumed to have been offered these, so a
	 * baseline string the admin removed is not restored. Strings added later
	 * cannot be told apart from never-received ones, and are offered once.
	 *
	 * @since 1.7.3
	 */
	private const BASELINE_UA_AGENT_STRINGS = array(
		'GPTBot',
		'ChatGPT-User',
		'ClaudeBot',
		'Claude-Web',
		'anthropic-ai',
		'PerplexityBot',
		'Google-Extended',
		'Amazonbot',
		'cohere-ai',
		'meta-externalagent',
		'Bytespider',
		'CCBot',
		'Applebot-Extended',
	);

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

		// dbDelta adds missing columns and the object_agent_date unique index,
		// but never alters or drops an existing index. The new index is added
		// before the old one is dropped, so the table always has a unique key
		// and concurrent upserts cannot create duplicate daily rows.
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( StatsRepository::get_create_table_sql( $wpdb ) );

		// Drop the superseded post_agent_date index (3-column in 1.0, 4-column
		// in 1.1). Left in place it would reject a term row sharing a post's ID.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional migration query, caching not appropriate here.
		$old_index = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM information_schema.STATISTICS
				 WHERE table_schema = DATABASE()
				 AND table_name = %s
				 AND index_name = 'post_agent_date'",
				$table
			)
		);

		if ( $old_index > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Intentional schema migration; $table is $wpdb->prefix value only.
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX post_agent_date" );
		}

		// Convert old rows where agent stored the method for unknown agents.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional migration query, caching not appropriate here.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is set to $wpdb->prefix value only.
			"UPDATE {$table} SET access_method = agent, agent = ''
			 WHERE agent IN ('accept-header', 'query-param')"
		);

		// Back-fill remaining named-agent rows — these could only have arrived via UA.
		// After column addition with DEFAULT '', un-migrated rows have access_method = ''.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional migration query, caching not appropriate here.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is set to $wpdb->prefix value only.
			"UPDATE {$table} SET access_method = 'ua'
			 WHERE access_method IS NULL OR access_method = ''"
		);

		update_option( self::OPTION_KEY, StatsRepository::DB_VERSION );
	}

	/**
	 * Append newly shipped default UA strings to a saved agent list.
	 *
	 * A saved `ua_agent_strings` list replaces the defaults wholesale, so sites
	 * that saved their settings never receive strings added in later releases.
	 * This offers each default once: strings the site has not been offered are
	 * appended to its saved list, and an admin's later removal is respected.
	 * Returns early, without writes, once every default has been offered.
	 *
	 * @since  1.7.3
	 */
	public static function maybe_sync_ua_defaults(): void {
		$defaults = (array) Options::get_defaults()['ua_agent_strings'];
		$seen     = get_option( self::UA_SEEN_OPTION_KEY, null );
		$recorded = is_array( $seen );
		$seen     = $recorded ? $seen : self::BASELINE_UA_AGENT_STRINGS;
		$unseen   = array_diff( $defaults, $seen );

		if ( $recorded && array() === $unseen ) {
			return;
		}

		$saved = get_option( Options::OPTION_KEY, array() );
		if ( is_array( $saved ) && isset( $saved['ua_agent_strings'] ) && is_array( $saved['ua_agent_strings'] ) ) {
			$have  = array_map( 'strtolower', $saved['ua_agent_strings'] );
			$added = false;

			foreach ( $unseen as $string ) {
				if ( ! in_array( strtolower( $string ), $have, true ) ) {
					$saved['ua_agent_strings'][] = $string;
					$added                       = true;
				}
			}

			if ( $added ) {
				update_option( Options::OPTION_KEY, $saved );
			}
		}

		update_option( self::UA_SEEN_OPTION_KEY, array_values( array_unique( array_merge( $seen, $defaults ) ) ) );
	}

	/**
	 * Show the "regenerate" admin notice once after an upgrade that changed
	 * existing exports.
	 *
	 * Sites with no exported files are not flagged: there is nothing out of
	 * date, and a new install is told to generate by the setup steps.
	 * Returns early, without writes, once the check has run for this release.
	 *
	 * @since  1.7.3
	 */
	public static function maybe_flag_upgrade_regen(): void {
		$checked = (string) get_option( self::REGEN_VERSION_OPTION_KEY, '0' );

		if ( version_compare( $checked, self::REGEN_REQUIRED_VERSION, '>=' ) ) {
			return;
		}

		update_option( self::REGEN_VERSION_OPTION_KEY, self::REGEN_REQUIRED_VERSION );

		$options = Options::get();
		$types   = ExportPolicy::enabled_post_types( $options );
		$base    = Options::get_export_base( $options );

		foreach ( $types as $type ) {
			$type_dir = $base . '/' . sanitize_file_name( (string) $type );

			if ( is_dir( $type_dir ) && glob( $type_dir . '/*.md' ) ) {
				( new NeedsRegenTracker() )->flag_upgrade( $types, self::REGEN_REQUIRED_VERSION );
				return;
			}
		}
	}
}

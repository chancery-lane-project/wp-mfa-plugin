<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin options, the stats table, scheduled events and (when
 * `delete_files_on_uninstall` is set) the OKF bundle. Does NOT delete
 * generated Markdown files or the export directory — those are user data
 * that may be in use by other tools.
 *
 * @package Tclp\WpMarkdownForAgents
 * @since   1.0.0
 */

declare(strict_types=1);

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = get_option( 'markdown_for_agents_options', array() );

delete_option( 'markdown_for_agents_options' );
delete_option( 'markdown_for_agents_bundle_hash' );
delete_option( 'markdown_for_agents_db_version' );
delete_transient( 'markdown_for_agents_needs_regen' );
wp_clear_scheduled_hook( 'markdown_for_agents_rebuild_bundle' );

// A half-finished bulk-generation job must not survive uninstall either —
// its cron events would be orphaned and its tick lock would block any future
// reinstall. Literal option/hook names, not GenerationJob::OPTION etc.: the
// plugin autoloader is not loaded at this point in the file (it is required
// below, conditionally, only for the bundle path), so referencing those
// classes here would fatal. Do not "tidy" these into constants.
delete_option( 'markdown_for_agents_job' );
delete_option( 'markdown_for_agents_job_tick_lock' );
wp_clear_scheduled_hook( 'markdown_for_agents_process_batch' );
wp_clear_scheduled_hook( 'markdown_for_agents_job_watchdog' );

// Drop the access-stats table.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mfa_access_stats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( ! empty( $options['delete_files_on_uninstall'] ) ) {
	// Use BundleGenerator so the bundle path stays in one place; the plugin's
	// autoloader is not loaded during uninstall, so require it here.
	$mfa_autoload = __DIR__ . '/vendor/autoload.php';

	if ( file_exists( $mfa_autoload ) ) {
		require_once $mfa_autoload;

		$bundle = ( new Tclp\WpMarkdownForAgents\Generator\BundleGenerator( is_array( $options ) ? $options : array() ) )->bundle_path();

		if ( file_exists( $bundle ) ) {
			unlink( $bundle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}

// Note: the export tree itself (wp-content/uploads/{export_dir}/) is never
// deleted here, even when delete_files_on_uninstall is set — that is a
// pre-existing gap tracked as a follow-up, not addressed by this bundle work.

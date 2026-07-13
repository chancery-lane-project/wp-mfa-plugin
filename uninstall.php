<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin options. Does NOT delete generated Markdown files or the
 * export directory — those are user data that may be in use by other tools.
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

if ( ! empty( $options['delete_files_on_uninstall'] ) ) {
	$export_dir = sanitize_file_name( (string) ( $options['export_dir'] ?? 'wp-mfa-exports' ) );
	$upload_dir = wp_upload_dir();
	$bundle     = rtrim( (string) $upload_dir['basedir'], '/\\' ) . '/' . $export_dir . '.tar.gz';

	if ( file_exists( $bundle ) ) {
		unlink( $bundle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}

// Note: the export tree itself (wp-content/uploads/{export_dir}/) is never
// deleted here, even when delete_files_on_uninstall is set — that is a
// pre-existing gap tracked as a follow-up, not addressed by this bundle work.

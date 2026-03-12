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

delete_option( 'wp_mfa_options' );

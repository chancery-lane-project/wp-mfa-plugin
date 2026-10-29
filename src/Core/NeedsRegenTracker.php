<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `markdown_for_agents_needs_regen` transient that drives the
 * "settings changed, regenerate" admin notice.
 *
 * Extracted from Admin so JobRunner can clear a post type when the job
 * finishes regenerating it — the old call site was a private Admin method
 * reachable only from the retired AJAX handler.
 *
 * Lives in Core rather than Admin deliberately: both the admin UI (which flags
 * post types on a settings change and renders the notice) and the background
 * job queue (which clears them as stages complete) depend on it, so filing it
 * under Admin would point Jobs\JobRunner at the admin-UI layer.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class NeedsRegenTracker {

	/**
	 * Transient key. The single source of truth — SettingsPage flags post types
	 * here on a settings change and Admin renders the notice from it.
	 *
	 * @since  1.7.0
	 */
	public const TRANSIENT = 'markdown_for_agents_needs_regen';

	/**
	 * Plugin version whose upgrade requires regeneration, while the notice
	 * for it is pending. Changes the notice wording; cleared with the
	 * transient.
	 *
	 * @since 1.7.3
	 */
	public const UPGRADE_OPTION = 'markdown_for_agents_regen_upgrade';

	/**
	 * Remove a post type from the pending list, deleting the transient
	 * entirely once every flagged type has been regenerated.
	 *
	 * @since  1.7.0
	 * @param  string $post_type Slug just regenerated to completion.
	 */
	public function clear( string $post_type ): void {
		$pending = get_transient( self::TRANSIENT );

		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}

		$remaining = array_values( array_diff( $pending, array( $post_type ) ) );

		if ( empty( $remaining ) ) {
			delete_transient( self::TRANSIENT );
			delete_option( self::UPGRADE_OPTION );
			return;
		}

		if ( $remaining !== $pending ) {
			set_transient( self::TRANSIENT, $remaining, 0 );
		}
	}

	/**
	 * Flag post types for regeneration because a plugin upgrade changed the
	 * output format. Merges with any types already pending.
	 *
	 * @since  1.7.3
	 * @param  string[] $post_types Post types to flag.
	 * @param  string   $version    Plugin version that requires it.
	 */
	public function flag_upgrade( array $post_types, string $version ): void {
		if ( empty( $post_types ) ) {
			return;
		}

		$pending = get_transient( self::TRANSIENT );
		$pending = is_array( $pending ) ? $pending : array();

		set_transient( self::TRANSIENT, array_values( array_unique( array_merge( $pending, $post_types ) ) ), 0 );
		update_option( self::UPGRADE_OPTION, $version );
	}
}

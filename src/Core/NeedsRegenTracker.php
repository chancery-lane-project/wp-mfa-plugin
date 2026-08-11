<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

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
			return;
		}

		if ( $remaining !== $pending ) {
			set_transient( self::TRANSIENT, $remaining, 0 );
		}
	}
}

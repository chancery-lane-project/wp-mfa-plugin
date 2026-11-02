<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Homepage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Keeps pages with a core Latest Posts block fresh in their Markdown export.
 *
 * Export files are static, so a page listing the latest posts goes stale as
 * soon as a post is published. This marks such pages when a post is
 * published, unpublished, deleted, or has its title, date or slug changed,
 * and regenerates each marked page once at the end of the request. Bulk
 * publishing therefore costs one render per page, not one per post.
 *
 * Only wired when auto-generate is on, matching how term archives are kept
 * up to date.
 *
 * @since  1.8.0
 * @package Tclp\WpMarkdownForAgents\Homepage
 */
final class HomepageRefresher {

	/**
	 * The core block that lists the latest posts. It only lists the `post`
	 * post type.
	 *
	 * @since  1.8.0
	 */
	public const LATEST_POSTS_BLOCK = 'core/latest-posts';

	/**
	 * Page IDs awaiting regeneration, as keys.
	 *
	 * @var array<int, true>
	 */
	private array $pending = array();

	/**
	 * @since  1.8.0
	 * @param  array<string, mixed> $options   Plugin options.
	 * @param  Generator            $generator Writes the refreshed export files.
	 */
	public function __construct(
		private readonly array $options,
		private readonly Generator $generator
	) {}

	/**
	 * Hook callback for transition_post_status.
	 *
	 * @since  1.8.0
	 * @param  string   $new_status New post status.
	 * @param  string   $old_status Previous post status.
	 * @param  \WP_Post $post       The post.
	 */
	public function on_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		// Updates to an already-published post are handled by on_post_updated,
		// which can tell whether anything the list shows has changed.
		if ( $new_status === $old_status || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
			return;
		}

		$this->mark_pages_listing( $post );
	}

	/**
	 * Hook callback for post_updated: catch changes the list displays.
	 *
	 * @since  1.8.0
	 * @param  int      $post_id     The post ID.
	 * @param  \WP_Post $post_after  The post as saved.
	 * @param  \WP_Post $post_before The post before the update.
	 */
	public function on_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return;
		}

		if ( $post_after->post_title === $post_before->post_title
			&& $post_after->post_date === $post_before->post_date
			&& $post_after->post_name === $post_before->post_name
			&& $post_after->post_password === $post_before->post_password ) {
			return;
		}

		$this->mark_pages_listing( $post_after );
	}

	/**
	 * Hook callback for after_delete_post: a published post deleted without
	 * passing through the trash.
	 *
	 * @since  1.8.0
	 * @param  int      $post_id The deleted post's ID.
	 * @param  \WP_Post $post    The deleted post.
	 */
	public function on_after_delete_post( int $post_id, \WP_Post $post ): void {
		if ( 'publish' === $post->post_status ) {
			$this->mark_pages_listing( $post );
		}
	}

	/**
	 * Regenerate every marked page once. Hooked to `shutdown`, before the
	 * index listings are flushed, so their rebuild sees the new file.
	 *
	 * @since  1.8.0
	 */
	public function flush(): void {
		$pending       = array_keys( $this->pending );
		$this->pending = array();

		foreach ( $pending as $page_id ) {
			$page = get_post( $page_id );

			if ( ! $page instanceof \WP_Post ) {
				continue;
			}

			try {
				// generate_post() skips pages that are no longer eligible.
				$this->generator->generate_post( $page );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, guarded by WP_DEBUG.
					error_log( 'WP Markdown for Agents: refreshing page ' . $page_id . ' failed: ' . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Return the page IDs currently awaiting regeneration.
	 *
	 * @since  1.8.0
	 * @return int[]
	 */
	public function pending(): array {
		return array_keys( $this->pending );
	}

	/**
	 * Mark each watched page that has a Latest Posts block, when the post
	 * changed is a `post`.
	 *
	 * @since  1.8.0
	 * @param  \WP_Post $post The post that changed.
	 */
	private function mark_pages_listing( \WP_Post $post ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}

		foreach ( $this->watched_page_ids() as $page_id ) {
			if ( $page_id === $post->ID || isset( $this->pending[ $page_id ] ) ) {
				continue;
			}

			$page = get_post( $page_id );

			// has_block() also finds the block nested inside groups and columns.
			if ( $page instanceof \WP_Post && has_block( self::LATEST_POSTS_BLOCK, $page ) ) {
				$this->pending[ $page_id ] = true;
			}
		}
	}

	/**
	 * Return the IDs of pages to keep fresh.
	 *
	 * @since  1.8.0
	 * @return int[]
	 */
	private function watched_page_ids(): array {
		$homepage = AgentHomepage::id( $this->options );

		/**
		 * Filter the pages whose Markdown export is regenerated when a post
		 * listed by their Latest Posts block changes.
		 *
		 * Defaults to the agent homepage. Add the IDs of other pages that use
		 * the block.
		 *
		 * @since 1.8.0
		 * @param int[] $page_ids Page IDs.
		 */
		$ids = (array) apply_filters( 'markdown_for_agents_refresh_pages', $homepage > 0 ? array( $homepage ) : array() );

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
	}
}

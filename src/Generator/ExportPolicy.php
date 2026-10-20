<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Single source of truth for export eligibility and file-path shape.
 *
 * These rules were previously duplicated across Generator, InternalUrlResolver,
 * TaxonomyArchiveGenerator, IndexGenerator and CLI\Commands; any change to what
 * gets exported, or where, must be made here so all consumers stay in lockstep.
 *
 * Stateless: every method takes the resolved options array it needs.
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
final class ExportPolicy {

	/**
	 * Directory under the export base that holds taxonomy term archives.
	 *
	 * @since  1.6.0
	 */
	public const TAXONOMY_DIR = 'taxonomy';

	/**
	 * Return the post types enabled for export.
	 *
	 * @since  1.6.0
	 * @param  array<string, mixed> $options Plugin options.
	 * @return string[]
	 */
	public static function enabled_post_types( array $options ): array {
		return (array) ( $options['post_types'] ?? array() );
	}

	/**
	 * Check whether a post is eligible for export.
	 *
	 * Eligible means: an enabled post type, published, not password-protected,
	 * and not excluded via the per-post meta flag.
	 *
	 * @since  1.6.0
	 * @param  \WP_Post             $post    The post to check.
	 * @param  array<string, mixed> $options Plugin options.
	 * @return bool
	 */
	public static function is_eligible( \WP_Post $post, array $options ): bool {
		if ( ! in_array( $post->post_type, self::enabled_post_types( $options ), true )
			|| 'publish' !== $post->post_status
			|| '' !== $post->post_password
			|| get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
			return false;
		}

		/**
		 * Filter whether an otherwise eligible post is exported.
		 *
		 * Only runs for posts that passed the built-in checks, so it can narrow
		 * the export (e.g. to the default language on a multilingual site) but
		 * never widen it to drafts or password-protected posts.
		 *
		 * @since  1.7.3
		 * @param  bool     $eligible Always true when the filter runs.
		 * @param  \WP_Post $post     The post.
		 */
		return (bool) apply_filters( 'markdown_for_agents_is_eligible', true, $post );
	}

	/**
	 * Return a post's export path relative to the export base.
	 *
	 * Shape: `{sanitised-post-type}/{sanitised-slug}.md`, forward slashes. For
	 * hierarchical post types the ancestors' slugs become directories
	 * (`page/about/team.md`), because WordPress only keeps page slugs unique
	 * among siblings.
	 *
	 * @since  1.6.0
	 * @since  1.7.3 Hierarchical types nest under their ancestors; filterable.
	 * @param  \WP_Post $post The post.
	 * @return string
	 */
	public static function post_relative_path( \WP_Post $post ): string {
		$type    = sanitize_file_name( $post->post_type );
		$default = $type . '/' . self::slug_path( $post ) . '.md';

		/**
		 * Filter a post's export path relative to the export base.
		 *
		 * Honoured everywhere a post's path is built: generation, serving,
		 * manifests, index listings and link rewriting. The result must stay
		 * inside the post type's directory and end in `.md`; anything else is
		 * ignored and the default is used.
		 *
		 * @since  1.7.3
		 * @param  string   $path Default path, e.g. `page/about/team.md`.
		 * @param  \WP_Post $post The post.
		 */
		$filtered = (string) apply_filters( 'markdown_for_agents_post_relative_path', $default, $post );

		return self::is_valid_post_path( $filtered, $type ) ? $filtered : $default;
	}

	/**
	 * Return a post's export path relative to its post type's directory.
	 *
	 * This is the form manifests and post-type index listings use, e.g.
	 * `about/team.md` for `page/about/team.md`.
	 *
	 * @since  1.7.3
	 * @param  \WP_Post $post The post.
	 * @return string
	 */
	public static function post_type_relative_path( \WP_Post $post ): string {
		return substr( self::post_relative_path( $post ), strlen( sanitize_file_name( $post->post_type ) ) + 1 );
	}

	/**
	 * Build the slug part of a post's path, prefixed by ancestor slugs for
	 * hierarchical post types.
	 *
	 * Uses the passed object's own post_name rather than re-reading it, so
	 * callers can pass a clone with a restored slug (see the trash handler).
	 *
	 * @since  1.7.3
	 * @param  \WP_Post $post The post.
	 * @return string
	 */
	private static function slug_path( \WP_Post $post ): string {
		$segments = array( sanitize_file_name( $post->post_name ) );

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			// Nearest ancestor first, so each one is prepended.
			foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
				$ancestor = get_post( $ancestor_id );

				if ( $ancestor instanceof \WP_Post ) {
					array_unshift( $segments, sanitize_file_name( $ancestor->post_name ) );
				}
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Check a filtered path stays inside its post type's directory.
	 *
	 * @since  1.7.3
	 * @param  string $path Candidate path relative to the export base.
	 * @param  string $type Sanitised post type.
	 * @return bool
	 */
	private static function is_valid_post_path( string $path, string $type ): bool {
		if ( ! str_starts_with( $path, $type . '/' ) || ! str_ends_with( $path, '.md' ) || str_contains( $path, '\\' ) ) {
			return false;
		}

		foreach ( explode( '/', substr( $path, strlen( $type ) + 1 ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return a term archive's export path relative to the export base.
	 *
	 * Shape: `taxonomy/{sanitised-taxonomy}/{sanitised-slug}.md`, forward slashes.
	 *
	 * @since  1.6.0
	 * @param  string $taxonomy Taxonomy slug.
	 * @param  string $slug     Term slug.
	 * @return string
	 */
	public static function term_relative_path( string $taxonomy, string $slug ): string {
		return self::TAXONOMY_DIR . '/' . sanitize_file_name( $taxonomy ) . '/' . sanitize_file_name( $slug ) . '.md';
	}
}

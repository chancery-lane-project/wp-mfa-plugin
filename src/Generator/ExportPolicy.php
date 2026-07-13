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
		return in_array( $post->post_type, self::enabled_post_types( $options ), true )
			&& 'publish' === $post->post_status
			&& '' === $post->post_password
			&& ! get_post_meta( $post->ID, '_markdown_for_agents_excluded', true );
	}

	/**
	 * Return a post's export path relative to the export base.
	 *
	 * Shape: `{sanitised-post-type}/{sanitised-slug}.md`, forward slashes.
	 *
	 * @since  1.6.0
	 * @param  \WP_Post $post The post.
	 * @return string
	 */
	public static function post_relative_path( \WP_Post $post ): string {
		return sanitize_file_name( $post->post_type ) . '/' . sanitize_file_name( $post->post_name ) . '.md';
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

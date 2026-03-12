<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Assembles the frontmatter array for a post.
 *
 * Adapted from wp-to-file AbstractProcessor::prepareMeta(). SSG-specific keys
 * (layout, eleventyComputed, file_type, relative permalink) are not included.
 * Permalink is the canonical absolute URL.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class FrontmatterBuilder {

	/**
	 * @since  1.0.0
	 * @param  TaxonomyCollector    $taxonomy_collector Injected collector for testability.
	 * @param  array<string, mixed> $options            Plugin options.
	 */
	public function __construct(
		private readonly TaxonomyCollector $taxonomy_collector,
		private readonly array $options = array()
	) {}

	/**
	 * Build the frontmatter array for a post.
	 *
	 * @since  1.0.0
	 * @param  \WP_Post $post The post to build frontmatter for.
	 * @return array<string, mixed>
	 */
	public function build( \WP_Post $post ): array {
		$frontmatter = array(
			'title'     => wp_strip_all_tags( $post->post_title ),
			'date'      => $this->to_iso8601( $post->post_date_gmt ),
			'modified'  => $this->to_iso8601( $post->post_modified_gmt ),
			'permalink' => get_permalink( $post->ID ),
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'excerpt'   => wp_strip_all_tags( $post->post_excerpt ),
			'wpid'      => $post->ID,
		);

		if ( ! empty( $this->options['include_taxonomies'] ) ) {
			$terms       = $this->taxonomy_collector->collect( $post->ID, $post->post_type );
			$frontmatter = array_merge( $frontmatter, $terms );
		}

		if ( ! empty( $this->options['include_meta'] ) && ! empty( $this->options['meta_keys'] ) ) {
			foreach ( (array) $this->options['meta_keys'] as $key ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key ) {
					$frontmatter[ $key ] = get_post_meta( $post->ID, $key, true );
				}
			}
		}

		$frontmatter = $this->add_featured_image( $frontmatter, $post );

		/**
		 * Modify the frontmatter array before serialisation.
		 *
		 * @since  1.0.0
		 * @param  array<string, mixed> $frontmatter The assembled frontmatter.
		 * @param  \WP_Post             $post        The post.
		 */
		return apply_filters( 'wp_mfa_frontmatter', $frontmatter, $post );
	}

	/**
	 * Add featured image URL and alt text to frontmatter, if set.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $frontmatter Existing frontmatter.
	 * @param  \WP_Post             $post        The post.
	 * @return array<string, mixed>
	 */
	private function add_featured_image( array $frontmatter, \WP_Post $post ): array {
		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		if ( ! $thumbnail_id ) {
			return $frontmatter;
		}

		$url = wp_get_attachment_url( $thumbnail_id );

		if ( $url ) {
			$frontmatter['featured_image'] = $url;

			$alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
			if ( $alt ) {
				$frontmatter['featured_image_alt'] = wp_strip_all_tags( (string) $alt );
			}
		}

		return $frontmatter;
	}

	/**
	 * Convert a WordPress GMT date string to ISO 8601 format.
	 *
	 * @since  1.0.0
	 * @param  string $date WordPress date (YYYY-MM-DD HH:MM:SS).
	 * @return string ISO 8601 (YYYY-MM-DDTHH:MM:SSZ) or empty string.
	 */
	private function to_iso8601( string $date ): string {
		if ( empty( $date ) || '0000-00-00 00:00:00' === $date ) {
			return '';
		}
		return str_replace( ' ', 'T', $date ) . 'Z';
	}
}

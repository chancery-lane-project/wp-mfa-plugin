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
	 * @param  FieldResolver        $field_resolver     Resolves custom field values.
	 * @param  TaxonomyCollector    $taxonomy_collector Injected collector for testability.
	 * @param  array<string, mixed> $options            Plugin options.
	 */
	public function __construct(
		private readonly FieldResolver $field_resolver,
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

		// Per-post-type frontmatter fields (takes priority over global meta_keys).
		$type_config = $this->options['post_type_configs'][ $post->post_type ] ?? array();
		$fm_fields   = (array) ( $type_config['frontmatter_fields'] ?? array() );

		foreach ( $fm_fields as $field_path ) {
			$key   = $this->field_key( $field_path );
			$value = $this->field_resolver->resolve( $post->ID, $field_path );
			if ( null !== $value && '' !== $value ) {
				$frontmatter[ $key ] = self::normalize_value( $value );
			}
		}

		$frontmatter = $this->add_featured_image( $frontmatter, $post );

		if ( ! empty( $this->options['include_hierarchy'] ) && is_post_type_hierarchical( $post->post_type ) ) {
			$parent_id                = wp_get_post_parent_id( $post->ID );
			$frontmatter['parent']    = $parent_id ? $parent_id : null;
			$frontmatter['ancestors'] = get_post_ancestors( $post->ID );
			$children                 = get_posts(
				array(
					'post_type'      => $post->post_type,
					'post_parent'    => $post->ID,
					'post_status'    => 'publish',
					'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- intentional, bounded to direct children only
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$frontmatter['children'] = is_array( $children ) ? $children : array();
		}

		if ( ! empty( $this->options['include_author'] ) ) {
			$user = get_userdata( (int) $post->post_author );
			if ( $user instanceof \WP_User ) {
				$frontmatter['author'] = $user->display_name;
			}
		}

		/**
		 * Modify the frontmatter array before serialisation.
		 *
		 * @since  1.0.0
		 * @param  array<string, mixed> $frontmatter The assembled frontmatter.
		 * @param  \WP_Post             $post        The post.
		 */
		return apply_filters( 'markdown_for_agents_frontmatter', $frontmatter, $post );
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
			if ( ! empty( $this->options['relative_image_paths'] ) ) {
				$url = wp_make_link_relative( $url );
			}
			$frontmatter['featured_image'] = $url;

			$alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
			if ( $alt ) {
				$frontmatter['featured_image_alt'] = wp_strip_all_tags( (string) $alt );
			}
		}

		return $frontmatter;
	}

	/**
	 * Normalize a field value for safe YAML serialisation.
	 *
	 * Converts WP_Post objects (e.g. from ACF relationship fields) to their
	 * titles. Recursively normalizes arrays.
	 *
	 * @since  1.1.0
	 * @param  mixed $value The raw field value.
	 * @return mixed Normalised value safe for YamlFormatter.
	 */
	private static function normalize_value( mixed $value ): mixed {
		if ( $value instanceof \WP_Post ) {
			return $value->post_title;
		}

		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'normalize_value' ), $value );
		}

		return $value;
	}

	/**
	 * Extract the display key from a field path.
	 *
	 * For dot-notation paths, returns the last segment (e.g. `group.subfield` → `subfield`).
	 * For plain keys, returns the key as-is.
	 *
	 * @since  1.1.0
	 * @param  string $field_path Field key or dot-notation path.
	 * @return string
	 */
	private function field_key( string $field_path ): string {
		if ( str_contains( $field_path, '.' ) ) {
			$segments = explode( '.', $field_path );
			return end( $segments );
		}

		return $field_path;
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

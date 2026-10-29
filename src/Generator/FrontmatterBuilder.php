<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles the frontmatter array for a post.
 *
 * SSG-specific keys (layout, eleventyComputed, file_type, relative permalink)
 * are not included. Permalink is the canonical absolute URL.
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
			'title'     => self::clean_text( $post->post_title ),
			'date'      => $this->to_iso8601( $post->post_date_gmt ),
			'modified'  => $this->to_iso8601( $post->post_modified_gmt ),
			'permalink' => get_permalink( $post->ID ),
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'excerpt'   => self::clean_text( $post->post_excerpt ),
			'wpid'      => $post->ID,
		);

		$collected = null;

		if ( ! empty( $this->options['include_taxonomies'] ) ) {
			$collected   = $this->taxonomy_collector->collect( $post->ID, $post->post_type );
			$frontmatter = array_merge( $frontmatter, $collected );
		}

		// Per-post-type frontmatter fields (takes priority over global meta_keys).
		$type_config = $this->options['post_type_configs'][ $post->post_type ] ?? array();
		$fm_fields   = (array) ( $type_config['frontmatter_fields'] ?? array() );

		$field_keys = $this->field_keys( $fm_fields, array_keys( $frontmatter ) );

		foreach ( $fm_fields as $field_path ) {
			$key   = $field_keys[ $field_path ];
			$value = self::normalize_value( $this->field_resolver->resolve( $post->ID, $field_path ) );
			if ( null !== $value && '' !== $value ) {
				$frontmatter[ $key ] = $value;
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

		if ( '' !== $frontmatter['modified'] ) {
			$frontmatter['timestamp'] = $frontmatter['modified'];
		}

		if ( null === $collected ) {
			$collected = $this->taxonomy_collector->collect( $post->ID, $post->post_type );
		}

		$flat = array();
		foreach ( $collected as $names ) {
			foreach ( (array) $names as $name ) {
				if ( ! in_array( $name, $flat, true ) ) {
					$flat[] = $name;
				}
			}
		}

		/**
		 * Modify the flat OKF tags list before it is written to frontmatter.
		 *
		 * @since  1.6.0
		 * @param  string[]  $flat The deduplicated cross-taxonomy tag list.
		 * @param  \WP_Post  $post The post.
		 */
		$frontmatter['tags'] = (array) apply_filters( 'markdown_for_agents_flat_tags', $flat, $post );

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
				$frontmatter['featured_image_alt'] = self::clean_text( (string) $alt );
			}
		}

		return $frontmatter;
	}

	/**
	 * Normalize a field value for safe YAML serialisation.
	 *
	 * Converts WP_Post objects (e.g. from ACF relationship fields) to their
	 * titles and WP_Term objects to their names, and cleans HTML out of strings
	 * (e.g. ACF WYSIWYG fields). Recursively normalizes arrays.
	 *
	 * @since  1.1.0
	 * @param  mixed $value The raw field value.
	 * @return mixed Normalised value safe for YamlFormatter.
	 */
	private static function normalize_value( mixed $value ): mixed {
		if ( $value instanceof \WP_Post ) {
			return $value->post_title;
		}

		if ( $value instanceof \WP_Term ) {
			return $value->name;
		}

		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'normalize_value' ), $value );
		}

		if ( is_string( $value ) ) {
			return self::clean_text( $value );
		}

		return $value;
	}

	/**
	 * Reduce an HTML string to single-line plain text for frontmatter.
	 *
	 * Strings with no tags or entities are returned unchanged, so plain values
	 * such as "a < b" or deliberate line breaks are kept. Otherwise, block-level
	 * tags become spaces (so paragraphs do not run together), then
	 * wp_strip_all_tags() strips tags and collapses whitespace, and entities
	 * are decoded last so encoded text such as "&lt;b&gt;" is not stripped.
	 *
	 * @since  1.7.3
	 * @param  string $value The raw string.
	 * @return string Plain text.
	 */
	private static function clean_text( string $value ): string {
		$has_tag    = 1 === preg_match( '/<\/?[a-z!][^>]*>/i', $value );
		$has_entity = 1 === preg_match( '/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $value );

		if ( ! $has_tag && ! $has_entity ) {
			return $value;
		}

		// wp_strip_all_tags() does not separate adjacent blocks, so do that first.
		$text = preg_replace( '/<\/?(?:p|div|br|hr|li|ul|ol|h[1-6]|blockquote|pre|table|tr|td|th|section|article)\b[^>]*>/i', ' ', $value ) ?? $value;
		// Turn non-breaking spaces into plain spaces so the collapse below catches them.
		$text = str_ireplace( array( '&nbsp;', '&#160;', '&#xa0;', "\u{00A0}" ), ' ', $text );
		$text = wp_strip_all_tags( $text, true );

		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Choose stable keys before resolving values, so empty fields cannot rename others.
	 *
	 * Keep legacy short keys unless dotted paths collide with another configured
	 * leaf or an automatic frontmatter key. Plain keys retain their explicit names.
	 *
	 * @param string[] $fields Configured paths.
	 * @param string[] $existing Keys already in frontmatter.
	 * @return array<string, string>
	 */
	private function field_keys( array $fields, array $existing ): array {
		$fields = array_values( array_unique( $fields ) );
		$counts = array_count_values( array_map( array( $this, 'field_key' ), $fields ) );
		$reserved = array_fill_keys(
			array_merge( $existing, array( 'featured_image', 'featured_image_alt', 'parent', 'ancestors', 'children', 'author', 'timestamp', 'tags' ) ),
			true
		);
		$keys = array();

		// Reserve explicit plain keys and all full paths before choosing fallbacks.
		$used = $reserved;
		foreach ( $fields as $path ) {
			$used[ $path ] = true;
		}

		foreach ( $fields as $path ) {
			$leaf = $this->field_key( $path );
			$key  = $leaf;
			if ( str_contains( $path, '.' ) && ( $counts[ $leaf ] > 1 || isset( $reserved[ $leaf ] ) ) ) {
				$key = $path;
				if ( isset( $reserved[ $key ] ) ) {
					do {
						$key = 'custom.' . $key;
					} while ( isset( $used[ $key ] ) );
				}
			}
			$keys[ $path ] = $key;
			$used[ $key ]  = true;
		}

		return $keys;
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

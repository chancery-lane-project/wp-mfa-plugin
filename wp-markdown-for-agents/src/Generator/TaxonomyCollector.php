<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Extracts taxonomy terms for a post, ready for frontmatter.
 *
 * Adapted from wp-to-file AbstractProcessor::prepareTaxonomies() and
 * normalizeTaxonomyName(). Unlike wp-to-file, this class collects ALL
 * registered taxonomies for the post type rather than a configured allow-list,
 * and normalises names using well-known slug mappings.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class TaxonomyCollector {

	/**
	 * Well-known taxonomy slug → frontmatter key mappings.
	 *
	 * @since  1.0.0
	 * @var    array<string, string>
	 */
	private const TAXONOMY_NAME_MAP = array(
		'post_tag' => 'tags',
		'category' => 'categories',
	);

	/**
	 * Collect all taxonomy terms for a post, keyed by normalised taxonomy name.
	 *
	 * Returns an array suitable for merging into a frontmatter array:
	 *
	 *   ['categories' => ['News', 'Climate'], 'tags' => ['legal']]
	 *
	 * Taxonomies with no terms assigned are omitted.
	 * HTML entities in term names are decoded.
	 *
	 * @since  1.0.0
	 * @param  int    $post_id   The post ID.
	 * @param  string $post_type The post type slug.
	 * @return array<string, string[]>
	 */
	public function collect( int $post_id, string $post_type ): array {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$result     = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy->name );

			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			$key            = $this->normalise_name( $taxonomy->name );
			$result[ $key ] = array_values(
				array_filter(
					array_map(
						fn( $term ) => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
						$terms
					)
				)
			);
		}

		return $result;
	}

	/**
	 * Normalise a taxonomy slug to a frontmatter key.
	 *
	 * Maps well-known WordPress slugs (`post_tag` → `tags`, `category` → `categories`).
	 * All other slugs are returned as-is.
	 *
	 * @since  1.0.0
	 * @param  string $taxonomy The taxonomy slug.
	 * @return string
	 */
	private function normalise_name( string $taxonomy ): string {
		return self::TAXONOMY_NAME_MAP[ $taxonomy ] ?? $taxonomy;
	}
}

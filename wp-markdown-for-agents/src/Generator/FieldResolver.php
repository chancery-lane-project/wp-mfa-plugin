<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Resolves custom field values for a post.
 *
 * Handles plain meta keys and ACF dot-notation paths. This is the
 * single place where field resolution logic lives — injected into
 * both FrontmatterBuilder and Generator.
 *
 * @since  1.2.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class FieldResolver {

	/**
	 * Resolve a field value for a post.
	 *
	 * - Plain key (e.g. `_yoast_wpseo_title`): uses get_post_meta().
	 * - Dot notation (e.g. `group.subfield`): uses get_field() and traverses the array.
	 *
	 * @since  1.2.0
	 * @param  int    $post_id    The post ID.
	 * @param  string $field_path Field key or dot-notation path.
	 * @return mixed Field value or null if not found.
	 */
	public function resolve( int $post_id, string $field_path ): mixed {
		// ACF dot notation: group.subfield.
		if ( str_contains( $field_path, '.' ) ) {
			$segments = explode( '.', $field_path );
			$root_key = $segments[0];

			if ( function_exists( 'get_field' ) ) {
				$root_value = get_field( $root_key, $post_id );

				if ( is_array( $root_value ) ) {
					$value = $root_value;
					for ( $i = 1; $i < count( $segments ); $i++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed
						if ( ! is_array( $value ) || ! isset( $value[ $segments[ $i ] ] ) ) {
							return null;
						}
						$value = $value[ $segments[ $i ] ];
					}
					return $value;
				}
			}

			return null;
		}

		// Plain meta key — try ACF first (handles type processing), fall back to post meta.
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_path, $post_id );
			if ( null !== $value && false !== $value ) {
				return $value;
			}
		}

		return get_post_meta( $post_id, $field_path, true ) ?: null;
	}
}

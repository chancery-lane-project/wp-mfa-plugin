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
	 * Resolve the human-readable label for a field.
	 *
	 * Uses ACF's get_field_object() to retrieve the label. For dot-notation
	 * paths the sub-field label is returned.
	 *
	 * @since  1.3.0
	 * @param  int    $post_id    The post ID.
	 * @param  string $field_path Field key or dot-notation path.
	 * @return string|null The field label or null if unavailable.
	 */
	public function resolve_label( int $post_id, string $field_path ): ?string {
		if ( ! function_exists( 'get_field_object' ) ) {
			return null;
		}

		if ( str_contains( $field_path, '.' ) ) {
			$segments   = explode( '.', $field_path );
			$root_key   = $segments[0];
			$field_obj  = get_field_object( $root_key, $post_id );

			if ( ! is_array( $field_obj ) || empty( $field_obj['sub_fields'] ) ) {
				return null;
			}

			// Walk sub_fields to find the target.
			$sub_fields = $field_obj['sub_fields'];
			$segment_count = count( $segments );
			for ( $i = 1; $i < $segment_count; $i++ ) {
				$found = null;
				foreach ( $sub_fields as $sf ) {
					if ( ( $sf['name'] ?? '' ) === $segments[ $i ] ) {
						$found = $sf;
						break;
					}
				}
				if ( null === $found ) {
					return null;
				}
				if ( $i === $segment_count - 1 ) {
					return $found['label'] ?? null;
				}
				$sub_fields = $found['sub_fields'] ?? array();
			}

			return null;
		}

		$field_obj = get_field_object( $field_path, $post_id );

		if ( is_array( $field_obj ) && ! empty( $field_obj['label'] ) ) {
			return $field_obj['label'];
		}

		return null;
	}

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
					$segment_count = count( $segments );
					for ( $i = 1; $i < $segment_count; $i++ ) {
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

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Serialises a PHP array to a YAML front matter block.
 *
 * Extracted and adapted from wp-to-file AbstractProcessor YAML methods.
 * Handles strings, integers, booleans, and simple/nested arrays.
 * Does not depend on any external YAML library or WordPress functions.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class YamlFormatter {

	/**
	 * Serialise an array to a YAML front matter string.
	 *
	 * Returns a string beginning and ending with `---\n`.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $data The data to serialise.
	 * @return string
	 */
	public function format( array $data ): string {
		$output = "---\n";

		foreach ( $data as $key => $value ) {
			$output .= $this->format_field( (string) $key, $value, 0 );
		}

		$output .= "---\n";

		return $output;
	}

	/**
	 * Format a single YAML field, recursing for arrays.
	 *
	 * @since  1.0.0
	 * @param  string $key          The field key.
	 * @param  mixed  $value        The field value.
	 * @param  int    $indent_level Current indentation depth.
	 * @return string
	 */
	private function format_field( string $key, mixed $value, int $indent_level ): string {
		$indent = str_repeat( '  ', $indent_level );

		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return "{$indent}{$key}: []\n";
			}

			// Distinguish sequential list from associative map.
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

			if ( $is_list ) {
				$yaml = "{$indent}{$key}:\n";
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						$yaml .= "{$indent}  -\n";
						foreach ( $item as $sub_key => $sub_value ) {
							$yaml .= $this->format_field( (string) $sub_key, $sub_value, $indent_level + 2 );
						}
					} else {
						$formatted = is_string( $item ) ? $this->escape_value( $item ) : $item;
						$yaml     .= "{$indent}  - {$formatted}\n";
					}
				}
				return $yaml;
			}

			// Associative map.
			$yaml = "{$indent}{$key}:\n";
			foreach ( $value as $sub_key => $sub_value ) {
				$yaml .= $this->format_field( (string) $sub_key, $sub_value, $indent_level + 1 );
			}
			return $yaml;
		}

		if ( is_bool( $value ) ) {
			return "{$indent}{$key}: " . ( $value ? 'true' : 'false' ) . "\n";
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return "{$indent}{$key}: {$value}\n";
		}

		$formatted = $this->escape_value( (string) $value );
		return "{$indent}{$key}: {$formatted}\n";
	}

	/**
	 * Quote and escape a scalar string value for YAML output.
	 *
	 * ISO 8601 dates are left unquoted. Strings with special characters,
	 * reserved words, or leading/trailing whitespace are double-quoted.
	 *
	 * @since  1.0.0
	 * @param  string $value The raw value.
	 * @return string
	 */
	private function escape_value( string $value ): string {
		// Empty string must always be quoted.
		if ( '' === $value ) {
			return '""';
		}

		// Already double-quoted — return as-is.
		if ( preg_match( '/^".*"$/s', $value ) ) {
			return $value;
		}

		// ISO 8601 date — safe to leave unquoted.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return $value;
		}

		$needs_quoting = false;

		// Special YAML characters.
		if ( preg_match( '/[:\[\]{}|>\'"`]/', $value ) ) {
			$needs_quoting = true;
		}

		// Starts with a YAML special character.
		if ( preg_match( '/^[@*&!%#?|-]/', $value ) ) {
			$needs_quoting = true;
		}

		// Inline comment marker.
		if ( str_contains( $value, ' #' ) ) {
			$needs_quoting = true;
		}

		// Bare hash at start.
		if ( str_starts_with( $value, '#' ) ) {
			$needs_quoting = true;
		}

		// Reserved words.
		if ( preg_match( '/^(true|false|yes|no|on|off|null|~)$/i', $value ) ) {
			$needs_quoting = true;
		}

		// Looks like a number.
		if ( preg_match( '/^[-+]?[0-9]*\.?[0-9]+([eE][-+]?[0-9]+)?$/', $value ) ||
			preg_match( '/^0[xX][0-9a-fA-F]+$/', $value ) ) {
			$needs_quoting = true;
		}

		// Leading or trailing whitespace.
		if ( $value !== trim( $value ) ) {
			$needs_quoting = true;
		}

		// Newlines — escape them.
		if ( str_contains( $value, "\n" ) || str_contains( $value, "\r" ) ) {
			$escaped = str_replace( array( "\r\n", "\r", "\n" ), '\n', $value );
			return '"' . $this->escape_string( $escaped ) . '"';
		}

		if ( $needs_quoting ) {
			return '"' . $this->escape_string( $value ) . '"';
		}

		return $value;
	}

	/**
	 * Escape a string for use inside YAML double quotes.
	 *
	 * Only backslash and double-quote need escaping in YAML double-quoted strings.
	 *
	 * @since  1.0.0
	 * @param  string $value The string to escape.
	 * @return string
	 */
	private function escape_string( string $value ): string {
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( '"', '\\"', $value );
		return $value;
	}
}

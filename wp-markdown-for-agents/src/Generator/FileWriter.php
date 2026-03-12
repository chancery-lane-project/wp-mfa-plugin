<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Handles all filesystem I/O for Markdown export files.
 *
 * Enforces path-traversal protection: every path is validated to stay within
 * the export base directory before any read or write operation.
 *
 * Uses WP_Filesystem in admin context and file_put_contents() in WP-CLI
 * context (where WP_Filesystem is unavailable).
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class FileWriter {

	/**
	 * Resolved real path of the export base directory.
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private string $base_dir;

	/**
	 * @since  1.0.0
	 * @param  string $base_dir Absolute path to the export base directory.
	 */
	public function __construct( string $base_dir ) {
		// Resolve symlinks/traversals once at construction time.
		$real           = realpath( $base_dir );
		$this->base_dir = $real !== false ? $real : $base_dir;
	}

	/**
	 * Write content to a file within the export directory.
	 *
	 * Creates parent directories as needed. Writes a protective .htaccess
	 * to the base directory on first write if one does not already exist.
	 *
	 * @since  1.0.0
	 * @param  string $filepath Absolute path to the destination file.
	 * @param  string $content  The content to write.
	 * @return bool True on success, false on failure or path violation.
	 */
	public function write( string $filepath, string $content ): bool {
		if ( ! $this->is_safe_path( $filepath ) ) {
			return false;
		}

		$dir = dirname( $filepath );

		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}

		$this->maybe_write_htaccess();

		return $this->put_contents( $filepath, $content );
	}

	/**
	 * Delete a file within the export directory.
	 *
	 * Returns true if the file does not exist (idempotent).
	 *
	 * @since  1.0.0
	 * @param  string $filepath Absolute path to the file.
	 * @return bool True on success or file not found, false on failure or path violation.
	 */
	public function delete( string $filepath ): bool {
		if ( ! $this->is_safe_path( $filepath ) ) {
			return false;
		}

		if ( ! file_exists( $filepath ) ) {
			return true;
		}

		return unlink( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Check whether a file exists within the export directory.
	 *
	 * @since  1.0.0
	 * @param  string $filepath Absolute path to the file.
	 * @return bool
	 */
	public function exists( string $filepath ): bool {
		return file_exists( $filepath );
	}

	/**
	 * Write a protective .htaccess to the base directory if not already present.
	 *
	 * @since  1.0.0
	 */
	private function maybe_write_htaccess(): void {
		$htaccess = $this->base_dir . '/.htaccess';

		if ( file_exists( $htaccess ) ) {
			return;
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$htaccess,
			"# Deny direct access to generated Markdown files.\nDeny from all\n"
		);
	}

	/**
	 * Validate that a path stays within the export base directory.
	 *
	 * Resolves symlinks by walking up to the deepest existing ancestor, then
	 * appending remaining segments lexically. This handles platforms (macOS)
	 * where sys_get_temp_dir() returns a symlink path.
	 *
	 * @since  1.0.0
	 * @param  string $filepath The path to validate.
	 * @return bool
	 */
	private function is_safe_path( string $filepath ): bool {
		$dir      = dirname( $filepath );
		$resolved = $this->resolve_path( $dir );

		$base = rtrim( $this->base_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		$real = rtrim( $resolved, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		return str_starts_with( $real, $base );
	}

	/**
	 * Resolve a path to its real form, even if it does not fully exist yet.
	 *
	 * Walks up the path to find the deepest existing ancestor, resolves that
	 * with realpath (following symlinks), then appends remaining segments.
	 *
	 * @since  1.0.0
	 * @param  string $path The path to resolve.
	 * @return string
	 */
	private function resolve_path( string $path ): string {
		// Normalise `..` segments first.
		$path  = $this->normalise_path( $path );
		$parts = explode( DIRECTORY_SEPARATOR, ltrim( $path, DIRECTORY_SEPARATOR ) );
		$tail  = array();

		// Walk up until we find an existing ancestor.
		while ( ! empty( $parts ) ) {
			$candidate = DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $parts );
			$real      = realpath( $candidate );

			if ( false !== $real ) {
				// Append the remaining tail segments to the resolved base.
				if ( ! empty( $tail ) ) {
					$real .= DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, array_reverse( $tail ) );
				}
				return $real;
			}

			array_unshift( $tail, array_pop( $parts ) );
		}

		return $path;
	}

	/**
	 * Normalise a path by resolving `.` and `..` segments lexically.
	 *
	 * @since  1.0.0
	 * @param  string $path The path to normalise.
	 * @return string
	 */
	private function normalise_path( string $path ): string {
		$parts  = explode( DIRECTORY_SEPARATOR, $path );
		$result = array();

		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $result );
			} else {
				$result[] = $part;
			}
		}

		return DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $result );
	}

	/**
	 * Write file contents using WP_Filesystem in admin or file_put_contents in CLI.
	 *
	 * @since  1.0.0
	 * @param  string $filepath The destination file path.
	 * @param  string $content  The content to write.
	 * @return bool
	 */
	private function put_contents( string $filepath, string $content ): bool {
		// Use WP_Filesystem when available (admin context).
		if ( ! defined( 'WP_CLI' ) && function_exists( 'WP_Filesystem' ) ) {
			global $wp_filesystem;

			if ( empty( $wp_filesystem ) ) {
				WP_Filesystem();
			}

			if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
				return (bool) $wp_filesystem->put_contents( $filepath, $content, FS_CHMOD_FILE );
			}
		}

		// Fallback: direct write for CLI or when WP_Filesystem is unavailable.
		return false !== file_put_contents( $filepath, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}

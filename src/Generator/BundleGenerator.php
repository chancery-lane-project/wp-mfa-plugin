<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

use Tclp\WpMarkdownForAgents\Core\Options;

/**
 * Builds, maintains, and tears down the OKF `.tar.gz` bundle.
 *
 * Packages the export tree (minus sync/administrative files) into a single
 * gzipped tarball, rewriting `.md` internal links from absolute upload URLs
 * to OKF bundle-absolute form (`/post/slug.md`) on the way in. The archive
 * is built at a process-unique temporary path and atomically renamed into
 * place so a concurrent download never observes a partial file.
 *
 * Freshness is tracked via a tree-state hash (sorted `relpath|mtime|size`
 * lines) stored in the `markdown_for_agents_bundle_hash` option, avoiding
 * any dependency on `manifest.json` (which is only written when generation
 * runs `--with-manifest`).
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class BundleGenerator {

	/**
	 * Option name storing the tree-state hash at last successful build.
	 *
	 * @since  1.6.0
	 */
	private const HASH_OPTION = 'markdown_for_agents_bundle_hash';

	/**
	 * Files at the root of the export tree excluded from the bundle.
	 *
	 * @since  1.6.0
	 */
	private const EXCLUDED_FILES = array( 'changes.json', 'ai-catalog.json' );

	/**
	 * @since  1.6.0
	 * @param  array<string, mixed> $options Plugin options.
	 */
	public function __construct( private readonly array $options ) {}

	/**
	 * Return the absolute filesystem path to the bundle file.
	 *
	 * @since  1.6.0
	 * @return string
	 */
	public function bundle_path(): string {
		$base = Options::get_export_base( $this->options );

		return dirname( $base ) . '/' . sanitize_file_name( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) ) . '.tar.gz';
	}

	/**
	 * Return the public URL to the bundle file.
	 *
	 * @since  1.6.0
	 * @return string
	 */
	public function bundle_url(): string {
		$base_url = Options::get_export_base_url( $this->options );

		return dirname( $base_url ) . '/' . sanitize_file_name( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) ) . '.tar.gz';
	}

	/**
	 * Build the bundle from the current export tree and atomically place it.
	 *
	 * @since  1.6.0
	 * @return bool True on success; false if the export tree is missing or
	 *              the archive could not be built.
	 */
	public function build(): bool {
		$base = Options::get_export_base( $this->options );

		if ( ! is_dir( $base ) ) {
			return false;
		}

		$bundle_path = $this->bundle_path();
		$tmp_tar     = $bundle_path . '.tmp-' . getmypid() . '.tar';
		$tmp_gz      = $tmp_tar . '.gz';

		try {
			$phar     = new \PharData( $tmp_tar );
			$base_url = Options::get_export_base_url( $this->options );

			foreach ( $this->iterate_export_tree( $base ) as $relative_path => $absolute_path ) {
				$content = (string) file_get_contents( $absolute_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( str_ends_with( $relative_path, '.md' ) ) {
					$content = str_replace( '](' . $base_url . '/', '](/', $content );
				}

				$phar->addFromString( $relative_path, $content );
			}

			$phar->compress( \Phar::GZ );
			unset( $phar );

			if ( file_exists( $tmp_tar ) ) {
				unlink( $tmp_tar ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Suppressed: a failed rename (cross-filesystem, permissions, disk full) is an expected, handled outcome, not a bug to surface as a PHP warning.
			if ( ! @rename( $tmp_gz, $bundle_path ) ) {
				if ( file_exists( $tmp_gz ) ) {
					unlink( $tmp_gz ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return false;
			}

			update_option( self::HASH_OPTION, $this->tree_hash() );

			return true;
		} catch ( \Throwable $e ) {
			if ( file_exists( $tmp_tar ) ) {
				unlink( $tmp_tar ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			if ( file_exists( $tmp_gz ) ) {
				unlink( $tmp_gz ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, guarded by WP_DEBUG.
				error_log( 'WP Markdown for Agents: bundle build failed: ' . $e->getMessage() );
			}

			return false;
		}
	}

	/**
	 * Whether the bundle is missing or out of date with the export tree.
	 *
	 * @since  1.6.0
	 * @return bool
	 */
	public function is_stale(): bool {
		if ( ! file_exists( $this->bundle_path() ) ) {
			return true;
		}

		return get_option( self::HASH_OPTION, '' ) !== $this->tree_hash();
	}

	/**
	 * Remove the bundle file and its stored staleness hash.
	 *
	 * @since  1.6.0
	 * @return bool True if the file existed and was removed, or already absent.
	 */
	public function delete(): bool {
		$path = $this->bundle_path();

		delete_option( self::HASH_OPTION );

		if ( ! file_exists( $path ) ) {
			return false;
		}

		return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Hook callback: mark the bundle stale and schedule a debounced rebuild.
	 *
	 * No-op unless `bundle_enabled`. Deletes the stored tree-state hash
	 * (a cheap stale marker — `is_stale()` then returns true) and, unless a
	 * rebuild is already pending, schedules one five minutes out. Because a
	 * pending event blocks scheduling another, a burst of edits within that
	 * window collapses to a single rebuild.
	 *
	 * @since  1.6.0
	 * @return void
	 */
	public function mark_stale_and_schedule(): void {
		if ( empty( $this->options['bundle_enabled'] ) ) {
			return;
		}

		delete_option( self::HASH_OPTION );

		if ( ! wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) ) {
			wp_schedule_single_event( time() + 300, 'markdown_for_agents_rebuild_bundle' );
		}
	}

	/**
	 * Cron callback: rebuild the bundle.
	 *
	 * No-op unless `bundle_enabled`.
	 *
	 * @since  1.6.0
	 * @return void
	 */
	public function on_rebuild_bundle(): void {
		if ( empty( $this->options['bundle_enabled'] ) ) {
			return;
		}

		$this->build();
	}

	/**
	 * Compute a stat-only hash representing the current state of the export tree.
	 *
	 * @since  1.6.0
	 * @return string md5 of sorted `relpath|mtime|size` lines; hash of an
	 *                empty string when the export directory does not exist.
	 */
	public function tree_hash(): string {
		$base = Options::get_export_base( $this->options );

		if ( ! is_dir( $base ) ) {
			return md5( '' );
		}

		$lines = array();

		foreach ( $this->iterate_export_tree( $base ) as $relative_path => $absolute_path ) {
			$lines[] = $relative_path . '|' . filemtime( $absolute_path ) . '|' . filesize( $absolute_path );
		}

		sort( $lines );

		return md5( implode( "\n", $lines ) );
	}

	/**
	 * Yield eligible export-tree files as relative_path => absolute_path.
	 *
	 * @since  1.6.0
	 * @param  string $base Absolute export base directory.
	 * @return \Generator<string, string>
	 */
	private function iterate_export_tree( string $base ): \Generator {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			$absolute_path = $file->getPathname();
			$relative_path = ltrim( substr( $absolute_path, strlen( $base ) ), '/\\' );
			$relative_path = str_replace( '\\', '/', $relative_path );

			if ( in_array( $relative_path, self::EXCLUDED_FILES, true ) ) {
				continue;
			}

			yield $relative_path => $absolute_path;
		}
	}
}

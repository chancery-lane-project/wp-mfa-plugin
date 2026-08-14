<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;

/**
 * Builds, maintains, and tears down the OKF `.zip` bundle.
 *
 * Packages the export tree (minus sync/administrative files) into a single
 * zip archive (deflated entries), rewriting `.md` internal links from absolute upload URLs
 * to paths relative to each linking file (`../post/slug.md`) on the way in. The archive
 * is built at a process-unique temporary path and atomically renamed into
 * place so a concurrent download never observes a partial file.
 *
 * Freshness is tracked via a tree-state hash (sorted `relpath|mtime|size`
 * lines) stored in the `markdown_for_agents_bundle_hash` option, avoiding
 * any dependency on `manifest.json` (manifests are refreshed by
 * Generator::rebuild_bundle() immediately before every build, but the hash
 * must not rely on that ordering).
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

		return dirname( $base ) . '/' . $this->bundle_filename();
	}

	/**
	 * Return the public URL to the bundle file.
	 *
	 * @since  1.6.0
	 * @return string
	 */
	public function bundle_url(): string {
		$base_url = Options::get_export_base_url( $this->options );

		return dirname( $base_url ) . '/' . $this->bundle_filename();
	}

	/**
	 * Return the bundle's filename, derived from the site name.
	 *
	 * Falls back to `export_dir` if the site name sanitizes to an empty
	 * string (e.g. a non-Latin name stripped entirely by `sanitize_file_name()`).
	 *
	 * @since  1.6.0
	 * @return string
	 */
	private function bundle_filename(): string {
		$site_name = sanitize_file_name( (string) get_bloginfo( 'name' ) );

		if ( '' === $site_name ) {
			$site_name = sanitize_file_name( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) );
		}

		return $site_name . '-okf.zip';
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
		// ZIP rather than tar: PharData's tar writer is ustar-only, capping
		// filenames at 100 characters — real post slugs exceed that. OKF §3
		// permits zip explicitly, and neither zip writer has that limit.
		$tmp_zip = $bundle_path . '.tmp-' . getmypid() . '.zip';

		try {
			$base_url = Options::get_export_base_url( $this->options );

			// ZipArchive buffers entries and flushes once on close(), so a
			// whole tree costs a single archive write. PharData::addFromString()
			// re-serialises the ENTIRE archive to disk on every call, which is
			// quadratic in file count: a real ~2,500-file export took ~90s and
			// could exceed max_execution_time mid-request — which is what used
			// to freeze bulk generation, before the rebuild moved into its own
			// background tick. Prefer ZipArchive when ext-zip is
			// available; fall back to the slow-but-correct PharData path only
			// where it is not.
			$written = class_exists( '\ZipArchive' )
				? $this->write_with_ziparchive( $tmp_zip, $base, $base_url )
				: $this->write_with_phardata( $tmp_zip, $base, $base_url );

			if ( ! $written ) {
				if ( file_exists( $tmp_zip ) ) {
					unlink( $tmp_zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Suppressed: a failed rename (cross-filesystem, permissions, disk full) is an expected, handled outcome, not a bug to surface as a PHP warning.
			if ( ! @rename( $tmp_zip, $bundle_path ) ) {
				if ( file_exists( $tmp_zip ) ) {
					unlink( $tmp_zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return false;
			}

			update_option( self::HASH_OPTION, $this->tree_hash() );

			return true;
		} catch ( \Throwable $e ) {
			if ( file_exists( $tmp_zip ) ) {
				unlink( $tmp_zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, guarded by WP_DEBUG.
				error_log( 'WP Markdown for Agents: bundle build failed: ' . $e->getMessage() );
			}

			return false;
		}
	}

	/**
	 * Write the export tree into a zip at $tmp_zip using ext-zip's ZipArchive.
	 *
	 * Entries are added in memory and written once on close(), giving a single
	 * linear pass over the tree regardless of file count.
	 *
	 * @since  1.6.2
	 * @param  string $tmp_zip  Destination archive path.
	 * @param  string $base     Absolute export base directory.
	 * @param  string $base_url Export base URL, no trailing slash.
	 * @return bool True on success; false if the archive could not be opened or closed.
	 */
	private function write_with_ziparchive( string $tmp_zip, string $base, string $base_url ): bool {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $tmp_zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}

		foreach ( $this->iterate_export_tree( $base ) as $relative_path => $absolute_path ) {
			$content = (string) file_get_contents( $absolute_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( str_ends_with( $relative_path, '.md' ) ) {
				$content = $this->rewrite_to_relative_links( $content, $relative_path, $base_url );
			}

			$zip->addFromString( $relative_path, $content );
		}

		return $zip->close();
	}

	/**
	 * Write the export tree into a zip at $tmp_zip using PharData.
	 *
	 * Fallback for environments without ext-zip. Correct but quadratic in file
	 * count (each addFromString() re-serialises the whole archive), so it is
	 * only used when ZipArchive is unavailable.
	 *
	 * @since  1.6.2
	 * @param  string $tmp_zip  Destination archive path.
	 * @param  string $base     Absolute export base directory.
	 * @param  string $base_url Export base URL, no trailing slash.
	 * @return bool Always true (throws on failure, caught by build()).
	 */
	private function write_with_phardata( string $tmp_zip, string $base, string $base_url ): bool {
		$phar = new \PharData( $tmp_zip );

		foreach ( $this->iterate_export_tree( $base ) as $relative_path => $absolute_path ) {
			$content = (string) file_get_contents( $absolute_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( str_ends_with( $relative_path, '.md' ) ) {
				$content = $this->rewrite_to_relative_links( $content, $relative_path, $base_url );
			}

			$phar->addFromString( $relative_path, $content );
		}

		$phar->compressFiles( \Phar::GZ );
		unset( $phar );

		return true;
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

		// Staleness is marked unconditionally, even during a job. Skipping this
		// too would leave a change landing after the job's BundleStage neither
		// marked nor scheduled, so the zip would stay wrong until some
		// unrelated later change.
		delete_option( self::HASH_OPTION );

		if ( GenerationJob::is_running() ) {
			// The running job's own BundleStage will rebuild; a debounced event
			// here would rebuild the same zip a second time.
			return;
		}

		if ( ! wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) ) {
			wp_schedule_single_event( time() + 300, 'markdown_for_agents_rebuild_bundle' );
		}
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
	 * Rewrite absolute upload-URL `.md` links in a bundle file to paths
	 * relative to that file's own position in the bundle tree.
	 *
	 * Root-absolute bundle paths (`/post/slug.md`) were tried first but many
	 * extractors (incl. Google's) treat a leading `/` as an external/root
	 * link and drop it, so no graph edge is built. True relative paths
	 * (`../post/slug.md`) are the OKF §3 norm and work everywhere.
	 *
	 * @since  1.6.0
	 * @param  string $content       Markdown file content.
	 * @param  string $relative_path This file's own path within the bundle.
	 * @param  string $base_url      Export base URL, no trailing slash.
	 * @return string
	 */
	private function rewrite_to_relative_links( string $content, string $relative_path, string $base_url ): string {
		$from_dir = dirname( $relative_path );
		$from_dir = ( '.' === $from_dir ) ? '' : $from_dir;

		$pattern = '/\]\(' . preg_quote( $base_url, '/' ) . '\/([^)\s]+)\)/';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $matches ) use ( $from_dir ): string {
				$target   = $matches[1];
				$fragment = '';

				$hash = strpos( $target, '#' );
				if ( false !== $hash ) {
					$fragment = substr( $target, $hash );
					$target   = substr( $target, 0, $hash );
				}

				return '](' . $this->relative_path( $from_dir, $target ) . $fragment . ')';
			},
			$content
		);
	}

	/**
	 * Compute a relative path from a directory to a bundle-root-relative
	 * target path.
	 *
	 * @since  1.6.0
	 * @param  string $from_dir Directory of the linking file, relative to
	 *                          bundle root; empty string for the root itself.
	 * @param  string $to_path  Target file path, relative to bundle root.
	 * @return string
	 */
	private function relative_path( string $from_dir, string $to_path ): string {
		$from_parts = ( '' === $from_dir ) ? array() : explode( '/', $from_dir );
		$to_parts   = explode( '/', $to_path );

		$i = 0;
		while ( $i < count( $from_parts ) && $i < count( $to_parts ) - 1 && $from_parts[ $i ] === $to_parts[ $i ] ) {
			++$i;
		}

		$ups = array_fill( 0, count( $from_parts ) - $i, '..' );

		return implode( '/', array_merge( $ups, array_slice( $to_parts, $i ) ) );
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

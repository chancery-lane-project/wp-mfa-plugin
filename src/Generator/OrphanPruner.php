<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Core\Options;

/**
 * Removes Markdown files that a full regeneration did not rewrite.
 *
 * A full run rewrites every eligible post's file, so any `.md` file in a
 * post type's directory older than the run's start belongs to no current
 * post: a path from before a slug or parent change, a post deleted or made
 * ineligible while auto-generate was off, or a pre-1.7.3 flat child page.
 *
 * The start time is read back from a file the run creates, not taken from
 * PHP's clock, so it comes from the same clock that stamps the exports.
 * On network filesystems the web server and file server clocks can differ.
 *
 * @since   1.7.3
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class OrphanPruner {

	/**
	 * @since 1.7.3
	 * @param array<string, mixed> $options Plugin options.
	 */
	public function __construct( private readonly array $options ) {}

	/**
	 * Record the export filesystem's current time.
	 *
	 * @since  1.7.3
	 * @return int|null Modification time of a just-written file, or null
	 *                  when the export directory cannot be written.
	 */
	public function mark(): ?int {
		$export_base = Options::get_export_base( $this->options );

		if ( ! is_dir( $export_base ) && ! wp_mkdir_p( $export_base ) ) {
			return null;
		}

		$marker = $export_base . '/.mfa-prune-marker';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Only the modification time is wanted.
		if ( ! touch( $marker ) ) {
			return null;
		}

		clearstatcache( true, $marker );
		$time = filemtime( $marker );
		unlink( $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		return false === $time ? null : $time;
	}

	/**
	 * Delete `.md` files under a post type's directory last modified before
	 * $since, then remove subdirectories left empty.
	 *
	 * The type's own `index.md` is kept: IndexGenerator rewrites it at the
	 * end of the request, which can be after this runs.
	 *
	 * @since  1.7.3
	 * @param  string $post_type Post type slug.
	 * @param  int    $since     Time from mark(). Files modified at or after it are kept.
	 * @return int Number of files deleted.
	 */
	public function prune( string $post_type, int $since ): int {
		if ( '' === $post_type || sanitize_key( $post_type ) !== $post_type ) {
			return 0;
		}

		$type_dir  = Options::get_export_base( $this->options ) . '/' . $post_type;
		$real_base = is_dir( $type_dir ) ? realpath( $type_dir ) : false;

		if ( false === $real_base ) {
			return 0;
		}

		$index   = $real_base . DIRECTORY_SEPARATOR . 'index.md';
		$deleted = 0;
		$files   = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real_base, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			/** @var \SplFileInfo $file */
			$real_file = $file->getRealPath();
			if ( false === $real_file || ! str_starts_with( $real_file, $real_base . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			if ( $file->isDir() ) {
				// Children come first, so a subdirectory emptied above can go too.
				if ( ! ( new \FilesystemIterator( $real_file ) )->valid() ) {
					rmdir( $real_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				}
			} elseif ( 'md' === $file->getExtension() && $real_file !== $index && $file->getMTime() < $since && unlink( $real_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				++$deleted;
			}
		}

		return $deleted;
	}
}

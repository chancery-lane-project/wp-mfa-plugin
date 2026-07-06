<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\CLI;

use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;
use Tclp\WpMarkdownForAgents\Generator\FileWriter;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\IndexGenerator;
use Tclp\WpMarkdownForAgents\Generator\ManifestGenerator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * WP-CLI commands for Markdown for Agents and Statistics.
 *
 * Register under the `markdown-agents` parent:
 *
 *   WP_CLI::add_command( 'markdown-agents', new Commands( $options, $generator ) );
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\CLI
 */
class Commands {

	/**
	 * @since  1.0.0
	 * @param  array<string, mixed>       $options           Plugin options.
	 * @param  Generator                  $generator         Generator instance.
	 * @param  FileWriter|null            $file_writer        FileWriter for manifest I/O.
	 * @param  TaxonomyArchiveGenerator|null $taxonomy_generator Optional taxonomy archive generator.
	 * @param  StatsRepository|null       $stats_repository  Optional stats repository for prune-stats.
	 * @param  IndexGenerator|null        $index_generator   Optional index generator for generate-indexes.
	 * @param  BundleGenerator|null       $bundle_generator  Optional bundle generator for the `bundle` subcommand.
	 */
	public function __construct(
		private readonly array $options,
		private readonly Generator $generator,
		private readonly ?FileWriter $file_writer = null,
		private readonly ?TaxonomyArchiveGenerator $taxonomy_generator = null,
		private readonly ?StatsRepository $stats_repository = null,
		private readonly ?IndexGenerator $index_generator = null,
		private readonly ?BundleGenerator $bundle_generator = null,
	) {}

	/**
	 * Generate Markdown export files.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<type>]
	 * : Generate all posts of this type.
	 *
	 * [--post-id=<id>]
	 * : Generate a single post.
	 *
	 * [--dry-run]
	 * : Report what would be generated without writing files.
	 *
	 * [--force]
	 * : Regenerate even if the .md file is newer than the post.
	 *
	 * [--with-manifest]
	 * : Generate manifest.json with content hashes and change tracking.
	 *
	 * [--incremental]
	 * : Only export changed documents (requires previous manifest.json).
	 *   Implies --with-manifest. Generates changes.json delta file.
	 *
	 * ## EXAMPLES
	 *
	 *   wp markdown-agents generate --post-type=post
	 *   wp markdown-agents generate --post-id=42
	 *   wp markdown-agents generate --incremental
	 *
	 * @since  1.0.0
	 * @param  array<int, string>    $args       Positional args.
	 * @param  array<string, string> $assoc_args Named args.
	 */
	public function generate( array $args, array $assoc_args ): void {
		$dry_run     = isset( $assoc_args['dry-run'] );
		$incremental = isset( $assoc_args['incremental'] );
		$post_id     = isset( $assoc_args['post-id'] ) ? (int) $assoc_args['post-id'] : null;
		$post_type   = $assoc_args['post-type'] ?? null;

		if ( null !== $post_id ) {
			$this->generate_single( $post_id, $dry_run );
			return;
		}

		$types = null !== $post_type
			? array( $post_type )
			: ExportPolicy::enabled_post_types( $this->options );

		$export_base = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );

		if ( $incremental && $this->file_writer ) {
			$this->generate_incremental( $export_base, $types, $dry_run );
		} else {
			foreach ( $types as $type ) {
				$this->generate_type( $type, $dry_run );
			}

			if ( isset( $assoc_args['with-manifest'] ) && $this->file_writer ) {
				$result = $this->generate_manifest( $export_base, $types );
				$result
					? \WP_CLI::success( 'manifest.json generated.' )
					: \WP_CLI::warning( 'manifest.json generation failed.' );
			}
		}

		if ( ! $dry_run ) {
			$this->rebuild_indexes();
			$this->rebuild_bundle();
		}
	}

	/**
	 * Build the OKF `.tar.gz` bundle from the current export tree.
	 *
	 * ## OPTIONS
	 *
	 * [--if-stale]
	 * : Only rebuild when the bundle is missing or out of date; skip otherwise.
	 *
	 * ## EXAMPLES
	 *
	 *   wp markdown-agents bundle
	 *   wp markdown-agents bundle --if-stale
	 *
	 * @since  1.6.0
	 * @param  array<int, string>    $args
	 * @param  array<string, string> $assoc_args
	 */
	public function bundle( array $args, array $assoc_args ): void {
		if ( null === $this->bundle_generator ) {
			\WP_CLI::error( 'BundleGenerator is not available.' );
			return;
		}

		if ( empty( $this->options['bundle_enabled'] ) ) {
			\WP_CLI::error( 'Bundle generation is disabled. Enable "bundle_enabled" in settings first.' );
			return;
		}

		if ( isset( $assoc_args['if-stale'] ) && ! $this->bundle_generator->is_stale() ) {
			\WP_CLI::log( 'Bundle is up to date; skipping.' );
			return;
		}

		if ( ! $this->bundle_generator->build() ) {
			\WP_CLI::error( 'Bundle build failed.' );
			return;
		}

		$path = $this->bundle_generator->bundle_path();
		$size = file_exists( $path ) ? filesize( $path ) : false;

		\WP_CLI::success(
			false !== $size
				? sprintf( 'Bundle built: %s (%d bytes).', $path, $size )
				: sprintf( 'Bundle built: %s.', $path )
		);
	}

	/**
	 * Show generation status for each enabled post type.
	 *
	 * ## EXAMPLES
	 *
	 *   wp markdown-agents status
	 *
	 * @since  1.0.0
	 * @param  array<int, string>    $args
	 * @param  array<string, string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$post_types  = ExportPolicy::enabled_post_types( $this->options );
		$export_base = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );

		$rows = array();

		foreach ( $post_types as $type ) {
			$total = (int) wp_count_posts( $type )->publish; // phpcs:ignore WordPress.WP.PostsPerPage

			// Count .md files in the export directory, excluding index.md
			// (an OKF directory listing, not a generated post).
			$generated = $this->count_post_files( $export_base . '/' . $type );

			$rows[] = array(
				'post_type' => $type,
				'published' => $total,
				'generated' => $generated,
				'missing'   => max( 0, $total - $generated ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'post_type', 'published', 'generated', 'missing' ) );

		\WP_CLI::log( sprintf( 'Index files: %d', $this->count_index_files( $export_base ) ) );

		if ( ! empty( $this->options['bundle_enabled'] ) && null !== $this->bundle_generator ) {
			\WP_CLI::log( $this->bundle_status_line() );
		}
	}

	/**
	 * Delete Markdown export files.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<type>]
	 * : Delete all .md files for this post type.
	 *
	 * [--post-id=<id>]
	 * : Delete the .md file for a single post.
	 *
	 * [--all]
	 * : Delete all generated files across all post types.
	 *
	 * [--yes]
	 * : Skip confirmation when using --all.
	 *
	 * ## EXAMPLES
	 *
	 *   wp markdown-agents delete --post-type=post
	 *   wp markdown-agents delete --all --yes
	 *
	 * @since  1.0.0
	 * @param  array<int, string>    $args
	 * @param  array<string, string> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['all'] ) ) {
			\WP_CLI::confirm( 'Delete all generated Markdown files?', $assoc_args );

			$types = ExportPolicy::enabled_post_types( $this->options );
			foreach ( $types as $type ) {
				$this->delete_type( $type );
			}

			if ( $this->index_generator ) {
				$deleted = $this->index_generator->delete_all();
				\WP_CLI::log( sprintf( 'Index files deleted: %d', $deleted ) );
			}

			if ( null !== $this->bundle_generator ) {
				$bundle_removed = $this->bundle_generator->delete();
				\WP_CLI::log(
					$bundle_removed ? 'Bundle file deleted.' : 'No bundle file to delete.'
				);
			}

			\WP_CLI::success( 'All Markdown files deleted.' );
			return;
		}

		if ( isset( $assoc_args['post-id'] ) ) {
			$post_id = (int) $assoc_args['post-id'];
			$ok      = $this->generator->delete_post( $post_id );
			$ok
				? \WP_CLI::success( "Deleted file for post {$post_id}." )
				: \WP_CLI::error( "Could not delete file for post {$post_id}." );
			return;
		}

		if ( isset( $assoc_args['post-type'] ) ) {
			$this->delete_type( $assoc_args['post-type'] );
			\WP_CLI::success( 'Done.' );
			return;
		}

		\WP_CLI::error( 'Specify --post-type, --post-id, or --all.' );
	}

	/**
	 * Generate Markdown archive files for taxonomy terms.
	 *
	 * ## OPTIONS
	 *
	 * [--taxonomy=<slug>]
	 * : Generate only terms in this taxonomy. Omit to generate all public taxonomies.
	 *
	 * [--dry-run]
	 * : Report what would be generated without writing files.
	 *
	 * ## EXAMPLES
	 *
	 *   wp markdown-agents generate-taxonomies
	 *   wp markdown-agents generate-taxonomies --taxonomy=category
	 *   wp markdown-agents generate-taxonomies --dry-run
	 *
	 * @since  1.1.0
	 * @param  array<int, string>    $args
	 * @param  array<string, string> $assoc_args
	 */
	public function generate_taxonomies( array $args, array $assoc_args ): void {
		if ( null === $this->taxonomy_generator ) {
			\WP_CLI::error( 'TaxonomyArchiveGenerator is not available.' );
			return;
		}

		$taxonomy = $assoc_args['taxonomy'] ?? '';
		$dry_run  = isset( $assoc_args['dry-run'] );

		$taxonomies = $taxonomy
			? array( $taxonomy )
			: array_keys( get_taxonomies( array( 'public' => true ) ) );

		if ( $dry_run ) {
			foreach ( $taxonomies as $tax ) {
				\WP_CLI::log( "[dry-run] Would generate all terms in taxonomy: {$tax}" );
			}
			return;
		}

		$results = $this->taxonomy_generator->generate_all( $taxonomy );

		\WP_CLI::success(
			sprintf(
				'Taxonomy archives: %d generated, %d failed.',
				$results['success'],
				$results['failed']
			)
		);

		$this->rebuild_indexes();
		$this->rebuild_bundle();
	}

	/**
	 * Generate OKF `index.md` directory listings for posts and taxonomies.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be generated without writing files.
	 *
	 * ## EXAMPLES
	 *
	 *   wp markdown-agents generate-indexes
	 *   wp markdown-agents generate-indexes --dry-run
	 *
	 * @since  1.6.0
	 * @param  array<int, string>    $args
	 * @param  array<string, string> $assoc_args
	 */
	public function generate_indexes( array $args, array $assoc_args ): void {
		if ( null === $this->index_generator ) {
			\WP_CLI::error( 'IndexGenerator is not available.' );
			return;
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$results = $this->index_generator->generate_all( $dry_run );

		$verb = $dry_run ? 'Would write' : 'Written';
		\WP_CLI::success(
			sprintf( 'Indexes: %s %d, skipped %d.', $verb, $results['written'], $results['skipped'] )
		);
	}

	/**
	 * Prune access statistics older than a given number of days.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<n>]
	 * : Delete records older than this many days. Default: 90.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *   wp markdown-agents prune-stats
	 *   wp markdown-agents prune-stats --days=30 --yes
	 *
	 * @since  1.2.0
	 * @param  array<int, string>    $args
	 * @param  array<string, string> $assoc_args
	 */
	public function prune_stats( array $args, array $assoc_args ): void {
		if ( null === $this->stats_repository ) {
			\WP_CLI::error( 'StatsRepository is not available.' );
			return;
		}

		$days = max( 1, (int) ( $assoc_args['days'] ?? 90 ) );

		\WP_CLI::confirm(
			sprintf( 'Delete access stats older than %d days?', $days ),
			$assoc_args
		);

		try {
			$deleted = $this->stats_repository->delete_before_date( $days );
			\WP_CLI::success( sprintf( 'Deleted %d stat records older than %d days.', $deleted, $days ) );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * @since  1.0.0
	 */
	private function generate_single( int $post_id, bool $dry_run ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( "Post {$post_id} not found." );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( "[dry-run] Would generate: {$post->post_name} ({$post->post_type})" );
			return;
		}

		$ok = $this->generator->generate_post( $post );
		$ok
			? \WP_CLI::success( "Generated: {$post->post_name}" )
			: \WP_CLI::warning( "Failed: {$post->post_name}" );

		$this->rebuild_indexes();
		$this->rebuild_bundle();
	}

	/**
	 * @since  1.0.0
	 */
	private function generate_type( string $post_type, bool $dry_run ): void {
		if ( $dry_run ) {
			\WP_CLI::log( "[dry-run] Would generate all published posts of type: {$post_type}" );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( "Generating {$post_type}", 0 );
		$done     = 0;

		$results = $this->generator->generate_post_type(
			$post_type,
			function () use ( $progress, &$done ): void {
				++$done;
				$progress->tick();
			}
		);

		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'%s: %d generated, %d skipped, %d failed.',
				$post_type,
				$results['success'],
				$results['skipped'],
				$results['failed']
			)
		);
	}

	/**
	 * Run an incremental export for the given post types.
	 *
	 * Loads the previous manifest, skips unchanged documents, and generates
	 * manifest.json + changes.json for each post type.
	 *
	 * @since  1.1.0
	 * @param  string   $export_base Absolute path to the export base directory.
	 * @param  string[] $post_types  Post type slugs to include.
	 * @param  bool     $dry_run     If true, report without writing files.
	 */
	private function generate_incremental( string $export_base, array $post_types, bool $dry_run ): void {
		$batch_size = 100;

		foreach ( $post_types as $post_type ) {
			$type_dir = trailingslashit( $export_base ) . $post_type . '/';
			$manifest = new ManifestGenerator( $type_dir, $this->file_writer );
			$type_ids = array();
			$offset   = 0;
			$exported = 0;
			$skipped  = 0;
			$failed   = 0;

			do {
				$posts = get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'publish',
						'posts_per_page' => $batch_size, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
						'offset'         => $offset,
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'no_found_rows'  => true,
					)
				);

				$fetched = count( $posts );

				foreach ( $posts as $post ) {
					$relative_path = sanitize_file_name( $post->post_name ) . '.md';
					$type_ids[]    = $post->ID;

					if ( $manifest->is_changed( $post ) ) {
						if ( $dry_run ) {
							\WP_CLI::log( "[dry-run] Would export: {$post->post_name}" );
						} elseif ( $this->generator->generate_post( $post ) ) {
							++$exported;
						} else {
							++$failed;
						}
					} else {
						++$skipped;
					}

					// Add all documents to the manifest (changed or not).
					$full_path = $this->generator->get_export_path( $post );
					if ( file_exists( $full_path ) ) {
						$manifest->add_document( $post, $relative_path );
					}

					clean_post_cache( $post );
				}

				$offset += $batch_size;
			} while ( $fetched === $batch_size );

			$manifest->mark_deleted_documents( $type_ids );

			if ( $dry_run ) {
				\WP_CLI::log(
					sprintf( '[dry-run] %s: %d changed, %d unchanged.', $post_type, $exported + $failed, $skipped )
				);
				continue;
			}

			$manifest->save();
			$manifest->save_changes_file();

			\WP_CLI::success(
				sprintf(
					'%s: %d exported, %d unchanged, %d failed.',
					$post_type,
					$exported,
					$skipped,
					$failed
				)
			);
		}
	}

	/**
	 * Build and save a manifest.json per post-type folder.
	 *
	 * Each post type gets its own manifest inside its export subdirectory,
	 * enabling independent change tracking per content type.
	 *
	 * @since  1.1.0
	 * @param  string   $export_base Absolute path to the export base directory.
	 * @param  string[] $post_types  Post type slugs to include.
	 * @return bool True if all manifests saved successfully.
	 */
	private function generate_manifest( string $export_base, array $post_types ): bool {
		$success    = true;
		$batch_size = 100;

		foreach ( $post_types as $post_type ) {
			$type_dir = trailingslashit( $export_base ) . $post_type . '/';
			$manifest = new ManifestGenerator( $type_dir, $this->file_writer );
			$type_ids = array();
			$offset   = 0;

			do {
				$posts = get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'publish',
						'posts_per_page' => $batch_size, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
						'offset'         => $offset,
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'no_found_rows'  => true,
					)
				);

				$fetched = count( $posts );

				foreach ( $posts as $post ) {
					$full_path     = $this->generator->get_export_path( $post );
					$relative_path = sanitize_file_name( $post->post_name ) . '.md';
					$type_ids[]    = $post->ID;

					if ( file_exists( $full_path ) ) {
						$manifest->add_document( $post, $relative_path );
					}

					clean_post_cache( $post );
				}

				$offset += $batch_size;
			} while ( $fetched === $batch_size );

			$manifest->mark_deleted_documents( $type_ids );

			if ( ! $manifest->save() ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Count the .md files directly inside a directory, excluding index.md
	 * (an OKF directory listing, not a generated post).
	 *
	 * @since  1.6.0
	 * @param  string $dir Absolute directory path.
	 * @return int
	 */
	private function count_post_files( string $dir ): int {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$files = glob( $dir . '/*.md' );
		$files = false === $files ? array() : $files;

		return count(
			array_filter( $files, static fn( string $file ): bool => 'index.md' !== basename( $file ) )
		);
	}

	/**
	 * Regenerate every index.md after a bulk generate run and print the counts.
	 * No-op when the index generator was not wired up.
	 *
	 * @since  1.6.0
	 */
	private function rebuild_indexes(): void {
		if ( null === $this->index_generator ) {
			return;
		}

		$results = $this->index_generator->generate_all();

		\WP_CLI::log( sprintf( 'Indexes: %d written, %d skipped.', $results['written'], $results['skipped'] ) );
	}

	/**
	 * Rebuild the OKF `.tar.gz` bundle after a bulk generate run, when enabled.
	 * No-op unless the bundle generator was wired up and `bundle_enabled` is on.
	 *
	 * @since  1.6.0
	 */
	private function rebuild_bundle(): void {
		if ( null === $this->bundle_generator || empty( $this->options['bundle_enabled'] ) ) {
			return;
		}

		if ( $this->bundle_generator->build() ) {
			\WP_CLI::log( sprintf( 'Bundle rebuilt: %s', $this->bundle_generator->bundle_path() ) );
		} else {
			\WP_CLI::warning( 'Bundle rebuild failed.' );
		}
	}

	/**
	 * Compose the bundle freshness line for `status`, disambiguating the
	 * mid-debounce window: a save deletes the tree hash immediately, so
	 * "stale" alone doesn't say whether a rebuild is already scheduled.
	 *
	 * @since  1.6.0
	 */
	private function bundle_status_line(): string {
		$path = $this->bundle_generator->bundle_path();

		if ( ! file_exists( $path ) ) {
			return 'Bundle: missing';
		}

		if ( ! $this->bundle_generator->is_stale() ) {
			return sprintf( 'Bundle: fresh (%s)', $path );
		}

		return wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' )
			? sprintf( 'Bundle: stale — rebuild scheduled (%s)', $path )
			: sprintf( 'Bundle: stale — no rebuild scheduled (%s)', $path );
	}

	/**
	 * Count every index.md file under the export base: the bundle root, each
	 * post type directory, the taxonomy root, and each taxonomy directory.
	 *
	 * @since  1.6.0
	 * @param  string $export_base Absolute path to the export base directory.
	 * @return int
	 */
	private function count_index_files( string $export_base ): int {
		$count = 0;

		if ( file_exists( $export_base . '/index.md' ) ) {
			++$count;
		}

		foreach ( ExportPolicy::enabled_post_types( $this->options ) as $type ) {
			if ( file_exists( $export_base . '/' . $type . '/index.md' ) ) {
				++$count;
			}
		}

		if ( file_exists( $export_base . '/' . ExportPolicy::TAXONOMY_DIR . '/index.md' ) ) {
			++$count;
		}

		foreach ( array_keys( get_taxonomies( array( 'public' => true ) ) ) as $taxonomy ) {
			if ( file_exists( $export_base . '/' . ExportPolicy::TAXONOMY_DIR . '/' . $taxonomy . '/index.md' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @since  1.0.0
	 */
	private function delete_type( string $post_type ): void {
		$export_base = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );
		$type_dir    = $export_base . '/' . $post_type;

		if ( ! is_dir( $type_dir ) ) {
			\WP_CLI::log( "No export directory for {$post_type}." );
			return;
		}

		$files     = glob( $type_dir . '/*.md' ) ?: array();
		$real_base = realpath( $type_dir );
		foreach ( $files as $file ) {
			$real_file = realpath( $file );
			if ( false === $real_base || false === $real_file || ! str_starts_with( $real_file, $real_base . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			unlink( $real_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		\WP_CLI::log( sprintf( 'Deleted %d files for %s.', count( $files ), $post_type ) );
	}
}

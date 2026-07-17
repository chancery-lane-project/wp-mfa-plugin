<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Generates and manages Markdown archive files for taxonomy terms.
 *
 * File path pattern: {export_dir}/taxonomy/{taxonomy}/{term-slug}.md
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class TaxonomyArchiveGenerator {

	/**
	 * @since  1.1.0
	 * @param  array<string, mixed> $options        Plugin options.
	 * @param  YamlFormatter        $yaml_formatter Serialises frontmatter to YAML.
	 * @param  FileWriter           $file_writer    Handles filesystem I/O.
	 */
	public function __construct(
		private readonly array $options,
		private readonly YamlFormatter $yaml_formatter,
		private readonly FileWriter $file_writer,
	) {}

	/**
	 * Return the full filesystem path for a term's Markdown archive file.
	 *
	 * Path pattern: {export_dir}/taxonomy/{taxonomy}/{term-slug}.md
	 * Both segments are passed through sanitize_file_name().
	 *
	 * @since  1.1.0
	 * @param  \WP_Term $term The term.
	 * @return string
	 */
	public function get_export_path( \WP_Term $term ): string {
		$base = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );

		return $base . DIRECTORY_SEPARATOR
			. str_replace( '/', DIRECTORY_SEPARATOR, ExportPolicy::term_relative_path( $term->taxonomy, $term->slug ) );
	}

	/**
	 * Generate and write the Markdown archive file for a term.
	 *
	 * @since  1.1.0
	 * @param  \WP_Term $term The term.
	 * @return bool True on success.
	 */
	public function generate_term( \WP_Term $term ): bool {
		$posts = $this->get_term_posts( $term );

		$frontmatter = array(
			'title'      => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'type'       => 'taxonomy_archive',
			'taxonomy'   => $term->taxonomy,
			'slug'       => $term->slug,
			'term_id'    => $term->term_id,
			'permalink'  => get_term_link( $term ),
			'post_count' => count( $posts ),
		);

		if ( '' !== $term->description ) {
			$frontmatter['description'] = $term->description;
		}

		/**
		 * Modify the frontmatter array for a taxonomy archive before serialisation.
		 *
		 * @since  1.1.0
		 * @param  array<string, mixed> $frontmatter The frontmatter array.
		 * @param  \WP_Term             $term        The term.
		 */
		$frontmatter = (array) apply_filters( 'markdown_for_agents_taxonomy_frontmatter', $frontmatter, $term );

		$yaml    = $this->yaml_formatter->format( $frontmatter );
		$body    = $this->build_body( $term, $posts );
		$content = $yaml . "\n" . $body;

		$path   = $this->get_export_path( $term );
		$result = $this->file_writer->write( $path, $content );

		if ( $result ) {
			/**
			 * Fired after a taxonomy archive Markdown file is successfully written.
			 *
			 * @since  1.6.0
			 * @param  string   $path The filesystem path to the written file.
			 * @param  \WP_Term $term The term.
			 */
			do_action( 'markdown_for_agents_taxonomy_file_generated', $path, $term );
		}

		return $result;
	}

	/**
	 * Delete the archive file for a term.
	 *
	 * Returns false (not an error) if the file does not exist.
	 *
	 * @since  1.1.0
	 * @param  \WP_Term $term The term.
	 * @return bool True if deleted, false if file was not found or deletion failed.
	 */
	public function delete_term_file( \WP_Term $term ): bool {
		$path = $this->get_export_path( $term );

		if ( ! file_exists( $path ) ) {
			return false;
		}

		$result = $this->file_writer->delete( $path );

		if ( $result ) {
			/**
			 * Fired after a taxonomy archive Markdown file is deleted.
			 *
			 * @since  1.6.0
			 * @param  string   $path The filesystem path of the deleted file.
			 * @param  \WP_Term $term The term.
			 */
			do_action( 'markdown_for_agents_taxonomy_file_deleted', $path, $term );
		}

		return $result;
	}

	/**
	 * Hook callback for delete_term — removes the term's archive file.
	 *
	 * @since  1.1.0
	 * @param  int      $term_id      Term ID.
	 * @param  int      $tt_id        Term taxonomy ID.
	 * @param  string   $taxonomy     Taxonomy slug.
	 * @param  \WP_Term $deleted_term The deleted term object.
	 */
	public function on_delete_term( int $term_id, int $tt_id, string $taxonomy, \WP_Term $deleted_term ): void {
		$this->delete_term_file( $deleted_term );
	}

	/**
	 * Generate archives for all public taxonomy terms (or one taxonomy).
	 *
	 * @since  1.1.0
	 * @param  string $taxonomy Optional. Limit to one taxonomy slug.
	 * @return array{success: int, skipped: int, failed: int}
	 */
	public function generate_all( string $taxonomy = '' ): array {
		$results    = array( 'success' => 0, 'skipped' => 0, 'failed' => 0 );
		$taxonomies = $taxonomy
			? array( $taxonomy )
			: array_keys( get_taxonomies( array( 'public' => true ) ) );

		foreach ( $taxonomies as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( $this->generate_term( $term ) ) {
					++$results['success'];
				} else {
					++$results['failed'];
				}
			}
		}

		return $results;
	}

	/**
	 * Generate a paginated batch of term archives across all public taxonomies.
	 *
	 * Mirrors Generator::generate_batch() — returns the same response shape so
	 * the Admin AJAX handler and bulk-generate.js can treat them identically.
	 *
	 * @since  1.1.0
	 * @param  int $offset Zero-based offset into the full term list.
	 * @param  int $limit  Maximum terms to process in this batch.
	 * @return array{total: int, processed: int, errors: list<array{term_id: int, message: string}>}
	 */
	public function generate_batch( int $offset, int $limit ): array {
		if ( $limit <= 0 ) {
			return array( 'total' => 0, 'processed' => 0, 'errors' => array() );
		}

		$all_terms = $this->get_all_public_terms();
		$total     = count( $all_terms );
		$batch     = array_slice( $all_terms, $offset, $limit );
		$processed = 0;
		$errors    = array();

		foreach ( $batch as $term ) {
			try {
				if ( $this->generate_term( $term ) ) {
					++$processed;
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'term_id' => $term->term_id,
					'message' => $e->getMessage(),
				);
			}
		}

		return array(
			'total'     => $total,
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Collect all terms across every public taxonomy.
	 *
	 * @since  1.1.0
	 * @return \WP_Term[]
	 */
	private function get_all_public_terms(): array {
		$taxonomies = array_keys( get_taxonomies( array( 'public' => true ) ) );
		$all_terms  = array();

		foreach ( $taxonomies as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );

			if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
				$all_terms = array_merge( $all_terms, $terms );
			}
		}

		return $all_terms;
	}

	/**
	 * Fetch all published posts in a term, batched to avoid memory exhaustion.
	 *
	 * @since  1.1.0
	 * @param  \WP_Term $term The term.
	 * @return \WP_Post[]
	 */
	private function get_term_posts( \WP_Term $term ): array {
		$batch_size = 100;
		$offset     = 0;
		$all_posts  = array();

		do {
			$posts = get_posts( // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				array(
					'post_type'      => ExportPolicy::enabled_post_types( $this->options ),
					'post_status'    => 'publish',
					'posts_per_page' => $batch_size,
					'offset'         => $offset,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => $term->taxonomy,
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			$all_posts = array_merge( $all_posts, $posts );
			$offset   += $batch_size;
		} while ( count( $posts ) === $batch_size );

		return $all_posts;
	}

	/**
	 * Build the Markdown body for a term archive.
	 *
	 * @since  1.1.0
	 * @param  \WP_Term    $term  The term.
	 * @param  \WP_Post[]  $posts Published posts in this term.
	 * @return string
	 */
	private function build_body( \WP_Term $term, array $posts ): string {
		$name  = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
		$count = count( $posts );

		$lines = array(
			'# ' . $name,
			'',
			'Posts in this archive: ' . $count,
			'',
		);

		$enabled_post_types = ExportPolicy::enabled_post_types( $this->options );

		foreach ( $posts as $post ) {
			$title   = wp_strip_all_tags( $post->post_title );
			$excerpt = wp_strip_all_tags( $post->post_excerpt );

			if ( in_array( $post->post_type, $enabled_post_types, true ) ) {
				$url = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base_url( $this->options )
					. '/' . ExportPolicy::post_relative_path( $post );
			} else {
				$url = get_permalink( $post->ID );
			}

			$line = '- [' . $title . '](' . $url . ')';

			if ( '' !== $excerpt ) {
				$line .= ' — ' . $excerpt;
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines ) . "\n";
	}
}

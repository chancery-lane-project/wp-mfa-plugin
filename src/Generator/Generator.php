<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Orchestrates Markdown file generation for WordPress posts.
 *
 * Coordinates FrontmatterBuilder, ContentFilter, Converter, YamlFormatter,
 * and FileWriter. All collaborators are injected via the constructor for
 * full testability.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class Generator {

	/**
	 * @since  1.0.0
	 * @param  array<string, mixed>           $options              Plugin options.
	 * @param  FrontmatterBuilder             $frontmatter_builder  Builds the frontmatter array.
	 * @param  ContentFilter                  $content_filter       Cleans HTML before conversion.
	 * @param  Converter                      $converter            Converts HTML to Markdown.
	 * @param  YamlFormatter                  $yaml_formatter       Serialises frontmatter to YAML.
	 * @param  FileWriter                     $file_writer          Handles filesystem I/O.
	 * @param  FieldResolver                  $field_resolver       Resolves custom field values.
	 * @param  TaxonomyArchiveGenerator|null  $taxonomy_generator   Optional taxonomy archive generator.
	 */
	public function __construct(
		private readonly array $options,
		private readonly FrontmatterBuilder $frontmatter_builder,
		private readonly ContentFilter $content_filter,
		private readonly Converter $converter,
		private readonly YamlFormatter $yaml_formatter,
		private readonly FileWriter $file_writer,
		private readonly FieldResolver $field_resolver,
		private readonly ?TaxonomyArchiveGenerator $taxonomy_generator = null,
	) {}

	/**
	 * Generate a Markdown export file for a single post.
	 *
	 * @since  1.0.0
	 * @param  \WP_Post $post The post to generate for.
	 * @return bool True on success, false on failure or skip.
	 */
	public function generate_post( \WP_Post $post ): bool {
		if ( ! $this->is_eligible( $post ) ) {
			return false;
		}

		$frontmatter = $this->frontmatter_builder->build( $post );

		$html = $this->get_post_content( $post );
		$html = $this->content_filter->filter( $html );
		$markdown = $this->converter->convert( $html, $post );

		$yaml    = $this->yaml_formatter->format( $frontmatter );
		$content = $yaml . "\n" . $markdown;

		$path   = $this->get_export_path( $post );
		$result = $this->file_writer->write( $path, $content );

		if ( $result ) {
			/**
			 * Fired after a Markdown export file is successfully written.
			 *
			 * @since  1.0.0
			 * @param  string    $path The filesystem path to the written file.
			 * @param  \WP_Post  $post The post.
			 */
			do_action( 'markdown_for_agents_file_generated', $path, $post );
		}

		return $result;
	}

	/**
	 * Generate Markdown files for all published posts of a given post type.
	 *
	 * Processes in batches of 100 to avoid memory exhaustion on large sites.
	 * Never uses posts_per_page: -1.
	 *
	 * @since  1.0.0
	 * @param  string        $post_type The post type slug.
	 * @param  callable|null $progress  Optional callback( int $done ) called after each post.
	 * @return array{success: int, failed: int, skipped: int}
	 */
	public function generate_post_type( string $post_type, ?callable $progress = null ): array {
		$results    = array(
			'success' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);
		$batch_size = 100;
		$offset     = 0;

		do {
			$posts = get_posts(
				array( // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				)
			);

			foreach ( $posts as $post ) {
				if ( $this->generate_post( $post ) ) {
					++$results['success'];
				} else {
					++$results['failed'];
				}

				if ( is_callable( $progress ) ) {
					$progress( $results['success'] + $results['failed'] + $results['skipped'] );
				}
			}

			$offset += $batch_size;
		} while ( count( $posts ) === $batch_size );

		return $results;
	}

	/**
	 * Generate Markdown files for a paginated slice of published posts.
	 *
	 * Processes $limit posts starting at $offset. Uses WP_Query so found_posts
	 * is always populated (do not set no_found_rows). Returns a summary of the
	 * batch suitable for JSON responses.
	 *
	 * @since  1.1.0
	 * @param  string $post_type The post type slug.
	 * @param  int    $offset    Zero-based offset into the full result set.
	 * @param  int    $limit     Maximum posts to process in this batch.
	 * @return array{total: int, processed: int, errors: list<array{post_id: int, message: string}>}
	 */
	public function generate_batch( string $post_type, int $offset, int $limit ): array {
		if ( $limit <= 0 ) {
			return array( 'total' => 0, 'processed' => 0, 'errors' => array() );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'offset'         => $offset,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$processed = 0;
		$errors    = array();

		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				$errors[] = array(
					'post_id' => $post_id,
					'message' => 'Post object not found; may have been deleted concurrently.',
				);
				continue;
			}

			try {
				if ( $this->generate_post( $post ) ) {
					++$processed;
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'post_id' => $post_id,
					'message' => $e->getMessage(),
				);
			}
		}

		return array(
			'total'     => $query->found_posts,
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Delete the Markdown export file for a post.
	 *
	 * @since  1.0.0
	 * @param  int $post_id The post ID.
	 * @return bool True on success or file not found, false on failure.
	 */
	public function delete_post( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$path   = $this->get_export_path( $post );
		$result = $this->file_writer->delete( $path );

		if ( $result ) {
			/**
			 * Fired after a Markdown export file is deleted.
			 *
			 * @since  1.0.0
			 * @param  string $path    The filesystem path of the deleted file.
			 * @param  int    $post_id The post ID.
			 */
			do_action( 'markdown_for_agents_file_deleted', $path, $post_id );
		}

		return $result;
	}

	/**
	 * Return the full filesystem path for a post's Markdown export file.
	 *
	 * Path pattern: {export_dir}/{post-type}/{post-slug}.md
	 *
	 * @since  1.0.0
	 * @param  \WP_Post|int $post The post object or ID.
	 * @return string
	 */
	public function get_export_path( \WP_Post|int $post ): string {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		$base      = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );
		$post_type = sanitize_file_name( $post->post_type );
		$slug      = sanitize_file_name( $post->post_name );
		$path      = $base . DIRECTORY_SEPARATOR . $post_type . DIRECTORY_SEPARATOR . $slug . '.md';

		/**
		 * Override the export file path for a given post.
		 *
		 * @since  1.0.0
		 * @param  string    $path The default export path.
		 * @param  \WP_Post  $post The post.
		 */
		$filtered = (string) apply_filters( 'markdown_for_agents_export_path', $path, $post );

		// Reject any filtered path that escapes the export base directory.
		$real_base     = realpath( $base );
		$filtered_dir  = realpath( dirname( $filtered ) );
		if (
			false !== $real_base &&
			false !== $filtered_dir &&
			str_starts_with( $filtered_dir . DIRECTORY_SEPARATOR, $real_base . DIRECTORY_SEPARATOR )
		) {
			return $filtered;
		}

		return $path;
	}

	/**
	 * Hook callback for save_post — generates or deletes the export file.
	 *
	 * Skips autosaves, revisions, and uses a post meta flag to prevent
	 * recursive triggers.
	 *
	 * @since  1.0.0
	 * @param  int      $post_id The post ID.
	 * @param  \WP_Post $post    The post object.
	 */
	public function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Guard against recursive triggers from within generate_post.
		if ( get_post_meta( $post_id, '_markdown_for_agents_generating', true ) ) {
			return;
		}

		try {
			update_post_meta( $post_id, '_markdown_for_agents_generating', '1' );

			if ( 'publish' === $post->post_status ) {
				$this->generate_post( $post );
			} elseif ( in_array( $post->post_status, array( 'trash', 'draft', 'pending', 'private' ), true ) ) {
				$this->delete_post( $post_id );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP Markdown for Agents: on_save_post failed for post ' . $post_id . ': ' . $e->getMessage() );
			}
		} finally {
			delete_post_meta( $post_id, '_markdown_for_agents_generating' );
		}

		// Regenerate taxonomy archives for all terms on this post (outside guard block).
		try {
			if ( ! empty( $this->options['auto_generate'] ) && null !== $this->taxonomy_generator ) {
				$this->regenerate_term_archives( $post_id );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP Markdown for Agents: term archive regeneration failed for post ' . $post_id . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Cache the taxonomy terms for a post before it is deleted.
	 *
	 * Call this from a before_delete_post hook, then call
	 * regenerate_term_archives_after_delete() from after_delete_post.
	 *
	 * @since  1.1.0
	 * @param  int $post_id The post ID about to be deleted.
	 */
	public function cache_post_terms( int $post_id ): void {
		if ( empty( $this->options['auto_generate'] ) || null === $this->taxonomy_generator ) {
			return;
		}

		$taxonomies = array_keys( get_taxonomies( array( 'public' => true ) ) );
		$cached     = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy );

			if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
				$cached = array_merge( $cached, $terms );
			}
		}

		$this->pending_deletion_terms[ $post_id ] = $cached;
	}

	/**
	 * Regenerate term archives for a post that has just been deleted.
	 *
	 * Must be preceded by a call to cache_post_terms() for the same post ID.
	 *
	 * @since  1.1.0
	 * @param  int      $post_id The post ID that was deleted.
	 * @param  \WP_Post $post    The post object (already deleted from DB).
	 */
	public function regenerate_term_archives_after_delete( int $post_id, \WP_Post $post ): void {
		if ( empty( $this->options['auto_generate'] ) || null === $this->taxonomy_generator ) {
			return;
		}

		$terms = $this->pending_deletion_terms[ $post_id ] ?? array();
		unset( $this->pending_deletion_terms[ $post_id ] );

		foreach ( $terms as $term ) {
			$this->taxonomy_generator->generate_term( $term );
		}
	}

	/** @var array<int, \WP_Term[]> */
	private array $pending_deletion_terms = array();

	/**
	 * Get the HTML content for a post.
	 *
	 * When content fields are configured for the post type, those fields are
	 * used as the body content and post_content is excluded. Otherwise falls
	 * back to standard post_content.
	 *
	 * @since  1.1.0
	 * @param  \WP_Post $post The post.
	 * @return string HTML content.
	 */
	private function get_post_content( \WP_Post $post ): string {
		$type_config    = $this->options['post_type_configs'][ $post->post_type ] ?? array();
		$content_fields = (array) ( $type_config['content_fields'] ?? array() );

		if ( empty( $content_fields ) ) {
			return apply_filters( 'the_content', $post->post_content );
		}

		// Build body from configured content fields.
		$parts = array();

		foreach ( $content_fields as $field_path ) {
			$value = $this->field_resolver->resolve( $post->ID, $field_path );

			if ( null === $value || '' === $value ) {
				continue;
			}

			// Convert to string if needed.
			if ( is_array( $value ) ) {
				$value = implode( "\n\n", array_filter( array_map( 'strval', $value ) ) );
			} else {
				$value = (string) $value;
			}

			// Wrap plain text in <p> tags if not already block-level HTML.
			if ( ! preg_match( '/^<(p|div|h[1-6]|ul|ol|table|blockquote|pre|figure|section|article)\b/i', trim( $value ) ) ) {
				$value = '<p>' . $value . '</p>';
			}

			// Prepend the ACF field label as an H2 heading when available.
			$label = $this->field_resolver->resolve_label( $post->ID, $field_path );
			if ( $label ) {
				$value = '<h2>' . esc_html( $label ) . '</h2>' . "\n\n" . $value;
			}

			$parts[] = $value;
		}

		$html = implode( "\n\n", $parts );

		return apply_filters( 'the_content', $html );
	}

	/**
	 * Regenerate archives for every public taxonomy term the post belongs to.
	 *
	 * @since  1.1.0
	 * @param  int $post_id The post ID.
	 */
	private function regenerate_term_archives( int $post_id ): void {
		$taxonomies = array_keys( get_taxonomies( array( 'public' => true ) ) );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy );

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$this->taxonomy_generator->generate_term( $term );
			}
		}
	}

	/**
	 * Check whether a post is eligible for export.
	 *
	 * @since  1.0.0
	 * @param  \WP_Post $post The post to check.
	 * @return bool
	 */
	private function is_eligible( \WP_Post $post ): bool {
		$enabled_types = (array) ( $this->options['post_types'] ?? array() );
		return in_array( $post->post_type, $enabled_types, true )
			&& 'publish' === $post->post_status;
	}
}

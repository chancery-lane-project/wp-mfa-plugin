<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

use Tclp\WpMarkdownForAgents\Generator\FieldResolver;

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
	 * @param  array<string, mixed> $options            Plugin options.
	 * @param  FrontmatterBuilder   $frontmatter_builder Builds the frontmatter array.
	 * @param  ContentFilter        $content_filter      Cleans HTML before conversion.
	 * @param  Converter            $converter           Converts HTML to Markdown.
	 * @param  YamlFormatter        $yaml_formatter      Serialises frontmatter to YAML.
	 * @param  FileWriter           $file_writer         Handles filesystem I/O.
	 * @param  FieldResolver        $field_resolver      Resolves custom field values.
	 */
	public function __construct(
		private readonly array $options,
		private readonly FrontmatterBuilder $frontmatter_builder,
		private readonly ContentFilter $content_filter,
		private readonly Converter $converter,
		private readonly YamlFormatter $yaml_formatter,
		private readonly FileWriter $file_writer,
		private readonly FieldResolver $field_resolver
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
			do_action( 'wp_mfa_file_generated', $path, $post );
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
			do_action( 'wp_mfa_file_deleted', $path, $post_id );
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
		return apply_filters( 'wp_mfa_export_path', $path, $post );
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
		if ( get_post_meta( $post_id, '_wp_mfa_generating', true ) ) {
			return;
		}

		update_post_meta( $post_id, '_wp_mfa_generating', '1' );

		if ( 'publish' === $post->post_status ) {
			$this->generate_post( $post );
		} elseif ( in_array( $post->post_status, array( 'trash', 'draft', 'pending', 'private' ), true ) ) {
			$this->delete_post( $post_id );
		}

		delete_post_meta( $post_id, '_wp_mfa_generating' );
	}

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

			$parts[] = $value;
		}

		$html = implode( "\n\n", $parts );

		return apply_filters( 'the_content', $html );
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

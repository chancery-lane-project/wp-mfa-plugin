<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Generates OKF `index.md` directory listings.
 *
 * Unlike the rest of the OKF-alignment work, index generation is always on —
 * indexes are new files that existing consumers never fetch, so there is no
 * backward-compatibility risk in producing them unconditionally.
 *
 * Covers four listing types:
 * - Bundle root:            {export_base}/index.md
 * - Per-post-type listing:  {export_base}/{type}/index.md
 * - Taxonomy root listing:  {export_base}/taxonomy/index.md
 * - Per-taxonomy listing:   {export_base}/taxonomy/{taxonomy}/index.md
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class IndexGenerator {

	/**
	 * Absolute path to the export base directory.
	 *
	 * @since  1.6.0
	 * @var    string
	 */
	private string $base;

	/**
	 * @since  1.6.0
	 * @param  array<string, mixed> $options     Plugin options.
	 * @param  FileWriter           $file_writer Handles filesystem I/O.
	 */
	public function __construct(
		private readonly array $options,
		private readonly FileWriter $file_writer,
	) {
		$this->base = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );
	}

	/**
	 * Generate every index.md the plugin manages.
	 *
	 * @since  1.6.0
	 * @return array{written: int, skipped: int}
	 */
	public function generate_all(): array {
		$written = 0;
		$skipped = 0;

		foreach ( $this->enabled_post_types() as $post_type ) {
			if ( ! is_dir( $this->base . '/' . sanitize_file_name( $post_type ) ) ) {
				++$skipped;
				continue;
			}

			if ( $this->generate_for_post_type( $post_type ) ) {
				++$written;
			} else {
				++$skipped;
			}
		}

		$any_taxonomy_dir = is_dir( $this->base . '/taxonomy' );

		foreach ( $this->public_taxonomies() as $taxonomy ) {
			if ( ! is_dir( $this->base . '/taxonomy/' . sanitize_file_name( $taxonomy ) ) ) {
				continue;
			}

			if ( $this->generate_for_taxonomy( $taxonomy ) ) {
				++$written;
			} else {
				++$skipped;
			}
		}

		if ( $any_taxonomy_dir ) {
			$this->generate_taxonomy_root() ? ++$written : ++$skipped;
		}

		$this->generate_root() ? ++$written : ++$skipped;

		return array( 'written' => $written, 'skipped' => $skipped );
	}

	/**
	 * Generate the listing for a single post type's directory.
	 *
	 * @since  1.6.0
	 * @param  string $post_type The post type slug.
	 * @return bool True on success, false if skipped (reserved slug) or write failed.
	 */
	public function generate_for_post_type( string $post_type ): bool {
		$posts = $this->fetch_published_posts( $post_type );

		if ( $this->has_reserved_post_slug( $posts ) ) {
			$this->log_reserved_skip( 'post type "' . $post_type . '"' );
			return false;
		}

		$entries = array();

		foreach ( $posts as $post ) {
			$eligible = '' === $post->post_password
				&& ! (bool) get_post_meta( $post->ID, '_markdown_for_agents_excluded', true );

			if ( $eligible ) {
				$entries[] = array(
					'title'       => wp_strip_all_tags( $post->post_title ),
					'target'      => sanitize_file_name( $post->post_name ) . '.md',
					'description' => wp_strip_all_tags( $post->post_excerpt ),
				);
			}

			// Evict this post's caches so long runs over many posts don't
			// inflate the object cache. Only clears entries for this post.
			clean_post_cache( $post );
		}

		usort( $entries, static fn( array $a, array $b ): int => strcmp( $a['title'], $b['title'] ) );

		$label = $this->post_type_label( $post_type );
		$lines = array_map(
			fn( array $entry ): string => $this->build_entry( $entry['title'], $entry['target'], $entry['description'] ),
			$entries
		);

		$relative_path = sanitize_file_name( $post_type ) . '/index.md';
		$content       = $this->build_body( array( $label => $lines ) );

		return $this->write( $relative_path, $content );
	}

	/**
	 * Generate the listing for a single taxonomy's directory.
	 *
	 * @since  1.6.0
	 * @param  string $taxonomy The taxonomy slug.
	 * @return bool True on success, false if skipped (reserved slug) or write failed.
	 */
	public function generate_for_taxonomy( string $taxonomy ): bool {
		$terms = $this->fetch_terms( $taxonomy );

		if ( $this->has_reserved_term_slug( $terms ) ) {
			$this->log_reserved_skip( 'taxonomy "' . $taxonomy . '"' );
			return false;
		}

		$entries = array();

		foreach ( $terms as $term ) {
			$entries[] = array(
				'name'        => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'target'      => sanitize_file_name( $term->slug ) . '.md',
				'description' => $this->collapse_description( (string) $term->description ),
			);
		}

		usort( $entries, static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );

		$label = $this->taxonomy_label( $taxonomy );
		$lines = array_map(
			fn( array $entry ): string => $this->build_entry( $entry['name'], $entry['target'], $entry['description'] ),
			$entries
		);

		$relative_path = 'taxonomy/' . sanitize_file_name( $taxonomy ) . '/index.md';
		$content       = $this->build_body( array( $label => $lines ) );

		return $this->write( $relative_path, $content );
	}

	/**
	 * Generate the bundle-root index.md.
	 *
	 * The only index carrying frontmatter (the OKF version pin).
	 *
	 * @since  1.6.0
	 * @return bool True on success.
	 */
	public function generate_root(): bool {
		$lines = array();

		foreach ( $this->enabled_post_types() as $post_type ) {
			$count = $this->count_md_files( $this->base . '/' . sanitize_file_name( $post_type ) );

			if ( $count < 1 ) {
				continue;
			}

			$lines[] = $this->build_entry(
				$post_type,
				sanitize_file_name( $post_type ) . '/',
				$this->pluralize( $count, 'document', 'documents' )
			);
		}

		$sections = array( 'Content' => $lines );

		if ( is_dir( $this->base . '/taxonomy' ) ) {
			$sections['Taxonomies'] = array(
				$this->build_entry( 'taxonomy', 'taxonomy/', 'Term archives grouped by taxonomy' ),
			);
		}

		$content = "---\nokf_version: \"0.1\"\n---\n\n" . $this->build_body( $sections );

		return $this->write( 'index.md', $content );
	}

	/**
	 * Generate the taxonomy-directory root index.md, listing all taxonomies.
	 *
	 * @since  1.6.0
	 * @return bool True on success.
	 */
	public function generate_taxonomy_root(): bool {
		$lines = array();

		foreach ( $this->public_taxonomies() as $taxonomy ) {
			$dir = $this->base . '/taxonomy/' . sanitize_file_name( $taxonomy );

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$count   = $this->count_md_files( $dir );
			$lines[] = $this->build_entry(
				$this->taxonomy_label( $taxonomy ),
				sanitize_file_name( $taxonomy ) . '/',
				$this->pluralize( $count, 'term', 'terms' )
			);
		}

		$content = $this->build_body( array( 'Taxonomies' => $lines ) );

		return $this->write( 'taxonomy/index.md', $content );
	}

	/**
	 * Delete every index.md file this class manages.
	 *
	 * Never touches concept files, including a post-owned {type}/index.md
	 * (a published post slugged "index").
	 *
	 * @since  1.6.0
	 * @return int Number of files deleted.
	 */
	public function delete_all(): int {
		$count = 0;

		foreach ( $this->enabled_post_types() as $post_type ) {
			if ( $this->has_published_post_slugged_index( $post_type ) ) {
				continue;
			}

			$count += $this->delete_if_exists( sanitize_file_name( $post_type ) . '/index.md' );
		}

		foreach ( $this->public_taxonomies() as $taxonomy ) {
			if ( $this->has_term_slugged_index( $taxonomy ) ) {
				continue;
			}

			$count += $this->delete_if_exists( 'taxonomy/' . sanitize_file_name( $taxonomy ) . '/index.md' );
		}

		$count += $this->delete_if_exists( 'taxonomy/index.md' );
		$count += $this->delete_if_exists( 'index.md' );

		return $count;
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Fetch all published posts of a type, batched, ordered by title ascending.
	 *
	 * @since  1.6.0
	 * @param  string $post_type The post type slug.
	 * @return \WP_Post[]
	 */
	private function fetch_published_posts( string $post_type ): array {
		$batch_size = 100;
		$offset     = 0;
		$all_posts  = array();

		do {
			$posts = get_posts( // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => $batch_size,
					'offset'                 => $offset,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					// Only get_post_meta() for the exclusion flag is read per post below,
					// so priming the full meta cache would be wasted work.
					'update_post_meta_cache' => false,
				)
			);

			$all_posts = array_merge( $all_posts, $posts );
			$offset   += $batch_size;
		} while ( count( $posts ) === $batch_size );

		return $all_posts;
	}

	/**
	 * Fetch all terms for a taxonomy, tolerating WP_Error.
	 *
	 * @since  1.6.0
	 * @param  string $taxonomy The taxonomy slug.
	 * @return \WP_Term[]
	 */
	private function fetch_terms( string $taxonomy ): array {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return $terms;
	}

	/**
	 * Targeted lookup for delete_all(): is there a published post slugged
	 * "index" in this post type? Narrows the query instead of fetching every
	 * published post just to scan for one slug.
	 *
	 * @since  1.6.0
	 * @param  string $post_type The post type slug.
	 * @return bool
	 */
	private function has_published_post_slugged_index( string $post_type ): bool {
		$matches = get_posts(
			array(
				'post_type'      => $post_type,
				'name'           => 'index',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		return $this->has_reserved_post_slug( $matches );
	}

	/**
	 * Targeted lookup for delete_all(): is there a term slugged "index" in
	 * this taxonomy? Narrows the query instead of fetching every term just
	 * to scan for one slug.
	 *
	 * @since  1.6.0
	 * @param  string $taxonomy The taxonomy slug.
	 * @return bool
	 */
	private function has_term_slugged_index( string $taxonomy ): bool {
		$matches = get_terms( array( 'taxonomy' => $taxonomy, 'slug' => 'index', 'hide_empty' => false ) );

		if ( is_wp_error( $matches ) || ! is_array( $matches ) ) {
			return false;
		}

		return $this->has_reserved_term_slug( $matches );
	}

	/**
	 * @since  1.6.0
	 * @param  \WP_Post[] $posts Published posts.
	 * @return bool
	 */
	private function has_reserved_post_slug( array $posts ): bool {
		foreach ( $posts as $post ) {
			if ( 'index' === $post->post_name && 'publish' === $post->post_status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @since  1.6.0
	 * @param  \WP_Term[] $terms Terms.
	 * @return bool
	 */
	private function has_reserved_term_slug( array $terms ): bool {
		foreach ( $terms as $term ) {
			if ( 'index' === $term->slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @since  1.6.0
	 * @param  string $what Human-readable description of what was skipped.
	 */
	private function log_reserved_skip( string $what ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, guarded by WP_DEBUG.
			error_log( 'WP Markdown for Agents: skipped index.md generation for ' . $what . ' — a published item is slugged "index".' );
		}
	}

	/**
	 * @since  1.6.0
	 * @return string[]
	 */
	private function enabled_post_types(): array {
		return (array) ( $this->options['post_types'] ?? array( 'post' ) );
	}

	/**
	 * @since  1.6.0
	 * @return string[]
	 */
	private function public_taxonomies(): array {
		return array_keys( get_taxonomies( array( 'public' => true ) ) );
	}

	/**
	 * @since  1.6.0
	 * @param  string $post_type The post type slug.
	 * @return string
	 */
	private function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		return ( $object && isset( $object->labels->name ) ) ? $object->labels->name : $post_type;
	}

	/**
	 * @since  1.6.0
	 * @param  string $taxonomy The taxonomy slug.
	 * @return string
	 */
	private function taxonomy_label( string $taxonomy ): string {
		$object = get_taxonomy( $taxonomy );

		return ( $object && isset( $object->labels->name ) ) ? $object->labels->name : $taxonomy;
	}

	/**
	 * Strip HTML and collapse a term description to a single line.
	 *
	 * @since  1.6.0
	 * @param  string $description Raw term description.
	 * @return string
	 */
	private function collapse_description( string $description ): string {
		$stripped = wp_strip_all_tags( $description );

		return trim( str_replace( array( "\r\n", "\n", "\r" ), ' ', $stripped ) );
	}

	/**
	 * Count the .md files directly inside a directory, excluding index.md.
	 *
	 * @since  1.6.0
	 * @param  string $dir Absolute directory path.
	 * @return int
	 */
	private function count_md_files( string $dir ): int {
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
	 * @since  1.6.0
	 * @param  int    $count    The quantity.
	 * @param  string $singular Singular noun.
	 * @param  string $plural   Plural noun.
	 * @return string
	 */
	private function pluralize( int $count, string $singular, string $plural ): string {
		return $count . ' ' . ( 1 === $count ? $singular : $plural );
	}

	/**
	 * Build a single OKF list entry line.
	 *
	 * @since  1.6.0
	 * @param  string $title       Link text.
	 * @param  string $target      Link target.
	 * @param  string $description Optional description.
	 * @return string
	 */
	private function build_entry( string $title, string $target, string $description ): string {
		$line = '* [' . $title . '](' . $target . ')';

		if ( '' !== $description ) {
			$line .= ' - ' . $description;
		}

		return $line;
	}

	/**
	 * Assemble an index body from named sections, each a heading plus lines.
	 *
	 * @since  1.6.0
	 * @param  array<string, string[]> $sections Heading => list of entry lines.
	 * @return string
	 */
	private function build_body( array $sections ): string {
		$blocks = array();

		foreach ( $sections as $heading => $lines ) {
			$blocks[] = '# ' . $heading . "\n\n" . implode( "\n", $lines );
		}

		return implode( "\n\n", $blocks ) . "\n";
	}

	/**
	 * Apply the content filter and write an index file relative to the export base.
	 *
	 * @since  1.6.0
	 * @param  string $relative_path Path relative to the export base, e.g. 'post/index.md'.
	 * @param  string $content       The index body (and frontmatter, for the root index).
	 * @return bool
	 */
	private function write( string $relative_path, string $content ): bool {
		/**
		 * Filter an index.md file's content before it is written.
		 *
		 * @since  1.6.0
		 * @param  string $content       The index content.
		 * @param  string $relative_path Path relative to the export base, e.g. 'post/index.md'.
		 */
		$content = (string) apply_filters( 'markdown_for_agents_index_content', $content, $relative_path );

		return $this->file_writer->write( $this->base . '/' . $relative_path, $content );
	}

	/**
	 * Delete a managed index file if present.
	 *
	 * @since  1.6.0
	 * @param  string $relative_path Path relative to the export base.
	 * @return int 1 if deleted, 0 otherwise.
	 */
	private function delete_if_exists( string $relative_path ): int {
		$path = $this->base . '/' . $relative_path;

		if ( ! file_exists( $path ) ) {
			return 0;
		}

		return $this->file_writer->delete( $path ) ? 1 : 0;
	}
}

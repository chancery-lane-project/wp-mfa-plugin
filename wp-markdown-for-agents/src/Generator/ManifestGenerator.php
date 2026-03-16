<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Generates manifest.json files for change tracking.
 *
 * Produces a document registry with content hashes and change summaries,
 * enabling RAG systems to identify what changed since the last export
 * without reprocessing all documents.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class ManifestGenerator {

	/**
	 * Current manifest data being built.
	 *
	 * @since  1.1.0
	 * @var    array<string, mixed>
	 */
	private array $manifest;

	/**
	 * Previous manifest loaded from disk (for incremental comparison).
	 *
	 * @since  1.1.0
	 * @var    array<string, mixed>|null
	 */
	private ?array $previous_manifest;

	/**
	 * @since  1.1.0
	 * @param  string     $export_dir Absolute path to the export base directory (with trailing slash).
	 * @param  FileWriter $file_writer FileWriter instance for safe I/O.
	 */
	public function __construct(
		private readonly string $export_dir,
		private readonly FileWriter $file_writer
	) {
		$this->previous_manifest = $this->load_previous_manifest();
		$this->manifest          = $this->initialize_manifest();
	}

	/**
	 * Add a document entry to the manifest.
	 *
	 * Call this for every post that was successfully exported.
	 *
	 * @since  1.1.0
	 * @param  \WP_Post $post     The exported post.
	 * @param  string   $filepath Relative file path from the export directory.
	 */
	public function add_document( \WP_Post $post, string $filepath ): void {
		$content_hash = md5( $post->post_content );
		$meta_hash    = md5( $post->post_modified . $post->post_title );
		$full_hash    = md5( $content_hash . $meta_hash );

		$change_status = $this->determine_change_status( $post->ID, $full_hash );

		$this->manifest['documents'][] = array(
			'id'            => $post->ID,
			'path'          => $filepath,
			'title'         => $post->post_title,
			'post_type'     => $post->post_type,
			'status'        => $post->post_status,
			'modified'      => $post->post_modified,
			'content_hash'  => $content_hash,
			'metadata_hash' => $meta_hash,
			'full_hash'     => $full_hash,
			'word_count'    => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'change_status' => $change_status,
		);

		++$this->manifest['total_documents'];
		++$this->manifest['change_summary'][ $change_status ];
	}

	/**
	 * Mark documents that existed in the previous manifest but are absent from the current export.
	 *
	 * @since  1.1.0
	 * @param  int[] $current_post_ids Post IDs included in the current export.
	 */
	public function mark_deleted_documents( array $current_post_ids ): void {
		if ( empty( $this->previous_manifest ) ) {
			return;
		}

		$deleted = array();

		foreach ( $this->previous_manifest['documents'] ?? array() as $doc ) {
			if ( ! in_array( $doc['id'], $current_post_ids, true ) ) {
				$deleted[] = array(
					'id'         => $doc['id'],
					'path'       => $doc['path'],
					'title'      => $doc['title'] ?? '',
					'deleted_at' => current_time( 'mysql' ),
				);

				++$this->manifest['change_summary']['deleted'];
			}
		}

		if ( ! empty( $deleted ) ) {
			$this->manifest['deleted_documents'] = $deleted;
		}
	}

	/**
	 * Generate the manifest JSON string.
	 *
	 * @since  1.1.0
	 * @return string Pretty-printed JSON.
	 */
	public function generate(): string {
		$this->manifest['export_completed'] = current_time( 'mysql' );

		$total   = $this->manifest['total_documents'];
		$changed = $this->manifest['change_summary']['new']
				+ $this->manifest['change_summary']['modified'];

		$this->manifest['change_percentage'] = $total > 0
			? round( ( $changed / $total ) * 100, 2 )
			: 0;

		return (string) wp_json_encode( $this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Save the manifest to the export directory.
	 *
	 * @since  1.1.0
	 * @return bool True on success.
	 */
	public function save(): bool {
		$json = $this->generate();
		$path = rtrim( $this->export_dir, '/\\' ) . '/manifest.json';

		return $this->file_writer->write( $path, $json );
	}

	/**
	 * Return the current manifest data.
	 *
	 * @since  1.1.0
	 * @return array<string, mixed>
	 */
	public function get_manifest(): array {
		return $this->manifest;
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Build an empty manifest structure.
	 *
	 * @since  1.1.0
	 * @return array<string, mixed>
	 */
	private function initialize_manifest(): array {
		global $wp_version;

		return array(
			'export_timestamp'  => current_time( 'mysql' ),
			'wordpress_version' => $wp_version ?? '',
			'plugin_version'    => defined( 'WP_MFA_VERSION' ) ? WP_MFA_VERSION : '1.0.0',
			'total_documents'   => 0,
			'documents'         => array(),
			'change_summary'    => array(
				'new'       => 0,
				'modified'  => 0,
				'deleted'   => 0,
				'unchanged' => 0,
			),
		);
	}

	/**
	 * Determine change status by comparing the full hash against the previous manifest.
	 *
	 * @since  1.1.0
	 * @param  int    $post_id   The post ID.
	 * @param  string $full_hash The current full hash.
	 * @return string 'new', 'modified', or 'unchanged'.
	 */
	private function determine_change_status( int $post_id, string $full_hash ): string {
		if ( empty( $this->previous_manifest ) ) {
			return 'new';
		}

		foreach ( $this->previous_manifest['documents'] ?? array() as $doc ) {
			if ( $doc['id'] === $post_id ) {
				return ( $doc['full_hash'] ?? '' ) !== $full_hash ? 'modified' : 'unchanged';
			}
		}

		return 'new';
	}

	/**
	 * Load the previous manifest.json from the export directory.
	 *
	 * @since  1.1.0
	 * @return array<string, mixed>|null
	 */
	private function load_previous_manifest(): ?array {
		$path = rtrim( $this->export_dir, '/\\' ) . '/manifest.json';

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		return is_array( $data ) ? $data : null;
	}
}

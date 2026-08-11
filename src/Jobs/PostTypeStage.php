<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Walks every published post of one post type, in ascending ID order.
 *
 * Pagination is an explicit ID cursor rather than WP_Query's `offset`, whose
 * scan cost grows linearly with how far into the run you are. The ID query is
 * deliberately plain SQL: injecting `AND ID > n` into WP_Query through a
 * scoped posts_where filter is both harder to reason about and untestable
 * against the mock WP_Query, which never applies that filter.
 *
 * Trade-off, accepted: this collection query does not pass through
 * pre_get_posts. Everything after ID collection is unchanged — get_post()
 * then Generator::generate_post(), with all their hooks intact.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class PostTypeStage implements Stage {

	/**
	 * @since  1.7.0
	 * @param  \wpdb                $wpdb      WordPress database handle.
	 * @param  Generator            $generator Writes each post's Markdown.
	 * @param  array<string, mixed> $options   Plugin options, for eligibility checks.
	 * @param  string               $post_type Post type slug this stage walks.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Generator $generator,
		private readonly array $options,
		private readonly string $post_type,
	) {}

	/**
	 * Published count for this post type — one cached call, no table scan.
	 *
	 * @since  1.7.0
	 */
	public function count_total(): int {
		$counts = wp_count_posts( $this->post_type );

		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * @since  1.7.0
	 * @param  int $cursor Highest post ID already processed in this stage.
	 * @param  int $limit  Maximum posts to process.
	 * @return array{processed: int, skipped: int, errors: list<array{post_id?: int, message: string}>, next_cursor: int, done: bool}
	 */
	public function process_batch( int $cursor, int $limit ): array {
		$processed = 0;
		$skipped   = 0;
		$errors    = array();

		if ( $limit <= 0 ) {
			return array(
				'processed'   => 0,
				'skipped'     => 0,
				'errors'      => array(),
				'next_cursor' => $cursor,
				'done'        => true,
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deliberate ID-cursor pagination; see the class docblock. Values are passed through $wpdb->prepare(); only $this->wpdb->posts is interpolated.
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT ID FROM {$this->wpdb->posts}
				 WHERE post_type = %s AND post_status = 'publish' AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				$this->post_type,
				$cursor,
				$limit
			)
		);
		// phpcs:enable

		$ids         = array_map( 'intval', (array) $ids );
		$next_cursor = $cursor;

		foreach ( $ids as $post_id ) {
			$next_cursor = $post_id;
			$post        = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				$errors[] = array(
					'post_id' => $post_id,
					'message' => 'Post object not found; may have been deleted concurrently.',
				);
				continue;
			}

			try {
				if ( $this->generator->generate_post( $post ) ) {
					++$processed;
				} elseif ( ExportPolicy::is_eligible( $post, $this->options ) ) {
					// Eligible but nothing written means the filesystem write failed.
					$errors[] = array(
						'post_id' => $post_id,
						'message' => 'Failed to write Markdown file to disk; check export directory permissions.',
					);
				} else {
					// Ineligible: an intentional skip, counted so the UI can say so.
					++$skipped;
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'post_id' => $post_id,
					'message' => $e->getMessage(),
				);
			}

			clean_post_cache( $post );
		}

		return array(
			'processed'   => $processed,
			'skipped'     => $skipped,
			'errors'      => $errors,
			'next_cursor' => $next_cursor,
			'done'        => count( $ids ) < $limit,
		);
	}
}

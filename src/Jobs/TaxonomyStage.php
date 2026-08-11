<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;

/**
 * Walks every term of every public taxonomy, one archive file per
 * (term, taxonomy) pair.
 *
 * The cursor is `term_taxonomy_id`, not `term_id`, because:
 *
 * - the previous implementation called get_terms() once per taxonomy and
 *   concatenated the results, so term IDs were grouped per taxonomy rather
 *   than globally ascending — a term_id cursor would silently skip every
 *   later-taxonomy term with a lower ID;
 * - get_terms() orders by name by default, not by ID;
 * - a legacy shared term belongs to more than one taxonomy, so term_id is
 *   not unique per archive file, while term_taxonomy_id is.
 *
 * Note the deliberate asymmetry with PostTypeStage, which calls
 * clean_post_cache() after every post: there is no equivalent here.
 * generate_term() reaches get_term_posts(), which loads a term's posts in
 * batches of 100, so those post objects stay in the object cache for the rest
 * of the tick. Each batch is still bounded by $limit and every tick is a fresh
 * PHP process, so this is left alone on purpose — if tick-level memory growth
 * ever shows up on a large site, the purge belongs inside get_term_posts(),
 * not here, since this stage never sees those post objects.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class TaxonomyStage implements Stage {

	/**
	 * @since  1.7.0
	 * @param  \wpdb                    $wpdb               WordPress database handle.
	 * @param  TaxonomyArchiveGenerator $taxonomy_generator Writes each term archive.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly TaxonomyArchiveGenerator $taxonomy_generator,
	) {}

	/**
	 * @since  1.7.0
	 */
	public function count_total(): int {
		$taxonomies = $this->public_taxonomies();

		if ( empty( $taxonomies ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Counted once per stage; see the class docblock. Values are passed through $wpdb->prepare(); only $this->wpdb->term_taxonomy and the generated %s placeholder list are interpolated; the sniff cannot see that $placeholders' %s count matches count( $taxonomies ).
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->wpdb->term_taxonomy} tt WHERE tt.taxonomy IN ($placeholders)",
				...$taxonomies
			)
		);
		// phpcs:enable
	}

	/**
	 * @since  1.7.0
	 * @param  int $cursor Highest term_taxonomy_id already processed.
	 * @param  int $limit  Maximum term archives to write.
	 * @return array{processed: int, skipped: int, errors: list<array{term_id?: int, message: string}>, next_cursor: int, done: bool}
	 */
	public function process_batch( int $cursor, int $limit ): array {
		$taxonomies = $this->public_taxonomies();

		if ( empty( $taxonomies ) || $limit <= 0 ) {
			return array(
				'processed'   => 0,
				'skipped'     => 0,
				'errors'      => array(),
				'next_cursor' => $cursor,
				'done'        => true,
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Deliberate cursor pagination; see the class docblock. Values are passed through $wpdb->prepare(); only $this->wpdb->term_taxonomy and the generated %s placeholder list are interpolated; the sniff cannot see that array_merge( $taxonomies, [$cursor, $limit] ) supplies exactly count( $taxonomies ) + 2 args.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy
				 FROM {$this->wpdb->term_taxonomy} tt
				 WHERE tt.taxonomy IN ($placeholders) AND tt.term_taxonomy_id > %d
				 ORDER BY tt.term_taxonomy_id ASC
				 LIMIT %d",
				...array_merge( $taxonomies, array( $cursor, $limit ) )
			)
		);
		// phpcs:enable

		$rows        = (array) $rows;
		$processed   = 0;
		$errors      = array();
		$next_cursor = $cursor;

		foreach ( $rows as $row ) {
			$next_cursor = (int) $row->term_taxonomy_id;
			$term_id     = (int) $row->term_id;
			$term        = get_term( $term_id, (string) $row->taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				$errors[] = array(
					'term_id' => $term_id,
					'message' => 'Term not found; may have been deleted concurrently.',
				);
				continue;
			}

			try {
				if ( $this->taxonomy_generator->generate_term( $term ) ) {
					++$processed;
				} else {
					// generate_term() has no skip path, so false means the write failed.
					$errors[] = array(
						'term_id' => $term_id,
						'message' => 'Failed to write Markdown archive to disk; check export directory permissions.',
					);
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'term_id' => $term_id,
					'message' => $e->getMessage(),
				);
			}
		}

		return array(
			'processed'   => $processed,
			'skipped'     => 0,
			'errors'      => $errors,
			'next_cursor' => $next_cursor,
			'done'        => count( $rows ) < $limit,
		);
	}

	/**
	 * The same taxonomy set TaxonomyArchiveGenerator::generate_all() uses.
	 *
	 * @since  1.7.0
	 * @return string[]
	 */
	private function public_taxonomies(): array {
		return array_values( (array) get_taxonomies( array( 'public' => true ) ) );
	}
}

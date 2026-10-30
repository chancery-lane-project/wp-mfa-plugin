<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Generator\IndexGenerator;

/**
 * Single-shot stage: rebuild every OKF `index.md` listing once.
 *
 * IndexGenerator::flush_dirty() skips its usual end-of-request rebuild while
 * a job is running, because each listing is built from every published post
 * of its type and would otherwise be rebuilt at the end of every tick. This
 * stage does that work once, after the last post type and taxonomy batch and
 * before the bundle stage zips the tree.
 *
 * @since  1.7.4
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class IndexStage implements Stage {

	/**
	 * @since  1.7.4
	 * @param  IndexGenerator $index_generator Writes the listings.
	 */
	public function __construct(
		private readonly IndexGenerator $index_generator,
	) {}

	/** @since 1.7.4 */
	public function count_total(): int {
		return 1;
	}

	/**
	 * @since  1.7.4
	 * @param  int $cursor Unused — this stage has exactly one item.
	 * @param  int $limit  Unused.
	 * @return array{processed: int, skipped: int, errors: list<array{message: string}>, next_cursor: int, done: bool}
	 */
	public function process_batch( int $cursor, int $limit ): array {
		$this->index_generator->generate_all();

		return array(
			'processed'   => 1,
			'skipped'     => 0,
			'errors'      => array(),
			'next_cursor' => 1,
			'done'        => true,
		);
	}
}

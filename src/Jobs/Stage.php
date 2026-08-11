<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * One unit of bulk generation work that can be walked in cursor-paginated
 * batches across many requests.
 *
 * Two invariants every implementation must hold:
 *
 * 1. `done` is derived ONLY from rows returned this batch being fewer than
 *    $limit. Never from processed/skipped/error counts — that was the live
 *    bug this queue replaces (see the design spec, Problem #5).
 * 2. No batch query asks for a row count. Totals come from count_total(),
 *    called once when the stage becomes current, so batch cost stays flat
 *    however far into the run the cursor is.
 *
 * A stage's *progress* is tracked separately from the stage itself, as a plain
 * array called a stage descriptor, because it has to round-trip through a
 * WordPress option between ticks. That shape is:
 *
 *     array{
 *         type: 'post_type'|'taxonomy'|'bundle',
 *         slug?: string,          // post_type stages only
 *         total: int|null,        // null until count_total() has run once
 *         processed: int,
 *         skipped: int,
 *         error_count: int,
 *         state: 'pending'|'running'|'done'|'unavailable',
 *     }
 *
 * Descriptors are authored in exactly one place — StageFactory::descriptor() —
 * and only ever read or counter-mutated elsewhere (GenerationJob persists them,
 * JobRunner advances them). Keep it that way: if a second place starts building
 * them, the shape needs a named home instead of this docblock.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
interface Stage {

	/**
	 * Total items this stage will walk.
	 *
	 * Called once, on stage entry; the result is stored in the job record and
	 * never recomputed — a cursor-filtered count shrinks as the cursor moves.
	 *
	 * @since  1.7.0
	 */
	public function count_total(): int;

	/**
	 * Process one batch of items after $cursor.
	 *
	 * @since  1.7.0
	 * @param  int $cursor Resume key; 0 for the first batch of the stage.
	 * @param  int $limit  Maximum items to process in this batch.
	 * @return array{
	 *     processed: int,
	 *     skipped: int,
	 *     errors: list<array{post_id?: int, term_id?: int, message: string}>,
	 *     next_cursor: int,
	 *     done: bool
	 * }
	 */
	public function process_batch( int $cursor, int $limit ): array;
}

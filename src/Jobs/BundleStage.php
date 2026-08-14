<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Single-shot final stage: rebuild the manifests and the export zip.
 *
 * Previously this ran synchronously on the last AJAX batch's request
 * (Admin::maybe_rebuild_bundle()), which is where bulk generation most often
 * exhausted memory. As a stage it gets a cron tick to itself.
 *
 * only_if_stale is always true, preserving the existing behaviour that
 * clicking Generate twice with no content change in between does not re-zip
 * the whole tree.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class BundleStage implements Stage {

	/**
	 * @since  1.7.0
	 * @param  Generator            $generator        Writes manifests, then delegates the zip.
	 * @param  BundleGenerator|null $bundle_generator Null when bundling is not wired up.
	 */
	public function __construct(
		private readonly Generator $generator,
		private readonly ?BundleGenerator $bundle_generator,
	) {}

	/** @since 1.7.0 */
	public function count_total(): int {
		return 1;
	}

	/**
	 * @since  1.7.0
	 * @param  int $cursor Unused — this stage has exactly one item.
	 * @param  int $limit  Unused.
	 * @return array{processed: int, skipped: int, errors: list<array{message: string}>, next_cursor: int, done: bool}
	 */
	public function process_batch( int $cursor, int $limit ): array {
		$errors = array();

		if ( null !== $this->bundle_generator ) {
			$result = $this->generator->rebuild_bundle( $this->bundle_generator, true );

			if ( ! $result['manifests_ok'] ) {
				$errors[] = array( 'message' => 'Manifest write failed during bundle rebuild; bundle may be stale.' );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, guarded by WP_DEBUG.
					error_log( 'WP Markdown for Agents: manifest write failed during bundle rebuild; bundle may be stale.' );
				}
			}

			if ( Generator::BUNDLE_FAILED === $result['status'] ) {
				$errors[] = array( 'message' => 'Bundle rebuild failed after bulk generation.' );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, guarded by WP_DEBUG.
					error_log( 'WP Markdown for Agents: bundle rebuild failed after bulk generation.' );
				}
			}
		}

		return array(
			'processed'   => 1,
			'skipped'     => 0,
			'errors'      => $errors,
			'next_cursor' => 1,
			'done'        => true,
		);
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;

/**
 * Builds the stage list a job walks, and rehydrates one stage at a time.
 *
 * Descriptors are plain arrays because they are stored in the job option and
 * read back one tick at a time; nothing serialisable-unfriendly may go in them.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class StageFactory {

	/**
	 * @since  1.7.0
	 * @param  \wpdb                    $wpdb               Database handle for cursor queries.
	 * @param  array<string, mixed>     $options            Plugin options.
	 * @param  Generator                $generator          Post Markdown writer.
	 * @param  TaxonomyArchiveGenerator $taxonomy_generator Term archive writer.
	 * @param  BundleGenerator|null     $bundle_generator   Optional bundle builder.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly array $options,
		private readonly Generator $generator,
		private readonly TaxonomyArchiveGenerator $taxonomy_generator,
		private readonly ?BundleGenerator $bundle_generator = null,
	) {}

	/**
	 * Translate a scope from the admin UI into an ordered stage list.
	 *
	 * Accepted scopes: `all`, `post_type:{slug}`, `taxonomy`. Anything else —
	 * including a post type that is not enabled for export — builds nothing,
	 * so the caller can reject the request.
	 *
	 * @since  1.7.0
	 * @param  string $scope Scope string.
	 * @return list<array{type: string, slug?: string, total: int|null, processed: int, skipped: int, error_count: int, state: string}>
	 */
	public function build_stage_list( string $scope ): array {
		$enabled = ExportPolicy::enabled_post_types( $this->options );
		$stages  = array();

		if ( 'all' === $scope ) {
			foreach ( $enabled as $post_type ) {
				$stages[] = $this->descriptor( 'post_type', (string) $post_type );
			}
			$stages[] = $this->descriptor( 'taxonomy' );
		} elseif ( 'taxonomy' === $scope ) {
			$stages[] = $this->descriptor( 'taxonomy' );
		} elseif ( str_starts_with( $scope, 'post_type:' ) ) {
			$slug = substr( $scope, strlen( 'post_type:' ) );

			if ( '' === $slug || ! in_array( $slug, $enabled, true ) ) {
				return array();
			}

			$stages[] = $this->descriptor( 'post_type', $slug );
		}

		if ( empty( $stages ) ) {
			return array();
		}

		// Every scope ends in exactly one bundle rebuild, never more.
		$stages[] = $this->descriptor( 'bundle' );

		return $stages;
	}

	/**
	 * Rehydrate one stored descriptor into a runnable Stage.
	 *
	 * @since  1.7.0
	 * @param  array<string, mixed> $descriptor Stored stage descriptor.
	 * @return Stage|null Null when the descriptor cannot be run (unknown type,
	 *                    or a post type no longer enabled for export).
	 */
	public function make( array $descriptor ): ?Stage {
		$type = (string) ( $descriptor['type'] ?? '' );

		if ( 'taxonomy' === $type ) {
			return new TaxonomyStage( $this->wpdb, $this->taxonomy_generator );
		}

		if ( 'bundle' === $type ) {
			return new BundleStage( $this->generator, $this->bundle_generator );
		}

		if ( 'post_type' === $type ) {
			$slug = (string) ( $descriptor['slug'] ?? '' );

			if ( '' === $slug || ! in_array( $slug, ExportPolicy::enabled_post_types( $this->options ), true ) ) {
				return null;
			}

			return new PostTypeStage( $this->wpdb, $this->generator, $this->options, $slug );
		}

		return null;
	}

	/**
	 * @since  1.7.0
	 * @return array{type: string, slug?: string, total: int|null, processed: int, skipped: int, error_count: int, state: string}
	 */
	private function descriptor( string $type, string $slug = '' ): array {
		$descriptor = array(
			'type'        => $type,
			// null, not 0: the total is unknown until the stage becomes current
			// and JobRunner calls count_total() once. Zero would be a real count.
			'total'       => null,
			'processed'   => 0,
			'skipped'     => 0,
			'error_count' => 0,
			'state'       => 'pending',
		);

		if ( '' !== $slug ) {
			$descriptor['slug'] = $slug;
		}

		return $descriptor;
	}
}

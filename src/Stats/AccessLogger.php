<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Logs Markdown access events to the stats repository.
 *
 * Called by the Negotiator when serving a Markdown file. Determines
 * the agent identifier and delegates to StatsRepository for storage.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class AccessLogger {

	/**
	 * @since  1.1.0
	 * @param  StatsRepository $repository Stats storage layer.
	 */
	public function __construct( private readonly StatsRepository $repository ) {}

	/**
	 * Record a Markdown access event.
	 *
	 * @since  1.1.0
	 * @param  int    $post_id       The accessed post ID.
	 * @param  string $agent         Agent identity substring, or '' for unknown.
	 * @param  string $access_method How the request arrived: 'ua', 'accept-header', or 'query-param'.
	 */
	public function log_access( int $post_id, string $agent, string $access_method ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$this->repository->record_access( $post_id, mb_substr( $agent, 0, 100 ), $access_method );
	}
}

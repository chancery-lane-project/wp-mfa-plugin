<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Processes one time-boxed run of batches per cron tick. See Task 10.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
class JobRunner {

	/** @since 1.7.0 */
	public const TICK_HOOK = 'markdown_for_agents_process_batch';

	/** @since 1.7.0 */
	public const WATCHDOG_HOOK = 'markdown_for_agents_job_watchdog';
}

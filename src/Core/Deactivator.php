<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;

/**
 * Handles plugin deactivation tasks.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * @since  1.0.0
	 */
	public static function deactivate(): void {
		// A half-finished job must not survive deactivation: its cron events
		// would be orphaned and its tick lock would block the next run.
		delete_option( GenerationJob::OPTION );
		delete_option( TickMutex::OPTION );
		wp_unschedule_hook( JobRunner::TICK_HOOK );
		wp_unschedule_hook( JobRunner::WATCHDOG_HOOK );

		flush_rewrite_rules();
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Production Clock: plain PHP time functions.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
final class SystemClock implements Clock {

	/** @since 1.7.0 */
	public function now(): int {
		return time();
	}

	/** @since 1.7.0 */
	public function monotonic(): float {
		return microtime( true );
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Jobs;

/**
 * Time seam for the job queue.
 *
 * Tick time-boxing and the tick-mutex staleness window are both time-driven,
 * and both need to be testable without sleeping, so every read of the clock
 * inside src/Jobs/ goes through this interface.
 *
 * @since  1.7.0
 * @package Tclp\WpMarkdownForAgents\Jobs
 */
interface Clock {

	/**
	 * Current Unix timestamp in whole seconds — for stored timestamps.
	 *
	 * @since  1.7.0
	 */
	public function now(): int;

	/**
	 * Fractional seconds for measuring elapsed time within one request.
	 *
	 * @since  1.7.0
	 */
	public function monotonic(): float;
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

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
		flush_rewrite_rules();
	}
}

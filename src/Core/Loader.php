<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues actions, then registers them all with WordPress on run().
 *
 * Following the dgwltd-boilerplate Loader pattern. Filters are registered
 * with native add_filter() at their call sites; this class only queues
 * actions because that is all the plugin wires through it.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class Loader {

	/** @var array<int, array<string, mixed>> */
	private array $actions = array();

	/**
	 * @since  1.0.0
	 */
	public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * @since  1.0.0
	 */
	public function run(): void {
		foreach ( $this->actions as $hook ) {
			add_action( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}
	}

	/**
	 * @param  array<int, array<string, mixed>> $hooks
	 * @return array<int, array<string, mixed>>
	 */
	private function add( array $hooks, string $hook, object $component, string $callback, int $priority, int $accepted_args ): array {
		$hooks[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
		return $hooks;
	}
}

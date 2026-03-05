<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

/**
 * Queues actions and filters, then registers them all with WordPress on run().
 *
 * Following the dgwltd-boilerplate Loader pattern.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class Loader {

    /** @var array<int, array<string, mixed>> */
    private array $actions = [];

    /** @var array<int, array<string, mixed>> */
    private array $filters = [];

    /**
     * @since  1.0.0
     */
    public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * @since  1.0.0
     */
    public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * @since  1.0.0
     */
    public function run(): void {
        foreach ( $this->filters as $hook ) {
            add_filter( $hook['hook'], [ $hook['component'], $hook['callback'] ], $hook['priority'], $hook['accepted_args'] );
        }
        foreach ( $this->actions as $hook ) {
            add_action( $hook['hook'], [ $hook['component'], $hook['callback'] ], $hook['priority'], $hook['accepted_args'] );
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

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Support;

use Tclp\WpMarkdownForAgents\Jobs\Clock;

/**
 * Test double: time only moves when a test moves it.
 */
final class FrozenClock implements Clock {

    public function __construct(private int $now = 1_700_000_000) {}

    public function now(): int {
        return $this->now;
    }

    public function monotonic(): float {
        return (float) $this->now;
    }

    public function advance(int $seconds): void {
        $this->now += $seconds;
    }
}

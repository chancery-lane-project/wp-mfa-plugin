<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Jobs\Clock;
use Tclp\WpMarkdownForAgents\Jobs\SystemClock;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\SystemClock
 */
class ClockTest extends TestCase {

    public function test_system_clock_reports_wall_and_monotonic_time(): void {
        $clock = new SystemClock();

        $this->assertInstanceOf( Clock::class, $clock );
        $this->assertGreaterThan( 1_700_000_000, $clock->now() );
        $this->assertGreaterThan( 0.0, $clock->monotonic() );
    }

    public function test_frozen_clock_advances_only_when_told(): void {
        $clock = new FrozenClock( 1000 );

        $this->assertSame( 1000, $clock->now() );
        $this->assertSame( 1000, $clock->now() );

        $clock->advance( 45 );

        $this->assertSame( 1045, $clock->now() );
        $this->assertSame( 1045.0, $clock->monotonic() );
    }
}

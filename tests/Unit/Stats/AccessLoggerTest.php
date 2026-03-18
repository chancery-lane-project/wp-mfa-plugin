<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\AccessLogger
 */
class AccessLoggerTest extends TestCase {

    /** @var StatsRepository&MockObject */
    private StatsRepository $repository;

    private AccessLogger $logger;

    protected function setUp(): void {
        $this->repository = $this->createMock( StatsRepository::class );
        $this->logger     = new AccessLogger( $this->repository );
    }

    public function test_log_access_calls_record_access(): void {
        $this->repository->expects( $this->once() )
            ->method( 'record_access' )
            ->with( 42, 'GPTBot' );

        $this->logger->log_access( 42, 'GPTBot' );
    }

    public function test_log_access_passes_accept_header_agent(): void {
        $this->repository->expects( $this->once() )
            ->method( 'record_access' )
            ->with( 10, 'accept-header' );

        $this->logger->log_access( 10, 'accept-header' );
    }

    public function test_log_access_does_nothing_for_zero_post_id(): void {
        $this->repository->expects( $this->never() )
            ->method( 'record_access' );

        $this->logger->log_access( 0, 'GPTBot' );
    }

    public function test_log_access_does_nothing_for_negative_post_id(): void {
        $this->repository->expects( $this->never() )
            ->method( 'record_access' );

        $this->logger->log_access( -1, 'GPTBot' );
    }
}

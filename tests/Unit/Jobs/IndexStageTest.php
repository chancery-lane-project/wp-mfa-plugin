<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\IndexGenerator;
use Tclp\WpMarkdownForAgents\Jobs\IndexStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\IndexStage
 */
class IndexStageTest extends TestCase {

    public function test_total_is_always_one(): void {
        $stage = new IndexStage( $this->createMock( IndexGenerator::class ) );

        $this->assertSame( 1, $stage->count_total() );
    }

    public function test_rebuilds_every_index_once_and_reports_done(): void {
        $index_generator = $this->createMock( IndexGenerator::class );
        $index_generator->expects( $this->once() )
            ->method( 'generate_all' )
            ->willReturn( [ 'written' => 3, 'skipped' => 0 ] );

        $result = ( new IndexStage( $index_generator ) )->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( [], $result['errors'] );
    }
}

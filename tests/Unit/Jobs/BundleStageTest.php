<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Jobs\BundleStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\BundleStage
 */
class BundleStageTest extends TestCase {

    /** @var Generator&MockObject */
    private Generator $generator;

    /** @var BundleGenerator&MockObject */
    private BundleGenerator $bundle_generator;

    protected function setUp(): void {
        $this->generator        = $this->createMock( Generator::class );
        $this->bundle_generator = $this->createMock( BundleGenerator::class );
    }

    public function test_total_is_always_one(): void {
        $stage = new BundleStage( $this->generator, $this->bundle_generator );

        $this->assertSame( 1, $stage->count_total() );
    }

    public function test_rebuild_is_only_if_stale_and_reports_done(): void {
        $this->bundle_generator->method( 'is_stale' )->willReturn( true );
        $this->generator->expects( $this->once() )
            ->method( 'rebuild_bundle' )
            ->with( $this->bundle_generator, true )
            ->willReturn( [ 'status' => Generator::BUNDLE_BUILT, 'manifests_ok' => true ] );

        $result = ( new BundleStage( $this->generator, $this->bundle_generator ) )->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( [], $result['errors'] );
    }

    public function test_failed_rebuild_is_reported_as_an_error(): void {
        $this->generator->method( 'rebuild_bundle' )
            ->willReturn( [ 'status' => Generator::BUNDLE_FAILED, 'manifests_ok' => true ] );

        $result = ( new BundleStage( $this->generator, $this->bundle_generator ) )->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertStringContainsString( 'Bundle rebuild failed', $result['errors'][0]['message'] );
    }

    public function test_manifest_failure_is_reported_as_an_error(): void {
        $this->generator->method( 'rebuild_bundle' )
            ->willReturn( [ 'status' => Generator::BUNDLE_BUILT, 'manifests_ok' => false ] );

        $result = ( new BundleStage( $this->generator, $this->bundle_generator ) )->process_batch( 0, 50 );

        $this->assertCount( 1, $result['errors'] );
        $this->assertStringContainsString( 'Manifest write failed', $result['errors'][0]['message'] );
    }

    public function test_fresh_or_disabled_bundle_is_not_an_error(): void {
        $this->generator->method( 'rebuild_bundle' )
            ->willReturn( [ 'status' => Generator::BUNDLE_FRESH, 'manifests_ok' => true ] );

        $result = ( new BundleStage( $this->generator, $this->bundle_generator ) )->process_batch( 0, 50 );

        $this->assertSame( [], $result['errors'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_missing_bundle_generator_is_a_no_op_that_still_completes(): void {
        $this->generator->expects( $this->never() )->method( 'rebuild_bundle' );

        $result = ( new BundleStage( $this->generator, null ) )->process_batch( 0, 50 );

        $this->assertTrue( $result['done'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( [], $result['errors'] );
    }
}

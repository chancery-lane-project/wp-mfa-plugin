<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\CLI\Commands;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Covers Commands::bundle_status_line() only — it is pure (no \WP_CLI calls),
 * so it can be driven directly via reflection.
 *
 * The rest of Commands (generate, bundle, delete, status, rebuild_bundle,
 * etc.) calls \WP_CLI::* statically and this repo has no WP_CLI stub
 * (established posture — see tests/mocks/), so those methods remain
 * untested at the unit level.
 *
 * @covers \Tclp\WpMarkdownForAgents\CLI\Commands::bundle_status_line
 */
class CommandsTest extends TestCase {

    private string $bundle_path;

    protected function setUp(): void {
        reset_mock_scheduled_events();
        $this->bundle_path = sys_get_temp_dir() . '/wp-mfa-commands-test-' . uniqid() . '.tar.gz';
    }

    protected function tearDown(): void {
        if ( file_exists( $this->bundle_path ) ) {
            unlink( $this->bundle_path );
        }
        reset_mock_scheduled_events();
    }

    private function make_commands( BundleGenerator $bundle_generator ): Commands {
        $generator = $this->createMock( Generator::class );

        return new Commands(
            Options::get_defaults(),
            $generator,
            null,
            null,
            null,
            null,
            $bundle_generator
        );
    }

    private function invoke_bundle_status_line( Commands $commands ): string {
        $method = new \ReflectionMethod( Commands::class, 'bundle_status_line' );
        return $method->invoke( $commands );
    }

    public function test_missing_when_bundle_file_absent(): void {
        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_path' )->willReturn( $this->bundle_path );

        $line = $this->invoke_bundle_status_line( $this->make_commands( $bundle_generator ) );

        $this->assertSame( 'Bundle: missing', $line );
    }

    public function test_fresh_when_file_exists_and_not_stale(): void {
        file_put_contents( $this->bundle_path, 'fake bundle' );

        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_path' )->willReturn( $this->bundle_path );
        $bundle_generator->method( 'is_stale' )->willReturn( false );

        $line = $this->invoke_bundle_status_line( $this->make_commands( $bundle_generator ) );

        $this->assertSame( "Bundle: fresh ({$this->bundle_path})", $line );
    }

    public function test_stale_with_rebuild_scheduled(): void {
        file_put_contents( $this->bundle_path, 'fake bundle' );
        wp_schedule_single_event( time() + 300, 'markdown_for_agents_rebuild_bundle' );

        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_path' )->willReturn( $this->bundle_path );
        $bundle_generator->method( 'is_stale' )->willReturn( true );

        $line = $this->invoke_bundle_status_line( $this->make_commands( $bundle_generator ) );

        $this->assertSame( "Bundle: stale — rebuild scheduled ({$this->bundle_path})", $line );
    }

    public function test_stale_with_no_rebuild_scheduled(): void {
        file_put_contents( $this->bundle_path, 'fake bundle' );

        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_path' )->willReturn( $this->bundle_path );
        $bundle_generator->method( 'is_stale' )->willReturn( true );

        $line = $this->invoke_bundle_status_line( $this->make_commands( $bundle_generator ) );

        $this->assertSame( "Bundle: stale — no rebuild scheduled ({$this->bundle_path})", $line );
    }
}

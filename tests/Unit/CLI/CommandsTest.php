<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\CLI\Commands;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Covers Commands::bundle_status_line() (pure, no \WP_CLI calls, driven
 * directly via reflection) plus targeted coverage of bundle() and
 * rebuild_bundle() using the WP_CLI stub in tests/mocks/wordpress-mocks.php.
 *
 * The remainder of Commands (generate, delete, status, etc.) is not covered
 * here; broader WP_CLI-call coverage can be added incrementally using the
 * same stub.
 *
 * @covers \Tclp\WpMarkdownForAgents\CLI\Commands::bundle_status_line
 * @covers \Tclp\WpMarkdownForAgents\CLI\Commands::bundle
 * @covers \Tclp\WpMarkdownForAgents\CLI\Commands::rebuild_bundle
 */
class CommandsTest extends TestCase {

    private string $bundle_path;

    protected function setUp(): void {
        reset_mock_scheduled_events();
        reset_mock_wp_cli_warnings();
        $this->bundle_path = sys_get_temp_dir() . '/wp-mfa-commands-test-' . uniqid() . '.zip';
    }

    protected function tearDown(): void {
        if ( file_exists( $this->bundle_path ) ) {
            unlink( $this->bundle_path );
        }
        reset_mock_scheduled_events();
        reset_mock_wp_cli_warnings();
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

    private function make_commands_with_generator( Generator $generator, BundleGenerator $bundle_generator ): Commands {
        $options                   = Options::get_defaults();
        $options['bundle_enabled'] = true;

        return new Commands(
            $options,
            $generator,
            null,
            null,
            null,
            null,
            $bundle_generator
        );
    }

    public function test_bundle_command_delegates_to_coordinator(): void {
        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_path' )->willReturn( $this->bundle_path );

        $generator = $this->createMock( Generator::class );
        $generator->expects( $this->once() )
            ->method( 'rebuild_bundle' )
            ->with( $bundle_generator, false )
            ->willReturn(
                array(
                    'status'       => Generator::BUNDLE_BUILT,
                    'manifests_ok' => true,
                )
            );

        $commands = $this->make_commands_with_generator( $generator, $bundle_generator );
        $commands->bundle( array(), array() );

        $this->assertSame( array(), get_mock_wp_cli_warnings() );
    }

    public function test_bundle_command_passes_if_stale_flag_through(): void {
        $bundle_generator = $this->createMock( BundleGenerator::class );

        $generator = $this->createMock( Generator::class );
        $generator->expects( $this->once() )
            ->method( 'rebuild_bundle' )
            ->with( $bundle_generator, true )
            ->willReturn(
                array(
                    'status'       => Generator::BUNDLE_FRESH,
                    'manifests_ok' => true,
                )
            );

        $commands = $this->make_commands_with_generator( $generator, $bundle_generator );
        $commands->bundle( array(), array( 'if-stale' => true ) );

        $this->assertSame( array(), get_mock_wp_cli_warnings() );
    }

    public function test_bundle_command_warns_when_manifest_write_fails(): void {
        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_path' )->willReturn( $this->bundle_path );

        $generator = $this->createMock( Generator::class );
        $generator->method( 'rebuild_bundle' )
            ->willReturn(
                array(
                    'status'       => Generator::BUNDLE_BUILT,
                    'manifests_ok' => false,
                )
            );

        $commands = $this->make_commands_with_generator( $generator, $bundle_generator );
        $commands->bundle( array(), array() );

        $warnings = get_mock_wp_cli_warnings();
        $this->assertNotEmpty( $warnings );
        $this->assertStringContainsString( 'manifest', strtolower( $warnings[0] ) );
    }

    public function test_rebuild_bundle_delegates_to_coordinator(): void {
        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_path' )->willReturn( $this->bundle_path );

        $generator = $this->createMock( Generator::class );
        $generator->expects( $this->once() )
            ->method( 'rebuild_bundle' )
            ->with( $bundle_generator, false )
            ->willReturn(
                array(
                    'status'       => Generator::BUNDLE_BUILT,
                    'manifests_ok' => true,
                )
            );

        $commands = $this->make_commands_with_generator( $generator, $bundle_generator );

        $method = new \ReflectionMethod( Commands::class, 'rebuild_bundle' );
        $method->invoke( $commands );

        $this->assertSame( array(), get_mock_wp_cli_warnings() );
    }

    public function test_rebuild_bundle_warns_when_manifest_write_fails(): void {
        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_path' )->willReturn( $this->bundle_path );

        $generator = $this->createMock( Generator::class );
        $generator->method( 'rebuild_bundle' )
            ->willReturn(
                array(
                    'status'       => Generator::BUNDLE_BUILT,
                    'manifests_ok' => false,
                )
            );

        $commands = $this->make_commands_with_generator( $generator, $bundle_generator );

        $method = new \ReflectionMethod( Commands::class, 'rebuild_bundle' );
        $method->invoke( $commands );

        $warnings = get_mock_wp_cli_warnings();
        $this->assertNotEmpty( $warnings );
        $this->assertStringContainsString( 'manifest', strtolower( $warnings[0] ) );
    }
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\CLI\Commands;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\OrphanPruner;

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
 * @covers \Tclp\WpMarkdownForAgents\CLI\Commands::delete
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

    public function test_delete_post_type_removes_nested_child_page_files(): void {
        $subdir = 'wp-mfa-commands-delete-' . uniqid();
        $base   = sys_get_temp_dir() . '/' . $subdir;
        mkdir( $base . '/page/about/people', 0755, true );
        file_put_contents( $base . '/page/about.md', 'a' );
        file_put_contents( $base . '/page/about/team.md', 'b' );
        file_put_contents( $base . '/page/about/people/jo.md', 'c' );
        file_put_contents( $base . '/page/manifest.json', '{}' );

        $GLOBALS['_mock_upload_dir'] = [ 'basedir' => sys_get_temp_dir(), 'baseurl' => 'https://example.com/wp-content/uploads' ];

        try {
            $commands = new Commands(
                array_merge( Options::get_defaults(), [ 'export_dir' => $subdir ] ),
                $this->createMock( Generator::class )
            );
            $commands->delete( [], [ 'post-type' => 'page' ] );

            $this->assertFileDoesNotExist( $base . '/page/about.md' );
            $this->assertDirectoryDoesNotExist( $base . '/page/about' );
            $this->assertFileExists( $base . '/page/manifest.json' );
        } finally {
            unset( $GLOBALS['_mock_upload_dir'] );
            @unlink( $base . '/page/manifest.json' );
            @rmdir( $base . '/page' );
            @rmdir( $base );
        }
    }

    public function test_delete_post_type_rejects_traversing_type(): void {
        // Uploads is a sandbox directory, so '..' from the export base stays
        // inside it. The canary sits where '..' would reach.
        $uploads = sys_get_temp_dir() . '/wp-mfa-commands-sandbox-' . uniqid();
        $base    = $uploads . '/exports';
        mkdir( $base . '/page', 0755, true );
        file_put_contents( $base . '/page/about.md', 'a' );
        file_put_contents( $uploads . '/canary.md', 'c' );

        $GLOBALS['_mock_upload_dir'] = [ 'basedir' => $uploads, 'baseurl' => 'https://example.com/wp-content/uploads' ];

        try {
            $commands = new Commands(
                array_merge( Options::get_defaults(), [ 'export_dir' => 'exports' ] ),
                $this->createMock( Generator::class )
            );

            foreach ( [ '..', 'page/..', '.' ] as $type ) {
                $commands->delete( [], [ 'post-type' => $type ] );
            }

            $this->assertFileExists( $uploads . '/canary.md' );
            $this->assertFileExists( $base . '/page/about.md' );
        } finally {
            unset( $GLOBALS['_mock_upload_dir'] );
            @unlink( $uploads . '/canary.md' );
            @unlink( $base . '/page/about.md' );
            @rmdir( $base . '/page' );
            @rmdir( $base );
            @rmdir( $uploads );
        }
    }

    public function test_prune_orphans_prunes_each_type_only_when_nothing_failed(): void {
        $commands = $this->make_commands( $this->createMock( BundleGenerator::class ) );
        $method   = new \ReflectionMethod( Commands::class, 'prune_orphans' );

        $pruner = $this->createMock( OrphanPruner::class );
        $pruner->expects( $this->exactly( 2 ) )->method( 'prune' )
            ->withConsecutive( [ 'post', 100 ], [ 'page', 100 ] )
            ->willReturn( 1 );
        $method->invoke( $commands, $pruner, [ 'post', 'page' ], 100, 0 );

        $failing = $this->createMock( OrphanPruner::class );
        $failing->expects( $this->never() )->method( 'prune' );
        $method->invoke( $commands, $failing, [ 'post' ], 100, 1 );
        $this->assertNotEmpty( get_mock_wp_cli_warnings() );

        $unmarked = $this->createMock( OrphanPruner::class );
        $unmarked->expects( $this->never() )->method( 'prune' );
        $method->invoke( $commands, $unmarked, [ 'post' ], null, 0 );
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

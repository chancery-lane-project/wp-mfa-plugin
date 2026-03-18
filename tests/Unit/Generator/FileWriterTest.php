<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\FileWriter;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\FileWriter
 */
class FileWriterTest extends TestCase {

    private string $base_dir;
    private FileWriter $writer;

    protected function setUp(): void {
        $this->base_dir = sys_get_temp_dir() . '/wp-mfa-test-' . uniqid();
        mkdir( $this->base_dir, 0755, true );
        $this->writer = new FileWriter( $this->base_dir );
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->base_dir );
    }

    // -----------------------------------------------------------------------
    // write()
    // -----------------------------------------------------------------------

    public function test_write_creates_file_with_content(): void {
        $path = $this->base_dir . '/post/hello.md';

        $result = $this->writer->write( $path, '# Hello' );

        $this->assertTrue( $result );
        $this->assertFileExists( $path );
        $this->assertSame( '# Hello', file_get_contents( $path ) );
    }

    public function test_write_creates_parent_directory(): void {
        $path = $this->base_dir . '/custom-type/nested/post.md';

        $this->writer->write( $path, 'content' );

        $this->assertDirectoryExists( dirname( $path ) );
    }

    public function test_write_creates_htaccess_in_base_dir_on_first_write(): void {
        $path = $this->base_dir . '/post/test.md';
        $this->writer->write( $path, 'content' );

        $this->assertFileExists( $this->base_dir . '/.htaccess' );
        $this->assertStringContainsString( 'Deny from all', file_get_contents( $this->base_dir . '/.htaccess' ) );
    }

    public function test_write_does_not_overwrite_existing_htaccess(): void {
        file_put_contents( $this->base_dir . '/.htaccess', 'custom rules' );

        $this->writer->write( $this->base_dir . '/post/test.md', 'content' );

        $this->assertSame( 'custom rules', file_get_contents( $this->base_dir . '/.htaccess' ) );
    }

    public function test_write_rejects_path_outside_base_dir(): void {
        $path = sys_get_temp_dir() . '/outside.md';

        $result = $this->writer->write( $path, 'evil content' );

        $this->assertFalse( $result );
        $this->assertFileDoesNotExist( $path );
    }

    public function test_write_rejects_path_traversal(): void {
        $path = $this->base_dir . '/../../outside.md';

        $result = $this->writer->write( $path, 'evil' );

        $this->assertFalse( $result );
    }

    // -----------------------------------------------------------------------
    // delete()
    // -----------------------------------------------------------------------

    public function test_delete_removes_existing_file(): void {
        $path = $this->base_dir . '/post/delete-me.md';
        mkdir( dirname( $path ), 0755, true );
        file_put_contents( $path, 'content' );

        $result = $this->writer->delete( $path );

        $this->assertTrue( $result );
        $this->assertFileDoesNotExist( $path );
    }

    public function test_delete_returns_true_for_nonexistent_file(): void {
        $result = $this->writer->delete( $this->base_dir . '/post/nope.md' );
        $this->assertTrue( $result );
    }

    public function test_delete_rejects_path_outside_base_dir(): void {
        $outside = sys_get_temp_dir() . '/sneaky.md';
        file_put_contents( $outside, 'data' );

        $result = $this->writer->delete( $outside );

        $this->assertFalse( $result );
        $this->assertFileExists( $outside );
        unlink( $outside );
    }

    // -----------------------------------------------------------------------
    // exists()
    // -----------------------------------------------------------------------

    public function test_exists_returns_true_for_existing_file(): void {
        $path = $this->base_dir . '/post/exists.md';
        mkdir( dirname( $path ), 0755, true );
        file_put_contents( $path, '' );

        $this->assertTrue( $this->writer->exists( $path ) );
    }

    public function test_exists_returns_false_for_missing_file(): void {
        $this->assertFalse( $this->writer->exists( $this->base_dir . '/post/missing.md' ) );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function remove_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $target = $dir . '/' . $item;
            is_dir( $target ) ? $this->remove_dir( $target ) : unlink( $target );
        }
        rmdir( $dir );
    }
}

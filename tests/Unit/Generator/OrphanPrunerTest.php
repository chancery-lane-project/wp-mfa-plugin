<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\OrphanPruner;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\OrphanPruner
 */
class OrphanPrunerTest extends TestCase {

    private const SINCE = 1_800_000_000;

    private string $uploads;
    private string $base;

    protected function setUp(): void {
        // Uploads is a sandbox, so nothing outside it can be touched.
        $this->uploads = sys_get_temp_dir() . '/wp-mfa-pruner-' . uniqid();
        $this->base    = $this->uploads . '/exports';
        mkdir( $this->base, 0755, true );

        $GLOBALS['_mock_upload_dir'] = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.com/wp-content/uploads' ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_mock_upload_dir'] );

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $files as $file ) {
            $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
        }
        rmdir( $this->uploads );
    }

    private function pruner(): OrphanPruner {
        return new OrphanPruner( array_merge( Options::get_defaults(), [ 'export_dir' => 'exports' ] ) );
    }

    private function export( string $relative, int $mtime ): string {
        $path = $this->base . '/' . $relative;
        if ( ! is_dir( dirname( $path ) ) ) {
            mkdir( dirname( $path ), 0755, true );
        }
        file_put_contents( $path, 'x' );
        touch( $path, $mtime );

        return $path;
    }

    public function test_deletes_files_older_than_since_and_keeps_rewritten_ones(): void {
        $old_flat  = $this->export( 'page/team.md', self::SINCE - 3600 );
        $rewritten = $this->export( 'page/about/team.md', self::SINCE );
        $newer     = $this->export( 'page/about.md', self::SINCE + 5 );

        $this->assertSame( 1, $this->pruner()->prune( 'page', self::SINCE ) );

        $this->assertFileDoesNotExist( $old_flat );
        $this->assertFileExists( $rewritten );
        $this->assertFileExists( $newer );
    }

    public function test_removes_directories_left_empty(): void {
        $this->export( 'page/old-parent/child.md', self::SINCE - 10 );

        $this->pruner()->prune( 'page', self::SINCE );

        $this->assertDirectoryDoesNotExist( $this->base . '/page/old-parent' );
    }

    public function test_keeps_type_index_and_non_markdown_files(): void {
        $index    = $this->export( 'page/index.md', self::SINCE - 10 );
        $manifest = $this->export( 'page/manifest.json', self::SINCE - 10 );
        $nested   = $this->export( 'page/about/index.md', self::SINCE - 10 );

        $this->pruner()->prune( 'page', self::SINCE );

        $this->assertFileExists( $index );
        $this->assertFileExists( $manifest );
        // Only the type root's listing is special; a page slugged `index` is not.
        $this->assertFileDoesNotExist( $nested );
    }

    public function test_only_touches_the_named_type(): void {
        $post = $this->export( 'post/old.md', self::SINCE - 10 );
        $root = $this->export( 'index.md', self::SINCE - 10 );

        $this->pruner()->prune( 'page', self::SINCE );

        $this->assertFileExists( $post );
        $this->assertFileExists( $root );
    }

    public function test_rejects_traversing_post_type(): void {
        $canary = $this->uploads . '/canary.md';
        file_put_contents( $canary, 'c' );
        touch( $canary, self::SINCE - 10 );
        $root = $this->export( 'index.md', self::SINCE - 10 );

        foreach ( [ '..', '.', 'page/..', '' ] as $type ) {
            $this->assertSame( 0, $this->pruner()->prune( $type, self::SINCE ) );
        }

        $this->assertFileExists( $canary );
        $this->assertFileExists( $root );
    }

    public function test_mark_returns_filesystem_time_and_leaves_no_marker(): void {
        $before = time() - 5;

        $since = $this->pruner()->mark();

        $this->assertIsInt( $since );
        $this->assertGreaterThanOrEqual( $before, $since );
        $this->assertSame( [], array_values( array_diff( scandir( $this->base ), [ '.', '..' ] ) ) );
    }
}

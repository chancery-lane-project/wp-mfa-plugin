<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\BundleGenerator
 */
class BundleGeneratorTest extends TestCase {

    private string $export_dir;
    private string $base_dir;
    private string $uploads_dir;
    private BundleGenerator $generator;

    protected function setUp(): void {
        reset_mock_options();
        reset_mock_scheduled_events();

        $this->uploads_dir = sys_get_temp_dir() . '/wp-mfa-bundle-uploads-' . uniqid();
        $this->export_dir  = 'wp-mfa-exports';
        $this->base_dir    = $this->uploads_dir . '/' . $this->export_dir;

        mkdir( $this->base_dir, 0755, true );

        $GLOBALS['_mock_upload_dir'] = [
            'basedir' => $this->uploads_dir,
            'baseurl' => 'https://example.com/wp-content/uploads',
        ];

        $this->generator = $this->make_generator();
    }

    protected function tearDown(): void {
        // The bundle file is a sibling of the export dir, i.e. still inside
        // $this->uploads_dir, so removing that tree cleans up everything.
        $this->remove_dir( $this->uploads_dir );
        unset( $GLOBALS['_mock_upload_dir'] );
    }

    private function make_generator( array $options = [] ): BundleGenerator {
        return new BundleGenerator( array_merge( [ 'export_dir' => $this->export_dir ], $options ) );
    }

    private function make_dir( string $relative ): string {
        $dir = $this->base_dir . '/' . $relative;
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        return $dir;
    }

    private function write_file( string $relative_path, string $content ): string {
        $path = $this->base_dir . '/' . $relative_path;
        $dir  = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        file_put_contents( $path, $content );
        return $path;
    }

    /** @return array<string, string> relative path => content, read back from a built archive. */
    private function read_archive_entries( string $path ): array {
        $phar    = new \PharData( $path );
        $base    = 'phar://' . $phar->getPath() . '/';
        $entries = [];

        foreach ( new \RecursiveIteratorIterator( $phar ) as $file ) {
            $relative = str_replace( $base, '', $file->getPathname() );
            $entries[ $relative ] = file_get_contents( $file->getPathname() );
        }

        return $entries;
    }

    private function remove_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $target = $dir . '/' . $item;
            is_dir( $target ) && ! is_link( $target ) ? $this->remove_dir( $target ) : unlink( $target );
        }
        rmdir( $dir );
    }

    // -----------------------------------------------------------------------
    // bundle_path() / bundle_url()
    // -----------------------------------------------------------------------

    public function test_bundle_path_is_sibling_tar_gz_of_export_dir(): void {
        $this->assertSame( $this->uploads_dir . '/' . $this->export_dir . '.zip', $this->generator->bundle_path() );
    }

    public function test_bundle_url_mirrors_bundle_path(): void {
        $this->assertSame(
            'https://example.com/wp-content/uploads/' . $this->export_dir . '.zip',
            $this->generator->bundle_url()
        );
    }

    // -----------------------------------------------------------------------
    // build()
    // -----------------------------------------------------------------------

    public function test_build_creates_valid_reopenable_archive_with_expected_entries(): void {
        $this->write_file( 'index.md', "# Content\n" );
        $this->write_file( 'post/hello.md', "# Hello\n" );
        $this->write_file( 'manifest.json', '{"a":1}' );

        $result = $this->generator->build();

        $this->assertTrue( $result );
        $this->assertFileExists( $this->generator->bundle_path() );

        $entries = $this->read_archive_entries( $this->generator->bundle_path() );

        $this->assertArrayHasKey( 'index.md', $entries );
        $this->assertArrayHasKey( 'post/hello.md', $entries );
        $this->assertArrayHasKey( 'manifest.json', $entries );
    }

    public function test_build_handles_filenames_beyond_the_ustar_100_char_limit(): void {
        // Regression: PharData's tar writer is ustar-only and rejects names
        // over 100 characters — real clause slugs exceed that, which is why
        // the bundle is a zip. This slug is 108 characters like the one that
        // broke the first live build.
        $long_slug = 'using-legal-contracts-to-mitigate-nature-related-risks-and-promote-nature-positive-action-across-industries';
        $this->write_file( 'post/' . $long_slug . '.md', "# Long\n" );

        $this->assertTrue( $this->generator->build() );

        $entries = $this->read_archive_entries( $this->generator->bundle_path() );
        $this->assertArrayHasKey( 'post/' . $long_slug . '.md', $entries );
    }

    public function test_build_excludes_changes_json_and_ai_catalog_json(): void {
        $this->write_file( 'index.md', "# Content\n" );
        $this->write_file( 'changes.json', '{"changed":[]}' );
        $this->write_file( 'ai-catalog.json', '{"entries":[]}' );

        $this->generator->build();

        $entries = $this->read_archive_entries( $this->generator->bundle_path() );

        $this->assertArrayNotHasKey( 'changes.json', $entries );
        $this->assertArrayNotHasKey( 'ai-catalog.json', $entries );
        $this->assertArrayHasKey( 'index.md', $entries );
    }

    public function test_build_rewrites_md_links_but_leaves_permalink_frontmatter_untouched(): void {
        $base_url = 'https://example.com/wp-content/uploads/' . $this->export_dir;
        $content  = "---\n"
            . 'permalink: ' . $base_url . "/post/other.md\n"
            . "---\n\n"
            . 'See [other](' . $base_url . "/post/other.md) for more.\n";

        $this->write_file( 'post/hello.md', $content );

        $this->generator->build();

        $entries = $this->read_archive_entries( $this->generator->bundle_path() );
        $rewritten = $entries['post/hello.md'];

        $this->assertStringContainsString( '[other](/post/other.md)', $rewritten );
        $this->assertStringContainsString( 'permalink: ' . $base_url . "/post/other.md", $rewritten );
    }

    public function test_build_includes_manifest_json_verbatim(): void {
        $manifest = '{"files":{"post/hello.md":"abc123"}}';
        $this->write_file( 'manifest.json', $manifest );
        $this->write_file( 'index.md', "# Content\n" );

        $this->generator->build();

        $entries = $this->read_archive_entries( $this->generator->bundle_path() );

        $this->assertSame( $manifest, $entries['manifest.json'] );
    }

    public function test_build_returns_false_when_export_dir_missing(): void {
        $this->remove_dir( $this->base_dir );

        $result = $this->generator->build();

        $this->assertFalse( $result );
        $this->assertFileDoesNotExist( $this->generator->bundle_path() );
    }

    public function test_build_leaves_no_temp_files_behind(): void {
        $this->write_file( 'index.md', "# Content\n" );

        $this->generator->build();

        $leftovers = glob( $this->generator->bundle_path() . '.tmp-*' );
        $this->assertSame( [], $leftovers );
    }

    public function test_build_cleans_up_gz_temp_when_rename_fails(): void {
        $this->write_file( 'index.md', "# Content\n" );

        // Pre-create the rename target as a non-empty directory: rename() over
        // a non-empty directory fails on every platform, giving a deterministic
        // failure path without touching permissions/filesystem boundaries.
        mkdir( $this->generator->bundle_path(), 0755, true );
        file_put_contents( $this->generator->bundle_path() . '/blocker.txt', 'x' );

        $result = $this->generator->build();

        $this->assertFalse( $result );

        $leftovers = glob( $this->generator->bundle_path() . '.tmp-*' );
        $this->assertSame( [], $leftovers, 'No .tmp-* temp files (tar or gz) should remain after a failed rename.' );
    }

    // -----------------------------------------------------------------------
    // is_stale() / tree_hash()
    // -----------------------------------------------------------------------

    public function test_is_stale_true_before_first_build(): void {
        $this->write_file( 'index.md', "# Content\n" );

        $this->assertTrue( $this->generator->is_stale() );
    }

    public function test_is_stale_false_immediately_after_build(): void {
        $this->write_file( 'index.md', "# Content\n" );

        $this->generator->build();

        $this->assertFalse( $this->generator->is_stale() );
    }

    public function test_is_stale_true_after_file_mtime_advances(): void {
        $file = $this->write_file( 'index.md', "# Content\n" );
        $this->generator->build();

        touch( $file, time() + 10 );

        $this->assertTrue( $this->generator->is_stale() );
    }

    public function test_is_stale_true_after_new_file_added(): void {
        $this->write_file( 'index.md', "# Content\n" );
        $this->generator->build();

        $this->write_file( 'post/new.md', "# New\n" );

        $this->assertTrue( $this->generator->is_stale() );
    }

    public function test_tree_hash_is_hash_of_empty_string_when_export_dir_missing(): void {
        $this->remove_dir( $this->base_dir );

        $this->assertSame( md5( '' ), $this->generator->tree_hash() );
    }

    // -----------------------------------------------------------------------
    // delete()
    // -----------------------------------------------------------------------

    public function test_delete_removes_bundle_file_and_hash_option(): void {
        $this->write_file( 'index.md', "# Content\n" );
        $this->generator->build();

        $this->assertFileExists( $this->generator->bundle_path() );

        $result = $this->generator->delete();

        $this->assertTrue( $result );
        $this->assertFileDoesNotExist( $this->generator->bundle_path() );
        $this->assertTrue( $this->generator->is_stale() );
    }

    public function test_delete_returns_false_when_no_bundle_exists(): void {
        $this->assertFalse( $this->generator->delete() );
    }

    // -----------------------------------------------------------------------
    // mark_stale_and_schedule() / on_rebuild_bundle()
    // -----------------------------------------------------------------------

    public function test_mark_stale_and_schedule_deletes_hash_and_schedules_when_enabled(): void {
        $this->write_file( 'index.md', "# Content\n" );
        $generator = $this->make_generator( [ 'bundle_enabled' => true ] );
        $generator->build();

        $this->assertFalse( $generator->is_stale() );

        $generator->mark_stale_and_schedule();

        $this->assertTrue( $generator->is_stale() );
        $this->assertNotFalse( wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' ) );
    }

    public function test_mark_stale_and_schedule_only_schedules_once_across_repeated_calls(): void {
        $generator = $this->make_generator( [ 'bundle_enabled' => true ] );

        $generator->mark_stale_and_schedule();
        $generator->mark_stale_and_schedule();
        $generator->mark_stale_and_schedule();

        $this->assertCount( 1, $GLOBALS['_mock_scheduled_events'] );
    }

    public function test_mark_stale_and_schedule_noop_when_bundle_disabled(): void {
        $this->write_file( 'index.md', "# Content\n" );
        $generator = $this->make_generator( [ 'bundle_enabled' => false ] );
        $generator->build();

        $generator->mark_stale_and_schedule();

        $this->assertFalse( $generator->is_stale() );
        $this->assertSame( [], $GLOBALS['_mock_scheduled_events'] );
    }

    public function test_on_rebuild_bundle_builds_when_enabled(): void {
        $this->write_file( 'index.md', "# Content\n" );
        $generator = $this->make_generator( [ 'bundle_enabled' => true ] );

        $generator->on_rebuild_bundle();

        $this->assertFileExists( $generator->bundle_path() );
    }

    public function test_on_rebuild_bundle_noop_when_disabled(): void {
        $this->write_file( 'index.md', "# Content\n" );
        $generator = $this->make_generator( [ 'bundle_enabled' => false ] );

        $generator->on_rebuild_bundle();

        $this->assertFileDoesNotExist( $generator->bundle_path() );
    }
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\LlmsTxtGenerator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\LlmsTxtGenerator
 */
class LlmsTxtGeneratorTest extends TestCase {

    private string $base_dir;
    private LlmsTxtGenerator $gen;

    protected function setUp(): void {
        $this->base_dir = sys_get_temp_dir() . '/wp-mfa-llms-' . uniqid();
        mkdir( $this->base_dir, 0755, true );

        $GLOBALS['_mock_bloginfo']  = [ 'name' => 'Test Site', 'description' => 'A test site' ];
        $GLOBALS['_mock_permalink'] = 'https://example.com/test/';

        $this->gen = new LlmsTxtGenerator();
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->base_dir );
    }

    private function write_md( string $subpath, string $content ): void {
        $dir = dirname( $this->base_dir . '/' . $subpath );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        file_put_contents( $this->base_dir . '/' . $subpath, $content );
    }

    public function test_returns_false_for_nonexistent_directory(): void {
        $result = $this->gen->generate( '/nonexistent/dir' );
        $this->assertFalse( $result );
    }

    public function test_generates_llms_txt_file(): void {
        $this->write_md( 'post/hello.md', "---\ntitle: Hello World\npermalink: https://example.com/hello/\nexcerpt: A post excerpt.\n---\n\n# Hello World\n" );

        $result = $this->gen->generate( $this->base_dir );

        $this->assertTrue( $result );
        $this->assertFileExists( $this->base_dir . '/llms.txt' );
    }

    public function test_llms_txt_contains_site_name(): void {
        $this->write_md( 'post/hello.md', "---\ntitle: Hello\npermalink: https://example.com/hello/\n---\n" );
        $this->gen->generate( $this->base_dir );

        $content = file_get_contents( $this->base_dir . '/llms.txt' );
        $this->assertStringContainsString( '# Test Site', $content );
    }

    public function test_llms_txt_contains_post_link(): void {
        $this->write_md( 'post/hello.md', "---\ntitle: Hello World\npermalink: https://example.com/hello/\nexcerpt: A short excerpt.\n---\n" );
        $this->gen->generate( $this->base_dir );

        $content = file_get_contents( $this->base_dir . '/llms.txt' );
        $this->assertStringContainsString( '[Hello World](https://example.com/hello/)', $content );
        $this->assertStringContainsString( 'A short excerpt', $content );
    }

    public function test_llms_txt_skips_file_without_permalink(): void {
        $this->write_md( 'post/no-link.md', "---\ntitle: No Link\n---\n" );
        $this->gen->generate( $this->base_dir );

        $content = file_get_contents( $this->base_dir . '/llms.txt' );
        $this->assertStringNotContainsString( '[No Link]', $content );
    }

    public function test_llms_txt_groups_by_post_type(): void {
        $this->write_md( 'post/a.md', "---\ntitle: A Post\npermalink: https://example.com/a/\n---\n" );
        $this->write_md( 'page/b.md', "---\ntitle: A Page\npermalink: https://example.com/b/\n---\n" );
        $this->gen->generate( $this->base_dir );

        $content = file_get_contents( $this->base_dir . '/llms.txt' );
        $this->assertStringContainsString( '## Post', $content );
        $this->assertStringContainsString( '## Page', $content );
    }

    public function test_parse_frontmatter_extracts_scalar_values(): void {
        $file = $this->base_dir . '/test.md';
        file_put_contents( $file, "---\ntitle: My Title\npermalink: https://example.com/\n---\n\nBody\n" );

        $result = $this->gen->parse_frontmatter( $file );

        $this->assertSame( 'My Title', $result['title'] );
        $this->assertSame( 'https://example.com/', $result['permalink'] );
    }

    public function test_parse_frontmatter_returns_empty_for_missing_file(): void {
        $result = $this->gen->parse_frontmatter( '/nonexistent.md' );
        $this->assertSame( [], $result );
    }

    public function test_parse_frontmatter_skips_yaml_array_items(): void {
        $file = $this->base_dir . '/array.md';
        file_put_contents(
            $file,
            "---\ntitle: My Post\ncategories:\n  - News\n  - Sport\npermalink: https://example.com/\n---\n\nBody\n"
        );

        $result = $this->gen->parse_frontmatter( $file );

        $this->assertSame( 'My Post', $result['title'] );
        $this->assertSame( 'https://example.com/', $result['permalink'] );
        $this->assertArrayNotHasKey( '  - News', $result );
        $this->assertArrayNotHasKey( '- News', $result );
    }

    public function test_parse_frontmatter_strips_single_quoted_values(): void {
        $file = $this->base_dir . '/single-quotes.md';
        file_put_contents( $file, "---\ntitle: 'Single Quoted Title'\nexcerpt: 'A short excerpt'\n---\n" );

        $result = $this->gen->parse_frontmatter( $file );

        $this->assertSame( 'Single Quoted Title', $result['title'] );
        $this->assertSame( 'A short excerpt', $result['excerpt'] );
    }

    public function test_parse_frontmatter_strips_double_quoted_values(): void {
        $file = $this->base_dir . '/double-quotes.md';
        file_put_contents( $file, "---\ntitle: \"Double Quoted\"\n---\n" );

        $result = $this->gen->parse_frontmatter( $file );

        $this->assertSame( 'Double Quoted', $result['title'] );
    }

    private function remove_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $t = $dir . '/' . $item;
            is_dir( $t ) ? $this->remove_dir( $t ) : unlink( $t );
        }
        rmdir( $dir );
    }
}

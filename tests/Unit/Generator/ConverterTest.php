<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\Converter;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\Converter
 */
class ConverterTest extends TestCase {

    private Converter $converter;

    protected function setUp(): void {
        $this->converter = new Converter();
    }

    public function test_converts_paragraph(): void {
        $output = $this->converter->convert( '<p>Hello world</p>' );
        $this->assertStringContainsString( 'Hello world', $output );
    }

    public function test_converts_heading_to_atx_style(): void {
        $output = $this->converter->convert( '<h2>Section Title</h2>' );
        $this->assertStringContainsString( '## Section Title', $output );
    }

    public function test_converts_bold_to_double_asterisk(): void {
        $output = $this->converter->convert( '<p><strong>Bold text</strong></p>' );
        $this->assertStringContainsString( '**Bold text**', $output );
    }

    public function test_converts_emphasis_to_single_underscore(): void {
        $output = $this->converter->convert( '<p><em>Emphasised text</em></p>' );
        $this->assertStringContainsString( '_Emphasised text_', $output );
    }

    public function test_converts_link(): void {
        $output = $this->converter->convert( '<a href="https://example.com">Click here</a>' );
        $this->assertStringContainsString( '[Click here](https://example.com)', $output );
    }

    public function test_converts_unordered_list(): void {
        $output = $this->converter->convert( '<ul><li>Alpha</li><li>Beta</li></ul>' );
        $this->assertStringContainsString( '- Alpha', $output );
        $this->assertStringContainsString( '- Beta', $output );
    }

    public function test_decodes_html_entities(): void {
        $output = $this->converter->convert( '<p>Cats &amp; Dogs</p>' );
        $this->assertStringContainsString( 'Cats & Dogs', $output );
        $this->assertStringNotContainsString( '&amp;', $output );
    }

    public function test_fix_image_spacing_adds_newline_after_image(): void {
        $output = $this->converter->convert(
            '<p><img src="photo.jpg" alt="Photo">Some caption text</p>'
        );
        // The image markdown should be followed by a blank line before the text.
        $this->assertMatchesRegularExpression( '/!\[.*\]\(.*\)\n\n\S/', $output );
    }

    public function test_empty_string_returns_empty(): void {
        $output = $this->converter->convert( '' );
        $this->assertSame( '', trim( $output ) );
    }

    public function test_convert_with_post_passes_post_to_filters(): void {
        // Filters are stubs (pass-through) in tests — just confirm no exception thrown.
        $post = new \WP_Post( [ 'ID' => 1, 'post_title' => 'Test' ] );
        $output = $this->converter->convert( '<p>Content</p>', $post );
        $this->assertStringContainsString( 'Content', $output );
    }
}

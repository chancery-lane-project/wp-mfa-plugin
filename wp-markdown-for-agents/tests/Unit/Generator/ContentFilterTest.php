<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\ContentFilter;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\ContentFilter
 */
class ContentFilterTest extends TestCase {

    private ContentFilter $filter;

    protected function setUp(): void {
        $this->filter = new ContentFilter();
    }

    public function test_strips_wp_block_opening_comments(): void {
        $input  = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
        $output = $this->filter->filter( $input );
        $this->assertStringNotContainsString( '<!-- wp:', $output );
        $this->assertStringNotContainsString( '<!-- /wp:', $output );
        $this->assertStringContainsString( '<p>Hello</p>', $output );
    }

    public function test_strips_wp_block_comments_with_attributes(): void {
        $input  = '<!-- wp:image {"id":123,"sizeSlug":"large"} --><figure></figure><!-- /wp:image -->';
        $output = $this->filter->filter( $input );
        $this->assertStringNotContainsString( '<!-- wp:', $output );
        $this->assertStringContainsString( '<figure></figure>', $output );
    }

    public function test_passes_through_plain_html(): void {
        $input  = '<p>Just a paragraph.</p>';
        $output = $this->filter->filter( $input );
        $this->assertStringContainsString( '<p>Just a paragraph.</p>', $output );
    }

    public function test_empty_string_returns_empty(): void {
        $this->assertSame( '', $this->filter->filter( '' ) );
    }

    public function test_strips_multiple_block_comments(): void {
        $input = implode( '', [
            '<!-- wp:heading {"level":2} --><h2>Title</h2><!-- /wp:heading -->',
            '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
        ] );
        $output = $this->filter->filter( $input );
        $this->assertStringNotContainsString( '<!--', $output );
        $this->assertStringContainsString( '<h2>Title</h2>', $output );
        $this->assertStringContainsString( '<p>Body.</p>', $output );
    }

    public function test_does_not_strip_non_wp_html_comments(): void {
        // Regular HTML comments that are not WP block comments should be left alone
        // (the converter will handle them downstream).
        $input  = '<!-- A normal comment --><p>Text</p>';
        $output = $this->filter->filter( $input );
        $this->assertStringContainsString( '<!-- A normal comment -->', $output );
    }

    public function test_multiline_block_comment_stripped(): void {
        $input  = "<!-- wp:group\n{\"layout\":{\"type\":\"constrained\"}}\n--><div></div><!-- /wp:group -->";
        $output = $this->filter->filter( $input );
        $this->assertStringNotContainsString( '<!-- wp:', $output );
        $this->assertStringContainsString( '<div></div>', $output );
    }
}

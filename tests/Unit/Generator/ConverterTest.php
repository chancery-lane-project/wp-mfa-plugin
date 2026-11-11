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

    public function test_image_followed_by_text_stays_inline(): void {
        $output = $this->converter->convert(
            '<p><img src="photo.jpg" alt="Photo">Some caption text</p>'
        );
        $this->assertSame( '![Photo](photo.jpg)Some caption text', trim( $output ) );
    }

    /**
     * @see https://github.com/chancery-lane-project/wp-mfa-plugin/issues/55
     */
    public function test_linked_image_is_not_split(): void {
        $output = $this->converter->convert(
            '<p><a href="https://example.com"><img src="logo.png" alt="Logo"></a></p>'
        );
        $this->assertSame( '[![Logo](logo.png)](https://example.com)', trim( $output ) );
    }

    /**
     * @see https://github.com/chancery-lane-project/wp-mfa-plugin/issues/55
     */
    public function test_image_followed_by_punctuation_is_not_split(): void {
        $output = $this->converter->convert(
            '<p>See <img src="chart.png" alt="Chart">. Next sentence.</p>'
        );
        $this->assertSame( 'See ![Chart](chart.png). Next sentence.', trim( $output ) );
    }

    /**
     * @see https://github.com/chancery-lane-project/wp-mfa-plugin/issues/55
     */
    public function test_image_syntax_in_inline_code_is_unchanged(): void {
        $output = $this->converter->convert(
            '<p>Agents hit <code>![alt](src)</code> and nothing more.</p>'
        );
        $this->assertSame( 'Agents hit `![alt](src)` and nothing more.', trim( $output ) );
    }

    /**
     * @see https://github.com/chancery-lane-project/wp-mfa-plugin/issues/55
     */
    public function test_image_syntax_in_code_block_is_unchanged(): void {
        $output = $this->converter->convert(
            '<pre><code class="language-md">![alt](src).</code></pre>'
        );
        $this->assertStringContainsString( "\n![alt](src).\n", $output );
        $this->assertStringNotContainsString( "![alt](src)\n\n", $output );
    }

    public function test_empty_string_returns_empty(): void {
        $output = $this->converter->convert( '' );
        $this->assertSame( '', trim( $output ) );
    }

    public function test_convert_with_post_passes_post_to_filters(): void {
        $post     = new \WP_Post( [ 'ID' => 1, 'post_title' => 'Test' ] );
        $received = [];

        $GLOBALS['_mock_apply_filters']['markdown_for_agents_pre_convert'] =
            static function ( $html, $filter_post ) use ( &$received ) {
                $received['pre'] = $filter_post;
                return $html;
            };
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_post_convert'] =
            static function ( $markdown, $filter_post ) use ( &$received ) {
                $received['post'] = $filter_post;
                return $markdown;
            };

        $output = $this->converter->convert( '<p>Content</p>', $post );

        unset(
            $GLOBALS['_mock_apply_filters']['markdown_for_agents_pre_convert'],
            $GLOBALS['_mock_apply_filters']['markdown_for_agents_post_convert']
        );

        $this->assertStringContainsString( 'Content', $output );
        $this->assertSame( $post, $received['pre'] );
        $this->assertSame( $post, $received['post'] );
    }

    // -----------------------------------------------------------------------
    // TableConverter (registered on the environment)
    // -----------------------------------------------------------------------

    public function test_converts_table_to_gfm(): void {
        $html = '<table>'
            . '<thead><tr><th>Name</th><th>Role</th></tr></thead>'
            . '<tbody><tr><td>Ada</td><td>Engineer</td></tr>'
            . '<tr><td>Grace</td><td>Admiral</td></tr></tbody>'
            . '</table>';

        $output = $this->converter->convert( $html );

        $this->assertStringContainsString( '| Name | Role |', $output );
        $this->assertStringContainsString( '| --- | --- |', $output );
        $this->assertStringContainsString( '| Ada | Engineer |', $output );
        $this->assertStringContainsString( '| Grace | Admiral |', $output );
    }

    public function test_converts_table_caption_to_bold_text(): void {
        $html = '<table><caption>Team roster</caption>'
            . '<tr><th>Name</th></tr><tr><td>Ada</td></tr></table>';

        $output = $this->converter->convert( $html );

        $this->assertStringContainsString( '**Team roster**', $output );
    }

    // -----------------------------------------------------------------------
    // CodeBlockConverter (registered on the environment)
    // -----------------------------------------------------------------------

    public function test_converts_gutenberg_code_block_to_fenced_markdown(): void {
        $html = '<pre class="wp-block-code"><code>echo "hi";</code></pre>';

        $output = $this->converter->convert( $html );

        $this->assertStringContainsString( "```\necho \"hi\";\n```", $output );
    }

    public function test_gutenberg_code_block_language_from_style_class(): void {
        $html = '<pre class="wp-block-code is-style-php"><code>echo 1;</code></pre>';

        $output = $this->converter->convert( $html );

        $this->assertStringContainsString( "```php\necho 1;\n```", $output );
    }

    public function test_gutenberg_code_block_decodes_entities(): void {
        $html = '<pre class="wp-block-code"><code>if ( $a &lt; $b &amp;&amp; $b &gt; 0 )</code></pre>';

        $output = $this->converter->convert( $html );

        $this->assertStringContainsString( 'if ( $a < $b && $b > 0 )', $output );
    }

    public function test_plain_pre_without_wp_block_code_class_is_untouched_by_custom_converter(): void {
        $html = '<pre>plain preformatted</pre>';

        $output = $this->converter->convert( $html );

        $this->assertStringContainsString( 'plain preformatted', $output );
    }

    // --- Link text stays inline ---

    public function test_card_link_wrapping_block_markup_stays_one_link(): void {
        // Card markup from a real theme: the whole card is one link.
        $html = <<<'HTML'
<a class="card" href="https://example.com/post/">
	<div class="card__banner">
		<img src="card.png" alt="" loading="lazy" />
		<span class="card__badge">Code</span>
	</div>
	<div class="card__content">
		<ul class="card__tags" role="list">
			<li>#code</li>
			<li>#ai</li>
		</ul>
		<h3 class="card__heading">
			All this used to be xsl:fields		</h3>
		<p class="card__excerpt">Chrome removes XSLT on 17 November.</p>
		<div class="card__footer">
			<p class="card__meta">Nov 2026 · 4 min read</p>
			<span aria-hidden="true">Read →</span>
		</div>
	</div>
</a>
HTML;

        $output = trim( $this->converter->convert( $html ) );

        $this->assertSame(
            '[![](card.png) Code \#code \#ai All this used to be xsl:fields Chrome removes XSLT on 17 November. Nov 2026 · 4 min read Read →](https://example.com/post/)',
            $output
        );
    }

    public function test_link_text_whitespace_is_collapsed_and_trimmed(): void {
        $html = '<ol><li><a href="https://example.com/part-3/"> <img src="part-3.jpg" alt="">   Part 03 · 15 min   Human in the Loop   → </a></li></ol>';

        $output = $this->converter->convert( $html );

        $this->assertStringContainsString(
            '1. [![](part-3.jpg) Part 03 · 15 min Human in the Loop →](https://example.com/part-3/)',
            $output
        );
    }

    public function test_link_with_title_attribute_is_flattened(): void {
        $output = $this->converter->convert(
            '<a href="https://example.com/" title="Example"><h2>Heading</h2><p>Summary</p></a>'
        );

        $this->assertStringContainsString( '[Heading Summary](https://example.com/ "Example")', $output );
    }

    public function test_link_text_keeps_escaped_marker_characters(): void {
        $output = $this->converter->convert(
            '<a href="https://example.com/"><p>2024. A year</p><p>- not a list</p><p># not a heading</p></a>'
        );

        $this->assertStringContainsString( '2024\. A year', $output );
        $this->assertStringContainsString( '\- not a list', $output );
        $this->assertStringContainsString( '\# not a heading', $output );
    }

    public function test_link_without_href_is_left_alone(): void {
        $output = $this->converter->convert( '<a><h2>Heading</h2><p>Summary</p></a>' );

        $this->assertStringContainsString( '## Heading', $output );
    }
}

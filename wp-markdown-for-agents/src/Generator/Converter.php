<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

use League\HTMLToMarkdown\Environment;
use League\HTMLToMarkdown\HtmlConverter;
use Tclp\WpMarkdownForAgents\Generator\Converters\CodeBlockConverter;
use Tclp\WpMarkdownForAgents\Generator\Converters\TableConverter;

/**
 * Converts HTML to Markdown.
 *
 * Wraps league/html-to-markdown v5.x with custom converters from wp-to-file
 * (TableConverter, CodeBlockConverter) and WordPress-specific post-processing.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class Converter {

	/**
	 * The underlying HtmlConverter instance.
	 *
	 * @since  1.0.0
	 * @var    HtmlConverter
	 */
	private HtmlConverter $html_converter;

	/**
	 * Initialise the converter.
	 *
	 * Accepts an optional options array which is merged over the defaults and
	 * passed through the `wp_mfa_converter_options` filter.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $options Override options for HtmlConverter.
	 */
	public function __construct( array $options = array() ) {
		$defaults = array(
			'header_style' => 'atx',
			'bold_style'   => '**',
			'italic_style' => '_',
			'hard_break'   => true,
			'strip_tags'   => true,
		);

		/**
		 * Override HtmlConverter options.
		 *
		 * @since  1.0.0
		 * @param  array<string, mixed> $options Merged converter options.
		 */
		$merged_options = apply_filters(
			'wp_mfa_converter_options',
			array_merge( $defaults, $options )
		);

		// league/html-to-markdown v5: use createDefaultEnvironment() so all
		// built-in converters are registered before we add our custom ones.
		$environment = Environment::createDefaultEnvironment( $merged_options );
		$environment->addConverter( new TableConverter() );
		$environment->addConverter( new CodeBlockConverter() );

		$this->html_converter = new HtmlConverter( $environment );
	}

	/**
	 * Convert an HTML string to Markdown.
	 *
	 * The ContentFilter should be applied before calling this method to strip
	 * WordPress block editor comments. This method handles:
	 *
	 * 1. `wp_mfa_pre_convert` filter on the HTML
	 * 2. HTML → Markdown via HtmlConverter
	 * 3. Image spacing fix (ensures blank line after inline images)
	 * 4. `html_entity_decode()` to clean up leftover entities
	 * 5. `wp_mfa_post_convert` filter on the Markdown
	 *
	 * @since  1.0.0
	 * @param  string        $html The HTML content.
	 * @param  \WP_Post|null $post The post object (passed to filters).
	 * @return string
	 */
	public function convert( string $html, ?\WP_Post $post = null ): string {
		if ( '' === $html ) {
			return '';
		}

		/**
		 * Modify HTML before conversion to Markdown.
		 *
		 * @since  1.0.0
		 * @param  string        $html The HTML content.
		 * @param  \WP_Post|null $post The post object.
		 */
		$html = apply_filters( 'wp_mfa_pre_convert', $html, $post );

		$markdown = $this->html_converter->convert( $html );

		$markdown = $this->fix_image_spacing( $markdown );

		$markdown = html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		/**
		 * Modify Markdown after conversion.
		 *
		 * @since  1.0.0
		 * @param  string        $markdown The Markdown content.
		 * @param  \WP_Post|null $post     The post object.
		 */
		$markdown = apply_filters( 'wp_mfa_post_convert', $markdown, $post );

		return $markdown;
	}

	/**
	 * Ensure images are followed by a blank line so text does not run on.
	 *
	 * Extracted from wp-to-file MarkdownProcessor::fixImageSpacing().
	 *
	 * @since  1.0.0
	 * @param  string $content Markdown content.
	 * @return string
	 */
	private function fix_image_spacing( string $content ): string {
		return preg_replace(
			'/(\!\[.*?\]\([^\)]+\))(?!\n\n)(\S)/m',
			"$1\n\n$2",
			$content
		) ?? $content;
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Cleans WordPress HTML content before Markdown conversion.
 *
 * This class deliberately does NOT normalise URLs to relative paths —
 * canonical absolute URLs are preserved.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class ContentFilter {

	/**
	 * Filter HTML content ready for Markdown conversion.
	 *
	 * Strips WordPress Gutenberg block editor comments and <style>/<script>
	 * blocks (tag and contents) from the HTML. Other HTML (tags, attributes,
	 * inline styles) is left for the HtmlConverter to process.
	 *
	 * @since  1.0.0
	 * @param  string $html Raw HTML from WordPress.
	 * @return string Cleaned HTML.
	 */
	public function filter( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		// Strip WordPress block editor opening comments (with optional JSON attrs).
		// Matches: <!-- wp:block-name { ... } --> and <!-- wp:block-name -->.
		$html = preg_replace( '/<!--\s*wp:[^\-]*?-->/s', '', $html ) ?? $html;

		// Strip WordPress block editor closing comments.
		// Matches: <!-- /wp:block-name -->.
		$html = preg_replace( '/<!--\s*\/wp:[^\-]*?-->/s', '', $html ) ?? $html;

		// Strip <style>/<script> blocks including their contents. HtmlConverter
		// runs with strip_tags enabled, which removes the wrapper but keeps the
		// inner CSS/JS as bare text — so these must be removed before conversion.
		$html = preg_replace( '#<(style|script)\b[^>]*>.*?</\1>#is', '', $html ) ?? $html;

		return $html;
	}
}

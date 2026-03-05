<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Cleans WordPress HTML content before Markdown conversion.
 *
 * Adapted from wp-to-file ContentFilter. This class deliberately does NOT
 * normalise URLs to relative paths — canonical absolute URLs are preserved.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class ContentFilter {

    /**
     * Filter HTML content ready for Markdown conversion.
     *
     * Strips WordPress Gutenberg block editor comments from the HTML.
     * Other HTML (tags, attributes, inline styles) is left for the
     * HtmlConverter to process.
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
        // Matches: <!-- wp:block-name { ... } --> and <!-- wp:block-name -->
        $html = preg_replace( '/<!--\s*wp:[^\-]*?-->/s', '', $html ) ?? $html;

        // Strip WordPress block editor closing comments.
        // Matches: <!-- /wp:block-name -->
        $html = preg_replace( '/<!--\s*\/wp:[^\-]*?-->/s', '', $html ) ?? $html;

        return $html;
    }
}

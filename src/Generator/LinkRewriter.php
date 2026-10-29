<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites internal site links in Markdown to `?output_format=md` page URLs.
 *
 * Links go to the page URL rather than the static `.md` file in uploads
 * because the file depends on the web server for its Content-Type: nginx
 * hosts without a `.md` MIME mapping serve it as `application/octet-stream`,
 * and Apache honours the export directory's `Deny from all`. The query
 * parameter is served by Negotiator with `text/markdown`, is logged in the
 * statistics, and falls back to the HTML page if the plugin is deactivated.
 * Relativisation for the OKF bundle happens at bundle build time, not here.
 *
 * Pure string logic: URL resolution is delegated to an injected callable so
 * the class is fully unit-testable without WordPress.
 *
 * Pagination links (`?paged=2`, `?query-1-page=2`, `/page/2/`) are left as
 * HTML: only the first page of a listing is exported, so stripping the query
 * would turn "Next page" into a link back to the document itself.
 *
 * Reference-style links (`[text][ref]`) are out of scope by design, as the
 * Converter only ever emits inline links. Link text supports one level of
 * nested brackets; deeper nesting is left un-rewritten as a safe pass-through.
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class LinkRewriter {

	/**
	 * Query keys that select a later page of the linked document.
	 *
	 * `query-N-page` is the Query Loop block's per-block key.
	 *
	 * @since  1.7.3
	 */
	private const PAGINATION_KEYS = '/(?:^|&)(?:paged|page|cpage|query-\d+-page)=/';

	/**
	 * Trailing pretty-permalink pagination segment, for the default `page` base.
	 *
	 * @since  1.7.3
	 */
	private const PAGINATION_PATH = '#/page/\d+/?$#';

	/**
	 * @since  1.6.0
	 * @since  1.7.3 Dropped the `$base_url` parameter; the resolver now
	 *               returns a canonical page URL rather than a bundle path.
	 * @param  \Closure $resolver Maps a URL to the canonical URL of an exported
	 *                            document (e.g. `https://site/other/`) or null.
	 */
	public function __construct(
		private readonly \Closure $resolver
	) {}

	/**
	 * Append `output_format=md` to a page URL.
	 *
	 * Uses `&` when the URL already has a query string, as plain permalinks
	 * (`/?p=123`) do. Any fragment is kept after the query.
	 *
	 * @since  1.7.3
	 * @param  string $url Page URL.
	 * @return string
	 */
	public static function markdown_url( string $url ): string {
		$fragment = '';

		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'output_format=md' . $fragment;
	}

	/**
	 * Rewrite internal links in a Markdown document.
	 *
	 * @since  1.6.0
	 * @param  string $markdown The Markdown body.
	 * @return string
	 */
	public function rewrite( string $markdown ): string {
		// Inline links only; (?<!\!) excludes image syntax.
		$pattern = '/(?<!\!)(\[(?:[^\[\]]|\[[^\]]*\])*\])\(([^)\s]+)\)/';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $matches ): string {
				$url      = $matches[2];
				$fragment = '';

				$hash = strpos( $url, '#' );
				if ( false !== $hash ) {
					$fragment = substr( $url, $hash );
					$url      = substr( $url, 0, $hash );
				}

				$query = strpos( $url, '?' );
				if ( false !== $query ) {
					if ( preg_match( self::PAGINATION_KEYS, substr( $url, $query + 1 ) ) ) {
						return $matches[0];
					}

					$url = substr( $url, 0, $query );
				}

				if ( preg_match( self::PAGINATION_PATH, $url ) ) {
					return $matches[0];
				}

				$target = ( $this->resolver )( $url );

				if ( null === $target ) {
					return $matches[0];
				}

				return $matches[1] . '(' . self::markdown_url( $target . $fragment ) . ')';
			},
			$markdown
		);
	}
}

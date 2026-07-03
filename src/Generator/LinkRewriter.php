<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Rewrites internal site links in Markdown to absolute `.md` upload URLs.
 *
 * Pure string logic: URL resolution is delegated to an injected callable so
 * the class is fully unit-testable without WordPress. Relativisation for the
 * OKF bundle happens at bundle build time, not here.
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class LinkRewriter {

	/**
	 * @since  1.6.0
	 * @param  \Closure $resolver Maps an absolute URL to a bundle path
	 *                            (e.g. `post/other.md`) or null.
	 * @param  string   $base_url Export base URL, no trailing slash
	 *                            (Options::get_export_base_url()).
	 */
	public function __construct(
		private readonly \Closure $resolver,
		private readonly string $base_url
	) {}

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
					$url = substr( $url, 0, $query );
				}

				$target = ( $this->resolver )( $url );

				if ( null === $target ) {
					return $matches[0];
				}

				return $matches[1] . '(' . $this->base_url . '/' . $target . $fragment . ')';
			},
			$markdown
		);
	}
}

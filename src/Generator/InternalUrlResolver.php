<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Resolves absolute site URLs to bundle-relative Markdown file paths.
 *
 * Posts resolve via url_to_postid(); taxonomy term archives via a lazily
 * built map of term links. Results mirror the path logic used by
 * Generator::get_export_path() and TaxonomyArchiveGenerator::get_export_path().
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class InternalUrlResolver {

	/** @var array<string, string>|null Term link (untrailingslashed) → bundle path. */
	private ?array $term_map = null;

	/** @var array<string, string|null> Memoised results keyed by URL. */
	private array $resolved = array();

	/**
	 * @since  1.6.0
	 * @param  array<string, mixed> $options Plugin options.
	 */
	public function __construct( private readonly array $options ) {}

	/**
	 * Resolve a URL to a bundle-relative path, or null if not an exported document.
	 *
	 * @since  1.6.0
	 * @param  string $url Absolute URL.
	 * @return string|null e.g. `post/my-post.md` or `taxonomy/category/climate.md`.
	 */
	public function resolve( string $url ): ?string {
		if ( array_key_exists( $url, $this->resolved ) ) {
			return $this->resolved[ $url ];
		}

		$this->resolved[ $url ] = $this->do_resolve( $url );

		return $this->resolved[ $url ];
	}

	/**
	 * Check the host, then try post resolution before falling back to terms.
	 *
	 * @since  1.6.0
	 * @param  string $url Absolute URL.
	 * @return string|null
	 */
	private function do_resolve( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( empty( $host ) || strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== 0 ) {
			return null;
		}

		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			return $this->post_path( $post_id );
		}

		return $this->term_path( $url );
	}

	/**
	 * Build the bundle path for a post, or null if it is not an exported document.
	 *
	 * @since  1.6.0
	 * @param  int $post_id Post ID.
	 * @return string|null
	 */
	private function post_path( int $post_id ): ?string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( ! ExportPolicy::is_eligible( $post, $this->options ) ) {
			return null;
		}

		return ExportPolicy::post_relative_path( $post );
	}

	/**
	 * Look up a URL in the lazily built term-link map.
	 *
	 * @since  1.6.0
	 * @param  string $url Absolute URL.
	 * @return string|null
	 */
	private function term_path( string $url ): ?string {
		if ( null === $this->term_map ) {
			$this->term_map = $this->build_term_map();
		}

		return $this->term_map[ untrailingslashit( $url ) ] ?? null;
	}

	/**
	 * Build the full term-link → bundle-path map across all public taxonomies.
	 *
	 * Eagerly built once per resolver instance (i.e. once per request), rather
	 * than cached across requests: acceptable at modest term counts, but
	 * revisit with a transient cache if profiling shows cost on sites with
	 * large taxonomies.
	 *
	 * @since  1.6.0
	 * @return array<string, string>
	 */
	private function build_term_map(): array {
		$map = array();

		foreach ( array_keys( get_taxonomies( array( 'public' => true ) ) ) as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );

				if ( is_wp_error( $link ) || ! is_string( $link ) ) {
					continue;
				}

				$map[ untrailingslashit( $link ) ] = ExportPolicy::term_relative_path( $term->taxonomy, $term->slug );
			}
		}

		return $map;
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Resolves absolute site URLs to bundle-relative Markdown file paths.
 *
 * Posts resolve via url_to_postid(), falling back to a `_wp_old_slug` lookup
 * for links written against a since-renamed post; taxonomy term archives via a
 * lazily built map of term links. Results mirror the path logic used by
 * Generator::get_export_path() and TaxonomyArchiveGenerator::get_export_path().
 *
 * Same-host URLs that resolve to nothing fire `markdown_for_agents_unresolved_link`,
 * so the links left as HTML permalinks in an otherwise Markdown-linked document
 * are observable rather than silent.
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
	 * Why the URL currently being resolved failed, for the unresolved-link action.
	 *
	 * @var string
	 */
	private string $reason = 'not_found';

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

		$this->reason = 'not_found';
		$path         = $this->do_resolve( $url );

		$this->resolved[ $url ] = $path;

		if ( null === $path && $this->is_internal( $url ) ) {
			/**
			 * Fires when a same-host link could not be mapped to an exported document.
			 *
			 * Such links are left as HTML permalinks in the Markdown output, which is
			 * what produces documents mixing `.md` and HTML link targets. Hook this to
			 * audit export coverage. Fires at most once per unique URL per request.
			 *
			 * @since 1.6.1
			 * @param string $url    The unresolved absolute URL.
			 * @param string $reason Either `not_found` (no matching post or term) or
			 *                       `ineligible` (a post matched but is not exported).
			 */
			do_action( 'markdown_for_agents_unresolved_link', $url, $this->reason );
		}

		return $path;
	}

	/**
	 * Check whether a URL points at this site.
	 *
	 * @since  1.6.1
	 * @param  string $url Absolute URL.
	 * @return bool
	 */
	private function is_internal( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return ! empty( $host )
			&& 0 === strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Check the host, then try post resolution before falling back to terms.
	 *
	 * @since  1.6.0
	 * @param  string $url Absolute URL.
	 * @return string|null
	 */
	private function do_resolve( string $url ): ?string {
		if ( ! $this->is_internal( $url ) ) {
			return null;
		}

		$post_id = url_to_postid( $url );

		if ( $post_id <= 0 ) {
			$post_id = $this->old_slug_to_postid( $url );
		}

		if ( $post_id > 0 ) {
			return $this->post_path( $post_id );
		}

		return $this->term_path( $url );
	}

	/**
	 * Resolve a URL whose slug is a post's *former* slug.
	 *
	 * WordPress keeps superseded slugs in `_wp_old_slug` meta and 301s them at
	 * request time, but url_to_postid() only matches the current permalink. A
	 * cross-link written before a rename therefore resolves to nothing and is
	 * left as an HTML permalink, which is the main source of documents that mix
	 * link conventions.
	 *
	 * Only runs when url_to_postid() has already failed, and bails when more
	 * than one post claims the slug rather than guessing between them.
	 *
	 * @since  1.6.1
	 * @param  string $url Absolute URL.
	 * @return int Post ID, or 0 when there is no unambiguous match.
	 */
	private function old_slug_to_postid( string $url ): int {
		$slug = $this->url_slug( $url );

		if ( '' === $slug ) {
			return 0;
		}

		$post_types = ExportPolicy::enabled_post_types( $this->options );

		if ( empty( $post_types ) ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'numberposts'            => 2,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed meta_key lookup, only on links url_to_postid() could not resolve.
					array(
						'key'   => '_wp_old_slug',
						'value' => $slug,
					),
				),
			)
		);

		if ( ! is_array( $posts ) || 1 !== count( $posts ) ) {
			return 0;
		}

		return $posts[0] instanceof \WP_Post ? $posts[0]->ID : 0;
	}

	/**
	 * Extract the final path segment of a URL, which is the post slug for every
	 * permalink structure that ends in `%postname%`.
	 *
	 * @since  1.6.1
	 * @param  string $url Absolute URL.
	 * @return string Slug, or an empty string when the URL has no usable path.
	 */
	private function url_slug( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$segments = explode( '/', $path );

		return sanitize_title( rawurldecode( (string) end( $segments ) ) );
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
			$this->reason = 'ineligible';

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

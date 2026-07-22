<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Negotiate;

use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;

/**
 * Handles HTTP content negotiation for Markdown responses.
 *
 * Hooks into template_redirect to serve pre-generated .md files when the
 * client sends Accept: text/markdown. Also emits a <link rel="alternate">
 * tag in wp_head when a .md file exists for the current page.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Negotiate
 */
class Negotiator {

	/**
	 * @since  1.0.0
	 * @param  array<string, mixed>    $options           Plugin options.
	 * @param  Generator               $generator         Generator instance (provides get_export_path).
	 * @param  TaxonomyArchiveGenerator $taxonomy_generator Taxonomy archive generator.
	 * @param  AgentDetector           $agent_detector     Detects known AI agent user-agents.
	 * @param  AccessLogger            $access_logger      Records agent access events for statistics.
	 */
	public function __construct(
		private readonly array $options,
		private readonly Generator $generator,
		private readonly TaxonomyArchiveGenerator $taxonomy_generator,
		private readonly AgentDetector $agent_detector,
		private readonly AccessLogger $access_logger
	) {}

	/**
	 * Serve the Markdown file if the request accepts text/markdown.
	 *
	 * Hooked to `template_redirect` at priority 1.
	 *
	 * @since  1.0.0
	 */
	public function maybe_serve_markdown(): void {
		$accept    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
		$ua        = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		// Public content-negotiation parameter on anonymous frontend requests — nonces are not applicable here.
		$format_qp = sanitize_key( $_GET['output_format'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$matched_agent = $this->agent_detector->get_matched_agent( $ua );  // serving gate.
		$via_accept    = str_contains( $accept, 'text/markdown' );
		$via_query     = in_array( $format_qp, array( 'md', 'markdown' ), true );

		if ( ! $via_accept && ! $via_query && null === $matched_agent ) {
			return;
		}

		// Method precedence: query-param > accept-header > ua.
		if ( $via_query ) {
			$access_method = 'query-param';
		} elseif ( $via_accept ) {
			$access_method = 'accept-header';
		} else {
			$access_method = 'ua';
		}

		if ( $this->is_eligible_singular() ) {
			$post = $this->queried_servable_post();
			if ( null === $post ) {
				return;
			}

			/**
			 * Whether to serve Markdown for this specific post.
			 *
			 * Only fires when the request has already been identified as a Markdown
			 * request (Accept header, query param, or known UA). Return false to
			 * prevent serving for this post without affecting others.
			 *
			 * @since 1.1.0
			 * @param bool     $enabled Whether serving is enabled. Default true.
			 * @param \WP_Post $post    The queried post.
			 */
			if ( ! apply_filters( 'markdown_for_agents_serve_enabled', true, $post ) ) {
				return;
			}

			$filepath = $this->generator->get_export_path( $post );

			if ( ! file_exists( $filepath ) || ! $this->is_safe_filepath( $filepath ) ) {
				return;
			}

			// Agent detection for stats: always tries UA match, ignores ua_force_enabled.
			// Falls back to normalised UA so query-param / accept-header requests still
			// record the caller's product name even when no known-agent pattern matches.
			$agent = $this->agent_detector->detect_agent( $ua ) ?? $this->agent_detector->normalise_ua( $ua );

			$this->access_logger->log_access( $post->ID, $agent, $access_method );
			$this->send_markdown_file( $filepath, $access_method );
			return;
		}

		if ( is_tax() || is_category() || is_tag() ) {
			/**
			 * Whether to serve Markdown for taxonomy archive pages.
			 *
			 * @since 1.1.0
			 * @param bool $enabled Whether serving is enabled. Default true.
			 */
			if ( ! apply_filters( 'markdown_for_agents_serve_taxonomies', true ) ) {
				return;
			}

			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term ) {
				return;
			}

			$filepath = $this->taxonomy_generator->get_export_path( $term );

			if ( ! file_exists( $filepath ) || ! $this->is_safe_filepath( $filepath ) ) {
				return;
			}

			$this->send_markdown_file( $filepath, $access_method );
		}
	}

	/**
	 * Output a <link rel="alternate"> tag for the current page's .md file.
	 *
	 * Hooked to `wp_head` at priority 1. Only emits when the .md file exists.
	 *
	 * @since  1.0.0
	 */
	public function output_link_tag(): void {
		$url = $this->get_markdown_alternate_url();
		if ( null === $url ) {
			return;
		}

		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $url ) . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Send discovery and cache-variance headers with the HTML response.
	 *
	 * Hooked to `template_redirect` at priority 2, directly after
	 * maybe_serve_markdown() at priority 1: when Markdown is served the
	 * process has already exited, so this only ever runs for HTML responses.
	 *
	 * The `Link` header mirrors the wp_head <link rel="alternate"> tag at the
	 * protocol layer, making the Markdown alternate discoverable to HEAD
	 * requests and clients that do not parse HTML. `Vary: Accept` tells
	 * spec-correct shared caches not to replay a stored HTML variant to a
	 * client negotiating for text/markdown on the same URL.
	 *
	 * @since  1.6.1
	 */
	public function output_html_headers(): void {
		$url = $this->get_markdown_alternate_url();
		if ( null === $url ) {
			return;
		}

		/**
		 * Filter the headers added to the HTML response when a Markdown
		 * alternate exists for the current page.
		 *
		 * `Vary: Accept` fragments the HTML cache by Accept string on
		 * spec-correct caches, and some cache layers refuse to store any
		 * response whose Vary lists more than Accept-Encoding — map it to an
		 * empty string to omit it if full-page caching matters more than
		 * same-URL negotiation on your host. Headers are appended rather than
		 * replaced, so Vary/Link headers set elsewhere are preserved.
		 *
		 * @since 1.6.1
		 * @param array<string,string> $headers Header name => value pairs.
		 * @param string               $url     The Markdown alternate URL.
		 */
		$headers = apply_filters(
			'markdown_for_agents_html_headers',
			array(
				'Link' => '<' . esc_url_raw( $url ) . '>; rel="alternate"; type="text/markdown"',
				'Vary' => 'Accept',
			),
			$url
		);

		foreach ( (array) $headers as $name => $value ) {
			if ( '' !== $value ) {
				header( $name . ': ' . $value, false );
			}
		}
	}

	/**
	 * Resolve the Markdown alternate URL for the current request.
	 *
	 * Applies the same eligibility rules as serving: published,
	 * non-password-protected, non-excluded singulars of a configured post
	 * type, or taxonomy archives — in each case only when the pre-generated
	 * `.md` file exists on disk.
	 *
	 * @since  1.6.1
	 * @return string|null Unescaped URL, or null when no alternate exists.
	 */
	private function get_markdown_alternate_url(): ?string {
		if ( $this->is_eligible_singular() ) {
			$post = $this->queried_servable_post();
			if ( null === $post ) {
				return null;
			}

			$filepath = $this->generator->get_export_path( $post );
			if ( ! file_exists( $filepath ) ) {
				return null;
			}

			return add_query_arg( 'output_format', 'md', get_permalink( $post->ID ) );
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}

			$filepath = $this->taxonomy_generator->get_export_path( $term );
			if ( ! file_exists( $filepath ) ) {
				return null;
			}

			$term_link = get_term_link( $term );
			if ( is_wp_error( $term_link ) ) {
				return null;
			}

			return add_query_arg( 'output_format', 'md', $term_link );
		}

		return null;
	}

	/**
	 * Return the queried post when it may be served as Markdown, or null.
	 *
	 * Mirrors ExportPolicy::is_eligible() minus the post-type check, which is
	 * handled by is_eligible_singular() so the `markdown_for_agents_serve_post_types`
	 * filter keeps control of servable types.
	 *
	 * @since  1.6.0
	 * @return \WP_Post|null
	 */
	private function queried_servable_post(): ?\WP_Post {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return null;
		}

		if ( get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Check whether the current request is for a singular post of a configured type.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private function is_eligible_singular(): bool {
		$post_types = (array) ( $this->options['post_types'] ?? array() );

		/**
		 * Filter the post types eligible for Markdown serving.
		 *
		 * @since 1.1.0
		 * @param string[] $post_types Post type slugs from plugin settings.
		 */
		$post_types = (array) apply_filters( 'markdown_for_agents_serve_post_types', $post_types );

		return is_singular( $post_types );
	}

	/**
	 * Validate that a filepath stays within the configured export directory.
	 *
	 * Prevents path traversal before passing to readfile().
	 *
	 * @since  1.0.0
	 * @param  string $filepath The path to validate.
	 * @return bool
	 */
	private function is_safe_filepath( string $filepath ): bool {
		$export_dir = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );

		$real_base = realpath( $export_dir );
		$real_file = realpath( $filepath );

		if ( false === $real_base || false === $real_file ) {
			return false;
		}

		return str_starts_with( $real_file, $real_base . DIRECTORY_SEPARATOR );
	}

	/**
	 * Send HTTP headers and stream the Markdown file to the client.
	 *
	 * Emits cache-bypass headers so full-page caches (LiteSpeed, Varnish,
	 * nginx fastcgi_cache) do not store the Markdown response and replay it
	 * to subsequent HTML browser requests on the same URL.
	 *
	 * @since  1.1.0
	 * @param  string $filepath      Absolute path to the .md file.
	 * @param  string $access_method How the request was identified: 'query-param', 'accept-header' or 'ua'.
	 */
	private function send_markdown_file( string $filepath, string $access_method ): void {
		header( 'Content-Type: text/markdown; charset=utf-8' );

		/**
		 * Filter the cache-related headers sent with the Markdown response.
		 *
		 * The defaults prevent shared/full-page caches from storing the
		 * Markdown variant under the URL key and replaying it to HTML clients.
		 * Override with caution — but note the risk differs by access method:
		 * 'accept-header' and 'ua' responses share the page URL with the HTML
		 * representation, so allowing them to be cached risks a cache layer
		 * that ignores `Vary` serving Markdown to browsers. 'query-param'
		 * responses live on their own URL (`?output_format=md`) and therefore
		 * their own cache key, so they may safely be made cacheable. Map a
		 * header to an empty string to omit it entirely.
		 *
		 * @since 1.5.1
		 * @since 1.6.1 Added the `$access_method` parameter.
		 * @param array<string,string> $headers       Header name => value pairs.
		 * @param string               $filepath      Absolute path to the .md file.
		 * @param string               $access_method 'query-param', 'accept-header' or 'ua'.
		 */
		$cache_headers = apply_filters(
			'markdown_for_agents_cache_headers',
			array(
				'Cache-Control'             => 'private, no-store, max-age=0',
				'X-LiteSpeed-Cache-Control' => 'no-cache',
				'X-Accel-Expires'           => '0',
				'Vary'                      => 'Accept, User-Agent',
			),
			$filepath,
			$access_method
		);

		foreach ( (array) $cache_headers as $name => $value ) {
			if ( '' !== $value ) {
				header( $name . ': ' . $value );
			}
		}

		header( 'X-Markdown-Source: markdown-for-agents' );

		/**
		 * Filter the Content-Signal header value.
		 *
		 * Return an empty string to suppress the header entirely.
		 *
		 * @since 1.1.0
		 * @param string $signal The default signal value.
		 */
		$content_signal = preg_replace(
			'/[^\w=,\-\. ]/',
			'',
			(string) apply_filters( 'markdown_for_agents_content_signal', 'ai-input=yes, search=yes' )
		);

		if ( $content_signal ) {
			header( 'Content-Signal: ' . $content_signal );
		}

		readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Negotiate;

use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
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
		$accept    = $_SERVER['HTTP_ACCEPT'] ?? '';          // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$format_qp = sanitize_key( $_GET['output_format'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$matched_agent = $this->agent_detector->get_matched_agent( $ua );
		$via_accept    = str_contains( $accept, 'text/markdown' );
		$via_query     = in_array( $format_qp, array( 'md', 'markdown' ), true );

		if ( ! $via_accept && ! $via_query && null === $matched_agent ) {
			return;
		}

		$agent_label = $matched_agent ?? ( $via_accept ? 'accept-header' : 'query-param' );

		if ( $this->is_eligible_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
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
			if ( ! apply_filters( 'wp_mfa_serve_enabled', true, $post ) ) {
				return;
			}

			$filepath = $this->generator->get_export_path( $post );

			if ( ! file_exists( $filepath ) || ! $this->is_safe_filepath( $filepath ) ) {
				return;
			}

			$this->access_logger->log_access( $post->ID, $agent_label );
			$this->send_markdown_file( $filepath, $via_accept );
			return;
		}

		if ( is_tax() || is_category() || is_tag() ) {
			/**
			 * Whether to serve Markdown for taxonomy archive pages.
			 *
			 * @since 1.1.0
			 * @param bool $enabled Whether serving is enabled. Default true.
			 */
			if ( ! apply_filters( 'wp_mfa_serve_taxonomies', true ) ) {
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

			$this->send_markdown_file( $filepath, $via_accept );
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
		if ( $this->is_eligible_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
				return;
			}

			$filepath = $this->generator->get_export_path( $post );
			if ( ! file_exists( $filepath ) ) {
				return;
			}

			$url = esc_url( add_query_arg( 'output_format', 'md', get_permalink( $post->ID ) ) );
			echo '<link rel="alternate" type="text/markdown" href="' . $url . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term ) {
				return;
			}

			$filepath = $this->taxonomy_generator->get_export_path( $term );
			if ( ! file_exists( $filepath ) ) {
				return;
			}

			$term_link = get_term_link( $term );
			if ( is_wp_error( $term_link ) ) {
				return;
			}

			$url = esc_url( add_query_arg( 'output_format', 'md', $term_link ) );
			echo '<link rel="alternate" type="text/markdown" href="' . $url . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
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
		$post_types = (array) apply_filters( 'wp_mfa_serve_post_types', $post_types );

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
	 * @since  1.1.0
	 * @param  string $filepath   Absolute path to the .md file.
	 * @param  bool   $via_accept True when negotiated via Accept header (adds Vary).
	 */
	private function send_markdown_file( string $filepath, bool $via_accept ): void {
		header( 'Content-Type: text/markdown; charset=utf-8' );

		if ( $via_accept ) {
			header( 'Vary: Accept' );
		}

		header( 'X-Markdown-Source: wp-markdown-for-agents' );

		/**
		 * Filter the Content-Signal header value.
		 *
		 * Return an empty string to suppress the header entirely.
		 *
		 * @since 1.1.0
		 * @param string $signal The default signal value.
		 */
		$content_signal = str_replace(
			array( "\r", "\n" ),
			'',
			(string) apply_filters( 'wp_mfa_content_signal', 'ai-input=yes, search=yes' )
		);

		if ( $content_signal ) {
			header( 'Content-Signal: ' . $content_signal );
		}

		readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}

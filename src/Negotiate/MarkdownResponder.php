<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Negotiate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Stats\AccessLogger;

/**
 * Decides whether a request asks for Markdown, records it, and sends the file.
 *
 * Shared by the two routes that serve Markdown: Negotiator, at
 * template_redirect, and EarlyServer, before other plugins load. Keeping the
 * trigger rules, the statistics and the response headers here means the two
 * routes cannot drift apart.
 *
 * @since  1.8.0
 * @package Tclp\WpMarkdownForAgents\Negotiate
 */
class MarkdownResponder {

	/**
	 * X-Markdown-Source value for responses served at template_redirect.
	 */
	public const SOURCE = 'markdown-for-agents';

	/**
	 * @since  1.8.0
	 * @param  AgentDetector $agent_detector Detects known AI agent user-agents.
	 * @param  AccessLogger  $access_logger  Records agent access events for statistics.
	 */
	public function __construct(
		private readonly AgentDetector $agent_detector,
		private readonly AccessLogger $access_logger
	) {}

	/**
	 * How a request asks for Markdown, or null when it does not.
	 *
	 * Precedence: query-param > accept-header > ua. The User-Agent trigger
	 * applies only when UA serving is enabled (AgentDetector::get_matched_agent()).
	 *
	 * @since  1.8.0
	 * @param  AgentDetector $agent_detector Detector built from the plugin options.
	 * @param  string        $accept         Sanitised Accept header.
	 * @param  string        $ua             Sanitised User-Agent header.
	 * @param  string        $format_qp      Sanitised output_format query value.
	 * @return string|null 'query-param', 'accept-header', 'ua' or null.
	 */
	public static function access_method( AgentDetector $agent_detector, string $accept, string $ua, string $format_qp ): ?string {
		if ( in_array( $format_qp, array( 'md', 'markdown' ), true ) ) {
			return 'query-param';
		}

		if ( str_contains( $accept, 'text/markdown' ) ) {
			return 'accept-header';
		}

		return null !== $agent_detector->get_matched_agent( $ua ) ? 'ua' : null;
	}

	/**
	 * Record a served Markdown request in the access stats.
	 *
	 * Counts GET selections only, not HEAD probes or other HTTP methods.
	 *
	 * @since  1.7.3 As Negotiator::log_access().
	 * @since  1.8.0 Moved here.
	 * @param  int    $object_id     Post ID, or term ID for term archives.
	 * @param  string $object_type   StatsRepository::OBJECT_POST or StatsRepository::OBJECT_TERM.
	 * @param  string $ua            Sanitised User-Agent header.
	 * @param  string $method        HTTP request method.
	 * @param  string $access_method 'query-param', 'accept-header' or 'ua'.
	 */
	public function log_access( int $object_id, string $object_type, string $ua, string $method, string $access_method ): void {
		if ( 'GET' !== $method ) {
			return;
		}

		// Agent detection for stats: always tries UA match, ignores ua_force_enabled.
		// Falls back to normalised UA so query-param / accept-header requests still
		// record the caller's product name even when no known-agent pattern matches.
		$agent = $this->agent_detector->detect_agent( $ua ) ?? $this->agent_detector->normalise_ua( $ua );

		$this->access_logger->log_access( $object_id, $agent, $access_method, $object_type );
	}

	/**
	 * Send HTTP headers and stream the Markdown file to the client.
	 *
	 * Emits cache-bypass headers so full-page caches (LiteSpeed, Varnish,
	 * nginx fastcgi_cache) do not store the Markdown response and replay it
	 * to subsequent HTML browser requests on the same URL.
	 *
	 * @since  1.1.0 As Negotiator::send_markdown_file().
	 * @since  1.8.0 Moved here; $source added.
	 * @param  string      $filepath      Absolute path to the .md file.
	 * @param  string      $access_method How the request was identified: 'query-param', 'accept-header' or 'ua'.
	 * @param  string|null $canonical     The page's URL for the canonical `Link` header, or null for none.
	 * @param  string      $source        Value of the X-Markdown-Source header.
	 */
	public function send( string $filepath, string $access_method, ?string $canonical = null, string $source = self::SOURCE ): void {
		header( 'Content-Type: text/markdown; charset=utf-8' );

		/**
		 * Filter the cache-related headers sent with the Markdown response.
		 *
		 * Caches that respect these defaults do not store the negotiated
		 * Markdown variant. An overriding cache rule can defeat the headers.
		 * Override with caution — but note the risk differs by access method:
		 * 'accept-header' and 'ua' responses share the page URL with the HTML
		 * representation, so allowing them to be cached risks a cache layer
		 * that ignores `Vary` serving Markdown to browsers. 'query-param'
		 * responses have their own key only when every cache preserves
		 * output_format; verify that before making them cacheable. Map a
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

		header( 'X-Markdown-Source: ' . $source );

		if ( null !== $canonical && '' !== $canonical ) {
			header( 'Link: <' . $canonical . '>; rel="canonical"', false );
		}

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

		// Discard anything already buffered, such as whitespace a theme or
		// plugin printed outside its PHP tags, so the body starts with the
		// file's front matter. Output that has already been flushed to the
		// client cannot be recalled.
		for ( $level = ob_get_level(); $level > 0; $level-- ) {
			ob_end_clean();
		}

		readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}

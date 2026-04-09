<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Negotiate;

/**
 * Detects whether a User-Agent string belongs to a known LLM agent.
 *
 * Matching is case-insensitive substring. The list of substrings is
 * configured via the `ua_agent_strings` plugin option.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Negotiate
 */
class AgentDetector {

	/**
	 * @since  1.1.0
	 * @param  array<string, mixed> $options Plugin options.
	 */
	public function __construct( private readonly array $options ) {}

	/**
	 * Return the first matching UA substring regardless of ua_force_enabled.
	 *
	 * Use this for stats labelling. For the serving gate, use get_matched_agent().
	 *
	 * @since  1.2.0
	 * @param  string $ua The HTTP User-Agent header value.
	 * @return string|null The matched substring, or null.
	 */
	public function detect_agent( string $ua ): ?string {
		if ( '' === $ua ) {
			return null;
		}

		$substrings = (array) ( $this->options['ua_agent_strings'] ?? array() );

		foreach ( $substrings as $substring ) {
			if ( '' !== $substring && false !== stripos( $ua, $substring ) ) {
				return $substring;
			}
		}

		return null;
	}

	/**
	 * Return the first matching UA substring, or null if none matches.
	 *
	 * Returns null when ua_force_enabled is off — this controls whether a UA
	 * match alone triggers serving. For stats, use detect_agent() instead.
	 *
	 * @since  1.1.0
	 * @param  string $ua The HTTP User-Agent header value.
	 * @return string|null The matched substring, or null.
	 */
	public function get_matched_agent( string $ua ): ?string {
		if ( empty( $this->options['ua_force_enabled'] ) ) {
			return null;
		}

		return $this->detect_agent( $ua );
	}

	/**
	 * Extract a stable product name from a UA string for stats labelling.
	 *
	 * Returns the first token before the first '/' (e.g. 'LangChain' from
	 * 'LangChain/0.1.0'), making the label consistent across version changes.
	 * Returns the raw string if it contains no '/', and '' for empty input.
	 *
	 * @since  1.2.0
	 * @param  string $ua The HTTP User-Agent header value.
	 * @return string
	 */
	public function normalise_ua( string $ua ): string {
		if ( '' === $ua ) {
			return '';
		}

		if ( preg_match( '/^([^\/\s]+)/', $ua, $matches ) ) {
			return $matches[1];
		}

		return $ua;
	}

	/**
	 * Return true if the given UA string contains a known agent substring.
	 *
	 * Note: this method inherits the ua_force_enabled guard from get_matched_agent()
	 * and will return false when ua_force_enabled is off. For stats labelling, use
	 * detect_agent() instead.
	 *
	 * @since  1.1.0
	 * @param  string $ua The HTTP User-Agent header value.
	 * @return bool
	 */
	public function is_known_agent( string $ua ): bool {
		return null !== $this->get_matched_agent( $ua );
	}
}

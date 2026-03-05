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
     * Return true if the given UA string contains a known agent substring.
     *
     * @since  1.1.0
     * @param  string $ua The HTTP User-Agent header value.
     * @return bool
     */
    public function is_known_agent( string $ua ): bool {
        if ( empty( $this->options['ua_force_enabled'] ) ) {
            return false;
        }

        if ( '' === $ua ) {
            return false;
        }

        $substrings = (array) ( $this->options['ua_agent_strings'] ?? [] );

        foreach ( $substrings as $substring ) {
            if ( '' !== $substring && false !== stripos( $ua, $substring ) ) {
                return true;
            }
        }

        return false;
    }
}

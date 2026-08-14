<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Negotiate;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;

/**
 * @covers \Tclp\WpMarkdownForAgents\Negotiate\AgentDetector
 */
class AgentDetectorTest extends TestCase {

    private function make_detector( array $options = [] ): AgentDetector {
        return new AgentDetector( array_merge( [
            'ua_force_enabled' => true,
            'ua_agent_strings' => [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ],
        ], $options ) );
    }

    public function test_get_matched_agent_matches_any_entry_in_list(): void {
        $this->assertSame( 'ClaudeBot', $this->make_detector()->get_matched_agent( 'ClaudeBot/1.0 (+https://anthropic.com)' ) );
        $this->assertSame( 'PerplexityBot', $this->make_detector()->get_matched_agent( 'PerplexityBot/1.0' ) );
    }

    public function test_get_matched_agent_returns_null_when_agent_strings_list_is_empty(): void {
        $detector = $this->make_detector( [ 'ua_agent_strings' => [] ] );
        $this->assertNull( $detector->get_matched_agent( 'GPTBot/1.0' ) );
    }

    public function test_get_matched_agent_matches_substring_not_full_string(): void {
        $detector = $this->make_detector( [ 'ua_agent_strings' => [ 'ChatGPT-User' ] ] );
        $this->assertSame( 'ChatGPT-User', $detector->get_matched_agent( 'Mozilla/5.0 ChatGPT-User/1.0' ) );
    }

    public function test_get_matched_agent_returns_matched_substring(): void {
        $result = $this->make_detector()->get_matched_agent( 'GPTBot/1.0' );
        $this->assertSame( 'GPTBot', $result );
    }

    public function test_get_matched_agent_returns_null_for_unknown_ua(): void {
        $result = $this->make_detector()->get_matched_agent( 'Mozilla/5.0 Chrome/120' );
        $this->assertNull( $result );
    }

    public function test_get_matched_agent_is_case_insensitive(): void {
        $result = $this->make_detector()->get_matched_agent( 'gptbot/1.0' );
        $this->assertSame( 'GPTBot', $result );
    }

    // Validates that the ua_force_enabled guard is preserved after the get_matched_agent()
    // refactor to delegate to detect_agent().
    public function test_get_matched_agent_returns_null_when_disabled(): void {
        $detector = $this->make_detector( [ 'ua_force_enabled' => false ] );
        $result   = $detector->get_matched_agent( 'GPTBot/1.0' );
        $this->assertNull( $result );
    }

    public function test_get_matched_agent_returns_null_for_empty_ua(): void {
        $this->assertNull( $this->make_detector()->get_matched_agent( '' ) );
    }

    public function test_detect_agent_returns_match_when_ua_force_disabled(): void {
        $detector = $this->make_detector( [ 'ua_force_enabled' => false ] );
        $this->assertSame( 'GPTBot', $detector->detect_agent( 'GPTBot/1.0' ) );
    }

    public function test_detect_agent_returns_match_when_ua_force_enabled(): void {
        $this->assertSame( 'GPTBot', $this->make_detector()->detect_agent( 'GPTBot/1.0' ) );
    }

    public function test_detect_agent_returns_null_for_unknown_ua(): void {
        $this->assertNull( $this->make_detector()->detect_agent( 'Mozilla/5.0 Chrome/120' ) );
    }

    public function test_detect_agent_returns_null_for_empty_ua(): void {
        $this->assertNull( $this->make_detector()->detect_agent( '' ) );
    }

    public function test_detect_agent_matching_is_case_insensitive(): void {
        $this->assertSame( 'GPTBot', $this->make_detector()->detect_agent( 'gptbot/1.0' ) );
        $this->assertSame( 'GPTBot', $this->make_detector()->detect_agent( 'GPTBOT/1.0' ) );
    }

    // -----------------------------------------------------------------------
    // normalise_ua
    // -----------------------------------------------------------------------

    public function test_normalise_ua_strips_version_from_product_slash_version(): void {
        $this->assertSame( 'LangChain', $this->make_detector()->normalise_ua( 'LangChain/0.1.0' ) );
    }

    public function test_normalise_ua_strips_version_from_hyphenated_product(): void {
        $this->assertSame( 'python-openai', $this->make_detector()->normalise_ua( 'python-openai/1.2.3' ) );
    }

    public function test_normalise_ua_returns_bare_product_with_no_slash(): void {
        $this->assertSame( 'curl', $this->make_detector()->normalise_ua( 'curl' ) );
    }

    public function test_normalise_ua_returns_first_token_for_mozilla_ua(): void {
        $this->assertSame( 'Mozilla', $this->make_detector()->normalise_ua( 'Mozilla/5.0 (compatible; MyBot/1.0)' ) );
    }

    public function test_normalise_ua_returns_empty_string_for_empty_input(): void {
        $this->assertSame( '', $this->make_detector()->normalise_ua( '' ) );
    }

    // -----------------------------------------------------------------------
    // categorise_agent
    // -----------------------------------------------------------------------

    public function test_categorise_agent_returns_training_for_crawler(): void {
        $this->assertSame( 'training', $this->make_detector()->categorise_agent( 'GPTBot' ) );
        $this->assertSame( 'training', $this->make_detector()->categorise_agent( 'ClaudeBot' ) );
        $this->assertSame( 'training', $this->make_detector()->categorise_agent( 'CCBot' ) );
    }

    public function test_categorise_agent_returns_search_for_indexer(): void {
        $this->assertSame( 'search', $this->make_detector()->categorise_agent( 'PerplexityBot' ) );
        $this->assertSame( 'search', $this->make_detector()->categorise_agent( 'OAI-SearchBot' ) );
    }

    public function test_categorise_agent_returns_on_demand_for_human_triggered(): void {
        $this->assertSame( 'on-demand', $this->make_detector()->categorise_agent( 'ChatGPT-User' ) );
        $this->assertSame( 'on-demand', $this->make_detector()->categorise_agent( 'Claude-User' ) );
        $this->assertSame( 'on-demand', $this->make_detector()->categorise_agent( 'Perplexity-User' ) );
    }

    public function test_categorise_agent_returns_unknown_for_unmapped_agent(): void {
        $this->assertSame( 'unknown', $this->make_detector()->categorise_agent( 'SomeRandomBot' ) );
    }

    public function test_categorise_agent_returns_unknown_for_empty_string(): void {
        $this->assertSame( 'unknown', $this->make_detector()->categorise_agent( '' ) );
    }

    public function test_categorise_agent_is_case_insensitive(): void {
        $this->assertSame( 'training', $this->make_detector()->categorise_agent( 'gptbot' ) );
        $this->assertSame( 'on-demand', $this->make_detector()->categorise_agent( 'chatgpt-user' ) );
    }

    public function test_categorise_agent_on_demand_takes_precedence_over_search(): void {
        // Perplexity-User (on-demand) must not be swallowed by a PerplexityBot (search) substring rule.
        $this->assertSame( 'on-demand', $this->make_detector()->categorise_agent( 'Perplexity-User' ) );
    }

    public function test_categorise_agent_respects_filter_override(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_agent_categories'] = static fn( array $map ): array => array(
            'training' => array( 'MyCustomBot' ),
        );

        $this->assertSame( 'training', $this->make_detector()->categorise_agent( 'MyCustomBot' ) );
        // A default mapping is gone once the filter replaces the map.
        $this->assertSame( 'unknown', $this->make_detector()->categorise_agent( 'GPTBot' ) );

        unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_agent_categories'] );
    }

    // ---------------------------------------------------------------------
    // Stats continuity guarantees
    // ---------------------------------------------------------------------

    /**
     * Every shipped UA string must resolve to a real category.
     *
     * Detection and categorisation are two separate lists; when they drift, an
     * agent is either served Markdown but filed under "Unknown" in the stats,
     * or categorised but never detectable in the first place.
     */
    public function test_every_default_ua_string_is_categorised(): void {
        $detector = new AgentDetector( \Tclp\WpMarkdownForAgents\Core\Options::get_defaults() );

        foreach ( \Tclp\WpMarkdownForAgents\Core\Options::get_defaults()['ua_agent_strings'] as $string ) {
            $this->assertNotSame(
                'unknown',
                $detector->categorise_agent( $string ),
                sprintf( 'UA string "%s" is detected but has no category mapping.', $string )
            );
        }
    }

    /**
     * Categorising is derived from labels already stored in the stats table, so
     * dropping a mapping rewrites history into "Unknown".
     */
    public function test_categorise_agent_still_maps_every_legacy_agent(): void {
        $legacy = [
            'ChatGPT-User'       => 'on-demand',
            'Claude-User'        => 'on-demand',
            'Claude-Web'         => 'on-demand',
            'Perplexity-User'    => 'on-demand',
            'Gemini-User'        => 'on-demand',
            'OAI-SearchBot'      => 'search',
            'PerplexityBot'      => 'search',
            'Applebot-Extended'  => 'search',
            'GPTBot'             => 'training',
            'ClaudeBot'          => 'training',
            'CCBot'              => 'training',
            'Google-Extended'    => 'training',
            'Bytespider'         => 'training',
            'meta-externalagent' => 'training',
            'Amazonbot'          => 'training',
            'cohere-ai'          => 'training',
            'anthropic-ai'       => 'training',
        ];

        foreach ( $legacy as $agent => $expected ) {
            $this->assertSame( $expected, $this->make_detector()->categorise_agent( $agent ), $agent );
        }
    }

    /**
     * A stored label must stay stable across releases, so no shipped UA string
     * may be a substring of another — the first match wins in detect_agent(),
     * so an overlapping pair would relabel one of the bots mid-history and
     * split its stats into two series.
     */
    public function test_no_default_ua_string_shadows_another(): void {
        $strings = \Tclp\WpMarkdownForAgents\Core\Options::get_defaults()['ua_agent_strings'];

        foreach ( $strings as $a ) {
            foreach ( $strings as $b ) {
                if ( $a === $b ) {
                    continue;
                }

                $this->assertFalse(
                    stripos( $a, $b ) !== false,
                    sprintf( 'UA string "%s" contains "%s"; whichever is listed first wins and the label becomes unstable.', $a, $b )
                );
            }
        }
    }
}

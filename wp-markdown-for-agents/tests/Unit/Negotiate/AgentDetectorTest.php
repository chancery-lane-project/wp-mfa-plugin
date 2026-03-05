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

    public function test_returns_false_when_ua_force_disabled(): void {
        $detector = $this->make_detector( [ 'ua_force_enabled' => false ] );
        $this->assertFalse( $detector->is_known_agent( 'GPTBot/1.0' ) );
    }

    public function test_returns_false_for_empty_ua_string(): void {
        $this->assertFalse( $this->make_detector()->is_known_agent( '' ) );
    }

    public function test_returns_false_for_unknown_ua(): void {
        $this->assertFalse( $this->make_detector()->is_known_agent( 'Mozilla/5.0 Chrome/120' ) );
    }

    public function test_matches_known_agent_substring(): void {
        $this->assertTrue( $this->make_detector()->is_known_agent( 'GPTBot/1.0' ) );
    }

    public function test_matching_is_case_insensitive(): void {
        $this->assertTrue( $this->make_detector()->is_known_agent( 'gptbot/1.0' ) );
        $this->assertTrue( $this->make_detector()->is_known_agent( 'GPTBOT/1.0' ) );
    }

    public function test_matches_any_entry_in_list(): void {
        $this->assertTrue( $this->make_detector()->is_known_agent( 'ClaudeBot/1.0 (+https://anthropic.com)' ) );
        $this->assertTrue( $this->make_detector()->is_known_agent( 'PerplexityBot/1.0' ) );
    }

    public function test_returns_false_when_agent_strings_list_is_empty(): void {
        $detector = $this->make_detector( [ 'ua_agent_strings' => [] ] );
        $this->assertFalse( $detector->is_known_agent( 'GPTBot/1.0' ) );
    }

    public function test_matches_substring_not_full_string(): void {
        $detector = $this->make_detector( [ 'ua_agent_strings' => [ 'ChatGPT-User' ] ] );
        $this->assertTrue( $detector->is_known_agent( 'Mozilla/5.0 ChatGPT-User/1.0' ) );
    }
}

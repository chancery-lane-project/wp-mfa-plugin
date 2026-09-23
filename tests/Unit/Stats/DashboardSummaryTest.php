<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
use Tclp\WpMarkdownForAgents\Stats\DashboardSummary;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\DashboardSummary
 */
class DashboardSummaryTest extends TestCase {

    private DashboardSummary $summary;

    protected function setUp(): void {
        $this->summary = new DashboardSummary( new AgentDetector( [ 'ua_agent_strings' => [] ] ) );
    }

    private function row( string $agent, int $total, string $date = '2026-09-20' ): object {
        return (object) [ 'access_date' => $date, 'agent' => $agent, 'total' => $total ];
    }

    private function post( int $id, int $total ): object {
        return (object) [ 'post_id' => $id, 'total' => $total ];
    }

    private function operator_totals( array $result ): array {
        return array_column( $result['operators'], 'total', 'key' );
    }

    public function test_operator_cards_reconcile_with_total_including_unattributed(): void {
        $result = $this->summary->build( [
            $this->row( 'GPTBot', 40 ),
            $this->row( 'ChatGPT-User', 7 ),
            $this->row( 'ClaudeBot', 12 ),
            $this->row( 'Mozilla', 5 ),
            $this->row( '', 3 ),
            $this->row( 'accept-header', 2 ),
        ], [], 50 );

        $this->assertSame( 69, $result['total'] );
        $this->assertSame( $result['total'], array_sum( $this->operator_totals( $result ) ) );
        $this->assertSame( [ 'openai' => 47, 'anthropic' => 12, 'unattributed' => 10 ], $this->operator_totals( $result ) );
    }

    public function test_each_label_counts_once_across_days(): void {
        $result = $this->summary->build( [
            $this->row( 'GPTBot', 4, '2026-09-20' ),
            $this->row( 'GPTBot', 6, '2026-09-21' ),
        ], [], 50 );

        $this->assertSame( 10, $result['total'] );
        $this->assertCount( 1, $result['operators'] );
        $this->assertSame( [ [ 'label' => 'GPTBot', 'total' => 10 ] ], $result['operators'][0]['agents'] );
    }

    public function test_unattributed_card_is_last_even_when_largest(): void {
        $result = $this->summary->build( [
            $this->row( 'curl', 500 ),
            $this->row( 'ClaudeBot', 3 ),
            $this->row( 'GPTBot', 9 ),
        ], [], 50 );

        $this->assertSame( [ 'openai', 'anthropic', 'unattributed' ], array_column( $result['operators'], 'key' ) );
    }

    public function test_operators_with_equal_totals_are_ordered_by_name(): void {
        $result = $this->summary->build( [
            $this->row( 'PerplexityBot', 5 ),
            $this->row( 'ClaudeBot', 5 ),
        ], [], 50 );

        $this->assertSame( [ 'anthropic', 'perplexity' ], array_column( $result['operators'], 'key' ) );
    }

    public function test_operator_card_lists_top_agents_then_more_count(): void {
        $rows = [];
        foreach ( [ 'a1' => 9, 'a2' => 8, 'a3' => 7, 'a4' => 6, 'a5' => 5, 'a6' => 4, 'a7' => 3 ] as $label => $total ) {
            $rows[] = $this->row( $label, $total );
        }

        $card = $this->summary->build( $rows, [], 50 )['operators'][0];

        $this->assertSame( 'unattributed', $card['key'] );
        $this->assertSame( [ 'a1', 'a2', 'a3', 'a4', 'a5' ], array_column( $card['agents'], 'label' ) );
        $this->assertSame( 2, $card['more'] );
        $this->assertSame( 42, $card['total'] );
    }

    public function test_leaders_for_a_clear_winner(): void {
        $result = $this->summary->build(
            [ $this->row( 'GPTBot', 40 ), $this->row( 'ClaudeBot', 12 ) ],
            [ $this->post( 7, 30 ), $this->post( 3, 22 ) ],
            50
        );

        $this->assertSame( [ 'total' => 30, 'items' => [ 7 ], 'count' => 1, 'capped' => false ], $result['top_page'] );
        $this->assertSame( [ 'total' => 40, 'items' => [ 'GPTBot' ], 'count' => 1, 'capped' => false ], $result['top_agent'] );
        $this->assertSame( [ 'total' => 40, 'items' => [ 'openai' ], 'count' => 1, 'capped' => false ], $result['top_operator'] );
    }

    public function test_tied_leaders_report_count_and_first_names(): void {
        $result = $this->summary->build(
            [
                $this->row( 'PerplexityBot', 5 ),
                $this->row( 'GPTBot', 5 ),
                $this->row( 'ClaudeBot', 5 ),
                $this->row( 'Bytespider', 5 ),
                $this->row( 'CCBot', 1 ),
            ],
            [ $this->post( 2, 4 ), $this->post( 5, 4 ), $this->post( 9, 1 ) ],
            50
        );

        $this->assertSame( 4, $result['top_agent']['count'] );
        $this->assertSame( [ 'Bytespider', 'ClaudeBot', 'GPTBot' ], $result['top_agent']['items'] );

        // Operator ties are ordered by display name, not key.
        $this->assertSame( 4, $result['top_operator']['count'] );
        $this->assertSame( [ 'anthropic', 'bytedance', 'openai' ], $result['top_operator']['items'] );

        $this->assertSame( [ 'total' => 4, 'items' => [ 2, 5 ], 'count' => 2, 'capped' => false ], $result['top_page'] );
    }

    public function test_page_tie_filling_the_scan_is_capped(): void {
        $posts = [];
        for ( $id = 1; $id <= 5; $id++ ) {
            $posts[] = $this->post( $id, 1 );
        }

        $leader = $this->summary->build( [ $this->row( 'GPTBot', 5 ) ], $posts, 5 )['top_page'];

        $this->assertSame( 5, $leader['count'] );
        $this->assertTrue( $leader['capped'] );
        $this->assertSame( [ 1, 2, 3 ], $leader['items'] );
    }

    public function test_non_agent_labels_never_lead_but_stay_counted(): void {
        $result = $this->summary->build( [
            $this->row( '', 90 ),
            $this->row( 'accept-header', 80 ),
            $this->row( 'query-param', 70 ),
            $this->row( 'GPTBot', 1 ),
        ], [], 50 );

        $this->assertSame( [ 'GPTBot' ], $result['top_agent']['items'] );
        $this->assertSame( 241, $result['total'] );
    }

    public function test_unattributed_never_leads_operators(): void {
        $result = $this->summary->build( [ $this->row( 'curl', 9 ) ], [], 50 );

        $this->assertNull( $result['top_operator'] );
        $this->assertSame( [ 'curl' ], $result['top_agent']['items'] );
    }

    public function test_empty_report(): void {
        $result = $this->summary->build( [], [], 50 );

        $this->assertSame( 0, $result['total'] );
        $this->assertSame( [], $result['operators'] );
        $this->assertNull( $result['top_page'] );
        $this->assertNull( $result['top_agent'] );
        $this->assertNull( $result['top_operator'] );
    }

    public function test_numeric_agent_labels_stay_strings(): void {
        $card = $this->summary->build( [ $this->row( '123', 2 ) ], [], 50 )['operators'][0];

        $this->assertSame( '123', $card['agents'][0]['label'] );
    }
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;

/**
 * Builds the statistics dashboard summary and operator cards.
 *
 * Pure aggregation over rows the stats page has already fetched, so every
 * figure reconciles with the chart and table for the same filters: the total
 * is the sum of the daily rows, and each request lands in exactly one operator
 * card (reviewed operator or the explicit unattributed bucket).
 *
 * @since  1.7.2
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class DashboardSummary {

	/** Operator key for records without reviewed operator metadata. */
	public const UNATTRIBUTED = 'unattributed';

	/** Individual agents listed on each operator card before "+N more". */
	private const AGENTS_PER_CARD = 5;

	/** Names listed for a tied leader before the rest are summarised. */
	private const LEADER_NAMES = 3;

	/**
	 * Stored labels that record the access method rather than an agent (pre-1.3.0
	 * rows). They stay in totals and the unattributed card but never lead.
	 */
	private const NON_AGENT_LABELS = array( '', 'accept-header', 'query-param' );

	/**
	 * @since  1.7.2
	 * @param  AgentDetector $agent_detector Resolves agent labels to operators.
	 */
	public function __construct( private readonly AgentDetector $agent_detector ) {}

	/**
	 * Build the summary payload.
	 *
	 * Leaders are null when nothing qualifies. A tied leader reports how many
	 * items share the top total and lists the first few by name, rather than
	 * picking one arbitrarily.
	 *
	 * @since  1.7.2
	 * @param  array<int, object> $daily_rows      Rows from StatsRepository::get_daily_agent_totals()
	 *                                             (agent, total) for the report filters.
	 * @param  array<int, object> $post_rows       Rows from StatsRepository::get_post_totals()
	 *                                             (post_id, total), ordered by total descending.
	 * @param  int                $post_scan_limit The limit passed to get_post_totals(), so a tie
	 *                                             that fills every row is reported as capped.
	 * @return array{
	 *     total: int,
	 *     top_page: array{total: int, items: list<int>, count: int, capped: bool}|null,
	 *     top_agent: array{total: int, items: list<string>, count: int, capped: bool}|null,
	 *     top_operator: array{total: int, items: list<string>, count: int, capped: bool}|null,
	 *     operators: list<array{key: string, label: string, total: int, agents: list<array{label: string, total: int}>, more: int}>
	 * }
	 */
	public function build( array $daily_rows, array $post_rows, int $post_scan_limit ): array {
		$agent_totals = array();
		foreach ( $daily_rows as $row ) {
			$label                  = (string) ( $row->agent ?? '' );
			$agent_totals[ $label ] = ( $agent_totals[ $label ] ?? 0 ) + (int) ( $row->total ?? 0 );
		}

		// Group agents under their operator; each label resolves to exactly one bucket.
		$groups = array();
		foreach ( $agent_totals as $label => $value ) {
			$key = $this->agent_detector->get_operator( (string) $label ) ?? self::UNATTRIBUTED;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'total'  => 0,
					'agents' => array(),
				);
			}
			$groups[ $key ]['total']                     += $value;
			$groups[ $key ]['agents'][ (string) $label ] = $value;
		}

		$operators = array();
		foreach ( $groups as $key => $group ) {
			$key    = (string) $key;
			$agents = $this->rank( $group['agents'] );

			$operators[] = array(
				'key'    => $key,
				'label'  => self::UNATTRIBUTED === $key ? '' : $this->agent_detector->get_operator_label( $key ),
				'total'  => $group['total'],
				'agents' => array_map(
					// Keys may be ints: PHP converts numeric-string labels on insert.
					fn( $label ) => array(
						'label' => (string) $label,
						'total' => $agents[ $label ],
					),
					array_slice( array_keys( $agents ), 0, self::AGENTS_PER_CARD )
				),
				'more'   => max( 0, count( $agents ) - self::AGENTS_PER_CARD ),
			);
		}

		// Largest first, name as tie-break; the unattributed bucket always last.
		usort(
			$operators,
			function ( array $a, array $b ): int {
				$a_unattributed = self::UNATTRIBUTED === $a['key'];
				$b_unattributed = self::UNATTRIBUTED === $b['key'];
				if ( $a_unattributed !== $b_unattributed ) {
					return $a_unattributed ? 1 : -1;
				}

				return array( $b['total'], strtolower( $a['label'] ) ) <=> array( $a['total'], strtolower( $b['label'] ) );
			}
		);

		$leading_agents = array_diff_key( $agent_totals, array_flip( self::NON_AGENT_LABELS ) );

		$leading_operators = array();
		foreach ( $operators as $operator ) {
			if ( self::UNATTRIBUTED !== $operator['key'] ) {
				$leading_operators[ $operator['key'] ] = $operator['total'];
			}
		}

		return array(
			'total'        => (int) array_sum( $agent_totals ),
			'top_page'     => $this->page_leader( $post_rows, $post_scan_limit ),
			'top_agent'    => $this->leader( $leading_agents ),
			'top_operator' => $this->leader(
				$leading_operators,
				fn( string $key ) => $this->agent_detector->get_operator_label( $key )
			),
			'operators'    => $operators,
		);
	}

	/**
	 * Sort a label => total map by total descending, then label (case-insensitive).
	 *
	 * @param  array<string, int> $totals
	 * @return array<string, int>
	 */
	private function rank( array $totals ): array {
		uksort(
			$totals,
			fn( $a, $b ) => array( $totals[ $b ], strtolower( (string) $a ) ) <=> array( $totals[ $a ], strtolower( (string) $b ) )
		);

		return $totals;
	}

	/**
	 * Find the leader(s) of a key => total map, ignoring zero totals.
	 *
	 * @param  array<string, int>            $totals
	 * @param  (callable(string): string)|null $name Display name used to order tied items.
	 * @return array{total: int, items: list<string>, count: int, capped: bool}|null
	 */
	private function leader( array $totals, ?callable $name = null ): ?array {
		$totals = array_filter( $totals, fn( int $value ) => $value > 0 );
		if ( empty( $totals ) ) {
			return null;
		}

		$max  = max( $totals );
		$tied = array_map( 'strval', array_keys( array_filter( $totals, fn( int $value ) => $value === $max ) ) );
		usort(
			$tied,
			fn( string $a, string $b ) => strcasecmp( null !== $name ? $name( $a ) : $a, null !== $name ? $name( $b ) : $b )
		);

		return array(
			'total'  => $max,
			'items'  => array_slice( $tied, 0, self::LEADER_NAMES ),
			'count'  => count( $tied ),
			'capped' => false,
		);
	}

	/**
	 * Find the most-requested page(s) from rows ordered by total descending.
	 *
	 * @param  array<int, object> $post_rows
	 * @param  int                $scan_limit Row limit used by the query.
	 * @return array{total: int, items: list<int>, count: int, capped: bool}|null
	 */
	private function page_leader( array $post_rows, int $scan_limit ): ?array {
		$max  = 0;
		$tied = array();
		foreach ( $post_rows as $row ) {
			$value = (int) ( $row->total ?? 0 );
			if ( $value <= 0 || ( $max > 0 && $value < $max ) ) {
				break;
			}
			$max    = $value;
			$tied[] = (int) ( $row->post_id ?? 0 );
		}

		if ( empty( $tied ) ) {
			return null;
		}

		return array(
			'total'  => $max,
			'items'  => array_slice( $tied, 0, self::LEADER_NAMES ),
			'count'  => count( $tied ),
			'capped' => count( $tied ) >= $scan_limit,
		);
	}
}

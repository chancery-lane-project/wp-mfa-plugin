<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;

/**
 * Admin page for displaying agent access statistics.
 *
 * Registered as a top-level menu item. Shows a headline summary and operator
 * cards, then an intent-aware daily chart and stat cards, above a filterable,
 * paginated table of daily access counts. Every section reads the same filters.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class StatsPage {

	private const PAGE_SLUG = 'markdown-for-agents-stats';
	private const PER_PAGE  = 50;

	/** Report window when no explicit date range is set (days, inclusive, ending today UTC). */
	private const DEFAULT_RANGE_DAYS = 7;

	/** Above this span (days) the chart switches from daily to monthly bars. */
	private const CHART_DAY_SPAN_MAX = 92;

	/** Above this span (days, ~5 years) the chart switches from monthly to yearly bars. */
	private const CHART_MONTH_SPAN_MAX = 1827;

	/** Hard cap on daily bars to keep the SVG bounded (backstop; never hit by the day grain). */
	private const CHART_MAX_DAYS = 366;

	/** Hard cap on monthly bars (backstop; never hit by the month grain). */
	private const CHART_MAX_MONTHS = 66;

	/** Hard cap on yearly bars (backstop for absurdly long histories). */
	private const CHART_MAX_YEARS = 120;

	/** Rows scanned for the most-requested page; a tie filling them all reads "50+". */
	private const TOP_POSTS_SCAN = 50;

	/**
	 * Intent categories shown as series/cards, with brand display colour.
	 *
	 * Stacked bottom→top (training, search, on-demand, unknown), so volume-heavy
	 * background crawling forms the base in a muted navy tint, the headline
	 * human-intent reads pop in brand magenta, and unknown caps each bar as a
	 * muted remainder. Unknown sits on top rather than the base because its grey
	 * is too close to the training tint to separate when adjacent; every
	 * category is plotted so each bar's height equals the summary total.
	 */
	private const CATEGORY_COLORS = array(
		'training'  => '#B3B8C8', // Navy tint (muted, back layer).
		'search'    => '#1C2B58', // Brand primary navy.
		'on-demand' => '#D7288B', // Brand secondary magenta (headline).
		'unknown'   => '#D9DCE3', // Light neutral navy-grey.
	);

	/**
	 * @since  1.1.0
	 * @param  StatsRepository $repository     Stats query layer.
	 * @param  AgentDetector   $agent_detector Classifies agent labels by intent.
	 */
	public function __construct(
		private readonly StatsRepository $repository,
		private readonly AgentDetector $agent_detector
	) {}

	/**
	 * Register the admin menu page.
	 *
	 * @since  1.1.0
	 */
	public function add_page(): void {
		add_menu_page(
			__( 'Agent Access Statistics', 'markdown-for-agents-and-statistics' ),
			__( 'Agent Stats', 'markdown-for-agents-and-statistics' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-chart-bar'
		);
	}

	/**
	 * Render the stats page.
	 *
	 * @since  1.1.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filter_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;    // phpcs:ignore WordPress.Security.NonceVerification
		$filter_agent         = isset( $_GET['agent'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['agent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$filter_access_method = isset( $_GET['access_method'] ) ? sanitize_key( (string) $_GET['access_method'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$filter_operator      = isset( $_GET['operator'] ) ? sanitize_key( (string) $_GET['operator'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;             // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' !== $filter_operator && DashboardSummary::UNATTRIBUTED !== $filter_operator && ! array_key_exists( $filter_operator, $this->agent_detector->get_agent_operators() ) ) {
			$filter_operator = '';
		}

		// Operator of every recorded label. One distinct-labels query serves the
		// operator filter, the agent dropdown and the card links.
		$agents          = array_map( 'strval', $this->repository->get_distinct_agents() );
		$agent_operators = array();
		foreach ( $agents as $agent ) {
			$agent_operators[ $agent ] = $this->operator_of( $agent );
		}

		$agent_options = $agents;
		if ( '' !== $filter_operator ) {
			$agent_options = array_values(
				array_filter( $agents, fn( string $agent ) => $agent_operators[ $agent ] === $filter_operator )
			);
			// An agent run by a different operator can never match; the operator wins
			// so the report is never silently empty.
			if ( '' !== $filter_agent && $this->operator_of( $filter_agent ) !== $filter_operator ) {
				$filter_agent = '';
			}
		}

		// Window anchors (UTC).
		$today       = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$seven_ago   = ( clone $today )->modify( '-' . ( self::DEFAULT_RANGE_DAYS - 1 ) . ' days' );
		$thirty_ago  = ( clone $today )->modify( '-29 days' );
		$month_start = ( clone $today )->modify( 'first day of this month' );
		$today_str   = $today->format( 'Y-m-d' );

		// "All time" is an explicit choice via ?range=all; otherwise the page
		// defaults to the last 7 days so the chart and table always agree.
		$is_all_time = isset( $_GET['range'] ) && 'all' === sanitize_key( (string) $_GET['range'] ); // phpcs:ignore WordPress.Security.NonceVerification

		$date_from = '';
		if ( isset( $_GET['date_from'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$raw = sanitize_text_field( wp_unslash( (string) $_GET['date_from'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$dt = \DateTime::createFromFormat( 'Y-m-d', $raw );
			if ( false !== $dt && $dt->format( 'Y-m-d' ) === $raw ) {
				$date_from = $raw;
			}
		}
		$date_to = '';
		if ( isset( $_GET['date_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$raw = sanitize_text_field( wp_unslash( (string) $_GET['date_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$dt = \DateTime::createFromFormat( 'Y-m-d', $raw );
			if ( false !== $dt && $dt->format( 'Y-m-d' ) === $raw ) {
				$date_to = $raw;
			}
		}

		if ( $is_all_time ) {
			// All time spans the full history; clear any stray date bounds.
			$date_from = '';
			$date_to   = '';
		} elseif ( '' === $date_from && '' === $date_to ) {
			// Default view: last 7 days (the "Last 7 days" preset).
			$date_from = $seven_ago->format( 'Y-m-d' );
			$date_to   = $today_str;
		}

		$count_filters = array();
		if ( $filter_post_id > 0 ) {
			$count_filters['post_id'] = $filter_post_id;
		}
		if ( '' !== $filter_agent ) {
			$count_filters['agent'] = $filter_agent;
		}
		if ( DashboardSummary::UNATTRIBUTED === $filter_operator ) {
			$attributed = array_values(
				array_filter( $agents, fn( string $agent ) => DashboardSummary::UNATTRIBUTED !== $agent_operators[ $agent ] )
			);
			if ( ! empty( $attributed ) ) {
				$count_filters['agents_not_in'] = $attributed;
			}
		} elseif ( '' !== $filter_operator ) {
			$count_filters['agents_in'] = $agent_options;
		}
		if ( '' !== $filter_access_method ) {
			$count_filters['access_method'] = $filter_access_method;
		}
		if ( '' !== $date_from ) {
			$count_filters['date_from'] = $date_from;
		}
		if ( '' !== $date_to ) {
			$count_filters['date_to'] = $date_to;
		}

		$filters           = $count_filters;
		$filters['limit']  = self::PER_PAGE;
		$filters['offset'] = ( $paged - 1 ) * self::PER_PAGE;

		// Preset links. 7d/30d/month clear the all-time flag; All time clears dates.
		$without_range = remove_query_arg( 'range' );
		$preset_7d     = add_query_arg( array( 'date_from' => $seven_ago->format( 'Y-m-d' ), 'date_to' => $today_str, 'paged' => 1 ), $without_range );
		$preset_30d    = add_query_arg( array( 'date_from' => $thirty_ago->format( 'Y-m-d' ), 'date_to' => $today_str, 'paged' => 1 ), $without_range );
		$preset_month  = add_query_arg( array( 'date_from' => $month_start->format( 'Y-m-d' ), 'date_to' => $today_str, 'paged' => 1 ), $without_range );
		$preset_all    = add_query_arg( array( 'range' => 'all', 'paged' => 1 ), remove_query_arg( array( 'date_from', 'date_to' ) ) );

		$active_all   = $is_all_time;
		$active_7d    = ( ! $is_all_time && $date_from === $seven_ago->format( 'Y-m-d' ) && $date_to === $today_str );
		$active_30d   = ( ! $is_all_time && $date_from === $thirty_ago->format( 'Y-m-d' ) && $date_to === $today_str );
		$active_month = ( ! $is_all_time && $date_from === $month_start->format( 'Y-m-d' ) && $date_to === $today_str );

		$rows        = $this->repository->get_stats( $filters );
		$total       = $this->repository->get_total_count( $count_filters );
		$posts       = $this->repository->get_posts_with_stats();
		$total_pages = (int) ceil( $total / self::PER_PAGE );

		// One per-day, per-agent fetch feeds the summary, operator cards and chart,
		// so they reconcile by construction; totals ignore table pagination.
		$daily_rows = (array) $this->repository->get_daily_agent_totals( $count_filters );
		$post_rows  = (array) $this->repository->get_post_totals( $count_filters, self::TOP_POSTS_SCAN );
		$dashboard  = ( new DashboardSummary( $this->agent_detector ) )->build( $daily_rows, $post_rows, self::TOP_POSTS_SCAN );

		// Intent-aware chart. Aligns with the table window; all-time spans the full
		// history. Grain (daily vs monthly) adapts to the span inside build_chart_data().
		$chart = $this->build_chart_data(
			$daily_rows,
			$is_all_time ? null : $date_from,
			$is_all_time ? null : $date_to
		);

		$range_label = $this->range_label( $date_from, $date_to );

		// Summary tile bodies; leader_html() escapes everything it returns.
		$leader_tiles = array(
			'page'     => $this->leader_html(
				$dashboard['top_page'],
				'page',
				fn( $id ) => $this->post_label( (int) $id ),
				fn( $id ) => $this->filter_url( array( 'post_id' => (int) $id ) )
			),
			'agent'    => $this->leader_html(
				$dashboard['top_agent'],
				'agent',
				fn( $label ) => $this->agent_label( (string) $label ),
				fn( $label ) => $this->filter_url( array( 'agent' => (string) $label ) )
			),
			'operator' => $this->leader_html(
				$dashboard['top_operator'],
				'operator',
				fn( $key ) => $this->operator_name( (string) $key ),
				fn( $key ) => $this->operator_url( (string) $key, $filter_operator, $filter_agent )
			),
		);

		// Operators offered in the filter form: any seen in recorded labels, plus
		// the unattributed bucket and whichever is currently selected.
		$operator_options = array();
		foreach ( array_unique( array_merge( array_values( $agent_operators ), array( $filter_operator ) ) ) as $key ) {
			if ( '' !== $key && DashboardSummary::UNATTRIBUTED !== $key ) {
				$operator_options[ $key ] = $this->operator_name( $key );
			}
		}
		asort( $operator_options, SORT_FLAG_CASE | SORT_STRING );
		$operator_options[ DashboardSummary::UNATTRIBUTED ] = $this->operator_name( DashboardSummary::UNATTRIBUTED );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Access Statistics', 'markdown-for-agents-and-statistics' ); ?></h1>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( $preset_all ); ?>"<?php echo $active_all ? ' class="current"' : ''; ?>><?php esc_html_e( 'All time', 'markdown-for-agents-and-statistics' ); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url( $preset_7d ); ?>"<?php echo $active_7d ? ' class="current"' : ''; ?>><?php esc_html_e( 'Last 7 days', 'markdown-for-agents-and-statistics' ); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url( $preset_30d ); ?>"<?php echo $active_30d ? ' class="current"' : ''; ?>><?php esc_html_e( 'Last 30 days', 'markdown-for-agents-and-statistics' ); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url( $preset_month ); ?>"<?php echo $active_month ? ' class="current"' : ''; ?>><?php esc_html_e( 'This month', 'markdown-for-agents-and-statistics' ); ?></a>
				</li>
			</ul>

			<form method="get" action="">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<div class="tablenav top">
					<div class="alignleft actions">
						<select name="post_id" aria-label="<?php esc_attr_e( 'Filter by post', 'markdown-for-agents-and-statistics' ); ?>">
							<option value=""><?php esc_html_e( 'All posts', 'markdown-for-agents-and-statistics' ); ?></option>
							<?php foreach ( $posts as $id => $title ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $filter_post_id, $id ); ?>>
									<?php echo esc_html( '' !== $title ? $title : $this->post_label( (int) $id ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<select name="operator" aria-label="<?php esc_attr_e( 'Filter by operator', 'markdown-for-agents-and-statistics' ); ?>">
							<option value=""><?php esc_html_e( 'All operators', 'markdown-for-agents-and-statistics' ); ?></option>
							<?php foreach ( $operator_options as $key => $name ) : ?>
								<option value="<?php echo esc_attr( (string) $key ); ?>" <?php selected( $filter_operator, (string) $key ); ?>>
									<?php echo esc_html( $name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<select name="agent" aria-label="<?php esc_attr_e( 'Filter by agent', 'markdown-for-agents-and-statistics' ); ?>">
							<option value=""><?php esc_html_e( 'All agents', 'markdown-for-agents-and-statistics' ); ?></option>
							<?php foreach ( $agent_options as $agent ) : ?>
								<option value="<?php echo esc_attr( $agent ); ?>" <?php selected( $filter_agent, $agent ); ?>>
									<?php echo esc_html( $agent ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<select name="access_method" aria-label="<?php esc_attr_e( 'Filter by access method', 'markdown-for-agents-and-statistics' ); ?>">
							<option value=""><?php esc_html_e( 'All methods', 'markdown-for-agents-and-statistics' ); ?></option>
							<?php foreach ( array( 'ua', 'accept-header', 'query-param' ) as $method ) : ?>
								<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $filter_access_method, $method ); ?>>
									<?php echo esc_html( $method ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<label for="date_from"><?php esc_html_e( 'From', 'markdown-for-agents-and-statistics' ); ?></label>
						<input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
						<label for="date_to"><?php esc_html_e( 'To', 'markdown-for-agents-and-statistics' ); ?></label>
						<input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
						<?php submit_button( __( 'Filter', 'markdown-for-agents-and-statistics' ), 'secondary', 'filter', false ); ?>
					</div>
					<br class="clear">
				</div>
			</form>

			<style>
				/* Chrome (border, shadow, header, padding) is inherited from core .postbox/.inside. */
				.mfa-chart-card { margin-block-start: 12px; }
				.mfa-chart svg { display: block; width: 100%; height: auto; --grid: #dcdcde; --muted: #646970; }
				.mfa-legend { display: flex; gap: 16px; font-size: 13px; font-weight: 400; color: #50575e; padding-right: 12px; }
				.mfa-legend i { display: inline-block; width: 11px; height: 11px; margin-right: 6px; vertical-align: -1px; }
				.mfa-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin: 16px 0 20px; }
				/* Core .postbox has min-width: 255px, which overflows the grids on phones. */
				.mfa-stats .postbox, .mfa-summary .postbox, .mfa-operators .postbox { margin-bottom: 0; min-width: 0; }
				.mfa-stat.headline { border-left: 4px solid <?php echo esc_attr( self::CATEGORY_COLORS['on-demand'] ); ?>; }
				.mfa-stat.total { border-left: 4px solid #1C2B58; }
				.mfa-stat.search { border-left: 4px solid <?php echo esc_attr( self::CATEGORY_COLORS['search'] ); ?>; }
				.mfa-stat.training { border-left: 4px solid <?php echo esc_attr( self::CATEGORY_COLORS['training'] ); ?>; }
				.mfa-stat.unknown { border-left: 4px solid <?php echo esc_attr( self::CATEGORY_COLORS['unknown'] ); ?>; }
				.mfa-stat .lab, .mfa-tile .lab { font-size: 13px; color: #50575e; }
				.mfa-stat .num, .mfa-tile .num, .mfa-operator .num { font-size: 26px; font-weight: 600; margin-top: 6px; }
				.mfa-stat .est { display: block; font-size: 11px; color: #646970; margin-top: 4px; font-weight: 400; }
				/* Direction arrow is a CSS border-triangle (currentColor), not a glyph, so
					it can't be re-flowed by WP's emoji replacement and stays aligned. */
				.mfa-trend { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; margin-top: 6px; }
				.mfa-trend.rising::before, .mfa-trend.falling::before { content: ""; width: 0; height: 0; border-left: 4px solid transparent; border-right: 4px solid transparent; }
				.mfa-trend.rising::before { border-bottom: 6px solid currentColor; }
				.mfa-trend.falling::before { border-top: 6px solid currentColor; }
				.mfa-trend.rising { color: #1a7f37; }
				.mfa-trend.falling { color: #b32d2e; }
				.mfa-trend.none { color: #8c8f94; font-weight: 400; }
				.mfa-caption { margin: 2px 0 10px; }
				.postbox-header { padding-inline: 10px; }
				.wp-list-table { margin-bottom: 20px; }
				.mfa-section-title { margin: 20px 0 4px; }
				.mfa-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 12px 0 8px; }
				.mfa-tile .val { font-size: 15px; font-weight: 600; margin-top: 8px; overflow-wrap: anywhere; }
				.mfa-tile .sub { font-size: 12px; color: #646970; margin-top: 4px; overflow-wrap: anywhere; }
				.mfa-operators { list-style: none; margin: 12px 0 20px; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
				.mfa-operators > li { margin: 0; }
				/* The heading link covers the card so the whole card is one keyboard stop. */
				.mfa-operator { position: relative; }
				.mfa-operator h3 { margin: 0; font-size: 14px; }
				.mfa-operator h3 a { text-decoration: none; }
				.mfa-operator h3 a::after { content: ""; position: absolute; inset: 0; }
				.mfa-operator h3 a:focus { box-shadow: none; outline: none; }
				.mfa-operator:focus-within { box-shadow: 0 0 0 2px #2271b1; }
				.mfa-operator:hover { border-color: #8c8f94; }
				.mfa-operator.is-active { border-color: #2271b1; box-shadow: inset 4px 0 0 #2271b1; }
				.mfa-operator.is-active:focus-within { box-shadow: inset 4px 0 0 #2271b1, 0 0 0 2px #2271b1; }
				.mfa-operator .num { font-size: 22px; }
				.mfa-operator .num small { font-size: 12px; font-weight: 400; color: #646970; }
				.mfa-operator ul { margin: 8px 0 0; }
				.mfa-operator li { display: flex; justify-content: space-between; gap: 8px; margin: 0; padding: 2px 0; font-size: 12px; border-top: 1px solid #f0f0f1; }
				.mfa-operator li span:first-child { overflow-wrap: anywhere; }
				.mfa-operator li.more { color: #646970; }
				.mfa-badge { display: inline-block; margin-left: 6px; padding: 0 6px; border-radius: 2px; background: #2271b1; color: #fff; font-size: 11px; font-weight: 600; line-height: 18px; vertical-align: 1px; }
				@media (max-width: 1100px) { .mfa-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
				@media (max-width: 782px) { .mfa-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
				@media (max-width: 600px) { .mfa-summary { grid-template-columns: minmax(0, 1fr); } }
			</style>

			<h2 class="mfa-section-title">
				<?php
				/* translators: %s: the selected date range, e.g. "17 Sep 2026 – 23 Sep 2026" or "All time". */
				echo esc_html( sprintf( __( 'Summary · %s', 'markdown-for-agents-and-statistics' ), $range_label ) );
				?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Counts are singular-post Markdown GET selections recorded by WordPress, not all crawler traffic or proof of delivery. Requests answered by a CDN or static cache, or blocked before WordPress runs, are not counted.', 'markdown-for-agents-and-statistics' ); ?>
			</p>

			<div class="mfa-summary">
				<div class="postbox mfa-tile">
					<div class="inside">
						<div class="lab"><?php esc_html_e( 'Recorded Markdown requests', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num"><?php echo esc_html( number_format_i18n( $dashboard['total'] ) ); ?></div>
						<div class="sub"><?php echo esc_html( $range_label ); ?></div>
					</div>
				</div>
				<div class="postbox mfa-tile">
					<div class="inside">
						<div class="lab"><?php esc_html_e( 'Most requested page', 'markdown-for-agents-and-statistics' ); ?></div>
						<?php echo $leader_tiles['page']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside leader_html(). ?>
					</div>
				</div>
				<div class="postbox mfa-tile">
					<div class="inside">
						<div class="lab"><?php esc_html_e( 'Leading agent', 'markdown-for-agents-and-statistics' ); ?></div>
						<?php echo $leader_tiles['agent']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside leader_html(). ?>
					</div>
				</div>
				<div class="postbox mfa-tile">
					<div class="inside">
						<div class="lab"><?php esc_html_e( 'Leading operator', 'markdown-for-agents-and-statistics' ); ?></div>
						<?php echo $leader_tiles['operator']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside leader_html(). ?>
					</div>
				</div>
			</div>

			<h2 class="mfa-section-title"><?php esc_html_e( 'Purpose', 'markdown-for-agents-and-statistics' ); ?></h2>
			<p class="description">
				<?php
				// One string per category so translators can reorder freely; names are bold for scanning.
				$purposes = array(
					'on-demand' => __( 'fetched because a person asked an AI assistant, so it is the best estimate of human reads.', 'markdown-for-agents-and-statistics' ),
					'search'    => __( 'indexing for AI search answers and citations.', 'markdown-for-agents-and-statistics' ),
					'training'  => __( 'collecting content to train models.', 'markdown-for-agents-and-statistics' ),
					'unknown'   => __( "agents whose purpose we can't identify.", 'markdown-for-agents-and-statistics' ),
				);
				foreach ( $purposes as $cat => $definition ) {
					printf( '<strong>%1$s</strong>: %2$s ', esc_html( $this->category_label( $cat ) ), esc_html( $definition ) );
				}
				?>
			</p>

			<div class="postbox mfa-chart-card">
				<div class="postbox-header">
					<h3 class="hndle"><?php esc_html_e( 'Requests by purpose', 'markdown-for-agents-and-statistics' ); ?></h3>
					<div class="mfa-legend">
						<?php foreach ( $chart['legend'] as $cat => $color ) : ?>
							<span><i style="background:<?php echo esc_attr( $color ); ?>"></i><?php echo esc_html( $this->category_label( $cat ) ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="inside">
					<p class="mfa-caption description"><?php echo esc_html( $chart['caption'] ); ?></p>
					<div class="mfa-chart">
						<?php
						// Chart SVG is built from trusted numeric data; labels/colours are escaped inside the renderer.
						echo SvgBarChart::render( $chart['chart_args'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
				</div>
			</div>

			<div class="mfa-stats">
				<div class="postbox mfa-stat total">
					<div class="inside">
						<div class="lab">📊 <?php esc_html_e( 'Total agent visits', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num">
							<?php echo esc_html( number_format_i18n( $chart['totals']['total'] ) ); ?>
							<?php echo $this->trend_indicator( $chart['trends']['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				</div>
				<div class="postbox mfa-stat headline">
					<div class="inside">
						<div class="lab">👤 <?php esc_html_e( 'On-demand reads', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num">
							<?php echo esc_html( number_format_i18n( $chart['totals']['on-demand'] ) ); ?>
							<span class="est"><?php esc_html_e( 'estimated AI-mediated human reads', 'markdown-for-agents-and-statistics' ); ?></span>
							<?php echo $this->trend_indicator( $chart['trends']['on-demand'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				</div>
				<div class="postbox mfa-stat search">
					<div class="inside">
						<div class="lab">🔎 <?php esc_html_e( 'Search', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num">
							<?php echo esc_html( number_format_i18n( $chart['totals']['search'] ) ); ?>
							<?php echo $this->trend_indicator( $chart['trends']['search'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				</div>
				<div class="postbox mfa-stat training">
					<div class="inside">
						<div class="lab">🤖 <?php esc_html_e( 'Training crawls', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num">
							<?php echo esc_html( number_format_i18n( $chart['totals']['training'] ) ); ?>
							<?php echo $this->trend_indicator( $chart['trends']['training'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				</div>
				<div class="postbox mfa-stat unknown">
					<div class="inside">
						<div class="lab">❔ <?php esc_html_e( 'Unknown', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num">
							<?php echo esc_html( number_format_i18n( $chart['totals']['unknown'] ) ); ?>
							<?php echo $this->trend_indicator( $chart['trends']['unknown'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				</div>
			</div>

			<h2 class="mfa-section-title"><?php esc_html_e( 'Operators', 'markdown-for-agents-and-statistics' ); ?></h2>
			<p class="description">
				<?php if ( '' !== $filter_operator ) : ?>
					<?php
					/* translators: %s: operator name, e.g. "OpenAI". */
					echo esc_html( sprintf( __( 'Showing %s only.', 'markdown-for-agents-and-statistics' ), $this->operator_name( $filter_operator ) ) );
					?>
					<a href="<?php echo esc_url( $this->operator_url( $filter_operator, $filter_operator, $filter_agent ) ); ?>"><?php esc_html_e( 'Clear operator filter', 'markdown-for-agents-and-statistics' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'Select an operator to filter the whole report.', 'markdown-for-agents-and-statistics' ); ?>
					<?php
					// Worded in parallel with the Purpose "Unknown" definition: different question, same shape.
					printf(
						'<strong>%1$s</strong>: %2$s',
						esc_html( $this->operator_name( DashboardSummary::UNATTRIBUTED ) ),
						esc_html__( "agents whose operator we haven't identified.", 'markdown-for-agents-and-statistics' )
					);
					?>
				<?php endif; ?>
			</p>

			<?php if ( empty( $dashboard['operators'] ) ) : ?>
				<p><?php esc_html_e( 'No requests recorded for these filters.', 'markdown-for-agents-and-statistics' ); ?></p>
			<?php else : ?>
				<ul class="mfa-operators">
					<?php foreach ( $dashboard['operators'] as $operator ) : ?>
						<?php
						$is_active = $operator['key'] === $filter_operator;
						$name      = $this->operator_name( $operator['key'] );
						?>
						<li class="postbox mfa-operator<?php echo $is_active ? ' is-active' : ''; ?>">
							<div class="inside">
								<h3>
									<a href="<?php echo esc_url( $this->operator_url( $operator['key'], $filter_operator, $filter_agent ) ); ?>"
										<?php echo $is_active ? 'aria-current="true"' : ''; ?>
										aria-label="<?php echo esc_attr( $is_active ? sprintf( /* translators: %s: operator name. */ __( '%s, filtered: remove operator filter', 'markdown-for-agents-and-statistics' ), $name ) : sprintf( /* translators: %s: operator name. */ __( '%s: filter report by this operator', 'markdown-for-agents-and-statistics' ), $name ) ); ?>"><?php echo esc_html( $name ); ?></a>
									<?php if ( $is_active ) : ?>
										<span class="mfa-badge" aria-hidden="true"><?php esc_html_e( 'Filtered', 'markdown-for-agents-and-statistics' ); ?></span>
									<?php endif; ?>
								</h3>
								<div class="num">
									<?php echo esc_html( number_format_i18n( $operator['total'] ) ); ?>
									<small><?php echo esc_html( _n( 'request', 'requests', $operator['total'], 'markdown-for-agents-and-statistics' ) ); ?></small>
								</div>
								<ul>
									<?php foreach ( $operator['agents'] as $agent_row ) : ?>
										<li><span><?php echo esc_html( $this->agent_label( $agent_row['label'] ) ); ?></span><span><?php echo esc_html( number_format_i18n( $agent_row['total'] ) ); ?></span></li>
									<?php endforeach; ?>
									<?php if ( $operator['more'] > 0 ) : ?>
										<li class="more">
											<?php
											/* translators: %s: number of further agents not listed on the card. */
											echo esc_html( sprintf( _n( '+%s more agent', '+%s more agents', $operator['more'], 'markdown-for-agents-and-statistics' ), number_format_i18n( $operator['more'] ) ) );
											?>
										</li>
									<?php endif; ?>
								</ul>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( '' !== $date_from || '' !== $date_to ) : ?>
				<?php $summary = $this->repository->get_agent_summary( $count_filters ); ?>
				<h2><?php esc_html_e( 'Agents by access method', 'markdown-for-agents-and-statistics' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="manage-column column-agent"><?php esc_html_e( 'Agent', 'markdown-for-agents-and-statistics' ); ?></th>
							<th scope="col" class="manage-column column-access-method"><?php esc_html_e( 'Access Method', 'markdown-for-agents-and-statistics' ); ?></th>
							<th scope="col" class="manage-column column-total num"><?php esc_html_e( 'Total accesses', 'markdown-for-agents-and-statistics' ); ?></th>
							<th scope="col" class="manage-column column-unique num"><?php esc_html_e( 'Unique posts', 'markdown-for-agents-and-statistics' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $summary ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No data for this period.', 'markdown-for-agents-and-statistics' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $summary as $row ) : ?>
								<tr>
									<td><?php echo esc_html( '' !== $row->agent ? $row->agent : '(unknown)' ); ?></td>
									<td><?php echo esc_html( $row->access_method ); ?></td>
									<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td>
									<td class="num"><?php echo esc_html( (string) $row->unique_posts ); ?></td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td><strong><?php esc_html_e( 'Total', 'markdown-for-agents-and-statistics' ); ?></strong></td>
								<td>&mdash;</td>
								<td class="num"><strong><?php echo esc_html( number_format_i18n( (int) array_sum( array_column( $summary, 'total' ) ) ) ); ?></strong></td>
								<td>&mdash;</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" class="manage-column column-post"><?php esc_html_e( 'Post', 'markdown-for-agents-and-statistics' ); ?></th>
						<th scope="col" class="manage-column column-agent"><?php esc_html_e( 'Agent', 'markdown-for-agents-and-statistics' ); ?></th>
						<th scope="col" class="manage-column column-access-method"><?php esc_html_e( 'Access Method', 'markdown-for-agents-and-statistics' ); ?></th>
						<th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'markdown-for-agents-and-statistics' ); ?></th>
						<th scope="col" class="manage-column column-count num"><?php esc_html_e( 'Count', 'markdown-for-agents-and-statistics' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
						<td colspan="5"><?php esc_html_e( 'No access data recorded yet.', 'markdown-for-agents-and-statistics' ); ?></td>
					</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $this->post_label( (int) $row->post_id ) ); ?></td>
								<td><?php echo esc_html( '' !== $row->agent ? $row->agent : '(unknown)' ); ?></td>
								<td><?php echo esc_html( $row->access_method ); ?></td>
								<td><?php echo esc_html( $row->access_date ); ?></td>
								<td class="num"><?php echo esc_html( (string) $row->count ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							/* translators: %s: number of agent access log entries */
							echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'markdown-for-agents-and-statistics' ), number_format_i18n( $total ) ) );
							?>
						</span>
						<span class="pagination-links">
							<?php if ( $paged <= 1 ) : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
							<?php else : ?>
								<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>">&laquo;</a>
								<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">&lsaquo;</a>
							<?php endif; ?>
							<span class="paging-input">
								<span class="tablenav-paging-text">
									<?php echo esc_html( sprintf( '%s of %s', number_format_i18n( $paged ), number_format_i18n( $total_pages ) ) ); ?>
								</span>
							</span>
							<?php if ( $paged >= $total_pages ) : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
							<?php else : ?>
								<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">&rsaquo;</a>
								<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>">&raquo;</a>
							<?php endif; ?>
						</span>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build the intent-aware chart payload for a window.
	 *
	 * Takes per-day, per-agent totals, classifies each agent into an intent
	 * category, and assembles one zero-filled series per category plus totals.
	 * Grain adapts to the span: short windows render daily bars, longer windows
	 * and all-time render monthly bars. Pass null bounds for all-time (the span
	 * is then derived from the data).
	 *
	 * @since  1.5.0
	 * @param  array<int, object> $rows Rows from StatsRepository::get_daily_agent_totals() for the page filters.
	 * @param  string|null        $from Window start (Y-m-d), or null for all-time.
	 * @param  string|null        $to   Window end (Y-m-d), or null for all-time.
	 * @return array{chart_args: array<string, mixed>, legend: array<string, string>, totals: array<string, int>, caption: string}
	 */
	private function build_chart_data( array $rows, ?string $from, ?string $to ): array {
		$tz = new \DateTimeZone( 'UTC' );
		list( $start, $end ) = $this->resolve_window( $from, $to, $rows, $tz );

		// Grain adapts to the span so the visible bars always cover the whole
		// window — no truncation at realistic scales — and the caps stay backstops.
		$span_days = (int) $start->diff( $end )->days;
		if ( $span_days <= self::CHART_DAY_SPAN_MAX ) {
			$grain = 'day';
		} elseif ( $span_days <= self::CHART_MONTH_SPAN_MAX ) {
			$grain = 'month';
		} else {
			$grain = 'year';
		}

		$config = array(
			'day'   => array( 'step' => '+1 day', 'key' => 'Y-m-d', 'label' => 'M j', 'len' => 10, 'cap' => self::CHART_MAX_DAYS ),
			'month' => array( 'step' => '+1 month', 'key' => 'Y-m', 'label' => 'M Y', 'len' => 7, 'cap' => self::CHART_MAX_MONTHS ),
			'year'  => array( 'step' => '+1 year', 'key' => 'Y', 'label' => 'Y', 'len' => 4, 'cap' => self::CHART_MAX_YEARS ),
		)[ $grain ];

		// Snap the cursor to the start of its bucket (day → midnight, month → 1st, year → Jan 1).
		$cursor = clone $start;
		$cursor->setDate(
			(int) $cursor->format( 'Y' ),
			'year' === $grain ? 1 : (int) $cursor->format( 'n' ),
			'day' === $grain ? (int) $cursor->format( 'j' ) : 1
		);

		// Ordered bucket key => label.
		$buckets   = array();
		$truncated = false;
		while ( $cursor <= $end ) {
			if ( count( $buckets ) >= $config['cap'] ) {
				$truncated = true;
				break;
			}
			$buckets[ $cursor->format( $config['key'] ) ] = $cursor->format( $config['label'] );
			$cursor->modify( $config['step'] );
		}
		$keys = array_keys( $buckets );

		$by_cat = array();
		$totals = array();
		foreach ( array_keys( self::CATEGORY_COLORS ) as $cat ) {
			$by_cat[ $cat ] = array_fill_keys( $keys, 0 );
			$totals[ $cat ] = 0;
		}

		foreach ( $rows as $row ) {
			$cat   = $this->agent_detector->categorise_agent( (string) ( $row->agent ?? '' ) );
			$date  = (string) ( $row->access_date ?? '' );
			$key   = substr( $date, 0, $config['len'] );
			$value = (int) ( $row->total ?? 0 );
			if ( ! isset( $totals[ $cat ] ) ) {
				$cat = 'unknown';
			}
			$totals[ $cat ] += $value;
			if ( isset( $by_cat[ $cat ][ $key ] ) ) {
				$by_cat[ $cat ][ $key ] += $value;
			}
		}

		$series = array();
		$legend = array();
		foreach ( array( 'training', 'search', 'on-demand', 'unknown' ) as $cat ) {
			$series[]       = array(
				'name'  => $cat,
				'color' => self::CATEGORY_COLORS[ $cat ],
				'data'  => array_values( $by_cat[ $cat ] ),
			);
			$legend[ $cat ] = self::CATEGORY_COLORS[ $cat ];
		}

		// Combined total across all types, summed per bucket so it equals the
		// sum of the four category cards exactly.
		$total_series = array_fill_keys( $keys, 0 );
		foreach ( array_keys( self::CATEGORY_COLORS ) as $cat ) {
			foreach ( $by_cat[ $cat ] as $key => $value ) {
				$total_series[ $key ] += $value;
			}
		}
		$totals['total'] = array_sum( $totals );

		// PMCC of each series against the evenly-spaced bucket index. Its sign
		// matches the regression slope, so r > 0 reads as rising and r < 0 as
		// falling; null (n < 2 or flat series) renders as a neutral indicator.
		$trends = array();
		foreach ( array_keys( self::CATEGORY_COLORS ) as $cat ) {
			$trends[ $cat ] = $this->pmcc( array_values( $by_cat[ $cat ] ) );
		}
		$trends['total'] = $this->pmcc( array_values( $total_series ) );

		$count = count( $keys );

		return array(
			'chart_args' => array(
				'series'     => $series,
				'labels'     => array_values( $buckets ),
				'labelEvery' => max( 1, (int) ceil( $count / 12 ) ),
			),
			'legend'     => $legend,
			'totals'     => $totals,
			'trends'     => $trends,
			'caption'    => $this->window_caption( $start, $end, $grain, $truncated ),
		);
	}

	/**
	 * Resolve the chart window to a [start, end] DateTime pair.
	 *
	 * Uses explicit bounds when given; for all-time (null bounds) derives the
	 * span from the data; falls back to the default range when there is no data.
	 *
	 * @since  1.5.0
	 * @param  string|null         $from
	 * @param  string|null         $to
	 * @param  array<int, object>  $rows Daily rows (each with an access_date).
	 * @param  \DateTimeZone       $tz
	 * @return array{0: \DateTime, 1: \DateTime}
	 */
	private function resolve_window( ?string $from, ?string $to, array $rows, \DateTimeZone $tz ): array {
		$start = null !== $from ? \DateTime::createFromFormat( 'Y-m-d', $from, $tz ) : false;
		$end   = null !== $to ? \DateTime::createFromFormat( 'Y-m-d', $to, $tz ) : false;

		if ( false === $start || false === $end ) {
			$dates = array();
			foreach ( $rows as $row ) {
				$d = (string) ( $row->access_date ?? '' );
				if ( '' !== $d ) {
					$dates[] = $d;
				}
			}
			if ( ! empty( $dates ) ) {
				sort( $dates );
				$start = \DateTime::createFromFormat( 'Y-m-d', (string) reset( $dates ), $tz );
				$end   = \DateTime::createFromFormat( 'Y-m-d', (string) end( $dates ), $tz );
			}
		}

		if ( false === $start || false === $end || ! ( $start instanceof \DateTime ) || ! ( $end instanceof \DateTime ) || $end < $start ) {
			$end   = new \DateTime( 'now', $tz );
			$start = ( clone $end )->modify( '-' . ( self::DEFAULT_RANGE_DAYS - 1 ) . ' days' );
		}

		$start->setTime( 0, 0 );
		$end->setTime( 0, 0 );

		return array( $start, $end );
	}

	/**
	 * Build a human-readable caption describing the chart window and grain.
	 *
	 * @since  1.5.0
	 * @param  \DateTime $start
	 * @param  \DateTime $end
	 * @param  string    $grain     One of 'day', 'month', 'year'.
	 * @param  bool      $truncated Whether the bar cap clipped the window (extreme histories only).
	 * @return string
	 */
	private function window_caption( \DateTime $start, \DateTime $end, string $grain, bool $truncated ): string {
		$formats = array(
			'day'   => 'j M Y',
			'month' => 'M Y',
			'year'  => 'Y',
		);
		$grains  = array(
			'day'   => __( 'daily', 'markdown-for-agents-and-statistics' ),
			'month' => __( 'monthly', 'markdown-for-agents-and-statistics' ),
			'year'  => __( 'yearly', 'markdown-for-agents-and-statistics' ),
		);
		$fmt = $formats[ $grain ] ?? 'j M Y';

		$caption = sprintf(
			/* translators: 1: start date, 2: end date, 3: chart grain (daily, monthly or yearly). */
			__( '%1$s – %2$s · %3$s', 'markdown-for-agents-and-statistics' ),
			$start->format( $fmt ),
			$end->format( $fmt ),
			$grains[ $grain ] ?? $grain
		);

		if ( $truncated ) {
			$caption .= ' · ' . __( 'chart truncated', 'markdown-for-agents-and-statistics' );
		}

		return $caption;
	}

	/**
	 * Pearson product-moment correlation coefficient of a series against time.
	 *
	 * The x-axis is the evenly-spaced bucket index (0, 1, 2, …), so the sign of
	 * the result matches the sign of the least-squares trend slope: positive is
	 * rising, negative is falling. Returns null when no trend is defined — fewer
	 * than two buckets (no x-variance) or a perfectly flat series (no y-variance,
	 * e.g. an all-zero category) — so callers can render a neutral indicator
	 * rather than a NaN or a misleading arrow.
	 *
	 * @since  1.6.0
	 * @param  array<int, int|float> $y Per-bucket counts in chronological order.
	 * @return float|null PMCC in [-1, 1], or null when undefined.
	 */
	private function pmcc( array $y ): ?float {
		$n = count( $y );
		if ( $n < 2 ) {
			return null;
		}

		$mean_x = ( $n - 1 ) / 2.0; // Mean of 0..n-1.
		$mean_y = array_sum( $y ) / $n;

		$sxy = 0.0;
		$sxx = 0.0;
		$syy = 0.0;
		foreach ( array_values( $y ) as $i => $value ) {
			$dx   = $i - $mean_x;
			$dy   = $value - $mean_y;
			$sxy += $dx * $dy;
			$sxx += $dx * $dx;
			$syy += $dy * $dy;
		}

		if ( $sxx <= 0.0 || $syy <= 0.0 ) {
			return null;
		}

		return $sxy / sqrt( $sxx * $syy );
	}

	/**
	 * Render a coloured rise/fall trend indicator for a PMCC value.
	 *
	 * Green (rising) for r > 0, red (falling) for r < 0, neutral grey dash when
	 * the trend is undefined (null) or exactly flat.
	 *
	 * @since  1.6.0
	 * @param  float|null $r PMCC from {@see self::pmcc()}.
	 * @return string Escaped HTML for the indicator.
	 */
	private function trend_indicator( ?float $r ): string {
		// Neutral when undefined or when it would round to 0.00 — so the colour
		// (rising/falling) never contradicts the two-dp value shown beside it.
		if ( null === $r || 0.0 === round( $r, 2 ) ) {
			return sprintf(
				'<span class="mfa-trend none" title="%s">—</span>',
				esc_attr( __( 'No trend (too few data points or flat)', 'markdown-for-agents-and-statistics' ) )
			);
		}

		$rising = $r > 0;
		$class  = $rising ? 'rising' : 'falling';
		$label  = $rising
			? __( 'rising', 'markdown-for-agents-and-statistics' )
			: __( 'falling', 'markdown-for-agents-and-statistics' );

		return sprintf(
			'<span class="mfa-trend %1$s" title="%2$s">r = %3$s</span>',
			esc_attr( $class ),
			/* translators: 1: trend direction (rising/falling), 2: correlation coefficient. */
			esc_attr( sprintf( __( 'Trend %1$s · Pearson r = %2$s', 'markdown-for-agents-and-statistics' ), $label, number_format( $r, 2 ) ) ),
			esc_html( number_format( $r, 2 ) )
		);
	}

	/**
	 * Operator bucket for a stored agent label.
	 *
	 * @since  1.7.2
	 * @param  string $agent Stored agent label.
	 * @return string        Operator key, or DashboardSummary::UNATTRIBUTED.
	 */
	private function operator_of( string $agent ): string {
		return $this->agent_detector->get_operator( $agent ) ?? DashboardSummary::UNATTRIBUTED;
	}

	/**
	 * Display name for an operator key, including the unattributed bucket.
	 *
	 * @since  1.7.2
	 * @param  string $key
	 * @return string
	 */
	private function operator_name( string $key ): string {
		if ( DashboardSummary::UNATTRIBUTED === $key ) {
			return __( 'Unattributed', 'markdown-for-agents-and-statistics' );
		}

		return $this->agent_detector->get_operator_label( $key );
	}

	/**
	 * Display label for a stored agent label.
	 *
	 * @since  1.7.2
	 * @param  string $agent
	 * @return string
	 */
	private function agent_label( string $agent ): string {
		return '' !== $agent ? $agent : __( '(unknown)', 'markdown-for-agents-and-statistics' );
	}

	/**
	 * Display label for a post, keeping deleted and untitled posts identifiable.
	 *
	 * @since  1.7.2
	 * @param  int $post_id
	 * @return string
	 */
	private function post_label( int $post_id ): string {
		$title = get_the_title( $post_id );
		if ( '' !== $title ) {
			return $title;
		}

		return null === get_post( $post_id )
			/* translators: %d: post ID. */
			? sprintf( __( '(deleted post #%d)', 'markdown-for-agents-and-statistics' ), $post_id )
			/* translators: %d: post ID. */
			: sprintf( __( '(no title) #%d', 'markdown-for-agents-and-statistics' ), $post_id );
	}

	/**
	 * Build a report URL that sets filters, keeps the others and resets paging.
	 *
	 * @since  1.7.2
	 * @param  array<string, int|string> $set    Query args to set.
	 * @param  string[]                  $remove Query args to drop.
	 * @return string
	 */
	private function filter_url( array $set, array $remove = array() ): string {
		return add_query_arg( array_merge( $set, array( 'paged' => 1 ) ), remove_query_arg( $remove ) );
	}

	/**
	 * URL an operator card links to: select it, or remove it when already active.
	 *
	 * Selecting an operator keeps the other filters, but drops an agent filter
	 * for an agent run by a different operator, since that pair can never match.
	 *
	 * @since  1.7.2
	 * @param  string $key             Operator key the link targets.
	 * @param  string $active_operator Currently filtered operator, or ''.
	 * @param  string $active_agent    Currently filtered agent, or ''.
	 * @return string
	 */
	private function operator_url( string $key, string $active_operator, string $active_agent ): string {
		if ( $key === $active_operator ) {
			return $this->filter_url( array(), array( 'operator' ) );
		}

		$remove = ( '' !== $active_agent && $this->operator_of( $active_agent ) !== $key ) ? array( 'agent' ) : array();

		return $this->filter_url( array( 'operator' => $key ), $remove );
	}

	/**
	 * Human-readable label for the selected date range.
	 *
	 * @since  1.7.2
	 * @param  string $from Y-m-d, or '' when unbounded.
	 * @param  string $to   Y-m-d, or '' when unbounded.
	 * @return string
	 */
	private function range_label( string $from, string $to ): string {
		$format = static fn( string $date ): string => (string) \DateTime::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) )->format( 'j M Y' );

		if ( '' !== $from && '' !== $to ) {
			/* translators: 1: start date, 2: end date. */
			return sprintf( __( '%1$s – %2$s', 'markdown-for-agents-and-statistics' ), $format( $from ), $format( $to ) );
		}
		if ( '' !== $from ) {
			/* translators: %s: start date. */
			return sprintf( __( 'From %s', 'markdown-for-agents-and-statistics' ), $format( $from ) );
		}
		if ( '' !== $to ) {
			/* translators: %s: end date. */
			return sprintf( __( 'Up to %s', 'markdown-for-agents-and-statistics' ), $format( $to ) );
		}

		return __( 'All time', 'markdown-for-agents-and-statistics' );
	}

	/**
	 * Render a summary tile body for a leader from DashboardSummary.
	 *
	 * A single leader links to the matching filter. A tie is shown as a tie with
	 * the first few names, so no item is crowned arbitrarily.
	 *
	 * @since  1.7.2
	 * @param  array{total: int, items: list<int|string>, count: int, capped: bool}|null $leader
	 * @param  string                                                                      $type One of 'page', 'agent', 'operator'.
	 * @param  callable(int|string): string                                                $name Display name for an item.
	 * @param  callable(int|string): string                                                $url  Filter URL for an item.
	 * @return string Escaped HTML.
	 */
	private function leader_html( ?array $leader, string $type, callable $name, callable $url ): string {
		if ( null === $leader ) {
			$empty = array(
				'page'     => __( 'No pages requested in this range', 'markdown-for-agents-and-statistics' ),
				'agent'    => __( 'No identified agents in this range', 'markdown-for-agents-and-statistics' ),
				'operator' => __( 'No attributed operators in this range', 'markdown-for-agents-and-statistics' ),
			);

			return sprintf(
				'<div class="val" aria-hidden="true">—</div><div class="sub">%s</div>',
				esc_html( $empty[ $type ] ?? '' )
			);
		}

		/* translators: %s: number of requests. */
		$requests = sprintf( _n( '%s request', '%s requests', $leader['total'], 'markdown-for-agents-and-statistics' ), number_format_i18n( $leader['total'] ) );

		if ( 1 === $leader['count'] ) {
			$item = $leader['items'][0];

			return sprintf(
				'<div class="val"><a href="%1$s">%2$s</a></div><div class="sub">%3$s</div>',
				esc_url( $url( $item ) ),
				esc_html( $name( $item ) ),
				esc_html( $requests )
			);
		}

		$count = number_format_i18n( $leader['count'] ) . ( $leader['capped'] ? '+' : '' );
		// A tie always has two or more items, so no singular forms are needed.
		$nouns = array(
			/* translators: %s: number of tied pages, e.g. "3" or "50+". */
			'page'     => __( 'Tied: %s pages', 'markdown-for-agents-and-statistics' ),
			/* translators: %s: number of tied agents. */
			'agent'    => __( 'Tied: %s agents', 'markdown-for-agents-and-statistics' ),
			/* translators: %s: number of tied operators. */
			'operator' => __( 'Tied: %s operators', 'markdown-for-agents-and-statistics' ),
		);

		$names = implode( ', ', array_map( $name, $leader['items'] ) );
		$rest  = $leader['count'] - count( $leader['items'] );
		if ( $rest > 0 ) {
			/* translators: 1: comma-separated names, 2: number of further tied items (may end in "+"). */
			$names = sprintf( __( '%1$s and %2$s more', 'markdown-for-agents-and-statistics' ), $names, number_format_i18n( $rest ) . ( $leader['capped'] ? '+' : '' ) );
		}

		return sprintf(
			'<div class="val">%1$s</div><div class="sub">%2$s</div><div class="sub">%3$s</div>',
			esc_html( sprintf( $nouns[ $type ] ?? '%s', $count ) ),
			/* translators: %s: request count for each tied item, e.g. "14 requests". */
			esc_html( sprintf( __( '%s each', 'markdown-for-agents-and-statistics' ), $requests ) ),
			esc_html( $names )
		);
	}

	/**
	 * Human-readable label for an intent category.
	 *
	 * @since  1.5.0
	 * @param  string $category
	 * @return string
	 */
	private function category_label( string $category ): string {
		$labels = array(
			'training'  => __( 'Training', 'markdown-for-agents-and-statistics' ),
			'search'    => __( 'Search', 'markdown-for-agents-and-statistics' ),
			'on-demand' => __( 'On-demand', 'markdown-for-agents-and-statistics' ),
			'unknown'   => __( 'Unknown', 'markdown-for-agents-and-statistics' ),
		);

		return $labels[ $category ] ?? $category;
	}
}

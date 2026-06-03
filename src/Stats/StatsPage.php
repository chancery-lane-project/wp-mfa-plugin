<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;

/**
 * Admin page for displaying agent access statistics.
 *
 * Registered as a top-level menu item. Shows an intent-aware daily chart and
 * stat cards above a filterable, paginated table of daily access counts.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class StatsPage {

	private const PAGE_SLUG = 'markdown-for-agents-stats';
	private const PER_PAGE  = 50;

	/** Chart window when no explicit date range is set (days, inclusive). */
	private const CHART_DEFAULT_DAYS = 30;

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

	/**
	 * Intent categories shown as series/cards, with brand display colour.
	 *
	 * Stacked bottom→top (training, search, on-demand), so volume-heavy
	 * background crawling forms the base in a muted navy tint while the headline
	 * human-intent reads pop in brand magenta on top of each daily bar.
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
		$paged          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;             // phpcs:ignore WordPress.Security.NonceVerification

		// Window anchors (UTC).
		$today       = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$seven_ago   = ( clone $today )->modify( '-6 days' );
		$thirty_ago  = ( clone $today )->modify( '-29 days' );
		$month_start = ( clone $today )->modify( 'first day of this month' );
		$today_str   = $today->format( 'Y-m-d' );

		// "All time" is an explicit choice via ?range=all; otherwise the page
		// defaults to the last 30 days so the chart and table always agree.
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
			// Default view: last 30 days.
			$date_from = $thirty_ago->format( 'Y-m-d' );
			$date_to   = $today_str;
		}

		$count_filters = array();
		if ( $filter_post_id > 0 ) {
			$count_filters['post_id'] = $filter_post_id;
		}
		if ( '' !== $filter_agent ) {
			$count_filters['agent'] = $filter_agent;
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
		$agents      = $this->repository->get_distinct_agents();
		$posts       = $this->repository->get_posts_with_stats();
		$total_pages = (int) ceil( $total / self::PER_PAGE );

		// Intent-aware chart. Aligns with the table window; all-time spans the full
		// history. Grain (daily vs monthly) adapts to the span inside build_chart_data().
		$chart = $this->build_chart_data(
			$count_filters,
			$is_all_time ? null : $date_from,
			$is_all_time ? null : $date_to
		);

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
						<select name="post_id">
							<option value=""><?php esc_html_e( 'All posts', 'markdown-for-agents-and-statistics' ); ?></option>
							<?php foreach ( $posts as $id => $title ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $filter_post_id, $id ); ?>>
									<?php echo esc_html( $title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<select name="agent">
							<option value=""><?php esc_html_e( 'All agents', 'markdown-for-agents-and-statistics' ); ?></option>
							<?php foreach ( $agents as $agent ) : ?>
								<option value="<?php echo esc_attr( $agent ); ?>" <?php selected( $filter_agent, $agent ); ?>>
									<?php echo esc_html( $agent ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<select name="access_method">
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
				.mfa-chart-card { margin-block-start: 20px; }
				.mfa-chart svg { display: block; width: 100%; height: auto; --grid: #dcdcde; --muted: #646970; }
				.mfa-legend { display: flex; gap: 16px; font-size: 13px; font-weight: 400; color: #50575e; padding-right: 12px; }
				.mfa-legend i { display: inline-block; width: 11px; height: 11px; margin-right: 6px; vertical-align: -1px; }
				.mfa-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 16px 0 20px; }
				.mfa-stats .postbox { margin-bottom: 0; }
				.mfa-stat.headline { border-left: 4px solid #D7288B; }
				.mfa-stat .lab { font-size: 13px; color: #50575e; }
				.mfa-stat .num { font-size: 26px; font-weight: 600; margin-top: 6px; }
				.mfa-stat .est { display: block; font-size: 11px; color: #646970; margin-top: 4px; font-weight: 400; }
				.mfa-caption { margin: 2px 0 10px; }
				.postbox-header { padding-inline: 10px; }
				.wp-list-table { margin-bottom: 20px; }
				@media (max-width: 782px) { .mfa-stats { grid-template-columns: repeat(2, 1fr); } }
			</style>

			<div class="postbox mfa-chart-card">
				<div class="postbox-header">
					<h2 class="hndle"><?php esc_html_e( 'AI access by intent', 'markdown-for-agents-and-statistics' ); ?></h2>
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
				<div class="postbox mfa-stat headline">
					<div class="inside">
						<div class="lab">👤 <?php esc_html_e( 'On-demand reads', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num">
							<?php echo esc_html( number_format_i18n( $chart['totals']['on-demand'] ) ); ?>
							<span class="est"><?php esc_html_e( 'estimated AI-mediated human reads', 'markdown-for-agents-and-statistics' ); ?></span>
						</div>
					</div>
				</div>
				<div class="postbox mfa-stat">
					<div class="inside">
						<div class="lab">🔎 <?php esc_html_e( 'Search', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num"><?php echo esc_html( number_format_i18n( $chart['totals']['search'] ) ); ?></div>
					</div>
				</div>
				<div class="postbox mfa-stat">
					<div class="inside">
						<div class="lab">🤖 <?php esc_html_e( 'Training crawls', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num"><?php echo esc_html( number_format_i18n( $chart['totals']['training'] ) ); ?></div>
					</div>
				</div>
				<div class="postbox mfa-stat">
					<div class="inside">
						<div class="lab">❔ <?php esc_html_e( 'Unknown', 'markdown-for-agents-and-statistics' ); ?></div>
						<div class="num"><?php echo esc_html( number_format_i18n( $chart['totals']['unknown'] ) ); ?></div>
					</div>
				</div>
			</div>

			<?php if ( '' !== $date_from || '' !== $date_to ) : ?>
				<?php $summary = $this->repository->get_agent_summary( $count_filters ); ?>
				<h2><?php esc_html_e( 'Summary', 'markdown-for-agents-and-statistics' ); ?></h2>
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
								<td><?php echo esc_html( get_the_title( (int) $row->post_id ) ); ?></td>
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
	 * Fetches per-day, per-agent totals, classifies each agent into an intent
	 * category, and assembles one zero-filled series per category plus totals.
	 * Grain adapts to the span: short windows render daily bars, longer windows
	 * and all-time render monthly bars. Pass null bounds for all-time (the span
	 * is then derived from the data).
	 *
	 * @since  1.5.0
	 * @param  array<string, mixed> $base_filters Filters from the page (post/agent/method); date keys are overridden.
	 * @param  string|null          $from         Window start (Y-m-d), or null for all-time.
	 * @param  string|null          $to           Window end (Y-m-d), or null for all-time.
	 * @return array{chart_args: array<string, mixed>, legend: array<string, string>, totals: array<string, int>, caption: string}
	 */
	private function build_chart_data( array $base_filters, ?string $from, ?string $to ): array {
		$filters = $base_filters;
		unset( $filters['limit'], $filters['offset'], $filters['date_from'], $filters['date_to'] );
		if ( null !== $from ) {
			$filters['date_from'] = $from;
		}
		if ( null !== $to ) {
			$filters['date_to'] = $to;
		}

		$rows = (array) $this->repository->get_daily_agent_totals( $filters );

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
		foreach ( array( 'training', 'search', 'on-demand' ) as $cat ) {
			$series[]       = array(
				'name'  => $cat,
				'color' => self::CATEGORY_COLORS[ $cat ],
				'data'  => array_values( $by_cat[ $cat ] ),
			);
			$legend[ $cat ] = self::CATEGORY_COLORS[ $cat ];
		}

		$count = count( $keys );

		return array(
			'chart_args' => array(
				'series'     => $series,
				'labels'     => array_values( $buckets ),
				'labelEvery' => max( 1, (int) ceil( $count / 12 ) ),
			),
			'legend'     => $legend,
			'totals'     => $totals,
			'caption'    => $this->window_caption( $start, $end, $grain, $truncated ),
		);
	}

	/**
	 * Resolve the chart window to a [start, end] DateTime pair.
	 *
	 * Uses explicit bounds when given; for all-time (null bounds) derives the
	 * span from the data; falls back to the last 30 days when there is no data.
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
			$start = ( clone $end )->modify( '-' . ( self::CHART_DEFAULT_DAYS - 1 ) . ' days' );
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

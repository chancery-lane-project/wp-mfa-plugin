<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Admin page for displaying agent access statistics.
 *
 * Registered as a top-level menu item. Shows a filterable, paginated
 * table of daily access counts by post and agent.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class StatsPage {

	private const PAGE_SLUG = 'wp-mfa-stats';
	private const PER_PAGE  = 50;

	/**
	 * @since  1.1.0
	 * @param  StatsRepository $repository Stats query layer.
	 */
	public function __construct( private readonly StatsRepository $repository ) {}

	/**
	 * Register the admin menu page.
	 *
	 * @since  1.1.0
	 */
	public function add_page(): void {
		add_menu_page(
			__( 'Agent Access Statistics', 'markdown-for-agents' ),
			__( 'Agent Stats', 'markdown-for-agents' ),
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

		// Preset link computation.
		$today       = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$seven_ago   = ( clone $today )->modify( '-6 days' );
		$thirty_ago  = ( clone $today )->modify( '-29 days' );
		$month_start = ( clone $today )->modify( 'first day of this month' );
		$today_str   = $today->format( 'Y-m-d' );

		$preset_7d    = add_query_arg( array( 'date_from' => $seven_ago->format( 'Y-m-d' ), 'date_to' => $today_str, 'paged' => 1 ) );
		$preset_30d   = add_query_arg( array( 'date_from' => $thirty_ago->format( 'Y-m-d' ), 'date_to' => $today_str, 'paged' => 1 ) );
		$preset_month = add_query_arg( array( 'date_from' => $month_start->format( 'Y-m-d' ), 'date_to' => $today_str, 'paged' => 1 ) );
		$preset_all   = add_query_arg( array( 'paged' => 1 ), remove_query_arg( array( 'date_from', 'date_to' ) ) );

		$active_7d    = ( $date_from === $seven_ago->format( 'Y-m-d' ) && $date_to === $today_str );
		$active_30d   = ( $date_from === $thirty_ago->format( 'Y-m-d' ) && $date_to === $today_str );
		$active_month = ( $date_from === $month_start->format( 'Y-m-d' ) && $date_to === $today_str );
		$active_all   = ( $date_from === '' && $date_to === '' );

		$rows        = $this->repository->get_stats( $filters );
		$total       = $this->repository->get_total_count( $count_filters );
		$agents      = $this->repository->get_distinct_agents();
		$posts       = $this->repository->get_posts_with_stats();
		$total_pages = (int) ceil( $total / self::PER_PAGE );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Access Statistics', 'markdown-for-agents' ); ?></h1>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( $preset_all ); ?>"<?php echo $active_all ? ' class="current"' : ''; ?>><?php esc_html_e( 'All time', 'markdown-for-agents' ); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url( $preset_7d ); ?>"<?php echo $active_7d ? ' class="current"' : ''; ?>><?php esc_html_e( 'Last 7 days', 'markdown-for-agents' ); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url( $preset_30d ); ?>"<?php echo $active_30d ? ' class="current"' : ''; ?>><?php esc_html_e( 'Last 30 days', 'markdown-for-agents' ); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url( $preset_month ); ?>"<?php echo $active_month ? ' class="current"' : ''; ?>><?php esc_html_e( 'This month', 'markdown-for-agents' ); ?></a>
				</li>
			</ul>

			<form method="get" action="">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<div class="tablenav top">
				<div class="alignleft actions">
					<select name="post_id">
						<option value=""><?php esc_html_e( 'All posts', 'markdown-for-agents' ); ?></option>
						<?php foreach ( $posts as $id => $title ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $filter_post_id, $id ); ?>>
								<?php echo esc_html( $title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="agent">
						<option value=""><?php esc_html_e( 'All agents', 'markdown-for-agents' ); ?></option>
						<?php foreach ( $agents as $agent ) : ?>
							<option value="<?php echo esc_attr( $agent ); ?>" <?php selected( $filter_agent, $agent ); ?>>
								<?php echo esc_html( $agent ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="access_method">
						<option value=""><?php esc_html_e( 'All methods', 'markdown-for-agents' ); ?></option>
						<?php foreach ( array( 'ua', 'accept-header', 'query-param' ) as $method ) : ?>
							<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $filter_access_method, $method ); ?>>
								<?php echo esc_html( $method ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<label for="date_from"><?php esc_html_e( 'From', 'markdown-for-agents' ); ?></label>
					<input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
					<label for="date_to"><?php esc_html_e( 'To', 'markdown-for-agents' ); ?></label>
					<input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
					<?php submit_button( __( 'Filter', 'markdown-for-agents' ), 'secondary', 'filter', false ); ?>
				</div>
				<br class="clear">
			</div>
			</form>

			<?php if ( '' !== $date_from || '' !== $date_to ) : ?>
				<?php $summary = $this->repository->get_agent_summary( $count_filters ); ?>
				<h2><?php esc_html_e( 'Summary', 'markdown-for-agents' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Agent', 'markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Access Method', 'markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Total accesses', 'markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Unique posts', 'markdown-for-agents' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $summary ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No data for this period.', 'markdown-for-agents' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $summary as $row ) : ?>
								<tr>
									<td><?php echo esc_html( '' !== $row->agent ? $row->agent : '(unknown)' ); ?></td>
									<td><?php echo esc_html( $row->access_method ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td>
									<td><?php echo esc_html( (string) $row->unique_posts ); ?></td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td><strong><?php esc_html_e( 'Total', 'markdown-for-agents' ); ?></strong></td>
								<td>&mdash;</td>
								<td><strong><?php echo esc_html( number_format_i18n( (int) array_sum( array_column( $summary, 'total' ) ) ) ); ?></strong></td>
								<td>&mdash;</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No access data recorded yet.', 'markdown-for-agents' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Agent', 'markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Access Method', 'markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Date', 'markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Count', 'markdown-for-agents' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( get_the_title( (int) $row->post_id ) ); ?></td>
								<td><?php echo esc_html( '' !== $row->agent ? $row->agent : '(unknown)' ); ?></td>
								<td><?php echo esc_html( $row->access_method ); ?></td>
								<td><?php echo esc_html( $row->access_date ); ?></td>
								<td><?php echo esc_html( (string) $row->count ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
								<?php if ( $i === $paged ) : ?>
									<span class="tablenav-pages-navspan button disabled"><?php echo esc_html( (string) $i ); ?></span>
								<?php else : ?>
									<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"><?php echo esc_html( (string) $i ); ?></a>
								<?php endif; ?>
							<?php endfor; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Database access layer for agent access statistics.
 *
 * All queries against the custom `wp_mfa_access_stats` table go through
 * this class. Counters are aggregated daily per post + agent combination.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class StatsRepository {

	private const TABLE_SUFFIX = 'mfa_access_stats';

	/** DB schema version. Increment when the table structure changes. */
	public const DB_VERSION = '1.1';

	/**
	 * @since  1.1.0
	 * @param  \wpdb $wpdb WordPress database abstraction.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * Return the full table name including prefix.
	 *
	 * @since  1.1.0
	 */
	public static function get_table_name( \wpdb $wpdb ): string {
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Return the CREATE TABLE SQL for use with dbDelta().
	 *
	 * @since  1.1.0
	 */
	public static function get_create_table_sql( \wpdb $wpdb ): string {
		$table   = self::get_table_name( $wpdb );
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        agent varchar(100) NOT NULL DEFAULT '',
        access_method varchar(20) NOT NULL DEFAULT '',
        access_date date NOT NULL,
        count int unsigned NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY post_agent_date (post_id, agent, access_method, access_date),
        KEY access_date (access_date)
    ) {$charset};";
	}

	/**
	 * Record a single access — upserts the daily counter.
	 *
	 * @since  1.1.0
	 * @param  int    $post_id       The accessed post ID.
	 * @param  string $agent         Agent identity substring, or '' for unknown.
	 * @param  string $access_method How the request arrived: 'ua', 'accept-header', or 'query-param'.
	 */
	public function record_access( int $post_id, string $agent, string $access_method ): void {
		$table = self::get_table_name( $this->wpdb );
		$date  = gmdate( 'Y-m-d' );

		$sql = $this->wpdb->prepare(
			"INSERT INTO {$table} (post_id, agent, access_method, access_date, count)
         VALUES (%d, %s, %s, %s, 1)
         ON DUPLICATE KEY UPDATE count = count + 1",
			$post_id,
			$agent,
			$access_method,
			$date
		);

		$this->wpdb->query( $sql );
	}

	/**
	 * Query stats rows with optional filters.
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $filters Optional: post_id, agent, date_from, date_to, limit, offset.
	 * @return array<int, object>
	 */
	public function get_stats( array $filters = array() ): array {
		$table  = self::get_table_name( $this->wpdb );
		$clause = $this->build_where( $filters );

		$where_sql = $clause['sql'];
		$values    = $clause['values'];
		$limit     = max( 1, (int) ( $filters['limit'] ?? 50 ) );
		$offset    = max( 0, (int) ( $filters['offset'] ?? 0 ) );

		$sql = "SELECT post_id, agent, access_method, access_date, count FROM {$table} {$where_sql} ORDER BY access_date DESC LIMIT {$limit} OFFSET {$offset}";

		if ( ! empty( $values ) ) {
			$sql = $this->wpdb->prepare( $sql, ...$values );
		}

		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Count total rows matching the given filters (for pagination).
	 *
	 * @since  1.1.0
	 * @param  array<string, mixed> $filters Optional: post_id, agent, date_from, date_to.
	 * @return int
	 */
	public function get_total_count( array $filters = array() ): int {
		$table  = self::get_table_name( $this->wpdb );
		$clause = $this->build_where( $filters );

		$where_sql = $clause['sql'];
		$values    = $clause['values'];
		$sql       = "SELECT COUNT(*) FROM {$table} {$where_sql}";

		if ( ! empty( $values ) ) {
			$sql = $this->wpdb->prepare( $sql, ...$values );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Build a WHERE clause and prepared values from a filters array.
	 *
	 * Supports 'post_id' (int), 'agent' (string), 'access_method' (string),
	 * 'date_from' (string Y-m-d), and 'date_to' (string Y-m-d) keys.
	 *
	 * @since  1.3.0
	 * @param  array<string, mixed> $filters
	 * @return array{sql: string, values: list<mixed>}
	 */
	private function build_where( array $filters ): array {
		$where  = array();
		$values = array();

		if ( ! empty( $filters['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$values[] = (int) $filters['post_id'];
		}

		if ( ! empty( $filters['agent'] ) ) {
			$where[]  = 'agent = %s';
			$values[] = (string) $filters['agent'];
		}

		if ( ! empty( $filters['access_method'] ) ) {
			$where[]  = 'access_method = %s';
			$values[] = (string) $filters['access_method'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'access_date >= %s';
			$values[] = (string) $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'access_date <= %s';
			$values[] = (string) $filters['date_to'];
		}

		return array(
			'sql'    => ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '',
			'values' => $values,
		);
	}

	/**
	 * Return all distinct agent strings that have recorded stats.
	 *
	 * @since  1.1.0
	 * @return string[]
	 */
	public function get_distinct_agents(): array {
		$table = self::get_table_name( $this->wpdb );
		$sql   = $this->wpdb->prepare(
			"SELECT DISTINCT agent FROM {$table} WHERE agent NOT IN (%s, %s, %s) ORDER BY agent ASC",
			'',
			'accept-header',
			'query-param'
		);
		$rows  = $this->wpdb->get_results( $sql );

		return array_map( fn( object $row ) => $row->agent, $rows );
	}

	/**
	 * Return per-agent-per-method totals for the given filters.
	 *
	 * @since  1.3.0
	 * @note   Groups by access_method in addition to agent; adds access_method to return objects.
	 * @param  array<string, mixed> $filters  Supports post_id, agent, access_method, date_from, date_to.
	 * @return array<int, object>             Each object has agent (string), access_method (string),
	 *                                        total (int), unique_posts (int).
	 */
	public function get_agent_summary( array $filters = array() ): array {
		$table  = self::get_table_name( $this->wpdb );
		$clause = $this->build_where( $filters );

		$where_sql = $clause['sql'];
		$values    = $clause['values'];

		$sql = "SELECT agent, access_method, SUM(`count`) AS total, COUNT(DISTINCT post_id) AS unique_posts FROM {$table} {$where_sql} GROUP BY agent, access_method ORDER BY total DESC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $values ) ) {
			$sql = $this->wpdb->prepare( $sql, ...$values );
		}

		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Return post IDs and titles for posts that have at least one stat row.
	 *
	 * @since  1.1.0
	 * @return array<int, string> Map of post_id => title.
	 */
	public function get_posts_with_stats(): array {
		$table = self::get_table_name( $this->wpdb );
		$rows  = $this->wpdb->get_results( "SELECT DISTINCT post_id FROM {$table} ORDER BY post_id ASC" );

		$result = array();
		foreach ( $rows as $row ) {
			$id            = (int) $row->post_id;
			$result[ $id ] = get_the_title( $id );
		}

		return $result;
	}
}

# Stats Date Range Filter & Agent Summary Table Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add date range filtering (preset links + custom From/To inputs) to the Agent Stats admin page and, when a date is active, show a headline summary table of total accesses and unique posts per agent.

**Architecture:** `build_where()` in `StatsRepository` gains two new filter keys (`date_from`, `date_to`). A new `get_agent_summary()` method runs a grouped query using the same `build_where()` output. `StatsPage` reads date params from `$_GET`, computes preset URLs, renders two date inputs in the existing form, four preset anchor links below it, and conditionally renders the headline table above the detail rows.

**Tech Stack:** PHP 8.0+, PHPUnit 9.6, WordPress admin UI conventions, existing `wpdb` mock infrastructure.

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `tests/mocks/wordpress-mocks.php` | Modify | Add `number_format_i18n()` and `remove_query_arg()` stubs |
| `src/Stats/StatsRepository.php` | Modify | Extend `build_where()` + add `get_agent_summary()` |
| `tests/Unit/Stats/StatsRepositoryTest.php` | Modify | 5 new tests for date filtering and agent summary |
| `src/Stats/StatsPage.php` | Modify | Date reading, preset links, date inputs, headline table |
| `tests/Unit/Stats/StatsPageTest.php` | Modify | 4 new tests for filter bar and headline table |

---

## Task 1: Add missing mock stubs

**Files:**
- Modify: `tests/mocks/wordpress-mocks.php`

- [ ] **Step 1: Add `number_format_i18n()` and `remove_query_arg()` stubs**

Open `tests/mocks/wordpress-mocks.php`. After the closing `}` of the `add_query_arg` stub (around line 832), insert:

```php
if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string {
        return number_format($number, $decimals);
    }
}

if (!function_exists('remove_query_arg')) {
    function remove_query_arg(string|array $key, string $query = ''): string {
        return $query;
    }
}
```

- [ ] **Step 2: Run the full suite to confirm baseline still passes**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: 211 tests, all pass.

- [ ] **Step 3: Commit**

```bash
git add tests/mocks/wordpress-mocks.php
git commit -m "test: add number_format_i18n and remove_query_arg stubs to mocks"
```

---

## Task 2: Extend `build_where()` with date range conditions

**Files:**
- Modify: `tests/Unit/Stats/StatsRepositoryTest.php`
- Modify: `src/Stats/StatsRepository.php`

- [ ] **Step 1: Write the three failing tests**

Open `tests/Unit/Stats/StatsRepositoryTest.php`. After `test_get_stats_with_post_id_and_agent_filters`, add:

```php
public function test_get_stats_with_date_from_filter(): void {
    $this->wpdb->mock_get_results = [];
    $this->repo->get_stats( [ 'date_from' => '2026-03-01' ] );

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'access_date >=', $last['query'] );
}

public function test_get_stats_with_date_to_filter(): void {
    $this->wpdb->mock_get_results = [];
    $this->repo->get_stats( [ 'date_to' => '2026-03-25' ] );

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'access_date <=', $last['query'] );
}

public function test_get_stats_with_full_date_range(): void {
    $this->wpdb->mock_get_results = [];
    $this->repo->get_stats( [ 'date_from' => '2026-03-01', 'date_to' => '2026-03-25' ] );

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'access_date >=', $last['query'] );
    $this->assertStringContainsString( 'access_date <=', $last['query'] );
}
```

- [ ] **Step 2: Run the three new tests to confirm they fail**

```bash
./vendor/bin/phpunit --no-coverage --filter "test_get_stats_with_date"
```

Expected: 3 FAIL — queries do not yet contain `access_date`.

- [ ] **Step 3: Extend `build_where()` in `StatsRepository`**

Open `src/Stats/StatsRepository.php`. Replace the entire `build_where()` method (lines 135–153) with:

```php
/**
 * Build a WHERE clause and prepared values from a filters array.
 *
 * Supports 'post_id' (int), 'agent' (string), 'date_from' (string Y-m-d),
 * and 'date_to' (string Y-m-d) keys.
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
```

- [ ] **Step 4: Run the three new tests to confirm they pass**

```bash
./vendor/bin/phpunit --no-coverage --filter "test_get_stats_with_date"
```

Expected: 3 PASS.

- [ ] **Step 5: Run the full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: 214 tests, all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Stats/StatsRepository.php tests/Unit/Stats/StatsRepositoryTest.php
git commit -m "feat: add date_from/date_to filter support to StatsRepository::build_where()"
```

---

## Task 3: Add `get_agent_summary()` to `StatsRepository`

**Files:**
- Modify: `tests/Unit/Stats/StatsRepositoryTest.php`
- Modify: `src/Stats/StatsRepository.php`

- [ ] **Step 1: Write the two failing tests**

Open `tests/Unit/Stats/StatsRepositoryTest.php`. After the `test_get_stats_with_full_date_range` test added in Task 2, add:

```php
public function test_get_agent_summary_builds_grouped_query(): void {
    $this->wpdb->mock_get_results = [];
    $this->repo->get_agent_summary();

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'GROUP BY', $last['query'] );
    $this->assertStringContainsString( 'SUM', $last['query'] );
    $this->assertStringContainsString( 'COUNT(DISTINCT', $last['query'] );
}

public function test_get_agent_summary_with_date_filter(): void {
    $this->wpdb->mock_get_results = [];
    $this->repo->get_agent_summary( [ 'date_from' => '2026-03-01' ] );

    $last = end( $this->wpdb->queries );
    $this->assertStringContainsString( 'access_date >=', $last['query'] );
}
```

- [ ] **Step 2: Run the two new tests to confirm they fail**

```bash
./vendor/bin/phpunit --no-coverage --filter "test_get_agent_summary"
```

Expected: 2 FAIL — `get_agent_summary` method does not exist.

- [ ] **Step 3: Add `get_agent_summary()` to `StatsRepository`**

Open `src/Stats/StatsRepository.php`. After the closing `}` of `get_distinct_agents()` (around line 166), insert:

```php
/**
 * Return per-agent totals for the given filters.
 *
 * @since  1.3.0
 * @param  array<string, mixed> $filters  Supports post_id, agent, date_from, date_to.
 * @return array<int, object>             Each object has agent (string), total (int), unique_posts (int).
 */
public function get_agent_summary( array $filters = array() ): array {
    $table  = self::get_table_name( $this->wpdb );
    $clause = $this->build_where( $filters );

    $where_sql = $clause['sql'];
    $values    = $clause['values'];

    $sql = "SELECT agent, SUM(count) AS total, COUNT(DISTINCT post_id) AS unique_posts FROM {$table} {$where_sql} GROUP BY agent ORDER BY total DESC";

    if ( ! empty( $values ) ) {
        $sql = $this->wpdb->prepare( $sql, ...$values );
    }

    return $this->wpdb->get_results( $sql );
}
```

- [ ] **Step 4: Run the two new tests to confirm they pass**

```bash
./vendor/bin/phpunit --no-coverage --filter "test_get_agent_summary"
```

Expected: 2 PASS.

- [ ] **Step 5: Run the full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: 216 tests, all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Stats/StatsRepository.php tests/Unit/Stats/StatsRepositoryTest.php
git commit -m "feat: add StatsRepository::get_agent_summary() with grouped query"
```

---

## Task 4: Filter bar — date inputs and preset links

**Files:**
- Modify: `tests/Unit/Stats/StatsPageTest.php`
- Modify: `src/Stats/StatsPage.php`

- [ ] **Step 1: Add `$_GET` cleanup to `StatsPageTest`**

Open `tests/Unit/Stats/StatsPageTest.php`. Add a `tearDown()` method after `setUp()`:

```php
protected function tearDown(): void {
    $_GET = [];
}
```

Also add `$_GET = [];` as the first line of `setUp()`:

```php
protected function setUp(): void {
    $_GET = [];
    $GLOBALS['_mock_menu_pages']       = [];
    $GLOBALS['_mock_current_user_can'] = true;

    $this->repository = $this->createMock( StatsRepository::class );
    $this->page       = new StatsPage( $this->repository );
}
```

- [ ] **Step 2: Write the two failing tests**

After `test_render_page_returns_early_without_permission`, add:

```php
public function test_render_page_shows_date_inputs_in_form(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'name="date_from"', $output );
    $this->assertStringContainsString( 'name="date_to"', $output );
}

public function test_render_page_shows_preset_links(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'Last 7 days', $output );
    $this->assertStringContainsString( 'Last 30 days', $output );
    $this->assertStringContainsString( 'This month', $output );
    $this->assertStringContainsString( 'All time', $output );
}
```

- [ ] **Step 3: Run the two new tests to confirm they fail**

```bash
./vendor/bin/phpunit --no-coverage --filter "test_render_page_shows_date_inputs_in_form|test_render_page_shows_preset_links"
```

Expected: 2 FAIL.

- [ ] **Step 4: Update `render_page()` — add date reading and preset computation**

Open `src/Stats/StatsPage.php`. Replace the block that reads GET params and builds filters (lines 53–67):

```php
$filter_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;    // phpcs:ignore WordPress.Security.NonceVerification
$filter_agent   = isset( $_GET['agent'] ) ? sanitize_text_field( (string) $_GET['agent'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$paged          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;             // phpcs:ignore WordPress.Security.NonceVerification

$date_from = '';
if ( isset( $_GET['date_from'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
    $raw = sanitize_text_field( (string) $_GET['date_from'] ); // phpcs:ignore WordPress.Security.NonceVerification
    if ( false !== \DateTime::createFromFormat( 'Y-m-d', $raw ) ) {
        $date_from = $raw;
    }
}
$date_to = '';
if ( isset( $_GET['date_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
    $raw = sanitize_text_field( (string) $_GET['date_to'] ); // phpcs:ignore WordPress.Security.NonceVerification
    if ( false !== \DateTime::createFromFormat( 'Y-m-d', $raw ) ) {
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
```

- [ ] **Step 5: Update the filter form HTML — add date inputs**

In the HTML section, find the agent `<select>` block followed by `submit_button()`:

```php
					<select name="agent">
						<option value=""><?php esc_html_e( 'All agents', 'wp-markdown-for-agents' ); ?></option>
						<?php foreach ( $agents as $agent ) : ?>
							<option value="<?php echo esc_attr( $agent ); ?>" <?php selected( $filter_agent, $agent ); ?>>
								<?php echo esc_html( $agent ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'wp-markdown-for-agents' ), 'secondary', 'filter', false ); ?>
```

Replace with:

```php
					<select name="agent">
						<option value=""><?php esc_html_e( 'All agents', 'wp-markdown-for-agents' ); ?></option>
						<?php foreach ( $agents as $agent ) : ?>
							<option value="<?php echo esc_attr( $agent ); ?>" <?php selected( $filter_agent, $agent ); ?>>
								<?php echo esc_html( $agent ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<label><?php esc_html_e( 'From', 'wp-markdown-for-agents' ); ?></label>
					<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
					<label><?php esc_html_e( 'To', 'wp-markdown-for-agents' ); ?></label>
					<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
					<?php submit_button( __( 'Filter', 'wp-markdown-for-agents' ), 'secondary', 'filter', false ); ?>
```

- [ ] **Step 6: Add preset links below the closing `</form>` tag**

Find `</form>` followed by the empty-state check. Insert after `</form>`:

```php
			<p>
				<a href="<?php echo esc_url( $preset_7d ); ?>"<?php echo $active_7d ? ' style="font-weight:bold;text-decoration:underline"' : ''; ?>><?php esc_html_e( 'Last 7 days', 'wp-markdown-for-agents' ); ?></a>
				|
				<a href="<?php echo esc_url( $preset_30d ); ?>"<?php echo $active_30d ? ' style="font-weight:bold;text-decoration:underline"' : ''; ?>><?php esc_html_e( 'Last 30 days', 'wp-markdown-for-agents' ); ?></a>
				|
				<a href="<?php echo esc_url( $preset_month ); ?>"<?php echo $active_month ? ' style="font-weight:bold;text-decoration:underline"' : ''; ?>><?php esc_html_e( 'This month', 'wp-markdown-for-agents' ); ?></a>
				|
				<a href="<?php echo esc_url( $preset_all ); ?>"<?php echo $active_all ? ' style="font-weight:bold;text-decoration:underline"' : ''; ?>><?php esc_html_e( 'All time', 'wp-markdown-for-agents' ); ?></a>
			</p>
```

- [ ] **Step 7: Run the two new tests to confirm they pass**

```bash
./vendor/bin/phpunit --no-coverage --filter "test_render_page_shows_date_inputs_in_form|test_render_page_shows_preset_links"
```

Expected: 2 PASS.

- [ ] **Step 8: Run the full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: 218 tests, all pass.

- [ ] **Step 9: Commit**

```bash
git add src/Stats/StatsPage.php tests/Unit/Stats/StatsPageTest.php
git commit -m "feat: add date range filter inputs and preset links to stats page"
```

---

## Task 5: Headline agent summary table

**Files:**
- Modify: `tests/Unit/Stats/StatsPageTest.php`
- Modify: `src/Stats/StatsPage.php`

- [ ] **Step 1: Write the two failing tests**

Open `tests/Unit/Stats/StatsPageTest.php`. After the tests added in Task 4, add:

```php
public function test_render_page_shows_headline_table_when_date_set(): void {
    $_GET['date_from'] = '2026-03-01';

    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );
    $this->repository->expects( $this->once() )
        ->method( 'get_agent_summary' )
        ->willReturn( [
            (object) [ 'agent' => 'GPTBot', 'total' => 10, 'unique_posts' => 3 ],
        ] );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'Total accesses', $output );
    $this->assertStringContainsString( 'GPTBot', $output );
}

public function test_render_page_hides_headline_table_without_date(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );
    $this->repository->expects( $this->never() )->method( 'get_agent_summary' );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringNotContainsString( 'Total accesses', $output );
}
```

- [ ] **Step 2: Run the two new tests to confirm they fail**

```bash
./vendor/bin/phpunit --no-coverage --filter "test_render_page_shows_headline_table|test_render_page_hides_headline_table"
```

Expected: 2 FAIL — `get_agent_summary` not called / `Total accesses` not in output.

- [ ] **Step 3: Add the headline table to `render_page()` in `StatsPage.php`**

Find the line `<?php if ( empty( $rows ) ) : ?>` in the HTML section. Insert the following block immediately before it:

```php
			<?php if ( '' !== $date_from || '' !== $date_to ) : ?>
				<?php $summary = $this->repository->get_agent_summary( $count_filters ); ?>
				<h2><?php esc_html_e( 'Summary', 'wp-markdown-for-agents' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Agent', 'wp-markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Total accesses', 'wp-markdown-for-agents' ); ?></th>
							<th><?php esc_html_e( 'Unique posts', 'wp-markdown-for-agents' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $summary ) ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No data for this period.', 'wp-markdown-for-agents' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $summary as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row->agent ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td>
									<td><?php echo esc_html( (string) $row->unique_posts ); ?></td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td><strong><?php esc_html_e( 'Total', 'wp-markdown-for-agents' ); ?></strong></td>
								<td><strong><?php echo esc_html( number_format_i18n( (int) array_sum( array_column( $summary, 'total' ) ) ) ); ?></strong></td>
								<td>&mdash;</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>

```

- [ ] **Step 4: Run the two new tests to confirm they pass**

```bash
./vendor/bin/phpunit --no-coverage --filter "test_render_page_shows_headline_table|test_render_page_hides_headline_table"
```

Expected: 2 PASS.

- [ ] **Step 5: Run the full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: 220 tests, all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Stats/StatsPage.php tests/Unit/Stats/StatsPageTest.php
git commit -m "feat: add agent summary headline table to stats page when date filter is active"
```

---

## Verification

After all tasks are complete:

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: 220 tests, all pass (211 existing + 9 new).

**Manual QA checklist:**
1. Open the Agent Stats admin page — confirm date inputs and preset links appear in the filter bar
2. Click "Last 7 days" — confirm "Last 7 days" is bolded/underlined, the headline table appears, and the detail table is filtered
3. Click "All time" — confirm no headline table, "All time" is active
4. Set a custom From/To date via the inputs and click Filter — confirm headline table appears
5. Set only a From date — confirm partial range works (detail table filtered, headline table shown)
6. Confirm the headline table totals row sums correctly

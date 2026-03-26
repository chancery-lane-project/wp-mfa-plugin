# Stats Page — WordPress Admin Styling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply standard WordPress admin CSS classes and markup conventions to `StatsPage::render_page()` so the page looks and behaves like a native WP admin screen.

**Architecture:** All changes are markup-only within the single `render_page()` method of `src/Stats/StatsPage.php`. No logic changes, no new classes, no custom CSS. Tests use `ob_start()`/`ob_get_clean()` to capture rendered HTML and assert on class names and structural patterns.

**Tech Stack:** PHP 8.0+, PHPUnit 9.6, WordPress admin CSS (no custom stylesheets)

**Spec:** `docs/superpowers/specs/2026-03-26-stats-page-wp-admin-styling-design.md`

---

## Files

- Modify: `src/Stats/StatsPage.php` — all markup changes live in `render_page()`
- Modify: `tests/Unit/Stats/StatsPageTest.php` — new tests for markup structure
- Modify: `tests/mocks/wordpress-mocks.php` — add `_n()` stub (needed for Task 4)

---

## Task 1: Preset date links → `.subsubsub`

**Files:**
- Modify: `src/Stats/StatsPage.php`
- Modify: `tests/Unit/Stats/StatsPageTest.php`

The existing preset links appear after the filter `<form>` inside a `<p>` tag, ordered Last 7 days | Last 30 days | This month | All time. They use inline styles for the active state. Replace with a `<ul class="subsubsub">` above the form, ordered All time | Last 7 days | Last 30 days | This month, using `class="current"` for the active link.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Stats/StatsPageTest.php`, within the class, after the existing tests:

```php
public function test_preset_links_rendered_as_subsubsub(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'class="subsubsub"', $output );
}

public function test_preset_links_order_all_time_first(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $pos_all  = strpos( $output, 'All time' );
    $pos_7d   = strpos( $output, 'Last 7 days' );
    $this->assertLessThan( $pos_7d, $pos_all, 'All time should appear before Last 7 days' );
}

public function test_active_preset_link_has_current_class(): void {
    $_GET['date_from'] = date( 'Y-m-d', strtotime( '-6 days' ) );
    $_GET['date_to']   = date( 'Y-m-d' );
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'class="current"', $output );
}

public function test_preset_links_have_no_inline_styles(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringNotContainsString( 'font-weight:bold', $output );
}
```

Also add this private helper at the bottom of the class (before the closing `}`), which the new tests and future tasks use to avoid repeating stub setup:

```php
private function stub_empty_repository(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );
}
```

- [ ] **Step 2: Run new tests to verify they fail**

```bash
composer test -- --filter="test_preset_links|test_active_preset"
```

Expected: 4 failures (subsubsub class not present, order wrong, current class missing, inline style present).

- [ ] **Step 3: Implement — replace `<p>` preset links with `.subsubsub`**

In `src/Stats/StatsPage.php`, find the HTML output block inside `render_page()`. Make two changes:

**a)** Move the preset links above the `<form>` tag and replace the `<p>…</p>` block with:

```php
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
```

**b)** Delete the old `<p>…</p>` block that contained the four preset links with pipe separators (lines ~158–166 in the current file).

- [ ] **Step 4: Run all tests**

```bash
composer test
```

Expected: all tests pass, including the 4 new ones.

- [ ] **Step 5: Commit**

```bash
git add src/Stats/StatsPage.php tests/Unit/Stats/StatsPageTest.php
git commit -m "feat: replace preset date links with .subsubsub nav"
```

---

## Task 2: Filter form → `alignleft actions` + label/id pairing

**Files:**
- Modify: `src/Stats/StatsPage.php`
- Modify: `tests/Unit/Stats/StatsPageTest.php`

Wrap the filter selects and date inputs inside `<div class="alignleft actions">` within the existing `<div class="tablenav top">`. Add `<br class="clear">` after. Add `id="date_from"` / `id="date_to"` to the date inputs and `for="date_from"` / `for="date_to"` to their labels.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Stats/StatsPageTest.php`:

```php
public function test_filter_controls_wrapped_in_alignleft_actions(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'class="alignleft actions"', $output );
}

public function test_date_inputs_have_ids(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'id="date_from"', $output );
    $this->assertStringContainsString( 'id="date_to"', $output );
}

public function test_date_labels_have_for_attributes(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'for="date_from"', $output );
    $this->assertStringContainsString( 'for="date_to"', $output );
}
```

- [ ] **Step 2: Run new tests to verify they fail**

```bash
composer test -- --filter="test_filter_controls|test_date_inputs|test_date_labels"
```

Expected: 3 failures.

- [ ] **Step 3: Implement — wrap controls and add id/for**

In the `<div class="tablenav top">` block in `render_page()`, replace its contents with:

```php
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
```

- [ ] **Step 4: Run all tests**

```bash
composer test
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Stats/StatsPage.php tests/Unit/Stats/StatsPageTest.php
git commit -m "feat: wrap filter controls in alignleft actions, add label/id pairing"
```

---

## Task 3: Table column classes

**Files:**
- Modify: `src/Stats/StatsPage.php`
- Modify: `tests/Unit/Stats/StatsPageTest.php`

Both tables get `wp-list-table widefat fixed striped`. Every `<th>` gets `scope="col"` and `class="manage-column column-{name}"`. Numeric `<th>` and `<td>` cells get `class="num"`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Stats/StatsPageTest.php`:

```php
public function test_main_table_has_wp_list_table_classes(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'wp-list-table widefat fixed striped', $output );
}

public function test_column_headers_have_scope_col(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'scope="col"', $output );
}

public function test_count_column_header_has_num_class(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'column-count num', $output );
}

public function test_count_cell_has_num_class(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 1 );
    $this->repository->method( 'get_stats' )->willReturn( [
        (object) [ 'post_id' => 1, 'agent' => 'GPTBot', 'access_method' => 'ua', 'access_date' => '2026-03-26', 'count' => 5 ],
    ] );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    // The count <td> should carry class="num"
    $this->assertMatchesRegularExpression( '/<td class="num">\s*5\s*<\/td>/', $output );
}

public function test_summary_table_numeric_columns_have_num_class(): void {
    $_GET['date_from'] = '2026-03-01';
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 0 );
    $this->repository->method( 'get_agent_summary' )->willReturn( [
        (object) [ 'agent' => 'GPTBot', 'access_method' => 'ua', 'total' => 42, 'unique_posts' => 3 ],
    ] );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    // Summary table header for Total accesses should carry num class
    $this->assertStringContainsString( 'column-total num', $output );
    // Summary table data cells for total and unique_posts should carry class="num"
    $this->assertMatchesRegularExpression( '/<td class="num">\s*42\s*<\/td>/', $output );
    $this->assertMatchesRegularExpression( '/<td class="num">\s*3\s*<\/td>/', $output );
}
```

- [ ] **Step 2: Run new tests to verify they fail**

```bash
composer test -- --filter="test_main_table_has_wp|test_column_headers|test_count_column|test_count_cell|test_summary_table"
```

Expected: 4 failures.

- [ ] **Step 3: Implement — update table and column markup**

**a) Both tables:** Change `class="widefat striped"` → `class="wp-list-table widefat fixed striped"` (two occurrences).

**b) Main table `<thead>` — replace the four `<th>` elements:**

```php
					<tr>
						<th scope="col" class="manage-column column-post"><?php esc_html_e( 'Post', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-agent"><?php esc_html_e( 'Agent', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-access-method"><?php esc_html_e( 'Access Method', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-count num"><?php esc_html_e( 'Count', 'markdown-for-agents' ); ?></th>
					</tr>
```

**c) Main table data rows — add `class="num"` to the Count `<td>` only:**

```php
							<tr>
								<td><?php echo esc_html( get_the_title( (int) $row->post_id ) ); ?></td>
								<td><?php echo esc_html( '' !== $row->agent ? $row->agent : '(unknown)' ); ?></td>
								<td><?php echo esc_html( $row->access_method ); ?></td>
								<td><?php echo esc_html( $row->access_date ); ?></td>
								<td class="num"><?php echo esc_html( (string) $row->count ); ?></td>
							</tr>
```

**d) Summary table `<thead>` — replace the four `<th>` elements:**

```php
					<tr>
						<th scope="col" class="manage-column column-agent"><?php esc_html_e( 'Agent', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-access-method"><?php esc_html_e( 'Access Method', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-total num"><?php esc_html_e( 'Total accesses', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-unique num"><?php esc_html_e( 'Unique posts', 'markdown-for-agents' ); ?></th>
					</tr>
```

**e) Summary table data rows — add `class="num"` to Total and Unique `<td>` cells:**

```php
								<tr>
									<td><?php echo esc_html( '' !== $row->agent ? $row->agent : '(unknown)' ); ?></td>
									<td><?php echo esc_html( $row->access_method ); ?></td>
									<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td>
									<td class="num"><?php echo esc_html( (string) $row->unique_posts ); ?></td>
								</tr>
```

**f) Summary totals row — add `class="num"` to the total count `<td>`:**

```php
							<tr>
								<td><strong><?php esc_html_e( 'Total', 'markdown-for-agents' ); ?></strong></td>
								<td>&mdash;</td>
								<td class="num"><strong><?php echo esc_html( number_format_i18n( (int) array_sum( array_column( $summary, 'total' ) ) ) ); ?></strong></td>
								<td>&mdash;</td>
							</tr>
```

- [ ] **Step 4: Run all tests**

```bash
composer test
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Stats/StatsPage.php tests/Unit/Stats/StatsPageTest.php
git commit -m "feat: apply wp-list-table classes, manage-column, scope and num to tables"
```

---

## Task 4: Pagination → WP standard pattern

**Files:**
- Modify: `src/Stats/StatsPage.php`
- Modify: `tests/Unit/Stats/StatsPageTest.php`
- Modify: `tests/mocks/wordpress-mocks.php`

Replace the numbered page button loop with the standard WP list-table pagination: `tablenav-pages` > `displaying-num` + `pagination-links`, using `<span>` for disabled buttons and `<a>` for active ones.

- [ ] **Step 1: Add `_n()` mock to the test stubs**

In `tests/mocks/wordpress-mocks.php`, find the `if (!function_exists('__'))` block. After it, add:

```php
if (!function_exists('_n')) {
    function _n(string $single, string $plural, $count, string $domain = 'default'): string {
        return (int) $count === 1 ? $single : $plural;
    }
}
```

- [ ] **Step 2: Write the failing tests**

Add to `tests/Unit/Stats/StatsPageTest.php`:

```php
public function test_pagination_shows_displaying_num(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    // 51 total rows → 2 pages → pagination renders
    $this->repository->method( 'get_total_count' )->willReturn( 51 );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'displaying-num', $output );
    $this->assertStringContainsString( '51 items', $output );
}

public function test_pagination_shows_pagination_links(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 51 );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'pagination-links', $output );
    $this->assertStringContainsString( 'tablenav-pages', $output );
}

public function test_pagination_first_prev_disabled_on_page_one(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 51 );
    // No $_GET['paged'] set → defaults to page 1. Disabled spans have no first-page class —
    // that class only appears on the active <a> element on pages 2+.

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'tablenav-pages-navspan button disabled', $output );
    $this->assertStringNotContainsString( 'class="prev-page button"', $output );
}

public function test_pagination_first_prev_active_on_page_two(): void {
    $_GET['paged'] = '2';
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 101 ); // 3 pages

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'class="first-page button"', $output );
    $this->assertStringContainsString( 'class="prev-page button"', $output );
}

public function test_pagination_shows_x_of_y_label(): void {
    $this->repository->method( 'get_distinct_agents' )->willReturn( [] );
    $this->repository->method( 'get_posts_with_stats' )->willReturn( [] );
    $this->repository->method( 'get_stats' )->willReturn( [] );
    $this->repository->method( 'get_total_count' )->willReturn( 51 );

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( '1 of 2', $output );
}

public function test_pagination_not_shown_for_single_page(): void {
    $this->stub_empty_repository(); // 0 total → 1 page (ceil(0/50)=0, but logic uses >1)

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringNotContainsString( 'pagination-links', $output );
}
```

- [ ] **Step 3: Run new tests to verify they fail**

```bash
composer test -- --filter="test_pagination_"
```

Expected: 5 failures (displaying-num not present, pagination-links not present, etc.).

- [ ] **Step 4: Implement — replace numbered pagination with WP pattern**

In `src/Stats/StatsPage.php`, replace the entire `<?php if ( $total_pages > 1 ) : ?>` block (including its `<div class="tablenav bottom">`) with:

```php
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'markdown-for-agents' ), number_format_i18n( $total ) ) ); ?>
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
```

- [ ] **Step 5: Run all tests**

```bash
composer test
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Stats/StatsPage.php tests/Unit/Stats/StatsPageTest.php tests/mocks/wordpress-mocks.php
git commit -m "feat: replace numbered pagination with WP standard prev/next pattern"
```

---

## Task 5: Empty state — always render table with `<thead>`

**Files:**
- Modify: `src/Stats/StatsPage.php`
- Modify: `tests/Unit/Stats/StatsPageTest.php`

Currently when `$rows` is empty the entire main table is skipped and a bare `<p>` is output. Restructure so the table always renders with its `<thead>`, and the empty state appears as a `<tr colspan="5">` inside `<tbody>`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Stats/StatsPageTest.php`:

```php
public function test_main_table_thead_always_rendered(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    // Even with no rows, the column headers must be present
    $this->assertStringContainsString( 'column-date', $output );
    $this->assertStringContainsString( 'column-count', $output );
}

public function test_empty_state_rendered_inside_table(): void {
    $this->stub_empty_repository();

    ob_start();
    $this->page->render_page();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'colspan="5"', $output );
    $this->assertStringContainsString( 'No access data recorded yet', $output );
    // Should NOT be a bare <p> outside a table
    $this->assertStringNotContainsString( '<p>No access data recorded yet', $output );
}
```

- [ ] **Step 2: Run new tests to verify they fail**

```bash
composer test -- --filter="test_main_table_thead|test_empty_state_rendered"
```

Expected: 2 failures (`column-date` not found when rows empty; `<p>` still present).

- [ ] **Step 3: Implement — restructure the empty-rows branch**

In `src/Stats/StatsPage.php`, replace the block starting with `<?php if ( empty( $rows ) ) : ?>` through to the closing `<?php endif; ?>` for the main table section with:

```php
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" class="manage-column column-post"><?php esc_html_e( 'Post', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-agent"><?php esc_html_e( 'Agent', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-access-method"><?php esc_html_e( 'Access Method', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'markdown-for-agents' ); ?></th>
						<th scope="col" class="manage-column column-count num"><?php esc_html_e( 'Count', 'markdown-for-agents' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No access data recorded yet.', 'markdown-for-agents' ); ?></td>
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
```

Note: this replaces the two separate blocks (the `<p>` branch and the `<table>` branch). The `<?php if ( $total_pages > 1 ) : ?>` pagination block follows immediately after this table.

- [ ] **Step 4: Run all tests**

```bash
composer test
```

Expected: all pass. Verify the count: the suite should have grown from 250 to ~264 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Stats/StatsPage.php tests/Unit/Stats/StatsPageTest.php
git commit -m "feat: always render main table thead; empty state inside tbody"
```

---

## Final check

- [ ] **Run full test suite one last time**

```bash
composer test
```

Expected: all tests pass, no regressions.

- [ ] **Push**

```bash
git push
```

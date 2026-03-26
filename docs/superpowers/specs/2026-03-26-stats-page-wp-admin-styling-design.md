# Design: Stats Page — WordPress Admin Styling

**Date:** 2026-03-26
**File:** `src/Stats/StatsPage.php`
**Scope:** Markup-only improvements to `render_page()`. No logic changes, no new classes, no custom CSS.

---

## Goal

Make the Agent Access Statistics admin page look and behave exactly like a native WordPress admin screen by applying standard WP admin CSS classes and markup conventions throughout.

---

## Section 1: Page structure and filter area

### Preset date links → `.subsubsub`

Move the date preset links **above** the filter form (they currently appear after it, wrapped in a `<p>` tag — remove the `<p>` entirely). Render as `<ul class="subsubsub">`.

**Order change:** The existing code renders Last 7 days | Last 30 days | This month | All time. The new order is **All time | Last 7 days | Last 30 days | This month** — broadest scope first, matching the WP convention (e.g. All | Published | Draft | Trash).

- Active link gets `class="current"` — no inline styles.
- Pipe separators live **inside** each `<li>` (after the `<a>` tag, before `</li>`), matching the WP convention. The final `<li>` has no pipe.

```html
<ul class="subsubsub">
    <li><a href="…" class="current">All time</a> |</li>
    <li><a href="…">Last 7 days</a> |</li>
    <li><a href="…">Last 30 days</a> |</li>
    <li><a href="…">This month</a></li>
</ul>
```

### Filter form → `alignleft actions`

The filter `<form>` wraps a `<div class="tablenav top">`. Inside, all selects and date inputs move into `<div class="alignleft actions">`. A `<br class="clear">` follows. Note: `actions` is included here because this div contains interactive filter controls (selects + button), not a count display.

Date inputs get `id="date_from"` / `id="date_to"` paired with their `<label for="…">`. All i18n calls remain unchanged — `__( 'Filter', 'markdown-for-agents' )` is passed to `submit_button()`.

```html
<form method="get" action="">
    <input type="hidden" name="page" value="…">
    <div class="tablenav top">
        <div class="alignleft actions">
            <select name="post_id">…</select>
            <select name="agent">…</select>
            <select name="access_method">…</select>
            <label for="date_from"><?php esc_html_e( 'From', 'markdown-for-agents' ); ?></label>
            <input type="date" id="date_from" name="date_from" value="…">
            <label for="date_to"><?php esc_html_e( 'To', 'markdown-for-agents' ); ?></label>
            <input type="date" id="date_to" name="date_to" value="…">
            <?php submit_button( __( 'Filter', 'markdown-for-agents' ), 'secondary', 'filter', false ); ?>
        </div>
        <br class="clear">
    </div>
</form>
```

---

## Section 2: Table markup

### Classes

Both the summary table and the main data table use:

```html
<table class="wp-list-table widefat fixed striped">
```

### Column headers

Every `<th>` gets `scope="col"` and `class="manage-column column-{name}"`.

**Main table columns:**

| Column | Class |
|---|---|
| Post | `manage-column column-post` |
| Agent | `manage-column column-agent` |
| Access Method | `manage-column column-access-method` |
| Date | `manage-column column-date` |
| Count | `manage-column column-count num` |

**Summary table columns:**

| Column | Class |
|---|---|
| Agent | `manage-column column-agent` |
| Access Method | `manage-column column-access-method` |
| Total accesses | `manage-column column-total num` |
| Unique posts | `manage-column column-unique num` |

### Number cells

`<td>` cells in the Count, Total accesses, and Unique posts columns get `class="num"` for right-alignment.

### Totals row

The summary table's totals row `<td>` cells also receive `class="num"` on the numeric cells.

No `<tfoot>` is added — it is optional in WP list tables and omitted here for simplicity.

---

## Section 3: Pagination

Replace the numbered button loop with the standard WP list-table pagination pattern.

The `<div class="tablenav bottom">` contains a `<div class="tablenav-pages">` which holds both the item count and the navigation links:

```html
<div class="tablenav bottom">
    <div class="tablenav-pages">
        <span class="displaying-num">X items</span>
        <span class="pagination-links">
            <!-- first-page / prev-page / label / next-page / last-page -->
        </span>
    </div>
</div>
```

Note: `displaying-num` sits directly inside `tablenav-pages` (not inside an `alignleft` div) — this matches WP core's `WP_List_Table` output.

### Active vs. disabled buttons

- **Active buttons** are `<a>` elements with an `href` pointing to the relevant page.
- **Disabled buttons** (first/prev on page 1; next/last on the final page) are `<span>` elements with no `href`. Using `<span>` rather than `<a aria-disabled="true" href="…">` matches WP core and avoids clickable-but-inert links.

**Page 1 of 5** (first-page and prev-page disabled):

```html
<span class="pagination-links">
    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
    <span class="paging-input">
        <span class="tablenav-paging-text">1 of 5</span>
    </span>
    <a class="next-page button" href="…">›</a>
    <a class="last-page button" href="…">»</a>
</span>
```

**Page 3 of 5** (all four buttons active):

```html
<span class="pagination-links">
    <a class="first-page button" href="…">«</a>
    <a class="prev-page button" href="…">‹</a>
    <span class="paging-input">
        <span class="tablenav-paging-text">3 of 5</span>
    </span>
    <a class="next-page button" href="…">›</a>
    <a class="last-page button" href="…">»</a>
</span>
```

The active state for each button uses the same class name as the disabled span, but as an `<a>` element with an `href` and without `aria-hidden`. The `displaying-num` item count uses `_n()` for correct singular/plural: `_n( '%s item', '%s items', $total, 'markdown-for-agents' )` formatted with `number_format_i18n()`.

The pagination block only renders when `$total_pages > 1`. The existing `$total` and `$total_pages` variables are reused — no new logic required.

---

## Section 4: Empty state

The current code skips rendering the main table entirely when `$rows` is empty, outputting a bare `<p>` instead. This changes: the main table is **always rendered** with its full `<thead>`. When there are no rows, `<tbody>` contains a single spanning row — the existing `if ( empty( $rows ) )` branch must be restructured accordingly.

```html
<tbody>
    <tr>
        <td colspan="5"><?php esc_html_e( 'No access data recorded yet.', 'markdown-for-agents' ); ?></td>
    </tr>
</tbody>
```

The summary table already uses this pattern. Its colspan is 4 (Agent, Access Method, Total accesses, Unique posts) — correct as-is.

---

## What does NOT change

- All PHP logic (filter parsing, query calls, pagination maths) — untouched.
- All text strings and i18n calls — untouched (the code examples above show i18n calls in full).
- `StatsRepository`, `StatsPage::add_page()`, or any other method — untouched.
- No custom CSS file added; all styling comes from WP core admin stylesheets.

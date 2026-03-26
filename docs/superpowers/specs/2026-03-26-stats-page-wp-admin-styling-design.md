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

Move the date preset links **above** the filter form. Render as `<ul class="subsubsub">` in the order: All time | Last 7 days | Last 30 days | This month.

- Active link gets `class="current"` — no inline styles.
- Pipe separators live **inside** each `<li>` (before the closing tag), matching the WP convention. The final `<li>` has no pipe.

```html
<ul class="subsubsub">
    <li><a href="…" class="current">All time</a> |</li>
    <li><a href="…">Last 7 days</a> |</li>
    <li><a href="…">Last 30 days</a> |</li>
    <li><a href="…">This month</a></li>
</ul>
```

### Filter form → `alignleft actions`

The filter `<form>` wraps a `<div class="tablenav top">`. Inside, all selects and date inputs move into `<div class="alignleft actions">`. A `<br class="clear">` follows.

Date inputs get `id="date_from"` / `id="date_to"` paired with their `<label for="…">`.

```html
<form method="get" action="">
    <input type="hidden" name="page" value="…">
    <div class="tablenav top">
        <div class="alignleft actions">
            <select name="post_id">…</select>
            <select name="agent">…</select>
            <select name="access_method">…</select>
            <label for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" value="…">
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" value="…">
            <?php submit_button( 'Filter', 'secondary', 'filter', false ); ?>
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

---

## Section 3: Pagination

Replace the numbered button loop with the standard WP list-table pagination pattern.

The `<div class="tablenav bottom">` contains:

- **Left:** `<span class="displaying-num">X items</span>` inside `<div class="alignleft">`.
- **Right:** `<span class="pagination-links">` containing, in order:
  - First-page button (`«`) — `class="first-page button"`, disabled on page 1
  - Prev-page button (`‹`) — `class="prev-page button"`, disabled on page 1
  - Current page label — `<span class="paging-input"><span class="tablenav-paging-text">X of Y</span></span>`
  - Next-page button (`›`) — `class="next-page button"`, disabled on last page
  - Last-page button (`»`) — `class="last-page button"`, disabled on last page

Disabled buttons use `aria-disabled="true"` and add `disabled` to their class list (replacing the `tablenav-pages-navspan` span approach).

The pagination block only renders when `$total_pages > 1`.

---

## Section 4: Empty state

The main table is always rendered with its full `<thead>`. When there are no rows, `<tbody>` contains a single spanning row:

```html
<tbody>
    <tr>
        <td colspan="5"><?php esc_html_e( 'No access data recorded yet.', 'markdown-for-agents' ); ?></td>
    </tr>
</tbody>
```

The summary table already uses this pattern; confirm `colspan="4"` is correct (it is — four columns: Agent, Access Method, Total accesses, Unique posts).

---

## What does NOT change

- All PHP logic (filter parsing, query calls, pagination maths) — untouched.
- Text strings and i18n calls — untouched.
- `StatsRepository`, `StatsPage::add_page()`, or any other method — untouched.
- No custom CSS file added; all styling comes from WP core admin stylesheets.

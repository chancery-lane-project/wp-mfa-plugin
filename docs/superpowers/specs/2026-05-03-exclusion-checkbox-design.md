# Per-post Exclusion Checkbox — Design Spec

**Date:** 2026-05-03  
**Branch:** feature/exclusion-checkbox  
**Status:** Approved

---

## Overview

Add a checkbox to the existing "Markdown for Agents" metabox on post edit screens that lets editors exclude an individual post from Markdown output entirely. When excluded:

- No `.md` file is written to disk (and any existing file is deleted immediately).
- The file is not served via content negotiation (`Accept: text/markdown`, `?output_format=md`, or known-UA).
- The `<link rel="alternate">` tag is suppressed in `<head>`.

The checkbox is saved via the standard WordPress `save_post` flow with a nonce field, consistent with the plugin's existing nonce patterns.

---

## Data Model

**Meta key:** `_markdown_for_agents_excluded`  
**Value when excluded:** `'1'` (string)  
**Value when not excluded:** absent (key deleted, not set to empty string)

- Private key (underscore prefix) — hidden from the custom fields UI by default.
- No database migration needed — WP post meta is schema-free.
- Same storage pattern as the existing `_markdown_for_agents_generating` guard flag.

---

## MetaBox UI and Save

### Render (`MetaBox::render()`)

Add a nonce field and checkbox above the existing buttons paragraph. PHP render output:

```php
<?php wp_nonce_field( 'markdown_for_agents_exclude', 'markdown_for_agents_exclude_nonce' ); ?>
<p>
    <label>
        <input type="checkbox" name="markdown_for_agents_excluded" value="1"
               <?php checked( get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ), '1' ); ?>>
        <?php esc_html_e( 'Exclude from Markdown output', 'markdown-for-agents-and-statistics' ); ?>
    </label>
</p>
```

When excluded, the Regenerate link and Preview Markdown button must be visually disabled. The `render()` method reads the exclusion meta once into `$excluded` (bool) and uses it to conditionally render:

- **Regenerate:** rendered as plain text (no `<a>` href) with a `disabled` CSS class when `$excluded`. The `disabled` HTML attribute has no effect on `<a>` elements, so the href must be omitted entirely.
- **Preview Markdown:** rendered with the `disabled` attribute on the `<button>` element when `$excluded`.

Both operations would silently fail if triggered on an excluded post (since `is_eligible()` returns false), so removing interactivity is the correct behaviour.

The status line ("Generated" / "No Markdown file generated yet.") continues to reflect filesystem state. After checking the box and saving, the status will update to "No Markdown file generated yet." because the file is deleted on save.

### Save (`MetaBox::save( int $post_id ): void`) — new method

1. Return early if `defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE`.
2. Return early if `wp_is_post_revision( $post_id )`.
3. Verify nonce from `$_POST['markdown_for_agents_exclude_nonce']` against action `markdown_for_agents_exclude` — return early if invalid.
4. Check `current_user_can( 'edit_post', $post_id )` — return early if fails.
5. Read and sanitise value: `$excluded = sanitize_key( wp_unslash( $_POST['markdown_for_agents_excluded'] ?? '' ) ) === '1'`
6. If `$excluded`:
   - `update_post_meta( $post_id, '_markdown_for_agents_excluded', '1' )`
   - `$this->generator->delete_post( $post_id )` — removes the `.md` file immediately.
7. Else:
   - `delete_post_meta( $post_id, '_markdown_for_agents_excluded' )` — file stays on disk until next regeneration (auto-generate on next save, or via Regenerate button).

`Generator` is already injected into `MetaBox` — no constructor change needed.

### Hook wiring

- New method `Admin::handle_meta_box_save( int $post_id ): void` delegates to `$this->meta_box->save( $post_id )`.
- `Plugin::define_admin_hooks()` wires: `save_post` → `$admin->handle_meta_box_save` at **priority 5** (before `Generator::on_save_post` at priority 10). This guarantees the exclusion meta is written before `is_eligible()` reads it on the same save.
- **This hook must be registered unconditionally** — outside the `auto_generate` guard in `define_generator()`. Exclusion meta must always be persisted regardless of the auto-generate setting, so the checkbox works on sites where auto-generation is disabled.

---

## Generator Integration

### `is_eligible()` — `Generator.php`

Add one condition to the existing check:

```php
&& ! get_post_meta( $post->ID, '_markdown_for_agents_excluded', true )
```

`generate_post()` and `get_post_markdown()` both call `is_eligible()` first, so both return `false` for excluded posts with no further work.

### `on_save_post()` — `Generator.php`

Add exclusion to the delete branch (defence-in-depth — handles meta set externally, e.g. via CLI import, without going through the metabox save flow):

```php
} elseif ( in_array( $post->post_status, array( 'trash', 'draft', 'pending', 'private' ), true )
    || ( 'publish' === $post->post_status && '' !== $post->post_password )
    || get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
    $this->delete_post( $post_id );
}
```

Both `$post->ID` and `$post_id` are valid here; use `$post->ID` for consistency with `is_eligible()`.

---

## Negotiator Integration

Add an exclusion guard in both `maybe_serve_markdown()` and `output_link_tag()`, immediately after the existing `post_status` / `post_password` check and before the `markdown_for_agents_serve_enabled` filter:

```php
if ( get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
    return;
}
```

**Ordering note:** The exclusion check intentionally runs before the `markdown_for_agents_serve_enabled` filter — meta exclusion takes precedence over the filter. `get_post_meta()` is backed by the WP object cache so the overhead per page load is a cache read, not a raw DB query, on sites with a persistent object cache.

Defensive — the `.md` file should not exist for excluded posts — but guards against stale files or meta set externally.

---

## Testing

### Additions to existing `tests/Unit/Admin/MetaBoxTest.php`

| Test | Assertion |
|------|-----------|
| `test_save_sets_meta_and_deletes_file_when_excluded` | Nonce valid, cap passes, checkbox ticked → meta written, `delete_post` called on Generator |
| `test_save_clears_meta_when_not_excluded` | Checkbox unticked → `delete_post_meta` called, Generator `delete_post` not called; existing file left on disk |
| `test_save_skips_autosave` | `DOING_AUTOSAVE` defined → no meta write |
| `test_save_skips_revision` | `wp_is_post_revision` returns post ID → no meta write |
| `test_save_does_nothing_with_invalid_nonce` | Bad nonce → no meta write |
| `test_render_checkbox_checked_when_excluded` | Meta `'1'` → rendered output contains `checked` |
| `test_render_checkbox_unchecked_when_not_excluded` | No meta → rendered output does not contain `checked` |

**Note on render tests:** The `wp_nonce_field()` stub in `tests/mocks/wordpress-mocks.php` outputs only the nonce `<input>` (not the referer field). Render tests should assert against the checkbox and button elements specifically, not on full output equality, to avoid fragility against stub behaviour.

### Additions to `tests/Unit/Generator/GeneratorTest.php`

| Test | Assertion |
|------|-----------|
| `test_generate_post_skips_excluded_post` | Meta set → `generate_post()` returns false, no file write |
| `test_on_save_post_deletes_for_excluded_published_post` | Published post with exclusion meta → `delete` called, not `write` |

### Additions to `tests/Unit/Negotiate/NegotiatorTest.php`

| Test | Assertion |
|------|-----------|
| `test_does_nothing_for_excluded_post` | Exclusion meta set → `get_export_path` never called |
| `test_link_tag_not_output_for_excluded_post` | Exclusion meta set → empty output |

---

## Files Changed

| File | Change |
|------|--------|
| `src/Admin/MetaBox.php` | Add nonce + checkbox to `render()`; disable buttons when excluded; add `save()` method |
| `src/Admin/Admin.php` | Add `handle_meta_box_save()` method |
| `src/Core/Plugin.php` | Wire `save_post` → `Admin::handle_meta_box_save` at priority 5 |
| `src/Generator/Generator.php` | Update `is_eligible()` and `on_save_post()` |
| `src/Negotiate/Negotiator.php` | Add exclusion guard in `maybe_serve_markdown()` and `output_link_tag()` |
| `tests/Unit/Admin/MetaBoxTest.php` | Add 7 new tests to existing class |
| `tests/Unit/Generator/GeneratorTest.php` | Add 2 new tests |
| `tests/Unit/Negotiate/NegotiatorTest.php` | Add 2 new tests |

---

## Out of Scope

- No WP-CLI flag for per-post exclusion (existing CLI bulk-generate already respects `is_eligible()`).
- No taxonomy archive exclusion (separate feature if needed).
- No REST API / Gutenberg sidebar panel (`save_post` flow works for both editors).

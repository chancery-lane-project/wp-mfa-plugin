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

The checkbox is saved via the standard WordPress `save_post` flow with a nonce field (Option A), consistent with the plugin's existing nonce patterns.

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

Add a nonce field and checkbox above the existing buttons paragraph:

```html
<input type="hidden" name="markdown_for_agents_exclude_nonce"
       value="{wp_create_nonce('markdown_for_agents_exclude')}">
<p>
    <label>
        <input type="checkbox" name="markdown_for_agents_excluded" value="1"
               [checked when meta is '1']>
        Exclude from Markdown output
    </label>
</p>
```

The status line ("Generated" / "No Markdown file generated yet.") continues to reflect filesystem state. After checking the box and saving, the status will update to "No Markdown file generated yet." because the file is deleted on save.

### Save (`MetaBox::save( int $post_id ): void`) — new method

1. Return early if `defined('DOING_AUTOSAVE') && DOING_AUTOSAVE`.
2. Return early if `wp_is_post_revision( $post_id )`.
3. Verify nonce: `wp_verify_nonce( $_POST['markdown_for_agents_exclude_nonce'] ?? '', 'markdown_for_agents_exclude' )` — return early if invalid.
4. Check capability: `current_user_can( 'edit_post', $post_id )` — return early if fails.
5. If `$_POST['markdown_for_agents_excluded'] === '1'`:
   - `update_post_meta( $post_id, '_markdown_for_agents_excluded', '1' )`
   - `$this->generator->delete_post( $post_id )` — removes the `.md` file immediately.
6. Else:
   - `delete_post_meta( $post_id, '_markdown_for_agents_excluded' )` — file regenerated on next save (if auto-generate is on) or via Regenerate button.

`Generator` is already injected into `MetaBox` — no constructor change needed.

### Hook wiring

- New method `Admin::handle_meta_box_save( int $post_id ): void` delegates to `$this->meta_box->save( $post_id )`.
- `Plugin::define_admin_hooks()` adds: `save_post` → `$admin->handle_meta_box_save`, priority 10, 1 arg.

---

## Generator Integration

### `is_eligible()` — `Generator.php`

Add one condition to the existing check:

```php
&& ! get_post_meta( $post->ID, '_markdown_for_agents_excluded', true )
```

`generate_post()` and `get_post_markdown()` both call `is_eligible()` first, so both return `false` for excluded posts with no further work.

### `on_save_post()` — `Generator.php`

Add exclusion to the delete branch (defence-in-depth — handles meta set externally, e.g. via CLI import):

```php
} elseif ( in_array( $post->post_status, array( 'trash', 'draft', 'pending', 'private' ), true )
    || ( 'publish' === $post->post_status && '' !== $post->post_password )
    || get_post_meta( $post_id, '_markdown_for_agents_excluded', true ) ) {
    $this->delete_post( $post_id );
}
```

A published, non-passworded, but excluded post triggers `delete_post()` rather than `generate_post()`.

---

## Negotiator Integration

Add an exclusion guard in both `maybe_serve_markdown()` and `output_link_tag()`, immediately after the existing `post_status` / `post_password` check:

```php
if ( get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
    return;
}
```

Defensive — the `.md` file should not exist — but guards against stale files or meta set externally.

---

## Testing

### New test class: `tests/Unit/Admin/MetaBoxTest.php`

| Test | Assertion |
|------|-----------|
| `test_save_sets_meta_and_deletes_file_when_excluded` | Nonce valid, cap passes, checkbox ticked → meta written, `delete_post` called on Generator |
| `test_save_clears_meta_when_not_excluded` | Checkbox unticked → `delete_post_meta` called, Generator `delete_post` not called |
| `test_save_skips_autosave` | `DOING_AUTOSAVE` defined → no meta write |
| `test_save_skips_revision` | `wp_is_post_revision` returns post ID → no meta write |
| `test_save_does_nothing_with_invalid_nonce` | Bad nonce → no meta write |
| `test_render_checkbox_checked_when_excluded` | Meta `'1'` → rendered output contains `checked` |
| `test_render_checkbox_unchecked_when_not_excluded` | No meta → rendered output does not contain `checked` |

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
| `src/Admin/MetaBox.php` | Add nonce + checkbox to `render()`; add `save()` method |
| `src/Admin/Admin.php` | Add `handle_meta_box_save()` method |
| `src/Core/Plugin.php` | Wire `save_post` → `Admin::handle_meta_box_save` |
| `src/Generator/Generator.php` | Update `is_eligible()` and `on_save_post()` |
| `src/Negotiate/Negotiator.php` | Add exclusion guard in `maybe_serve_markdown()` and `output_link_tag()` |
| `tests/Unit/Admin/MetaBoxTest.php` | New test class (7 tests) |
| `tests/Unit/Generator/GeneratorTest.php` | 2 new tests |
| `tests/Unit/Negotiate/NegotiatorTest.php` | 2 new tests |
| `tests/mocks/wordpress-mocks.php` | Add `get_post_meta` / `update_post_meta` / `delete_post_meta` stubs if not present |

---

## Out of Scope

- No WP-CLI flag for per-post exclusion (existing CLI bulk-generate already respects `is_eligible()`).
- No taxonomy archive exclusion (separate feature if needed).
- No REST API / Gutenberg sidebar panel (Option A `save_post` flow works for both editors).

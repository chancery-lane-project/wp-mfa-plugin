# AJAX Bulk Generation — Design Spec

**Date:** 2026-03-17
**Scope:** Replace the synchronous `admin-post.php` "Generate all" form POST with a client-driven AJAX batching loop that shows a live counter and logs per-post errors without halting the run.

---

## Problem

`Admin::handle_generate_action()` calls `Generator::generate_post_type()` synchronously inside a single PHP request. On sites with low hundreds of posts this can exceed PHP's `max_execution_time`, leaving the admin with a blank screen and no feedback. There is also no progress indication.

---

## Solution Overview

A small JS script intercepts the button click, drives sequential AJAX requests (10 posts per batch), updates a live counter after each batch, and shows a completion summary. Each individual PHP request is short and cannot timeout.

---

## Architecture

```
SettingsPage (PHP)
  └── renders buttons with data-post-type attribute
  └── enqueues bulk-generate.js + localises nonce/ajaxurl

bulk-generate.js
  └── intercepts button click
  └── sendBatch(postType, offset) loop
        └── POST admin-ajax.php action=mfa_generate_batch
              └── Admin::handle_generate_batch_ajax()
                    └── Generator::generate_batch(postType, offset, limit)
                          └── WP_Query (ids only, LIMIT/OFFSET)
                          └── generate_post(postId) per post
                          └── catch \Throwable → collect error, continue
                          └── return {total, processed, errors}
```

---

## 1. Generator changes

### New method: `generate_batch()`

```php
public function generate_batch(string $post_type, int $offset, int $limit): array
```

- Runs `WP_Query` with `post_type`, `post_status=publish`, `posts_per_page=$limit`, `offset=$offset`, `fields=ids`. Do **not** set `no_found_rows=true` — `found_posts` must be populated.
- `found_posts` provides the total matching posts (returned on every response so JS can use the first)
- For each post ID, calls `get_post($post_id)` to obtain a `\WP_Post` object, then calls the existing `$this->generate_post(\WP_Post $post)` (already public, line 46 of `Generator.php`)
- Three outcomes per post:
  - `generate_post()` returns `true` → increment `$processed`
  - `generate_post()` returns `false` (ineligible/skipped) → no count, no error
  - `generate_post()` throws `\Throwable` → append to `$errors`, continue
- Returns:
  ```php
  [
      'total'     => int,   // WP_Query::found_posts
      'processed' => int,   // posts where generate_post() returned true
      'errors'    => [['post_id' => int, 'message' => string], ...],
  ]
  ```

**No new private method needed.** `generate_batch()` resolves IDs to `\WP_Post` objects via `get_post()` and calls the existing `generate_post()`. `generate_post_type()` is unchanged.

---

## 2. Admin changes

### New method: `handle_generate_batch_ajax()`

```php
public function handle_generate_batch_ajax(): void
```

- `check_ajax_referer('mfa_generate_batch', 'nonce')` — dies on failure
- `current_user_can('manage_options')` — `wp_send_json_error(['message' => 'Unauthorised'], 403)` on failure
- Sanitises inputs: `sanitize_key($_POST['post_type'])`, `absint($_POST['offset'])`, `min(absint($_POST['limit']), 50)`
- Calls `$this->generator->generate_batch($post_type, $offset, $limit)`
- Returns `wp_send_json_success($result)`

### Hook registration

Hooks are registered in `Plugin.php` via the loader, alongside the existing admin hooks (lines 138–143). Add two lines after line 143:

```php
$this->loader->add_action( 'wp_ajax_mfa_generate_batch', $admin, 'handle_generate_batch_ajax' );
$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
```

---

## 3. SettingsPage changes

### Button markup

Each generate button gains:
- `type="button"` (removes form submission)
- `data-post-type="{post_type}"` attribute

The wrapping `<form>` element and `admin_url('admin-post.php')` action are removed for the generate buttons. (The existing `admin_post_mfa_generate` handler is left in place for non-JS fallback — it can be removed in a later cleanup.)

### Script enqueue

New `enqueue_scripts(string $hook)` method on `Admin`, registered via `Plugin.php` (see Hook registration above):
- Returns early unless `$hook === 'settings_page_wp-markdown-for-agents'`
- `wp_enqueue_script('mfa-bulk-generate', WP_MFA_PLUGIN_URL . 'assets/js/bulk-generate.js', [], WP_MFA_VERSION, true)`
- `wp_localize_script('mfa-bulk-generate', 'mfaBulkGenerate', ['nonce' => wp_create_nonce('mfa_generate_batch'), 'ajaxurl' => admin_url('admin-ajax.php')])`

---

## 4. JavaScript: `assets/js/bulk-generate.js`

Self-contained; no external dependencies beyond the browser and WordPress's `ajaxurl` (provided via localisation).

```
On DOMContentLoaded:
  for each button[data-post-type]:
    addEventListener('click', handleGenerateClick)

handleGenerateClick(event):
  postType = button.dataset.postType
  disable button
  button.textContent = "0 / …"
  accumulated = {processed: 0, errors: []}
  sendBatch(postType, offset=0, accumulated, button)

sendBatch(postType, offset, accumulated, button):
  POST to mfaBulkGenerate.ajaxurl:
    action=mfa_generate_batch, nonce, post_type, offset, limit=10
  on success (data):
    accumulated.processed += data.processed
    accumulated.errors.push(...data.errors)
    button.textContent = accumulated.processed + " / " + data.total
    if offset + 10 < data.total:
      sendBatch(postType, offset + 10, accumulated, button)
    else:
      errorSummary = accumulated.errors.length ? ", " + accumulated.errors.length + " error(s)" : ""
      button.textContent = "Done: " + accumulated.processed + " processed" + errorSummary
      enable button
  on error:
    button.textContent = "Error — generation stopped"
    enable button
```

---

## 5. Tests

### `tests/Unit/Admin/AdminAjaxTest.php` (new)

- Valid request returns `{total, processed, errors}` shape
- Missing/invalid nonce triggers `wp_die` (via `check_ajax_referer`)
- Non-admin user receives JSON error 403
- `post_type` is sanitised via `sanitize_key`
- `limit` is capped at 50

### `tests/Unit/Generator/GeneratorTest.php` (extend)

- `generate_batch()` returns correct `total` and `processed` count
- Post that throws is collected in `errors`; remaining posts are still processed
- Empty result set returns `['total' => 0, 'processed' => 0, 'errors' => []]`

---

## Affected files

| File | Change |
|------|--------|
| `src/Generator/Generator.php` | Add `generate_batch()` |
| `src/Admin/Admin.php` | Add `handle_generate_batch_ajax()` and `enqueue_scripts()` |
| `src/Admin/SettingsPage.php` | Update button markup |
| `src/Core/Plugin.php` | Register `wp_ajax_mfa_generate_batch` and `admin_enqueue_scripts` hooks |
| `assets/js/bulk-generate.js` | New |
| `tests/Unit/Admin/AdminAjaxTest.php` | New |
| `tests/Unit/Generator/GeneratorTest.php` | Extend with batch tests |

---

## Success criteria

1. Clicking "Generate" on the settings page fires AJAX requests (visible in browser DevTools)
2. Counter updates after each batch: `"10 / 47"`, `"20 / 47"`, …
3. A post that fails to generate does not stop the run; the error is included in the final summary
4. No PHP timeout on runs of 200 posts
5. All existing tests pass after changes

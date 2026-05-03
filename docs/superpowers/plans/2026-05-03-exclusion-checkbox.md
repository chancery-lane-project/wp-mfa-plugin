# Exclusion Checkbox Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-post "Exclude from Markdown output" checkbox to the existing metabox that prevents `.md` file generation and serving for excluded posts.

**Architecture:** Store the exclusion state in post meta (`_markdown_for_agents_excluded = '1'`). Enforce it in three layers: Generator (skip generation, delete on save), Negotiator (refuse to serve), and MetaBox (save meta + delete file, render checkbox + disable buttons). Save via standard `save_post` hook at priority 5 so meta is written before Generator's priority-10 hook reads it.

**Tech Stack:** PHP 8.1+, WordPress hooks/meta API, PHPUnit 9, existing WordPress function stubs in `tests/mocks/wordpress-mocks.php`.

**Spec:** `docs/superpowers/specs/2026-05-03-exclusion-checkbox-design.md`

---

## File Map

| File | Action | What changes |
|------|--------|-------------|
| `src/Generator/Generator.php` | Modify | `is_eligible()` + `on_save_post()` exclusion checks |
| `src/Negotiate/Negotiator.php` | Modify | Exclusion guard in `maybe_serve_markdown()` and `output_link_tag()` |
| `src/Admin/MetaBox.php` | Modify | Add `save()` method; update `render()` with checkbox and conditional button state |
| `src/Admin/Admin.php` | Modify | Add `handle_meta_box_save()` delegation method |
| `src/Core/Plugin.php` | Modify | Restructure `define_admin_hooks()` to register `save_post` hook before the `is_admin()` guard |
| `tests/mocks/wordpress-mocks.php` | Modify | Add `_mock_is_post_revision` control; add `disabled()` stub |
| `tests/Unit/Generator/GeneratorTest.php` | Modify | 2 new tests |
| `tests/Unit/Negotiate/NegotiatorTest.php` | Modify | 2 new tests |
| `tests/Unit/Admin/MetaBoxTest.php` | Modify | Add `setUp()` resets + 7 new tests |

---

## Task 1: Generator — exclusion in `is_eligible()` and `on_save_post()`

**Files:**
- Modify: `src/Generator/Generator.php:535-540` (`is_eligible`)
- Modify: `src/Generator/Generator.php:330-335` (`on_save_post`)
- Modify: `tests/Unit/Generator/GeneratorTest.php`

---

- [ ] **Step 1.1 — Write the two failing tests**

Add to `tests/Unit/Generator/GeneratorTest.php`:

Place `test_generate_post_skips_excluded_post` immediately after `test_generate_post_skips_non_published_post` (after the existing `test_generate_post_skips_password_protected_post`):

```php
public function test_generate_post_skips_excluded_post(): void {
    $post = $this->make_post();
    $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';

    $this->file_writer->expects( $this->never() )->method( 'write' );

    $result = $this->generator->generate_post( $post );

    $this->assertFalse( $result );
}
```

Place `test_on_save_post_deletes_for_excluded_published_post` immediately after `test_on_save_post_deletes_for_password_protected_published_post`:

```php
public function test_on_save_post_deletes_for_excluded_published_post(): void {
    $post = $this->make_post( [ 'post_status' => 'publish' ] );
    $GLOBALS['_mock_post_objects'][1] = $post;
    $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';

    $this->file_writer->expects( $this->once() )->method( 'delete' )->willReturn( true );
    $this->file_writer->expects( $this->never() )->method( 'write' );

    $this->generator->on_save_post( 1, $post );
}
```

- [ ] **Step 1.2 — Run the failing tests**

```bash
composer test -- --filter "test_generate_post_skips_excluded_post|test_on_save_post_deletes_for_excluded_published_post"
```

Expected: 2 FAIL — `is_eligible()` doesn't check exclusion yet.

- [ ] **Step 1.3 — Update `is_eligible()` in `Generator.php`**

In `src/Generator/Generator.php`, find `is_eligible()` at line ~535. Replace with:

```php
private function is_eligible( \WP_Post $post ): bool {
    $enabled_types = (array) ( $this->options['post_types'] ?? array() );
    return in_array( $post->post_type, $enabled_types, true )
        && 'publish' === $post->post_status
        && '' === $post->post_password
        && ! get_post_meta( $post->ID, '_markdown_for_agents_excluded', true );
}
```

- [ ] **Step 1.4 — Update `on_save_post()` delete branch in `Generator.php`**

In `src/Generator/Generator.php`, find the `if/elseif` block at line ~330. Replace with:

```php
if ( 'publish' === $post->post_status && '' === $post->post_password ) {
    $this->generate_post( $post );
} elseif ( in_array( $post->post_status, array( 'trash', 'draft', 'pending', 'private' ), true )
    || ( 'publish' === $post->post_status && '' !== $post->post_password )
    || get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
    $this->delete_post( $post_id );
}
```

- [ ] **Step 1.5 — Run all tests**

```bash
composer test
```

Expected: all tests pass.

- [ ] **Step 1.6 — Commit**

```bash
git add src/Generator/Generator.php tests/Unit/Generator/GeneratorTest.php
git commit -m "feat: exclude posts from MD generation when exclusion meta is set"
```

---

## Task 2: Negotiator — exclusion guard

**Files:**
- Modify: `src/Negotiate/Negotiator.php:66-68` (`maybe_serve_markdown`) and `~150-152` (`output_link_tag`)
- Modify: `tests/Unit/Negotiate/NegotiatorTest.php`

---

- [ ] **Step 2.1 — Write the two failing tests**

Add to `tests/Unit/Negotiate/NegotiatorTest.php`. Place `test_does_nothing_for_excluded_post` after the existing `test_does_nothing_for_password_protected_post`, and `test_link_tag_not_output_for_excluded_post` after `test_link_tag_not_output_for_password_protected_post`:

```php
public function test_does_nothing_for_excluded_post(): void {
    $post = $this->make_post();
    $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    $this->generator->expects( $this->never() )->method( 'get_export_path' );

    $this->make_negotiator()->maybe_serve_markdown();
    $this->addToAssertionCount( 1 );
}
```

```php
public function test_link_tag_not_output_for_excluded_post(): void {
    $post = $this->make_post();
    $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;

    $this->generator->expects( $this->never() )->method( 'get_export_path' );

    ob_start();
    $this->make_negotiator()->output_link_tag();
    $output = ob_get_clean();

    $this->assertSame( '', $output );
}
```

- [ ] **Step 2.2 — Run the failing tests**

```bash
composer test -- --filter "test_does_nothing_for_excluded_post|test_link_tag_not_output_for_excluded_post"
```

Expected: 2 FAIL.

- [ ] **Step 2.3 — Add exclusion guard to `maybe_serve_markdown()`**

In `src/Negotiate/Negotiator.php`, after the existing `post_status`/`post_password` check at line ~66–68, insert:

```php
if ( get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
    return;
}
```

The surrounding context should read:

```php
if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
    return;
}

if ( get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
    return;
}

/**
 * Whether to serve Markdown for this specific post.
```

- [ ] **Step 2.4 — Add exclusion guard to `output_link_tag()`**

In `src/Negotiate/Negotiator.php`, after the `post_status`/`post_password` check in `output_link_tag()` at line ~150–152, insert the same guard:

```php
if ( get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
    return;
}
```

- [ ] **Step 2.5 — Run all tests**

```bash
composer test
```

Expected: all tests pass.

- [ ] **Step 2.6 — Commit**

```bash
git add src/Negotiate/Negotiator.php tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "feat: suppress MD serving and link tag for excluded posts"
```

---

## Task 3: Mocks + MetaBox `save()` + hook wiring

**Files:**
- Modify: `tests/mocks/wordpress-mocks.php`
- Modify: `tests/Unit/Admin/MetaBoxTest.php`
- Modify: `src/Admin/MetaBox.php`
- Modify: `src/Admin/Admin.php`
- Modify: `src/Core/Plugin.php`

---

- [ ] **Step 3.1 — Update `tests/mocks/wordpress-mocks.php`: `wp_is_post_revision` + `disabled()`**

**a) Add `_mock_is_post_revision` global.** Find the admin-globals block at line ~437 (near `_mock_meta_boxes` and `_mock_current_user_can`). Add the new global to that block:

```php
$GLOBALS['_mock_meta_boxes']           = [];
$GLOBALS['_mock_current_user_can']     = true;
$GLOBALS['_mock_is_post_revision']     = false;   // ← add this line
$GLOBALS['_mock_transients']           = [];
```

**b) Update the `wp_is_post_revision` stub** (currently at line ~327) to respect the global:

```php
if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int|\WP_Post $post): int|false {
        return $GLOBALS['_mock_is_post_revision'] ?? false;
    }
}
```

**c) Add `disabled()` stub** — this WP helper is not present in the mocks and is used in the updated `render()`. Add it near `checked()` (which is around line ~848):

```php
if (!function_exists('disabled')) {
    function disabled(mixed $helper, mixed $current = true, bool $echo = true): string {
        $result = $helper === $current ? ' disabled="disabled"' : '';
        if ($echo) echo $result;
        return $result;
    }
}
```

- [ ] **Step 3.2 — Update `setUp()` in `MetaBoxTest.php`**

The existing `setUp()` only resets `_mock_meta_boxes`. All the new save/render tests manipulate `$_POST`, `_mock_post_meta`, `_mock_verify_nonce`, and `_mock_is_post_revision`. Without resetting these between tests, state leaks across test methods (e.g. `test_save_does_nothing_with_invalid_nonce` sets `_mock_verify_nonce = false`, which would break any test that runs after it).

Replace the existing `setUp()`:

```php
protected function setUp(): void {
    $GLOBALS['_mock_meta_boxes']       = [];
    $GLOBALS['_mock_post_meta']        = [];
    $GLOBALS['_mock_verify_nonce']     = 1;
    $GLOBALS['_mock_current_user_can'] = true;
    $GLOBALS['_mock_is_post_revision'] = false;
    $_POST                             = [];
    $this->generator = $this->createMock( Generator::class );
}
```

- [ ] **Step 3.3 — Write the five failing save() tests**

Add to `tests/Unit/Admin/MetaBoxTest.php` in a new `// save()` section, **placed before any DOING_AUTOSAVE test**. The autosave test must be last since `define()` cannot be undone in the same PHP process:

```php
// -----------------------------------------------------------------------
// save()
// -----------------------------------------------------------------------

public function test_save_sets_meta_and_deletes_file_when_excluded(): void {
    $_POST['markdown_for_agents_exclude_nonce'] = 'valid';
    $_POST['markdown_for_agents_excluded']      = '1';

    $this->generator->expects( $this->once() )->method( 'delete_post' )->with( 1 );

    $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
    $meta_box->save( 1 );

    $this->assertSame( '1', $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] ?? null );
}

public function test_save_clears_meta_when_not_excluded(): void {
    $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';
    $_POST['markdown_for_agents_exclude_nonce'] = 'valid';
    // $_POST['markdown_for_agents_excluded'] intentionally absent — checkbox unticked.

    $this->generator->expects( $this->never() )->method( 'delete_post' );

    $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
    $meta_box->save( 1 );

    $this->assertArrayNotHasKey( '_markdown_for_agents_excluded', $GLOBALS['_mock_post_meta'][1] ?? [] );
}

public function test_save_does_nothing_with_invalid_nonce(): void {
    $GLOBALS['_mock_verify_nonce']              = false;
    $_POST['markdown_for_agents_exclude_nonce'] = 'bad';
    $_POST['markdown_for_agents_excluded']      = '1';

    $this->generator->expects( $this->never() )->method( 'delete_post' );

    $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
    $meta_box->save( 1 );

    $this->assertArrayNotHasKey( '_markdown_for_agents_excluded', $GLOBALS['_mock_post_meta'][1] ?? [] );
}

public function test_save_skips_revision(): void {
    $GLOBALS['_mock_is_post_revision']          = 5; // non-false = is a revision
    $_POST['markdown_for_agents_exclude_nonce'] = 'valid';
    $_POST['markdown_for_agents_excluded']      = '1';

    $this->generator->expects( $this->never() )->method( 'delete_post' );

    $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
    $meta_box->save( 1 );

    $this->assertArrayNotHasKey( '_markdown_for_agents_excluded', $GLOBALS['_mock_post_meta'][1] ?? [] );
}

// MUST BE LAST in save() tests — define() cannot be undone; DOING_AUTOSAVE
// will be true for all tests that run after this in the same PHP process.
public function test_save_skips_autosave(): void {
    if ( ! defined( 'DOING_AUTOSAVE' ) ) {
        define( 'DOING_AUTOSAVE', true );
    }
    $_POST['markdown_for_agents_excluded'] = '1';

    $this->generator->expects( $this->never() )->method( 'delete_post' );

    $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
    $meta_box->save( 1 );

    $this->assertArrayNotHasKey( '_markdown_for_agents_excluded', $GLOBALS['_mock_post_meta'][1] ?? [] );
}
```

- [ ] **Step 3.4 — Run the failing tests**

```bash
composer test -- --filter "MetaBoxTest"
```

Expected: 5 new failures — `MetaBox::save()` doesn't exist yet.

- [ ] **Step 3.5 — Add `save()` to `MetaBox.php`**

In `src/Admin/MetaBox.php`, add after the closing `}` of `render()` (before the final class `}`):

```php
/**
 * Save the exclusion checkbox value from the metabox form.
 *
 * Hooked to `save_post` at priority 5 — runs before Generator::on_save_post
 * at priority 10 so the exclusion meta is readable on the same save.
 *
 * @since  1.3.0
 * @param  int $post_id The post being saved.
 */
public function save( int $post_id ): void {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    if ( ! wp_verify_nonce(
        sanitize_key( wp_unslash( $_POST['markdown_for_agents_exclude_nonce'] ?? '' ) ),
        'markdown_for_agents_exclude'
    ) ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $excluded = sanitize_key( wp_unslash( $_POST['markdown_for_agents_excluded'] ?? '' ) ) === '1';

    if ( $excluded ) {
        update_post_meta( $post_id, '_markdown_for_agents_excluded', '1' );
        $this->generator->delete_post( $post_id );
    } else {
        delete_post_meta( $post_id, '_markdown_for_agents_excluded' );
    }
}
```

- [ ] **Step 3.6 — Add `handle_meta_box_save()` to `Admin.php`**

In `src/Admin/Admin.php`, add after `add_meta_boxes()`:

```php
/**
 * Delegate save_post to MetaBox::save().
 *
 * Hooked to `save_post` at priority 5.
 *
 * @since  1.3.0
 * @param  int $post_id The post being saved.
 */
public function handle_meta_box_save( int $post_id ): void {
    $this->meta_box->save( $post_id );
}
```

- [ ] **Step 3.7 — Restructure `define_admin_hooks()` in `Plugin.php`**

The `save_post` hook must register unconditionally — regardless of `is_admin()` and regardless of the `auto_generate` setting — so the exclusion meta is always persisted. The current `define_admin_hooks()` has an early `return` if `! is_admin()`. Move the `save_post` hook registration **before** that early return.

Replace the entire `define_admin_hooks()` method in `src/Core/Plugin.php`:

```php
private function define_admin_hooks( array $options ): void {
    $admin = new Admin( $options, $this->generator, $this->taxonomy_generator );

    // Registered unconditionally — exclusion meta must be saved regardless of
    // is_admin() or auto_generate setting. Priority 5 runs before
    // Generator::on_save_post at priority 10.
    $this->loader->add_action( 'save_post', $admin, 'handle_meta_box_save', 5, 1 );

    if ( ! is_admin() ) {
        return;
    }

    $this->loader->add_action( 'admin_menu', $admin, 'add_settings_page' );
    $this->loader->add_action( 'admin_init', $admin, 'register_settings' );
    $this->loader->add_action( 'add_meta_boxes', $admin, 'add_meta_boxes' );
    $this->loader->add_action( 'admin_post_markdown_for_agents_generate', $admin, 'handle_generate_action' );
    $this->loader->add_action( 'admin_post_markdown_for_agents_regenerate_post', $admin, 'handle_regenerate_post_action' );
    $this->loader->add_action( 'admin_notices', $admin, 'display_admin_notices' );
    $this->loader->add_action( 'wp_ajax_mfa_generate_batch', $admin, 'handle_generate_batch_ajax' );
    $this->loader->add_action( 'wp_ajax_mfa_generate_taxonomy_batch', $admin, 'handle_generate_taxonomy_batch_ajax' );
    $this->loader->add_action( 'wp_ajax_mfa_preview_post', $admin, 'handle_preview_post_ajax' );
    $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );

    global $wpdb;
    $stats_page = new StatsPage( new StatsRepository( $wpdb ) );
    $this->loader->add_action( 'admin_menu', $stats_page, 'add_page' );
}
```

- [ ] **Step 3.8 — Run all tests**

```bash
composer test
```

Expected: all tests pass.

- [ ] **Step 3.9 — Commit**

```bash
git add tests/mocks/wordpress-mocks.php tests/Unit/Admin/MetaBoxTest.php \
        src/Admin/MetaBox.php src/Admin/Admin.php src/Core/Plugin.php
git commit -m "feat: add MetaBox save() for exclusion checkbox, wire save_post hook"
```

---

## Task 4: MetaBox `render()` — checkbox and conditional button state

**Files:**
- Modify: `src/Admin/MetaBox.php`
- Modify: `tests/Unit/Admin/MetaBoxTest.php`

---

- [ ] **Step 4.1 — Write the two failing render tests**

Add to `tests/Unit/Admin/MetaBoxTest.php` in a new `// render() — exclusion checkbox` section. Place these **before** `test_save_skips_autosave` (render tests must not run after the constant is defined):

```php
// -----------------------------------------------------------------------
// render() — exclusion checkbox
// -----------------------------------------------------------------------

public function test_render_checkbox_checked_when_excluded(): void {
    $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );
    $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';

    $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/path.md' );

    $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );

    ob_start();
    $meta_box->render( $post );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'checked="checked"', $output );
}

public function test_render_checkbox_unchecked_when_not_excluded(): void {
    $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );
    // No exclusion meta set.

    $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/path.md' );

    $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );

    ob_start();
    $meta_box->render( $post );
    $output = ob_get_clean();

    $this->assertStringNotContainsString( 'checked="checked"', $output );
}
```

- [ ] **Step 4.2 — Run the failing tests**

```bash
composer test -- --filter "test_render_checkbox"
```

Expected: 2 FAIL — checkbox not rendered yet.

- [ ] **Step 4.3 — Replace `render()` in `MetaBox.php`**

Replace the entire `render()` method body with:

```php
public function render( \WP_Post $post ): void {
    $filepath  = $this->generator->get_export_path( $post );
    $exists    = file_exists( $filepath );
    $excluded  = (bool) get_post_meta( $post->ID, '_markdown_for_agents_excluded', true );

    $regen_url     = wp_nonce_url(
        admin_url( 'admin-post.php?action=markdown_for_agents_regenerate_post&post_id=' . $post->ID ),
        'markdown_for_agents_regenerate'
    );
    $preview_nonce = wp_create_nonce( 'mfa_preview_post_' . $post->ID );
    ?>
    <?php wp_nonce_field( 'markdown_for_agents_exclude', 'markdown_for_agents_exclude_nonce' ); ?>
    <p>
        <label>
            <input type="checkbox" name="markdown_for_agents_excluded" value="1"
                   <?php checked( $excluded, true ); ?>>
            <?php esc_html_e( 'Exclude from Markdown output', 'markdown-for-agents-and-statistics' ); ?>
        </label>
    </p>
    <p>
        <?php if ( $exists ) : ?>
            <strong><?php esc_html_e( 'Markdown file:', 'markdown-for-agents-and-statistics' ); ?></strong>
            <?php esc_html_e( 'Generated', 'markdown-for-agents-and-statistics' ); ?><br>
            <small><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) filemtime( $filepath ) ) ); ?></small>
        <?php else : ?>
            <?php esc_html_e( 'No Markdown file generated yet.', 'markdown-for-agents-and-statistics' ); ?>
        <?php endif; ?>
    </p>
    <p>
        <?php if ( $excluded ) : ?>
            <span class="button button-secondary button-small" aria-disabled="true" style="opacity:0.5;cursor:default;">
                <?php esc_html_e( 'Regenerate', 'markdown-for-agents-and-statistics' ); ?>
            </span>
        <?php else : ?>
            <a href="<?php echo esc_url( $regen_url ); ?>" class="button button-secondary button-small">
                <?php esc_html_e( 'Regenerate', 'markdown-for-agents-and-statistics' ); ?>
            </a>
        <?php endif; ?>
        <button type="button" class="button button-secondary button-small mfa-preview-btn"
                data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
                data-nonce="<?php echo esc_attr( $preview_nonce ); ?>"
                data-ajaxurl="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
                <?php disabled( $excluded, true ); ?>>
            <?php esc_html_e( 'Preview Markdown', 'markdown-for-agents-and-statistics' ); ?>
        </button>
    </p>
    <details class="mfa-preview-output" hidden>
        <summary><?php esc_html_e( 'Markdown preview', 'markdown-for-agents-and-statistics' ); ?></summary>
        <pre class="mfa-preview-content" style="max-height:300px;overflow:auto;font-size:11px;white-space:pre-wrap;"></pre>
    </details>
    <?php
}
```

- [ ] **Step 4.4 — Run all tests**

```bash
composer test
```

Expected: all tests pass.

- [ ] **Step 4.5 — Commit**

```bash
git add src/Admin/MetaBox.php tests/Unit/Admin/MetaBoxTest.php
git commit -m "feat: render exclusion checkbox and disable buttons for excluded posts"
```

---

## Task 5: Final verification

- [ ] **Step 5.1 — Run the full test suite**

```bash
composer test
```

Expected: all tests pass. Starting count was 299; expected ~310 (2 Generator + 2 Negotiator + 7 MetaBox = 11 new tests).

- [ ] **Step 5.2 — Invoke the verification skill**

Use `superpowers:verification-before-completion` to verify the implementation is complete before finishing.

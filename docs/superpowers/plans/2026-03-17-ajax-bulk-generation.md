# AJAX Bulk Generation Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the synchronous "Generate all" form POST with a client-driven AJAX batching loop (10 posts per batch) that shows a live counter and logs per-post errors without halting the run.

**Architecture:** A new `Generator::generate_batch()` method processes a slice of posts via `WP_Query` and returns `{total, processed, errors}`. `Admin::handle_generate_batch_ajax()` validates the request and delegates to it. A small JS file drives sequential AJAX calls and updates a counter on the button.

**Tech Stack:** PHP 8.0+, PHPUnit 9.6, vanilla JS (XMLHttpRequest, no jQuery dependency), WordPress Settings API, `wp_ajax_` hook.

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `tests/mocks/wordpress-mocks.php` | Modify | Add `WP_Query` class stub, AJAX function stubs, plugin constants |
| `src/Generator/Generator.php` | Modify | Add `generate_batch()` public method |
| `src/Admin/Admin.php` | Modify | Add `handle_generate_batch_ajax()` and `enqueue_scripts()` |
| `src/Core/Plugin.php` | Modify | Register `wp_ajax_mfa_generate_batch` and `admin_enqueue_scripts` hooks |
| `src/Admin/SettingsPage.php` | Modify | Replace `<form>` buttons with `<button type="button" data-post-type="...">` |
| `assets/js/bulk-generate.js` | Create | Client-side batch loop with counter UI |
| `tests/Unit/Generator/GeneratorTest.php` | Modify | Add `generate_batch()` tests |
| `tests/Unit/Admin/AdminAjaxTest.php` | Create | Tests for AJAX handler and script enqueue |

---

## Task 1: Test infrastructure — stubs and constants

**Files:**
- Modify: `wp-markdown-for-agents/tests/mocks/wordpress-mocks.php`

- [ ] **Step 1: Add plugin constants**

At the top of the file, after the existing `ABSPATH`/`WP_CONTENT_DIR` constants block, add:

```php
if (!defined('WP_MFA_PLUGIN_URL')) {
    define('WP_MFA_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-markdown-for-agents/');
}

if (!defined('WP_MFA_VERSION')) {
    define('WP_MFA_VERSION', '1.0.0-test');
}
```

- [ ] **Step 2: Add `WP_Query` class stub**

After the `WP_Post` stub block (after line ~328), add:

```php
// ---------------------------------------------------------------------------
// WP_Query stub
// ---------------------------------------------------------------------------

if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts       = [];
        public int   $found_posts = 0;

        /**
         * Constructor reads $GLOBALS['_mock_wp_query'] callable.
         * Callable signature: (array $args): array{0: int[], 1: int}
         * Returns [post_id_array, found_posts_count].
         */
        public function __construct(array $args) {
            global $_mock_wp_query;
            if (isset($_mock_wp_query) && is_callable($_mock_wp_query)) {
                [$this->posts, $this->found_posts] = ($_mock_wp_query)($args);
            }
        }
    }
}
```

- [ ] **Step 3: Add AJAX function stubs**

After the `check_admin_referer` stub block, add:

```php
if (!function_exists('check_ajax_referer')) {
    /**
     * Verifies AJAX nonce. Calls wp_die(-1) if nonce is invalid.
     * Tests control validity via $GLOBALS['_mock_verify_nonce'] (truthy = valid, falsy = invalid).
     */
    function check_ajax_referer(string $action = '-1', string $query_arg = 'nonce'): int|false {
        $valid = $GLOBALS['_mock_verify_nonce'] ?? 1;
        if (!$valid) {
            wp_die(-1);
        }
        return 1;
    }
}
```

- [ ] **Step 4: Add `wp_send_json_success` and `wp_send_json_error` stubs**

```php
if (!function_exists('wp_send_json_success')) {
    /**
     * Captures response in $GLOBALS['_mock_json_response'].
     * Shape: ['success' => true, 'data' => mixed]
     */
    function wp_send_json_success(mixed $data = null, int $status_code = 200): void {
        $GLOBALS['_mock_json_response'] = [
            'success' => true,
            'data'    => $data,
            'status'  => $status_code,
        ];
    }
}

if (!function_exists('wp_send_json_error')) {
    /**
     * Captures response in $GLOBALS['_mock_json_response'].
     * Shape: ['success' => false, 'data' => mixed, 'status' => int]
     */
    function wp_send_json_error(mixed $data = null, int $status_code = 0): void {
        $GLOBALS['_mock_json_response'] = [
            'success' => false,
            'data'    => $data,
            'status'  => $status_code,
        ];
    }
}
```

- [ ] **Step 5: Add script enqueue stubs**

```php
// ---------------------------------------------------------------------------
// Script enqueue stubs for Admin::enqueue_scripts()
// ---------------------------------------------------------------------------

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = false): void {
        $GLOBALS['_mock_enqueued_scripts'][$handle] = $src;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $object_name, array $l10n): bool {
        $GLOBALS['_mock_localized_scripts'][$handle] = [
            'object' => $object_name,
            'data'   => $l10n,
        ];
        return true;
    }
}
```

- [ ] **Step 6: Verify existing tests still pass**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage
```

Expected: all existing tests pass (currently 173).

- [ ] **Step 7: Commit**

```bash
git add wp-markdown-for-agents/tests/mocks/wordpress-mocks.php
git commit -m "test: add WP_Query stub, AJAX stubs, plugin constants to mocks"
```

---

## Task 2: `Generator::generate_batch()`

**Files:**
- Modify: `wp-markdown-for-agents/src/Generator/Generator.php`
- Modify: `wp-markdown-for-agents/tests/Unit/Generator/GeneratorTest.php`

- [ ] **Step 1: Write failing test — empty result set**

In `GeneratorTest.php`, add after the existing `generate_post_type` tests:

```php
// -----------------------------------------------------------------------
// generate_batch()
// -----------------------------------------------------------------------

public function test_generate_batch_returns_zero_totals_when_no_posts(): void {
    $GLOBALS['_mock_wp_query'] = fn( array $args ): array => [ [], 0 ];

    $result = $this->generator->generate_batch( 'post', 0, 10 );

    $this->assertSame( 0, $result['total'] );
    $this->assertSame( 0, $result['processed'] );
    $this->assertSame( [], $result['errors'] );
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --filter test_generate_batch_returns_zero_totals_when_no_posts --no-coverage
```

Expected: FAIL with "Call to undefined method" or similar.

- [ ] **Step 3: Write remaining failing tests**

```php
public function test_generate_batch_returns_processed_count(): void {
    $post1 = $this->make_post( [ 'ID' => 10, 'post_name' => 'post-10' ] );
    $post2 = $this->make_post( [ 'ID' => 11, 'post_name' => 'post-11' ] );

    $GLOBALS['_mock_wp_query']      = fn( array $args ): array => [ [ 10, 11 ], 2 ];
    $GLOBALS['_mock_post_objects']  = [ 10 => $post1, 11 => $post2 ];

    $this->frontmatter_builder->method( 'build' )->willReturn( [] );
    $this->content_filter->method( 'filter' )->willReturn( '' );
    $this->converter->method( 'convert' )->willReturn( '' );
    $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
    $this->file_writer->method( 'write' )->willReturn( true );

    $result = $this->generator->generate_batch( 'post', 0, 10 );

    $this->assertSame( 2, $result['total'] );
    $this->assertSame( 2, $result['processed'] );
    $this->assertSame( [], $result['errors'] );
}

public function test_generate_batch_collects_error_and_continues(): void {
    $post1 = $this->make_post( [ 'ID' => 20, 'post_name' => 'post-20' ] );
    $post2 = $this->make_post( [ 'ID' => 21, 'post_name' => 'post-21' ] );

    $GLOBALS['_mock_wp_query']      = fn( array $args ): array => [ [ 20, 21 ], 2 ];
    $GLOBALS['_mock_post_objects']  = [ 20 => $post1, 21 => $post2 ];

    // First call to build succeeds; second throws.
    $call = 0;
    $this->frontmatter_builder->method( 'build' )
        ->willReturnCallback( function () use ( &$call ): array {
            if ( ++$call === 2 ) {
                throw new \RuntimeException( 'build failed' );
            }
            return [];
        } );
    $this->content_filter->method( 'filter' )->willReturn( '' );
    $this->converter->method( 'convert' )->willReturn( '' );
    $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
    $this->file_writer->method( 'write' )->willReturn( true );

    $result = $this->generator->generate_batch( 'post', 0, 10 );

    $this->assertSame( 2, $result['total'] );
    $this->assertSame( 1, $result['processed'] );
    $this->assertCount( 1, $result['errors'] );
    $this->assertSame( 21, $result['errors'][0]['post_id'] );
    $this->assertSame( 'build failed', $result['errors'][0]['message'] );
}

public function test_generate_batch_silently_skips_ineligible_post(): void {
    // Post type 'event' is not in options['post_types'], so generate_post returns false.
    $post = $this->make_post( [ 'ID' => 30, 'post_name' => 'event-30', 'post_type' => 'event' ] );

    $GLOBALS['_mock_wp_query']     = fn( array $args ): array => [ [ 30 ], 1 ];
    $GLOBALS['_mock_post_objects'] = [ 30 => $post ];

    $result = $this->generator->generate_batch( 'event', 0, 10 );

    $this->assertSame( 1, $result['total'] );
    $this->assertSame( 0, $result['processed'] );
    $this->assertSame( [], $result['errors'] );
}
```

- [ ] **Step 4: Run all four failing tests**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --filter "test_generate_batch" --no-coverage
```

Expected: 4 FAIL.

- [ ] **Step 5: Implement `generate_batch()` in Generator.php**

Add after `generate_post_type()` (after line 126):

```php
/**
 * Generate Markdown files for a paginated slice of published posts.
 *
 * Processes $limit posts starting at $offset. Uses WP_Query so found_posts
 * is always populated (do not set no_found_rows). Returns a summary of the
 * batch suitable for JSON responses.
 *
 * @since  1.1.0
 * @param  string $post_type The post type slug.
 * @param  int    $offset    Zero-based offset into the full result set.
 * @param  int    $limit     Maximum posts to process in this batch.
 * @return array{total: int, processed: int, errors: list<array{post_id: int, message: string}>}
 */
public function generate_batch( string $post_type, int $offset, int $limit ): array {
    $query = new \WP_Query(
        array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        )
    );

    $processed = 0;
    $errors    = array();

    foreach ( $query->posts as $post_id ) {
        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post ) {
            continue;
        }

        try {
            if ( $this->generate_post( $post ) ) {
                ++$processed;
            }
        } catch ( \Throwable $e ) {
            $errors[] = array(
                'post_id' => $post_id,
                'message' => $e->getMessage(),
            );
        }
    }

    return array(
        'total'     => $query->found_posts,
        'processed' => $processed,
        'errors'    => $errors,
    );
}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --filter "test_generate_batch" --no-coverage
```

Expected: 4 PASS.

- [ ] **Step 7: Run full suite**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add wp-markdown-for-agents/src/Generator/Generator.php wp-markdown-for-agents/tests/Unit/Generator/GeneratorTest.php
git commit -m "feat: add Generator::generate_batch() for AJAX pagination"
```

---

## Task 3: Admin AJAX handler and `enqueue_scripts()`

**Files:**
- Create: `wp-markdown-for-agents/tests/Unit/Admin/AdminAjaxTest.php`
- Modify: `wp-markdown-for-agents/src/Admin/Admin.php`

- [ ] **Step 1: Create AdminAjaxTest.php with all failing tests**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Admin\Admin;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::handle_generate_batch_ajax
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::enqueue_scripts
 */
class AdminAjaxTest extends TestCase {

    /** @var Generator&MockObject */
    private Generator $generator;

    private Admin $admin;

    protected function setUp(): void {
        $this->generator = $this->createMock( Generator::class );
        $this->admin     = new Admin( Options::get_defaults(), $this->generator );

        // Reset globals before each test.
        unset(
            $GLOBALS['_mock_json_response'],
            $GLOBALS['_mock_enqueued_scripts'],
            $GLOBALS['_mock_localized_scripts']
        );
        $GLOBALS['_mock_verify_nonce']      = 1;
        $GLOBALS['_mock_current_user_can']  = true;
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
        unset( $GLOBALS['_mock_verify_nonce'] );
    }

    // -----------------------------------------------------------------------
    // handle_generate_batch_ajax()
    // -----------------------------------------------------------------------

    public function test_valid_request_returns_batch_result(): void {
        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
            'offset'    => '0',
            'limit'     => '10',
        ];

        $this->generator->method( 'generate_batch' )
            ->willReturn( [ 'total' => 5, 'processed' => 5, 'errors' => [] ] );

        $this->admin->handle_generate_batch_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertTrue( $response['success'] );
        $this->assertSame( 5, $response['data']['total'] );
        $this->assertSame( 5, $response['data']['processed'] );
        $this->assertSame( [], $response['data']['errors'] );
    }

    public function test_invalid_nonce_triggers_wp_die(): void {
        $GLOBALS['_mock_verify_nonce'] = false;
        $_POST['nonce']                = 'bad-nonce';

        $this->expectException( \RuntimeException::class );

        $this->admin->handle_generate_batch_ajax();
    }

    public function test_non_admin_user_receives_json_error_403(): void {
        $GLOBALS['_mock_current_user_can'] = false;
        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
        ];

        $this->admin->handle_generate_batch_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertSame( 403, $response['status'] );
    }

    public function test_post_type_is_sanitised(): void {
        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'bad type!@#',
            'offset'    => '0',
            'limit'     => '10',
        ];

        $captured_post_type = null;
        $this->generator->method( 'generate_batch' )
            ->willReturnCallback(
                function ( string $pt ) use ( &$captured_post_type ): array {
                    $captured_post_type = $pt;
                    return [ 'total' => 0, 'processed' => 0, 'errors' => [] ];
                }
            );

        $this->admin->handle_generate_batch_ajax();

        // sanitize_key strips spaces and special characters.
        $this->assertSame( 'badtype', $captured_post_type );
    }

    public function test_limit_is_capped_at_50(): void {
        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
            'offset'    => '0',
            'limit'     => '200',
        ];

        $captured_limit = null;
        $this->generator->method( 'generate_batch' )
            ->willReturnCallback(
                function ( string $pt, int $offset, int $limit ) use ( &$captured_limit ): array {
                    $captured_limit = $limit;
                    return [ 'total' => 0, 'processed' => 0, 'errors' => [] ];
                }
            );

        $this->admin->handle_generate_batch_ajax();

        $this->assertSame( 50, $captured_limit );
    }

    // -----------------------------------------------------------------------
    // enqueue_scripts()
    // -----------------------------------------------------------------------

    public function test_enqueue_scripts_enqueues_on_settings_page(): void {
        $GLOBALS['_mock_enqueued_scripts']  = [];
        $GLOBALS['_mock_localized_scripts'] = [];

        $this->admin->enqueue_scripts( 'settings_page_wp-markdown-for-agents' );

        $this->assertArrayHasKey( 'mfa-bulk-generate', $GLOBALS['_mock_enqueued_scripts'] );
        $this->assertStringContainsString( 'bulk-generate.js', $GLOBALS['_mock_enqueued_scripts']['mfa-bulk-generate'] );

        $localised = $GLOBALS['_mock_localized_scripts']['mfa-bulk-generate'] ?? null;
        $this->assertNotNull( $localised );
        $this->assertSame( 'mfaBulkGenerate', $localised['object'] );
        $this->assertArrayHasKey( 'nonce', $localised['data'] );
        $this->assertArrayHasKey( 'ajaxurl', $localised['data'] );
    }

    public function test_enqueue_scripts_skips_other_pages(): void {
        $GLOBALS['_mock_enqueued_scripts'] = [];

        $this->admin->enqueue_scripts( 'options-general.php' );

        $this->assertArrayNotHasKey( 'mfa-bulk-generate', $GLOBALS['_mock_enqueued_scripts'] );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit tests/Unit/Admin/AdminAjaxTest.php --no-coverage
```

Expected: 7 FAIL (methods don't exist yet).

- [ ] **Step 3: Add `handle_generate_batch_ajax()` to Admin.php**

After the `handle_regenerate_post_action()` method (after line ~137), before `display_admin_notices()`, add:

```php
/**
 * Handle the AJAX batch-generate request.
 *
 * Processes one paginated slice (offset + limit) for a post type and
 * returns JSON with total found, processed count, and any per-post errors.
 *
 * Hooked to `wp_ajax_mfa_generate_batch`.
 *
 * @since  1.1.0
 */
public function handle_generate_batch_ajax(): void {
    check_ajax_referer( 'mfa_generate_batch', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorised' ), 403 );
        return;
    }

    $post_type = sanitize_key( (string) ( $_POST['post_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $offset    = absint( $_POST['offset'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $limit     = min( absint( $_POST['limit'] ?? 10 ), 50 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

    $result = $this->generator->generate_batch( $post_type, $offset, $limit );

    wp_send_json_success( $result );
}

/**
 * Enqueue the bulk-generate JS on the plugin settings page.
 *
 * Hooked to `admin_enqueue_scripts`.
 *
 * @since  1.1.0
 * @param  string $hook The current admin page hook suffix.
 */
public function enqueue_scripts( string $hook ): void {
    if ( 'settings_page_wp-markdown-for-agents' !== $hook ) {
        return;
    }

    wp_enqueue_script(
        'mfa-bulk-generate',
        WP_MFA_PLUGIN_URL . 'assets/js/bulk-generate.js',
        array(),
        WP_MFA_VERSION,
        true
    );

    wp_localize_script(
        'mfa-bulk-generate',
        'mfaBulkGenerate',
        array(
            'nonce'   => wp_create_nonce( 'mfa_generate_batch' ),
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
        )
    );
}
```

- [ ] **Step 4: Run AdminAjaxTest to verify all pass**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit tests/Unit/Admin/AdminAjaxTest.php --no-coverage
```

Expected: 7 PASS.

- [ ] **Step 5: Run full suite**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Admin/Admin.php wp-markdown-for-agents/tests/Unit/Admin/AdminAjaxTest.php
git commit -m "feat: add Admin::handle_generate_batch_ajax() and enqueue_scripts()"
```

---

## Task 4: Register hooks in Plugin.php

**Files:**
- Modify: `wp-markdown-for-agents/src/Core/Plugin.php`

No TDD needed — the AJAX handler is already tested directly. This step wires it into WordPress.

- [ ] **Step 1: Add two hook registrations**

In `define_admin_hooks()`, after line 143 (`$this->loader->add_action( 'admin_notices', $admin, 'display_admin_notices' );`), add:

```php
$this->loader->add_action( 'wp_ajax_mfa_generate_batch', $admin, 'handle_generate_batch_ajax' );
$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
```

The block will look like:

```php
$admin = new Admin( $options, $this->generator );
$this->loader->add_action( 'admin_menu', $admin, 'add_settings_page' );
$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
$this->loader->add_action( 'add_meta_boxes', $admin, 'add_meta_boxes' );
$this->loader->add_action( 'admin_post_wp_mfa_generate', $admin, 'handle_generate_action' );
$this->loader->add_action( 'admin_post_wp_mfa_regenerate_post', $admin, 'handle_regenerate_post_action' );
$this->loader->add_action( 'admin_notices', $admin, 'display_admin_notices' );
$this->loader->add_action( 'wp_ajax_mfa_generate_batch', $admin, 'handle_generate_batch_ajax' );
$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
```

- [ ] **Step 2: Run full suite**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add wp-markdown-for-agents/src/Core/Plugin.php
git commit -m "feat: register wp_ajax_mfa_generate_batch and admin_enqueue_scripts hooks"
```

---

## Task 5: SettingsPage — replace form buttons

**Files:**
- Modify: `wp-markdown-for-agents/src/Admin/SettingsPage.php`

- [ ] **Step 1: Replace the `<form>` loop in `render_generate_buttons()`**

Find this block (lines ~233–247):

```php
<?php foreach ( $post_types as $post_type ) : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="wp_mfa_generate">
        <input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
        <?php wp_nonce_field( 'wp_mfa_generate_' . $post_type ); ?>
        <p>
            <button type="submit" class="button button-secondary">
                <?php
                /* translators: %s: post type slug */
                printf( esc_html__( 'Generate all: %s', 'wp-markdown-for-agents' ), esc_html( $post_type ) );
                ?>
            </button>
        </p>
    </form>
<?php endforeach; ?>
```

Replace with:

```php
<?php foreach ( $post_types as $post_type ) : ?>
    <p>
        <button type="button" class="button button-secondary" data-post-type="<?php echo esc_attr( $post_type ); ?>">
            <?php
            /* translators: %s: post type slug */
            printf( esc_html__( 'Generate all: %s', 'wp-markdown-for-agents' ), esc_html( $post_type ) );
            ?>
        </button>
    </p>
<?php endforeach; ?>
```

- [ ] **Step 2: Run full suite to confirm SettingsPageTest still passes**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add wp-markdown-for-agents/src/Admin/SettingsPage.php
git commit -m "feat: replace generate form POST buttons with AJAX-ready data-post-type buttons"
```

---

## Task 6: JavaScript — `assets/js/bulk-generate.js`

**Files:**
- Create: `wp-markdown-for-agents/assets/js/bulk-generate.js`

No unit tests (vanilla JS, logic is thin; manual QA via browser DevTools is specified in the spec).

- [ ] **Step 1: Confirm `assets/js/` directory exists or create it**

```bash
ls wp-markdown-for-agents/assets/js/ 2>/dev/null || mkdir -p wp-markdown-for-agents/assets/js/
```

- [ ] **Step 2: Create `bulk-generate.js`**

```javascript
/* global mfaBulkGenerate */
/* WordPress admin bulk-generate AJAX loop.
 * Intercepts clicks on [data-post-type] buttons, drives sequential AJAX
 * batch requests (10 posts per request), and updates a live counter.
 */
(function () {
    'use strict';

    var BATCH_SIZE = 10;

    /**
     * Send one batch request and recurse until all posts are processed.
     *
     * @param {string} postType
     * @param {number} offset
     * @param {{processed: number, errors: Array}} accumulated
     * @param {HTMLButtonElement} button
     */
    function sendBatch(postType, offset, accumulated, button) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', mfaBulkGenerate.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            if (!response || !response.success) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            var data = response.data;
            accumulated.processed += data.processed;
            accumulated.errors    = accumulated.errors.concat(data.errors);

            button.textContent = accumulated.processed + ' / ' + data.total;

            if (offset + BATCH_SIZE < data.total) {
                sendBatch(postType, offset + BATCH_SIZE, accumulated, button);
            } else {
                var errorSummary = accumulated.errors.length
                    ? ', ' + accumulated.errors.length + ' error(s)'
                    : '';
                button.textContent = 'Done: ' + accumulated.processed + ' processed' + errorSummary;
                button.disabled = false;
            }
        };

        xhr.onerror = function () {
            button.textContent = 'Error \u2014 generation stopped';
            button.disabled = false;
        };

        var params = 'action=mfa_generate_batch'
            + '&nonce='     + encodeURIComponent(mfaBulkGenerate.nonce)
            + '&post_type=' + encodeURIComponent(postType)
            + '&offset='    + encodeURIComponent(offset)
            + '&limit='     + encodeURIComponent(BATCH_SIZE);

        xhr.send(params);
    }

    /**
     * @param {MouseEvent} event
     */
    function handleGenerateClick(event) {
        var button   = /** @type {HTMLButtonElement} */ (event.currentTarget);
        var postType = button.dataset.postType;

        button.disabled    = true;
        button.textContent = '0 / \u2026';

        var accumulated = { processed: 0, errors: [] };
        sendBatch(postType, 0, accumulated, button);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('button[data-post-type]');
        buttons.forEach(function (button) {
            button.addEventListener('click', handleGenerateClick);
        });
    });
}());
```

- [ ] **Step 3: Run full suite one final time**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add wp-markdown-for-agents/assets/js/bulk-generate.js
git commit -m "feat: add bulk-generate.js AJAX batch loop with live counter"
```

---

## Verification

After all tasks are complete:

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage
```

Expected output: all tests pass (at least 180 tests including the 7 new Admin tests and 4 new Generator batch tests).

**Manual QA checklist:**
1. On the settings page, click a "Generate all" button
2. Button should show `"0 / …"` immediately, then `"10 / N"`, `"20 / N"`, ...
3. When complete: `"Done: N processed"` (or `"Done: N processed, X error(s)"`)
4. Browser DevTools → Network tab should show sequential POST requests to `admin-ajax.php`
5. Verify no PHP errors in debug log during a run

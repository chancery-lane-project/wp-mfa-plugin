# Phase 1 & 2: Bug Fixes + HTTP Response Parity

> **Status: ✅ COMPLETE — merged to `main` on 2026-03-12. 169 tests, 261 assertions. See `comparison-plan.md` § "Phase 1 & 2 implementation notes" for decisions that affect future work.**

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix four known bugs (broken frontmatter parser, leaked production mock, broken discovery link, missing query-parameter negotiation) and add four HTTP response improvements (response headers, per-post kill switch, filterable post-type allowlist), committing only on a green test suite.

**Architecture:** All changes touch two source files — `src/Generator/LlmsTxtGenerator.php` and `src/Negotiate/Negotiator.php`. A prerequisite task upgrades the test mock layer before any feature work begins. Each task adds tests first, confirms they fail, implements the fix, confirms they pass, then commits.

**Tech Stack:** PHP 8.0+, PHPUnit 9.6, WordPress mock layer (`tests/mocks/wordpress-mocks.php`), `composer test`

**Run all tests:** `cd wp-markdown-for-agents && composer test`

---

## Chunk 1 — Prerequisites + Phase 1: Bug Fixes

### Task 0: Upgrade test mock layer

**Why this comes first:** Phases 1 and 2 both require correct mock behaviour. Specifically:
- `apply_filters` is currently a pure passthrough — Tasks 4–6 need per-test return value overrides.
- `add_query_arg` is hardcoded to a stats admin URL — Task 3 needs it to produce correct permalink-based URLs.
- `is_singular` ignores its argument — Task 6 needs it to return `false` for an empty post-types array.

All three fixes are backward-compatible: existing tests continue to pass.

**Files:**
- Modify: `tests/mocks/wordpress-mocks.php`

- [ ] **Step 1: Run the baseline test suite**

```bash
cd wp-markdown-for-agents && composer test
```

Note the exact pass count — every subsequent commit must match or exceed it.

- [ ] **Step 2: Replace the `apply_filters` mock**

Find the existing block (around line 68):

```php
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        return $value;
    }
}
```

Replace with:

```php
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        // Per-test override: $GLOBALS['_mock_apply_filters']['hook'] = fn($val, ...$args) => $modified
        if ( isset( $GLOBALS['_mock_apply_filters'][ $hook ] ) ) {
            $cb = $GLOBALS['_mock_apply_filters'][ $hook ];
            return $cb( $value, ...$args );
        }
        // Fallback: transparent passthrough for all unregistered hooks.
        return $value;
    }
}
```

- [ ] **Step 3: Replace the `add_query_arg` mock**

Find the existing block (around line 681):

```php
if (!function_exists('add_query_arg')) {
    function add_query_arg(string|array $key, mixed $value = null, string $url = ''): string {
        if (is_string($key)) {
            return '?page=wp-mfa-stats&' . $key . '=' . $value;
        }
        return $url;
    }
}
```

Replace with:

```php
if (!function_exists('add_query_arg')) {
    function add_query_arg( string|array $key, mixed $value = null, string $url = '' ): string {
        $pairs = is_array( $key ) ? $key : [ $key => $value ];
        $query = http_build_query( $pairs );
        if ( '' === $url ) {
            return '?' . $query;
        }
        $sep = str_contains( $url, '?' ) ? '&' : '?';
        return $url . $sep . $query;
    }
}
```

- [ ] **Step 4: Update the `is_singular` mock to respect an empty post-types array**

Find the existing block (around line 193):

```php
if (!function_exists('is_singular')) {
    function is_singular(string|array $post_types = ''): bool {
        return $GLOBALS['_mock_is_singular'];
    }
}
```

Replace with:

```php
if (!function_exists('is_singular')) {
    function is_singular( string|array $post_types = '' ): bool {
        // An empty allowlist means no types are eligible — match real WP behaviour.
        if ( is_array( $post_types ) && empty( $post_types ) ) {
            return false;
        }
        return $GLOBALS['_mock_is_singular'];
    }
}
```

- [ ] **Step 5: Run all tests — confirm still green**

```bash
composer test
```

Expected: same pass count as Step 1. If any test breaks, the original mock behaviour was load-bearing for an existing assertion — fix that test before proceeding.

- [ ] **Step 6: Commit**

```bash
git add tests/mocks/wordpress-mocks.php
git commit -m "test: upgrade apply_filters, add_query_arg, and is_singular mocks for Phase 1/2"
```

---

### Task 1: B2 — Move `get_bloginfo` mock out of production code

**Note:** This is a structural cleanup, not a feature — there is no failing test to write first. The existing `LlmsTxtGeneratorTest` tests prove correctness after the move.

**Files:**
- Modify: `src/Generator/LlmsTxtGenerator.php` — delete lines 157–161
- Modify: `tests/mocks/wordpress-mocks.php` — add `get_bloginfo` mock

- [ ] **Step 1: Add `get_bloginfo` to `tests/mocks/wordpress-mocks.php`**

Add after the `home_url` block (around line 147):

```php
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return $GLOBALS['_mock_bloginfo'][ $show ] ?? '';
    }
}
```

- [ ] **Step 2: Delete the mock from `src/Generator/LlmsTxtGenerator.php`**

Remove lines 157–161 (the block after the class closing brace):

```php
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return $GLOBALS['_mock_bloginfo'][$show] ?? '';
    }
}
```

The file must end with `}` (class closing brace) followed by a single newline.

- [ ] **Step 3: Run tests — confirm green**

```bash
composer test
```

Expected: same pass count as Task 0.

- [ ] **Step 4: Commit**

```bash
git add src/Generator/LlmsTxtGenerator.php tests/mocks/wordpress-mocks.php
git commit -m "fix: move get_bloginfo mock from production code to test mock layer"
```

---

### Task 2: B1 — Fix `parse_frontmatter` to handle YAML arrays

**The bugs:**
1. Indented lines (`  - News`) and bare list items (`- value`) must be skipped — the current code has no explicit skip for these.
2. Single-quoted YAML values (`'My Title'`) are returned with their quotes intact — the existing code only strips `"`.

**Implementation note — indentation check:** The spec uses `str_starts_with( ltrim( $line ), ' ' )` which is always `false` (ltrim removes leading whitespace before the check). This plan uses the correct approach: `$line !== ltrim( $line )` to detect any leading whitespace.

**Implementation note — quote stripping:** `substr( $value, 1, -1 )` returns `false` in PHP 8 for a string of length < 2. A guard `strlen( $value ) >= 2` is required before calling it.

**Files:**
- Modify: `src/Generator/LlmsTxtGenerator.php`
- Modify: `tests/Unit/Generator/LlmsTxtGeneratorTest.php`

- [x] **Step 1: Write the failing tests**

Add to `LlmsTxtGeneratorTest`:

```php
public function test_parse_frontmatter_skips_yaml_array_items(): void {
    $file = $this->base_dir . '/array.md';
    file_put_contents(
        $file,
        "---\ntitle: My Post\ncategories:\n  - News\n  - Sport\npermalink: https://example.com/\n---\n\nBody\n"
    );

    $result = $this->gen->parse_frontmatter( $file );

    $this->assertSame( 'My Post', $result['title'] );
    $this->assertSame( 'https://example.com/', $result['permalink'] );
    $this->assertArrayNotHasKey( '  - News', $result );
    $this->assertArrayNotHasKey( '- News', $result );
}

public function test_parse_frontmatter_strips_single_quoted_values(): void {
    $file = $this->base_dir . '/single-quotes.md';
    file_put_contents( $file, "---\ntitle: 'Single Quoted Title'\nexcerpt: 'A short excerpt'\n---\n" );

    $result = $this->gen->parse_frontmatter( $file );

    $this->assertSame( 'Single Quoted Title', $result['title'] );
    $this->assertSame( 'A short excerpt', $result['excerpt'] );
}

public function test_parse_frontmatter_strips_double_quoted_values(): void {
    $file = $this->base_dir . '/double-quotes.md';
    file_put_contents( $file, "---\ntitle: \"Double Quoted\"\n---\n" );

    $result = $this->gen->parse_frontmatter( $file );

    $this->assertSame( 'Double Quoted', $result['title'] );
}
```

- [x] **Step 2: Run new tests — confirm at least one fails**

```bash
composer test -- --filter="test_parse_frontmatter_skips_yaml_array|test_parse_frontmatter_strips_single|test_parse_frontmatter_strips_double"
```

Expected: `test_parse_frontmatter_strips_single_quoted_values` fails (quotes present in value).

- [x] **Step 3: Fix `parse_frontmatter()` in `src/Generator/LlmsTxtGenerator.php`**

Replace the inner block (lines 129–132):

```php
if ( $in_fm && str_contains( $trimmed, ':' ) ) {
    [ $key, $value ] = explode( ':', $trimmed, 2 );
    $data[ trim( $key ) ] = trim( trim( $value ), '"' );
}
```

With:

```php
if ( $in_fm ) {
    // Skip indented lines (YAML values like "  - News") and
    // bare list items at column 0 ("- value").
    if ( '' !== $trimmed && ( $line !== ltrim( $line ) || '-' === $trimmed[0] ) ) {
        continue;
    }

    if ( str_contains( $trimmed, ':' ) ) {
        $parts = explode( ':', $trimmed, 2 );
        $key   = trim( $parts[0] );
        $value = trim( $parts[1] );

        // Strip surrounding single or double quotes (matched pairs only,
        // length >= 2 required to avoid substr returning false).
        if (
            strlen( $value ) >= 2 &&
            (
                ( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) ) ||
                ( str_starts_with( $value, "'" ) && str_ends_with( $value, "'" ) )
            )
        ) {
            $value = substr( $value, 1, -1 );
        }

        if ( '' !== $key ) {
            $data[ $key ] = $value;
        }
    }
}
```

- [x] **Step 4: Run all tests**

```bash
composer test
```

Expected: all pass including the three new tests.

- [x] **Step 5: Commit**

```bash
git add src/Generator/LlmsTxtGenerator.php tests/Unit/Generator/LlmsTxtGeneratorTest.php
git commit -m "fix: parse_frontmatter skips YAML array items and strips single-quoted values"
```

---

### Task 3: B3 + B4 + G4 — Query parameter negotiation, fix link tag URL, scope Vary header

Three tightly coupled changes to `Negotiator.php`:

- **B4:** `maybe_serve_markdown()` has no `$_GET` check. Fix: detect `?output_format=md` or `?output_format=markdown`.
- **B3:** `output_link_tag()` points to the bare permalink. Fix: append `?output_format=md`.
- **G4:** `Vary: Accept` is sent for all responses. Fix: only send it when the request used the Accept header.

**Files:**
- Modify: `src/Negotiate/Negotiator.php`
- Modify: `tests/Unit/Negotiate/NegotiatorTest.php`

- [x] **Step 1: Write the failing tests**

First, update `tearDown()` to clean up `$_GET`:

```php
protected function tearDown(): void {
    $this->remove_dir( $this->tmp_dir );
    unset( $_SERVER['HTTP_ACCEPT'] );
    unset( $_SERVER['HTTP_USER_AGENT'] );
    unset( $_GET['output_format'] );    // ADD this line
}
```

Then add the new test methods:

```php
// -----------------------------------------------------------------------
// maybe_serve_markdown — query parameter negotiation (B4)
// -----------------------------------------------------------------------

public function test_serves_markdown_via_output_format_md_query_param(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_GET['output_format']           = 'md';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );
    $this->logger->expects( $this->once() )
        ->method( 'log_access' )
        ->with( 1, 'query-param' );

    $neg = $this->make_negotiator();
    try {
        $neg->maybe_serve_markdown();
    } catch ( \Exception $e ) {}
}

public function test_serves_markdown_via_output_format_markdown_query_param(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_GET['output_format']           = 'markdown';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );
    $this->logger->expects( $this->once() )
        ->method( 'log_access' )
        ->with( 1, 'query-param' );

    $neg = $this->make_negotiator();
    try {
        $neg->maybe_serve_markdown();
    } catch ( \Exception $e ) {}
}

public function test_does_nothing_when_output_format_query_param_is_invalid(): void {
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $this->make_post();
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_GET['output_format']           = 'html';

    $this->generator->expects( $this->never() )->method( 'get_export_path' );
    $this->make_negotiator()->maybe_serve_markdown();
}

// -----------------------------------------------------------------------
// output_link_tag — href includes ?output_format=md (B3)
// -----------------------------------------------------------------------

public function test_link_tag_href_includes_output_format_query_param(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $GLOBALS['_mock_permalink']      = 'https://example.com/test-post/';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );

    ob_start();
    $this->make_negotiator()->output_link_tag();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'output_format=md', $output );
    $this->assertStringContainsString( 'https://example.com/test-post/', $output );
}

// -----------------------------------------------------------------------
// maybe_serve_markdown — Vary: Accept scoping (G4)
// -----------------------------------------------------------------------

public function test_log_access_label_is_query_param_when_served_via_query_param(): void {
    // Indirectly verifies that the query-param path does not send Vary: Accept
    // (the access label distinguishes query-param from accept-header).
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_GET['output_format']           = 'md';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );
    $this->logger->expects( $this->once() )
        ->method( 'log_access' )
        ->with( 1, 'query-param' ); // NOT 'accept-header' — Vary: Accept must not be sent

    $neg = $this->make_negotiator();
    try {
        $neg->maybe_serve_markdown();
    } catch ( \Exception $e ) {}
}
```

- [x] **Step 2: Run new tests — confirm they fail**

```bash
composer test -- --filter="test_serves_markdown_via_output_format|test_does_nothing_when_output_format|test_link_tag_href_includes_output_format|test_log_access_label_is_query_param"
```

Expected: all fail — query param not yet detected, link tag has no `output_format`.

- [x] **Step 3: Update `maybe_serve_markdown()` in `src/Negotiate/Negotiator.php`**

Replace the variable declarations and early-return guard:

```php
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';          // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
$ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

$matched_agent = $this->agent_detector->get_matched_agent( $ua );
$via_accept    = str_contains( $accept, 'text/markdown' );

if ( ! $via_accept && null === $matched_agent ) {
    return;
}
```

With:

```php
$accept    = $_SERVER['HTTP_ACCEPT'] ?? '';          // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
$ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
$format_qp = sanitize_key( $_GET['output_format'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$matched_agent = $this->agent_detector->get_matched_agent( $ua );
$via_accept    = str_contains( $accept, 'text/markdown' );
$via_query     = in_array( $format_qp, [ 'md', 'markdown' ], true );

if ( ! $via_accept && ! $via_query && null === $matched_agent ) {
    return;
}
```

Then replace the access logging and headers block:

```php
$agent_label = $matched_agent ?? 'accept-header';
$this->access_logger->log_access( $post->ID, $agent_label );

header( 'Content-Type: text/markdown; charset=utf-8' );
header( 'Vary: Accept' );
```

With:

```php
$agent_label = $matched_agent ?? ( $via_accept ? 'accept-header' : 'query-param' );
$this->access_logger->log_access( $post->ID, $agent_label );

header( 'Content-Type: text/markdown; charset=utf-8' );

// Vary: Accept only when the request was Accept-header negotiated —
// not for UA-matched or query-param requests.
if ( $via_accept ) {
    header( 'Vary: Accept' );
}
```

- [x] **Step 4: Update `output_link_tag()` to append `?output_format=md`**

Replace:

```php
$url = esc_url( get_permalink( $post->ID ) );
```

With:

```php
$url = esc_url( add_query_arg( 'output_format', 'md', get_permalink( $post->ID ) ) );
```

- [x] **Step 5: Run all tests**

```bash
composer test
```

Expected: all pass including the new tests.

- [x] **Step 6: Commit**

```bash
git add src/Negotiate/Negotiator.php tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "fix: add query param negotiation, scope Vary to Accept header only, fix link tag href"
```

---

## Chunk 2 — Phase 2: HTTP Response Parity

### Task 4: G2 + G3 — Add `X-Markdown-Source` and `Content-Signal` response headers

**Why:** `X-Markdown-Source: wp-markdown-for-agents` identifies which plugin served the response. `Content-Signal: ai-input=yes, search=yes` follows the Cloudflare convention for AI-suitable content. The `Content-Signal` value is filterable via `wp_mfa_content_signal`; returning an empty string suppresses the header entirely.

**Note on testing PHP headers in PHPUnit:** `header()` cannot be inspected in CLI SAPI — `headers_list()` returns `[]`. Tests instead use the `_mock_apply_filters` mechanism (added in Task 0) to assert the filter is called with the correct default value. For the suppression path, the test asserts that `log_access` was called — confirming no fatal early return occurred when `Content-Signal` was suppressed, which means the full code path ran correctly.

**Dependency:** Requires Task 0 (`_mock_apply_filters` mechanism).

**Files:**
- Modify: `src/Negotiate/Negotiator.php`
- Modify: `tests/Unit/Negotiate/NegotiatorTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// -----------------------------------------------------------------------
// maybe_serve_markdown — Content-Signal header filter (G3)
// -----------------------------------------------------------------------

public function test_content_signal_filter_receives_correct_default_value(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );
    $this->logger->method( 'log_access' );

    $filter_received = null;
    $GLOBALS['_mock_apply_filters']['wp_mfa_content_signal'] = static function ( string $val ) use ( &$filter_received ): string {
        $filter_received = $val;
        return $val;
    };

    $neg = $this->make_negotiator();
    try {
        $neg->maybe_serve_markdown();
    } catch ( \Exception $e ) {}

    $this->assertSame( 'ai-input=yes, search=yes', $filter_received );
    unset( $GLOBALS['_mock_apply_filters']['wp_mfa_content_signal'] );
}

public function test_code_path_completes_when_content_signal_filter_returns_empty_string(): void {
    $md_file = $this->tmp_dir . '/test-post.md';
    file_put_contents( $md_file, '# Test' );

    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    $this->generator->method( 'get_export_path' )->willReturn( $md_file );

    $GLOBALS['_mock_apply_filters']['wp_mfa_content_signal'] = static fn( string $val ): string => '';

    // The method must proceed all the way to log_access (no fatal early-return
    // when the filter suppresses the Content-Signal header).
    $this->logger->expects( $this->once() )->method( 'log_access' );

    $neg = $this->make_negotiator();
    try {
        $neg->maybe_serve_markdown();
    } catch ( \Exception $e ) {}

    unset( $GLOBALS['_mock_apply_filters']['wp_mfa_content_signal'] );
}
```

- [ ] **Step 2: Run new tests — confirm `test_content_signal_filter_receives_correct_default_value` fails**

```bash
composer test -- --filter="test_content_signal_filter_receives|test_code_path_completes_when_content_signal"
```

Expected: first test fails (`$filter_received` is `null` — filter not yet invoked).

- [ ] **Step 3: Add headers after the `Vary: Accept` block in `Negotiator.php`**

```php
if ( $via_accept ) {
    header( 'Vary: Accept' );
}

header( 'X-Markdown-Source: wp-markdown-for-agents' );

/**
 * Filter the Content-Signal header value.
 *
 * Return an empty string to suppress the header entirely.
 *
 * @since 1.1.0
 * @param string $signal The default signal value.
 */
$content_signal = apply_filters( 'wp_mfa_content_signal', 'ai-input=yes, search=yes' );
if ( $content_signal ) {
    header( 'Content-Signal: ' . $content_signal );
}
```

- [ ] **Step 4: Run all tests**

```bash
composer test
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Negotiate/Negotiator.php tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "feat: add X-Markdown-Source and filterable Content-Signal response headers"
```

---

### Task 5: G6 — Per-post kill-switch filter

**Why:** Allows disabling Markdown serving for a specific post without changing admin settings — e.g. for gated content or A/B tests.

**Placement and dependency:** The filter fires on the hot path — after the Accept/UA/query-param check (from Task 3, B3/B4) has passed and a `WP_Post` has been confirmed. **Task 3 must be complete** before this task, because the guard at the top of `maybe_serve_markdown()` must include `$via_query` (added in Task 3) for the placement to be correct. Third-party filter callbacks can rely on the fact that Markdown serving is genuinely being attempted when `wp_mfa_serve_enabled` is invoked.

**Files:**
- Modify: `src/Negotiate/Negotiator.php`
- Modify: `tests/Unit/Negotiate/NegotiatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
// -----------------------------------------------------------------------
// maybe_serve_markdown — per-post kill switch (G6)
// -----------------------------------------------------------------------

public function test_does_nothing_when_serve_enabled_filter_returns_false(): void {
    $post = $this->make_post();
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    // Filter fires on hot path — after Accept check, after WP_Post check.
    $GLOBALS['_mock_apply_filters']['wp_mfa_serve_enabled'] = static fn( bool $val, \WP_Post $p ): bool => false;

    $this->generator->expects( $this->never() )->method( 'get_export_path' );
    $this->logger->expects( $this->never() )->method( 'log_access' );

    $this->make_negotiator()->maybe_serve_markdown();

    unset( $GLOBALS['_mock_apply_filters']['wp_mfa_serve_enabled'] );
}
```

- [ ] **Step 2: Run the test — confirm it fails**

```bash
composer test -- --filter="test_does_nothing_when_serve_enabled_filter_returns_false"
```

Expected: FAIL — `get_export_path` is called once (filter not yet checked).

- [ ] **Step 3: Add the kill-switch check to `maybe_serve_markdown()` in `Negotiator.php`**

Insert immediately after the `$post instanceof \WP_Post` guard and before `$filepath = ...`:

```php
$post = get_queried_object();
if ( ! $post instanceof \WP_Post ) {
    return;
}

/**
 * Whether to serve Markdown for this specific post.
 *
 * Only fires when the request has already been identified as a Markdown
 * request (Accept header, query param, or known UA). Return false to
 * prevent serving for this post without affecting others.
 *
 * @since 1.1.0
 * @param bool     $enabled Whether serving is enabled. Default true.
 * @param \WP_Post $post    The queried post.
 */
if ( ! apply_filters( 'wp_mfa_serve_enabled', true, $post ) ) {
    return;
}

$filepath = $this->generator->get_export_path( $post );
```

- [ ] **Step 4: Run all tests**

```bash
composer test
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Negotiate/Negotiator.php tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "feat: add wp_mfa_serve_enabled filter for per-post Markdown kill switch"
```

---

### Task 6: G7 — Filterable post-type allowlist

**Why:** Allows runtime addition/removal of post types eligible for Markdown serving without touching admin settings.

**Testing note:** `is_eligible_singular()` is private and is tested via `maybe_serve_markdown()`. The `is_singular` mock was updated in Task 0 to return `false` for an empty post-types array — this makes Test 2 (filter removes a type) genuinely provable: if the filter removes `'post'` from the array, the array becomes empty, `is_singular([])` returns `false`, and `get_export_path` is never called.

**Files:**
- Modify: `src/Negotiate/Negotiator.php`
- Modify: `tests/Unit/Negotiate/NegotiatorTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// -----------------------------------------------------------------------
// is_eligible_singular — filterable post type allowlist (G7)
// -----------------------------------------------------------------------

public function test_serves_post_type_added_to_allowlist_via_filter(): void {
    // 'event' is not in options['post_types'], but the filter adds it.
    // With the updated is_singular mock, the test verifies the full hot path runs.
    $GLOBALS['_mock_apply_filters']['wp_mfa_serve_post_types'] = static fn( array $types ): array =>
        array_merge( $types, [ 'event' ] );

    $post = new \WP_Post( [ 'ID' => 2, 'post_type' => 'event', 'post_name' => 'my-event' ] );
    $GLOBALS['_mock_is_singular']    = true;  // Simulates WP confirming this is a singular page.
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    // File missing → early return after get_export_path. Confirms eligible check passed.
    $this->generator->expects( $this->once() )
        ->method( 'get_export_path' )
        ->willReturn( '/nonexistent/event.md' );

    $this->make_negotiator()->maybe_serve_markdown();

    unset( $GLOBALS['_mock_apply_filters']['wp_mfa_serve_post_types'] );
}

public function test_does_not_serve_post_type_removed_from_allowlist_via_filter(): void {
    // Negotiator is configured with only 'post'. The filter removes it,
    // leaving an empty array. is_singular([]) returns false (Task 0 fix).
    $GLOBALS['_mock_apply_filters']['wp_mfa_serve_post_types'] = static fn( array $types ): array =>
        array_values( array_filter( $types, static fn( string $t ): bool => $t !== 'post' ) );

    $post = $this->make_post(); // post_type = 'post'
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $post;
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    // is_singular([]) returns false → eligible check fails → get_export_path never called.
    $this->generator->expects( $this->never() )->method( 'get_export_path' );

    $this->make_negotiator( [ 'post_types' => [ 'post' ] ] )->maybe_serve_markdown();

    unset( $GLOBALS['_mock_apply_filters']['wp_mfa_serve_post_types'] );
}
```

- [ ] **Step 2: Run new tests — confirm they fail**

```bash
composer test -- --filter="test_serves_post_type_added_to_allowlist|test_does_not_serve_post_type_removed"
```

Expected: both fail — filter not yet applied.

- [ ] **Step 3: Update `is_eligible_singular()` in `Negotiator.php`**

Replace:

```php
private function is_eligible_singular(): bool {
    $post_types = (array) ( $this->options['post_types'] ?? [] );
    return is_singular( $post_types );
}
```

With:

```php
private function is_eligible_singular(): bool {
    $post_types = (array) ( $this->options['post_types'] ?? [] );

    /**
     * Filter the post types eligible for Markdown serving.
     *
     * @since 1.1.0
     * @param string[] $post_types Post type slugs from plugin settings.
     */
    $post_types = (array) apply_filters( 'wp_mfa_serve_post_types', $post_types );

    return is_singular( $post_types );
}
```

- [ ] **Step 4: Run all tests**

```bash
composer test
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Negotiate/Negotiator.php tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "feat: add wp_mfa_serve_post_types filter for runtime post-type allowlist"
```

---

## Final Verification

- [ ] **Run the full test suite**

```bash
composer test
```

Expected: all tests pass with no warnings.

- [ ] **Run PHP CodeSniffer**

```bash
composer phpcs
```

- [ ] **Fix any violations and commit if needed**

```bash
composer phpcbf
composer phpcs  # must exit clean before committing
git add -p
git commit -m "style: PHPCS fixes after phase 1/2 implementation"
```

# UA Detection Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Serve pre-generated Markdown to known LLM agent crawlers based on `User-Agent` substring matching, with admin-configurable UA strings and sensible defaults.

**Architecture:** A new `Negotiate\AgentDetector` class handles substring matching and is injected into `Negotiator`. `maybe_serve_markdown()` serves Markdown when either the `Accept` header matches OR the UA is a known agent. Two new options (`ua_force_enabled`, `ua_agent_strings`) are added to `Options::get_defaults()` with a pre-populated default list. A new "Agent Detection" section is added to the existing settings page.

**Tech Stack:** PHP 8.0+, PHPUnit 9.6, WordPress Settings API, `stripos` for case-insensitive substring matching.

---

### Task 1: Add new option keys to Options

**Files:**
- Modify: `wp-markdown-for-agents/src/Core/Options.php`
- Modify: `wp-markdown-for-agents/tests/Unit/Core/OptionsTest.php`

**Step 1: Add failing tests for new default keys**

Add to `tests/Unit/Core/OptionsTest.php`:

```php
public function test_defaults_contain_ua_detection_keys(): void {
    $defaults = Options::get_defaults();
    $this->assertArrayHasKey( 'ua_force_enabled', $defaults );
    $this->assertArrayHasKey( 'ua_agent_strings', $defaults );
}

public function test_defaults_ua_force_enabled_is_true(): void {
    $this->assertTrue( Options::get_defaults()['ua_force_enabled'] );
}

public function test_defaults_ua_agent_strings_is_non_empty_array(): void {
    $strings = Options::get_defaults()['ua_agent_strings'];
    $this->assertIsArray( $strings );
    $this->assertNotEmpty( $strings );
}

public function test_defaults_ua_agent_strings_contains_known_agents(): void {
    $strings = Options::get_defaults()['ua_agent_strings'];
    $this->assertContains( 'GPTBot', $strings );
    $this->assertContains( 'ClaudeBot', $strings );
    $this->assertContains( 'PerplexityBot', $strings );
}
```

**Step 2: Run tests to verify they fail**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage tests/Unit/Core/OptionsTest.php
```

Expected: 4 failures.

**Step 3: Add new defaults to `Options::get_defaults()`**

In `src/Core/Options.php`, add to the returned array:

```php
'ua_force_enabled'  => true,
'ua_agent_strings'  => [
    'GPTBot',
    'ChatGPT-User',
    'ClaudeBot',
    'Claude-Web',
    'anthropic-ai',
    'PerplexityBot',
    'Google-Extended',
    'Amazonbot',
    'cohere-ai',
    'meta-externalagent',
    'Bytespider',
    'CCBot',
    'Applebot-Extended',
],
```

**Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Core/OptionsTest.php
```

Expected: all pass.

**Step 5: Run full suite to check no regressions**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Core/Options.php wp-markdown-for-agents/tests/Unit/Core/OptionsTest.php
git commit -m "feat: add ua_force_enabled and ua_agent_strings option defaults"
```

---

### Task 2: Create AgentDetector class

**Files:**
- Create: `wp-markdown-for-agents/src/Negotiate/AgentDetector.php`
- Create: `wp-markdown-for-agents/tests/Unit/Negotiate/AgentDetectorTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/Negotiate/AgentDetectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Negotiate;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;

/**
 * @covers \Tclp\WpMarkdownForAgents\Negotiate\AgentDetector
 */
class AgentDetectorTest extends TestCase {

    private function make_detector( array $options = [] ): AgentDetector {
        return new AgentDetector( array_merge( [
            'ua_force_enabled' => true,
            'ua_agent_strings' => [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ],
        ], $options ) );
    }

    public function test_returns_false_when_ua_force_disabled(): void {
        $detector = $this->make_detector( [ 'ua_force_enabled' => false ] );
        $this->assertFalse( $detector->is_known_agent( 'GPTBot/1.0' ) );
    }

    public function test_returns_false_for_empty_ua_string(): void {
        $this->assertFalse( $this->make_detector()->is_known_agent( '' ) );
    }

    public function test_returns_false_for_unknown_ua(): void {
        $this->assertFalse( $this->make_detector()->is_known_agent( 'Mozilla/5.0 Chrome/120' ) );
    }

    public function test_matches_known_agent_substring(): void {
        $this->assertTrue( $this->make_detector()->is_known_agent( 'GPTBot/1.0' ) );
    }

    public function test_matching_is_case_insensitive(): void {
        $this->assertTrue( $this->make_detector()->is_known_agent( 'gptbot/1.0' ) );
        $this->assertTrue( $this->make_detector()->is_known_agent( 'GPTBOT/1.0' ) );
    }

    public function test_matches_any_entry_in_list(): void {
        $this->assertTrue( $this->make_detector()->is_known_agent( 'ClaudeBot/1.0 (+https://anthropic.com)' ) );
        $this->assertTrue( $this->make_detector()->is_known_agent( 'PerplexityBot/1.0' ) );
    }

    public function test_returns_false_when_agent_strings_list_is_empty(): void {
        $detector = $this->make_detector( [ 'ua_agent_strings' => [] ] );
        $this->assertFalse( $detector->is_known_agent( 'GPTBot/1.0' ) );
    }

    public function test_matches_substring_not_full_string(): void {
        // 'ChatGPT-User' is a substring of a longer UA string.
        $detector = $this->make_detector( [ 'ua_agent_strings' => [ 'ChatGPT-User' ] ] );
        $this->assertTrue( $detector->is_known_agent( 'Mozilla/5.0 ChatGPT-User/1.0' ) );
    }
}
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Negotiate/AgentDetectorTest.php
```

Expected: error — class not found.

**Step 3: Create `src/Negotiate/AgentDetector.php`**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Negotiate;

/**
 * Detects whether a User-Agent string belongs to a known LLM agent.
 *
 * Matching is case-insensitive substring. The list of substrings is
 * configured via the `ua_agent_strings` plugin option.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Negotiate
 */
class AgentDetector {

    /**
     * @since  1.1.0
     * @param  array<string, mixed> $options Plugin options.
     */
    public function __construct( private readonly array $options ) {}

    /**
     * Return true if the given UA string contains a known agent substring.
     *
     * @since  1.1.0
     * @param  string $ua The HTTP User-Agent header value.
     * @return bool
     */
    public function is_known_agent( string $ua ): bool {
        if ( empty( $this->options['ua_force_enabled'] ) ) {
            return false;
        }

        if ( '' === $ua ) {
            return false;
        }

        $substrings = (array) ( $this->options['ua_agent_strings'] ?? [] );

        foreach ( $substrings as $substring ) {
            if ( '' !== $substring && false !== stripos( $ua, $substring ) ) {
                return true;
            }
        }

        return false;
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Negotiate/AgentDetectorTest.php
```

Expected: all pass.

**Step 5: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Negotiate/AgentDetector.php wp-markdown-for-agents/tests/Unit/Negotiate/AgentDetectorTest.php
git commit -m "feat: add AgentDetector for UA-based Markdown serving"
```

---

### Task 3: Inject AgentDetector into Negotiator

**Files:**
- Modify: `wp-markdown-for-agents/src/Negotiate/Negotiator.php`
- Modify: `wp-markdown-for-agents/tests/Unit/Negotiate/NegotiatorTest.php`

**Step 1: Add failing tests**

In `NegotiatorTest.php`:

1. Update the imports at the top to add `AgentDetector`:
```php
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
```

2. Update the `make_negotiator()` factory to pass `AgentDetector` as third argument.
   Use `ua_force_enabled: false` as the factory default so existing tests are unaffected:

```php
private function make_negotiator( array $options = [] ): Negotiator {
    $merged = array_merge( [
        'post_types'       => [ 'post', 'page' ],
        'export_dir'       => 'wp-mfa-exports',
        'ua_force_enabled' => false,
        'ua_agent_strings' => [],
    ], $options );
    return new Negotiator( $merged, $this->generator, new AgentDetector( $merged ) );
}
```

3. Update `tearDown()` to unset `HTTP_USER_AGENT`:
```php
protected function tearDown(): void {
    $this->remove_dir( $this->tmp_dir );
    unset( $_SERVER['HTTP_ACCEPT'] );
    unset( $_SERVER['HTTP_USER_AGENT'] );
}
```

4. Add new tests:

```php
// -----------------------------------------------------------------------
// maybe_serve_markdown — UA detection
// -----------------------------------------------------------------------

public function test_calls_get_export_path_when_ua_matches_known_agent(): void {
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $this->make_post();
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

    // File does not exist → exits early after get_export_path, before readfile/exit.
    $this->generator->expects( $this->once() )
        ->method( 'get_export_path' )
        ->willReturn( '/nonexistent/path/post.md' );

    $neg = $this->make_negotiator( [
        'ua_force_enabled' => true,
        'ua_agent_strings' => [ 'GPTBot' ],
    ] );
    $neg->maybe_serve_markdown();
}

public function test_does_nothing_when_ua_force_disabled_even_if_ua_matches(): void {
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $this->make_post();
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

    $this->generator->expects( $this->never() )->method( 'get_export_path' );

    $neg = $this->make_negotiator( [
        'ua_force_enabled' => false,
        'ua_agent_strings' => [ 'GPTBot' ],
    ] );
    $neg->maybe_serve_markdown();
}

public function test_does_nothing_when_ua_unknown_and_no_accept_header(): void {
    $GLOBALS['_mock_is_singular']    = true;
    $GLOBALS['_mock_queried_object'] = $this->make_post();
    $_SERVER['HTTP_ACCEPT']          = 'text/html';
    $_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 Chrome/120';

    $this->generator->expects( $this->never() )->method( 'get_export_path' );

    $neg = $this->make_negotiator( [
        'ua_force_enabled' => true,
        'ua_agent_strings' => [ 'GPTBot' ],
    ] );
    $neg->maybe_serve_markdown();
}
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Negotiate/NegotiatorTest.php
```

Expected: failures because `Negotiator` constructor doesn't accept `AgentDetector` yet.

**Step 3: Update `Negotiator`**

In `src/Negotiate/Negotiator.php`:

1. Add import:
```php
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
```

2. Add `AgentDetector` as third constructor parameter:
```php
public function __construct(
    private readonly array $options,
    private readonly Generator $generator,
    private readonly AgentDetector $agent_detector
) {}
```

3. Update `maybe_serve_markdown()` — replace the early-return check on Accept header:

```php
// Before (remove this):
$accept = $_SERVER['HTTP_ACCEPT'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
if ( ! str_contains( $accept, 'text/markdown' ) ) {
    return;
}

// After (replace with):
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';          // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
$ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
if ( ! str_contains( $accept, 'text/markdown' ) && ! $this->agent_detector->is_known_agent( $ua ) ) {
    return;
}
```

**Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Negotiate/NegotiatorTest.php
```

Expected: all pass.

**Step 5: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Negotiate/Negotiator.php wp-markdown-for-agents/tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "feat: inject AgentDetector into Negotiator for UA-based serving"
```

---

### Task 4: Add Agent Detection section to SettingsPage

**Files:**
- Modify: `wp-markdown-for-agents/src/Admin/SettingsPage.php`
- Modify: `wp-markdown-for-agents/tests/Unit/Admin/SettingsPageTest.php`

**Step 1: Add failing tests**

Add to `tests/Unit/Admin/SettingsPageTest.php`:

```php
public function test_register_adds_ua_detection_section(): void {
    $this->make_page()->register();
    $sections = $GLOBALS['_mock_settings_sections']['wp-markdown-for-agents'] ?? [];
    $this->assertContains( 'wp_mfa_ua_detection', $sections );
}

public function test_register_adds_ua_detection_fields(): void {
    $this->make_page()->register();
    $fields = $GLOBALS['_mock_settings_fields']['wp-markdown-for-agents'] ?? [];
    $this->assertContains( 'wp_mfa_ua_force_enabled', $fields );
    $this->assertContains( 'wp_mfa_ua_agent_strings', $fields );
}

public function test_sanitize_ua_force_enabled_cast_to_bool(): void {
    $result = $this->make_page()->sanitize_options( [ 'ua_force_enabled' => '1' ] );
    $this->assertTrue( $result['ua_force_enabled'] );

    $result = $this->make_page()->sanitize_options( [] );
    $this->assertFalse( $result['ua_force_enabled'] );
}

public function test_sanitize_ua_agent_strings_parses_textarea_lines(): void {
    $result = $this->make_page()->sanitize_options( [
        'ua_agent_strings' => "GPTBot\nClaudeBot\n\nPerplexityBot\n",
    ] );
    $this->assertSame( [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ], $result['ua_agent_strings'] );
}

public function test_sanitize_ua_agent_strings_trims_whitespace(): void {
    $result = $this->make_page()->sanitize_options( [
        'ua_agent_strings' => "  GPTBot  \n  ClaudeBot  \n",
    ] );
    $this->assertSame( [ 'GPTBot', 'ClaudeBot' ], $result['ua_agent_strings'] );
}

public function test_sanitize_ua_agent_strings_drops_empty_lines(): void {
    $result = $this->make_page()->sanitize_options( [
        'ua_agent_strings' => "\n\nGPTBot\n\n",
    ] );
    $this->assertSame( [ 'GPTBot' ], $result['ua_agent_strings'] );
}
```

**Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Admin/SettingsPageTest.php
```

Expected: failures.

**Step 3: Update `SettingsPage::register()`**

After the existing `add_settings_section( 'wp_mfa_general', ... )` block, add:

```php
add_settings_section(
    'wp_mfa_ua_detection',
    __( 'Agent Detection', 'wp-markdown-for-agents' ),
    '__return_false',
    self::PAGE_SLUG
);

add_settings_field( 'wp_mfa_ua_force_enabled', __( 'Enable UA detection', 'wp-markdown-for-agents' ), [ $this, 'field_ua_force_enabled' ], self::PAGE_SLUG, 'wp_mfa_ua_detection' );
add_settings_field( 'wp_mfa_ua_agent_strings', __( 'Agent user-agent strings', 'wp-markdown-for-agents' ), [ $this, 'field_ua_agent_strings' ], self::PAGE_SLUG, 'wp_mfa_ua_detection' );
```

**Step 4: Update `SettingsPage::sanitize_options()`**

Add after the `$clean['delete_files_on_uninstall']` line:

```php
$clean['ua_force_enabled'] = ! empty( $input['ua_force_enabled'] );

// UA agent strings: one per line, trim whitespace, drop empty lines.
$ua_raw        = (string) ( $input['ua_agent_strings'] ?? '' );
$ua_lines      = array_filter( array_map( 'trim', explode( "\n", $ua_raw ) ) );
$clean['ua_agent_strings'] = array_values( $ua_lines );
```

**Step 5: Add field renderer methods**

Add at the end of the field renderers section, before the closing `}`:

```php
/** @since 1.1.0 */
public function field_ua_force_enabled(): void {
    $checked = checked( ! empty( $this->options['ua_force_enabled'] ), true, false );
    echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[ua_force_enabled]" value="1" ' . $checked . '>';
    echo '<p class="description">' . esc_html__( 'Serve Markdown to known LLM agent crawlers based on User-Agent string.', 'wp-markdown-for-agents' ) . '</p>';
}

/** @since 1.1.0 */
public function field_ua_agent_strings(): void {
    $val = esc_textarea( implode( "\n", (array) ( $this->options['ua_agent_strings'] ?? [] ) ) );
    echo '<textarea name="' . esc_attr( Options::OPTION_KEY ) . '[ua_agent_strings]" rows="8" class="large-text">' . $val . '</textarea>';
    echo '<p class="description">' . esc_html__( 'One User-Agent substring per line. Matching is case-insensitive. Edit to add or remove agents.', 'wp-markdown-for-agents' ) . '</p>';
}
```

**Step 6: Run tests to verify they pass**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Admin/SettingsPageTest.php
```

Expected: all pass.

**Step 7: Run full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 8: Commit**

```bash
git add wp-markdown-for-agents/src/Admin/SettingsPage.php wp-markdown-for-agents/tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: add Agent Detection section to settings page"
```

---

### Task 5: Wire AgentDetector into Plugin

**Files:**
- Modify: `wp-markdown-for-agents/src/Core/Plugin.php`

No new tests needed — `Plugin` is a wiring class with no independent logic.

**Step 1: Add import to `Plugin.php`**

Add to the `use` block at the top:

```php
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
```

**Step 2: Update `define_negotiate_hooks()`**

Replace:
```php
$negotiator = new Negotiator( $options, $this->generator );
```

With:
```php
$agent_detector = new AgentDetector( $options );
$negotiator     = new Negotiator( $options, $this->generator, $agent_detector );
```

**Step 3: Run full suite to confirm nothing broken**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all pass.

**Step 4: Commit**

```bash
git add wp-markdown-for-agents/src/Core/Plugin.php
git commit -m "feat: wire AgentDetector into Plugin for content negotiation"
```

---

### Task 6: Final verification

**Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all tests pass (should be ~117+ tests).

**Step 2: Invoke finishing-a-development-branch skill**

Use `superpowers:finishing-a-development-branch` to merge, push, or discard as appropriate.

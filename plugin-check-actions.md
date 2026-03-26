# Plugin Check: Action Items
**Plugin:** WP Markdown for Agents
**Report date:** 2026-03-26

---

## ~~🔴 Critical — Fix before any public release~~

### ~~1. SQL injection risks in `StatsRepository.php`~~ ✅ Resolved

The `$table` variable is always `$wpdb->prefix . 'mfa_access_stats'` (a constant suffix, never user input), which is the standard WordPress pattern. All user-supplied filter values go through `$wpdb->prepare()`. No actual injection risk exists; the original flags were PHPCS false positives on table-name interpolation.

---

## ~~🟡 Blocking for WordPress.org submission~~

### ~~2. Restricted term "wp" in plugin name and slug~~ ✅ Resolved

Plugin renamed to **Markdown for Agents and Statistics** with slug `markdown-for-agents`. Updated: main PHP file (`markdown-for-agents.php`), plugin header, `readme.txt`, `composer.json`, all text-domain strings, admin page slugs, redirect URLs, the `X-Markdown-Source` header, and test fixtures.

---

## ~~🟢 Quick wins — low effort~~

### ~~3. Missing `wp_unslash()` on `$_GET` values in `StatsPage.php`~~ ✅ Resolved

`wp_unslash()` added to `$_GET['agent']`, `$_GET['date_from']`, and `$_GET['date_to']` before `sanitize_text_field()`. (`Admin.php` `$_POST['post_id']` was already correct — `(int)` cast is sufficient for integers.)

### ~~4. Remove deprecated `load_plugin_textdomain()` call~~ ✅ Resolved

Removed the `plugins_loaded` action and `load_plugin_textdomain()` call from `Plugin.php`. Unnecessary since WordPress 4.6 for plugins hosted on WordPress.org.

---

## ⚪ Low priority — test files only

### 5. Unprefixed globals/functions in mock files

~150 warnings across `tests/mocks/wordpress-mocks.php`, `tests/mocks/namespace-mocks.php`, and various unit test files. These are mock implementations of WordPress core functions (e.g. `add_action`, `get_option`) that intentionally use WordPress's own function names.

These pose **no runtime risk** in production. A strict WordPress.org review may flag them, but they are essentially linter false positives given their purpose as test mocks.

**Fix (if needed for submission):** Wrap all test mocks in a namespace or add a plugin-specific prefix to global variables, e.g. `$wpmfa_mock_actions`.

---

## Summary

| Priority | File | Issue | Status |
|---|---|---|---|
| ~~🔴 Critical~~ | `StatsRepository.php` | SQL injection (3 locations) | ✅ Was a false positive |
| ~~🟢 Quick win~~ | `StatsPage.php` | Missing `wp_unslash()` (3 locations) | ✅ Fixed |
| ~~🟢 Quick win~~ | `Plugin.php` | Remove `load_plugin_textdomain()` | ✅ Fixed |
| ~~🟡 Blocking~~ | `readme.txt`, main PHP file | "wp" in plugin name/slug | ✅ Fixed |
| ⚪ Low | `tests/mocks/*.php` + unit tests | Unprefixed globals (150+ warnings) | Defer until after rename |

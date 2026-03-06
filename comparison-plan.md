# wp-markdown-for-agents — Implementation Plan

Standalone plan for fixing all issues identified in [comparison.md](comparison.md).

This document is self-contained. It includes enough detail — file paths, method signatures, reference code, and expected behaviour — to implement every item without access to the wp-to-file or wp-to-file-serve codebases.

**Plugin namespace:** `Tclp\WpMarkdownForAgents\`
**PSR-4 root:** `src/`
**PHP target:** 8.0+, WordPress 6.3+
**Key dependency:** `league/html-to-markdown` ^5.1

---

## Current File Structure (Reference)

```
wp-markdown-for-agents/
├── wp-markdown-for-agents.php          # Bootstrap, constants, activation hooks
├── uninstall.php                        # Clean uninstall (deletes wp_mfa_options)
├── composer.json                        # PSR-4 autoload, league/html-to-markdown
├── config/                              # NEW — Phase 3
│   └── export-profiles.yaml            # NEW — per-post-type config
├── src/
│   ├── Admin/
│   │   ├── Admin.php                    # Admin coordinator, POST handlers
│   │   ├── MetaBox.php                  # Per-post status + regenerate button
│   │   └── SettingsPage.php             # WordPress Settings API page
│   ├── CLI/
│   │   └── Commands.php                 # WP-CLI: generate, status, delete
│   ├── Config/                          # NEW — Phase 3
│   │   ├── ConfigReader.php            # NEW — YAML parser
│   │   └── ConfigMerger.php            # NEW — merges YAML + Options
│   ├── Core/
│   │   ├── Activator.php               # Activation hook
│   │   ├── Deactivator.php             # Deactivation hook
│   │   ├── Loader.php                  # Hook queueing
│   │   ├── Options.php                 # wp_mfa_options defaults + getter
│   │   └── Plugin.php                  # Wires all dependencies, calls run()
│   ├── Generator/
│   │   ├── AcfExtractor.php            # NEW — Phase 4
│   │   ├── ContentFilter.php           # Strips block comments (expand in Phase 5)
│   │   ├── Converter.php               # HTML→Markdown via league library
│   │   ├── Converters/
│   │   │   ├── CodeBlockConverter.php  # WordPress code blocks → fenced MD
│   │   │   └── TableConverter.php      # HTML tables → GFM tables
│   │   ├── FileWriter.php              # Filesystem I/O, path traversal protection
│   │   ├── FrontmatterBuilder.php      # Assembles frontmatter array
│   │   ├── Generator.php               # Orchestrator: build, convert, write
│   │   ├── HierarchyCollector.php      # NEW — Phase 4
│   │   ├── LlmsTxtGenerator.php        # Generates llms.txt index
│   │   ├── TaxonomyCollector.php       # Extracts taxonomy terms
│   │   ├── TaxonomyArchiveRenderer.php # NEW — Phase 5
│   │   └── YamlFormatter.php           # YAML serialisation
│   ├── Negotiate/
│   │   ├── AgentDetector.php           # UA substring matching
│   │   └── Negotiator.php             # HTTP content negotiation + serving
│   └── Stats/
│       ├── AccessLogger.php            # Thin wrapper around StatsRepository
│       ├── StatsPage.php               # Admin stats dashboard
│       └── StatsRepository.php         # DB layer for wp_mfa_access_stats
└── tests/
    ├── bootstrap.php
    ├── mocks/
    │   └── wordpress-mocks.php
    └── Unit/
        └── ...                          # 17+ test classes
```

---

## Phase 1: Bug Fixes

### B3 + B4: Fix discovery link and add query parameter support

**Why:** The `<link rel="alternate">` tag currently points to the HTML permalink with no format indicator. Agents following the link get HTML. There is also no `?output_format=md` query parameter, making browser/curl testing difficult and CDN cache keying impossible.

**File:** `src/Negotiate/Negotiator.php`

#### Current code (broken):

```php
// maybe_serve_markdown() — only checks Accept header + UA
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

$matched_agent = $this->agent_detector->get_matched_agent( $ua );
$via_accept    = str_contains( $accept, 'text/markdown' );

if ( ! $via_accept && null === $matched_agent ) {
    return;
}
```

```php
// output_link_tag() — points to HTML URL
$url = esc_url( get_permalink( $post->ID ) );
echo '<link rel="alternate" type="text/markdown" href="' . $url . '">';
```

#### Required changes:

**1. Add query parameter detection to `maybe_serve_markdown()`:**

```php
public function maybe_serve_markdown(): void {
    if ( ! $this->is_eligible_singular() ) {
        return;
    }

    $accept    = $_SERVER['HTTP_ACCEPT'] ?? '';
    $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $format_qp = $_GET['output_format'] ?? '';    // NEW

    $matched_agent = $this->agent_detector->get_matched_agent( $ua );
    $via_accept    = str_contains( $accept, 'text/markdown' );
    $via_query     = in_array( $format_qp, [ 'md', 'markdown' ], true );  // NEW

    if ( ! $via_accept && ! $via_query && null === $matched_agent ) {
        return;
    }

    // ... existing post/filepath checks ...

    // Log access
    $agent_label = $matched_agent ?? ( $via_accept ? 'accept-header' : 'query-param' );
    $this->access_logger->log_access( $post->ID, $agent_label );

    header( 'Content-Type: text/markdown; charset=utf-8' );

    // Only send Vary: Accept when negotiated via Accept header (not query param or UA)
    if ( $via_accept ) {
        header( 'Vary: Accept' );
    }

    readfile( $filepath );
    exit;
}
```

**2. Fix `output_link_tag()` to include query param:**

```php
public function output_link_tag(): void {
    // ... existing eligibility checks ...

    $url = esc_url( add_query_arg( 'output_format', 'md', get_permalink( $post->ID ) ) );
    echo '<link rel="alternate" type="text/markdown" title="Markdown format" href="' . $url . '">' . "\n";
}
```

**3. Tests to add (in `tests/Unit/Negotiate/NegotiatorTest.php`):**

- Request with `$_GET['output_format'] = 'md'` serves the file
- Request with `$_GET['output_format'] = 'markdown'` serves the file
- Request with `Accept: text/markdown` serves the file (existing)
- Request with known UA serves the file (existing)
- Request with none of the above returns (no output)
- `Vary: Accept` header is only sent for Accept-negotiated requests
- Link tag href contains `?output_format=md`

---

### B1: Fix LlmsTxtGenerator frontmatter parser

**Why:** The simple YAML parser can crash on malformed files and silently drops nested values (arrays).

**File:** `src/Generator/LlmsTxtGenerator.php`

#### Current code (lines ~129-131):

```php
if ( $in_fm && str_contains( $trimmed, ':' ) ) {
    [ $key, $value ] = explode( ':', $trimmed, 2 );
    $data[ trim( $key ) ] = trim( trim( $value ), '"' );
}
```

#### Required changes:

```php
if ( $in_fm ) {
    // Skip indented lines (YAML array items like "  - News")
    if ( str_starts_with( $trimmed, '-' ) || str_starts_with( ltrim( $line ), ' ' ) ) {
        continue;
    }

    // Only process top-level key: value pairs
    if ( str_contains( $trimmed, ':' ) ) {
        $parts = explode( ':', $trimmed, 2 );
        if ( count( $parts ) === 2 ) {
            $key   = trim( $parts[0] );
            $value = trim( $parts[1] );
            // Strip surrounding quotes
            $value = trim( $value, '"' );
            $value = trim( $value, "'" );
            if ( '' !== $key ) {
                $data[ $key ] = $value;
            }
        }
    }
}
```

Note: We're using `$line` (the raw line from `fgets`) to check indentation, and `$trimmed` (rtrimmed version) for content parsing. Make sure both variables are available in the while loop.

**Tests to add:**
- Frontmatter with `categories:` followed by `  - News` lines — categories key should be ignored, not crash
- Frontmatter with no colon on a line — should be skipped
- Frontmatter with quoted values — quotes stripped
- Normal key: value pairs still work

---

### B2: Remove mock function from production code

**Why:** `get_bloginfo()` mock leaked into source file.

**File:** `src/Generator/LlmsTxtGenerator.php`

#### Delete these lines (at the bottom of the file, after the class closing brace):

```php
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return $GLOBALS['_mock_bloginfo'][$show] ?? '';
    }
}
```

#### Verify `tests/mocks/wordpress-mocks.php` already defines `get_bloginfo()`:

If not, add it there:

```php
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return $GLOBALS['_mock_bloginfo'][ $show ] ?? '';
    }
}
```

---

## Phase 2: HTTP Response Parity

### G2 + G3: Add Content-Signal and X-Markdown-Source headers

**Why:** These headers follow the Cloudflare convention for AI-suitable content and help debug which plugin served the response.

**File:** `src/Negotiate/Negotiator.php`

#### Add after the `Content-Type` header in `maybe_serve_markdown()`:

```php
header( 'Content-Type: text/markdown; charset=utf-8' );
header( 'X-Markdown-Source: wp-markdown-for-agents' );  // NEW

/**
 * Filter the Content-Signal header value.
 *
 * @since 1.1.0
 * @param string $signal The signal string. Empty string disables the header.
 */
$content_signal = apply_filters( 'wp_mfa_content_signal', 'ai-input=yes, search=yes' );
if ( $content_signal ) {
    header( 'Content-Signal: ' . $content_signal );     // NEW
}
```

---

### G6: Add per-post kill-switch filter

**Why:** Allows disabling Markdown serving for specific posts without changing admin settings.

**File:** `src/Negotiate/Negotiator.php`

#### Add after the `$post instanceof \WP_Post` check in `maybe_serve_markdown()`:

```php
$post = get_queried_object();
if ( ! $post instanceof \WP_Post ) {
    return;
}

// NEW: per-post kill switch
if ( ! apply_filters( 'wp_mfa_serve_enabled', true, $post ) ) {
    return;
}
```

---

### G7: Add filterable post type allowlist for serving

**Why:** Allows runtime override of which post types are served, without changing admin settings.

**File:** `src/Negotiate/Negotiator.php`

#### Update `is_eligible_singular()`:

```php
private function is_eligible_singular(): bool {
    $post_types = (array) ( $this->options['post_types'] ?? [] );

    /**
     * Filter the post types eligible for Markdown serving.
     *
     * @since 1.1.0
     * @param string[] $post_types Post type slugs.
     */
    $post_types = apply_filters( 'wp_mfa_serve_post_types', $post_types );

    return is_singular( $post_types );
}
```

---

## Phase 3: ConfigReader Integration

This is the highest-value structural change. It enables per-post-type configuration for taxonomies, ACF fields, hierarchy, and content exclusion.

### 3.1: Create `config/export-profiles.yaml`

**File:** `config/export-profiles.yaml` (new)

This is the plugin's own config file. It ships with sensible defaults for `post` and `page`. Site owners add their custom post types here.

```yaml
# wp-markdown-for-agents Configuration
#
# Per-post-type settings for Markdown export.
# Runtime toggles (enabled, auto_generate, ua_agent_strings) live in
# the admin UI (wp_mfa_options). This file handles structural config
# that is better expressed declaratively.

defaults:
  include_title_header: true
  include_taxonomies: true
  include_hierarchy: true
  author_metadata:
    enabled: false
    include_bio: false
    include_avatar: false
    include_email: false

# Post type specific configurations
# Each key is a registered post_type slug.
#
# Available options:
#   include_title_header: bool   — Prepend # Title as H1 in body (default: true)
#   exclude_content: bool        — Skip post_content, frontmatter + ACF only (default: false)
#   taxonomies: [slugs]          — Which taxonomies to include (default: all registered for type)
#   include_hierarchy: bool      — Add parent/ancestors to frontmatter (default: true)
#   include_acf_labels: bool     — Use ACF field labels as H2 headers in body (default: false)
#   acf_fields:
#     frontmatter: [field_names] — ACF fields to include in YAML frontmatter
#     content: [field_names]     — ACF fields to inject into Markdown body
#   meta_fields: [meta_keys]    — WordPress post meta keys to include in frontmatter

post_type_configs:

  post:
    taxonomies: [category, post_tag]

  page:
    include_hierarchy: true
    taxonomies: []
```

---

### 3.2: Create `src/Config/ConfigReader.php`

**File:** `src/Config/ConfigReader.php` (new)

This is a standalone copy of wp-to-file's `ConfigReader`, adapted to this plugin's namespace. It parses `config/export-profiles.yaml` once per request and exposes read-only accessors.

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Config;

/**
 * Lightweight, stateless YAML configuration reader.
 *
 * Parses config/export-profiles.yaml once per request (static cache) and
 * exposes read-only accessors for defaults and post type configs.
 *
 * Adapted from wp-to-file ConfigReader.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Config
 */
class ConfigReader {

    /**
     * Parsed YAML data, keyed by config path.
     *
     * @var array<string, array>
     */
    private static array $cache = [];

    /**
     * Absolute path to the YAML configuration file.
     *
     * @var string
     */
    private string $config_path;

    /**
     * @param string $config_path Optional override. Defaults to config/export-profiles.yaml.
     */
    public function __construct( string $config_path = '' ) {
        $this->config_path = $config_path
            ?: dirname( __DIR__, 2 ) . '/config/export-profiles.yaml';
    }

    /**
     * Get default settings.
     *
     * @return array<string, mixed>
     */
    public function getDefaults(): array {
        return $this->load()['defaults'] ?? [];
    }

    /**
     * Get all post type configurations.
     *
     * @return array<string, array>
     */
    public function getPostTypeConfigs(): array {
        return $this->load()['post_type_configs'] ?? [];
    }

    /**
     * Get configuration for a specific post type.
     *
     * @param string $post_type Post type slug.
     * @return array Post type config or empty array.
     */
    public function getPostTypeConfig( string $post_type ): array {
        return $this->load()['post_type_configs'][ $post_type ] ?? [];
    }

    /**
     * Reset the static cache. Testing only.
     */
    public static function resetCache(): void {
        self::$cache = [];
    }

    // ── Private: YAML loading ──────────────────────────────────

    private function load(): array {
        if ( isset( self::$cache[ $this->config_path ] ) ) {
            return self::$cache[ $this->config_path ];
        }

        if ( ! file_exists( $this->config_path ) ) {
            self::$cache[ $this->config_path ] = [];
            return [];
        }

        $content = file_get_contents( $this->config_path );

        if ( false === $content ) {
            self::$cache[ $this->config_path ] = [];
            return [];
        }

        self::$cache[ $this->config_path ] = function_exists( 'yaml_parse' )
            ? yaml_parse( $content )
            : $this->simpleYAMLParse( $content );

        return self::$cache[ $this->config_path ];
    }

    /**
     * Simple YAML parser for the subset used in export-profiles.yaml.
     *
     * Handles: nested maps, inline arrays [a, b, c], scalars, booleans,
     * numbers, quoted strings, comments, array items (- value).
     * Assumes 2-space indentation.
     */
    private function simpleYAMLParse( string $content ): array {
        $lines          = explode( "\n", $content );
        $result         = [];
        $current_path   = [];
        $current_indent = 0;

        foreach ( $lines as $line ) {
            if ( empty( trim( $line ) ) || str_starts_with( trim( $line ), '#' ) ) {
                continue;
            }

            $indent = strlen( $line ) - strlen( ltrim( $line ) );
            $line   = trim( $line );

            // Walk back up the tree on dedent.
            if ( $indent < $current_indent ) {
                $levels_back = (int) ( ( $current_indent - $indent ) / 2 );
                for ( $i = 0; $i < $levels_back; $i++ ) {
                    array_pop( $current_path );
                }
            }
            $current_indent = $indent;

            if ( str_contains( $line, ':' ) ) {
                [ $key, $value ] = explode( ':', $line, 2 );
                $key   = trim( $key );
                $value = trim( $value );

                if ( '' === $value ) {
                    $current_path[] = $key;
                    $this->setNestedValue( $result, $current_path, [] );
                } else {
                    $full_path    = array_merge( $current_path, [ $key ] );
                    $parsed_value = $this->parseYAMLValue( $value );
                    $this->setNestedValue( $result, $full_path, $parsed_value );
                }
            } elseif ( str_starts_with( $line, '- ' ) ) {
                $value        = substr( $line, 2 );
                $parsed_value = $this->parseYAMLValue( $value );
                $current      = &$this->getNestedValue( $result, $current_path );
                if ( ! is_array( $current ) ) {
                    $current = [];
                }
                $current[] = $parsed_value;
            }
        }

        return $result;
    }

    /**
     * @return string|int|float|bool|array
     */
    private function parseYAMLValue( string $value ): string|int|float|bool|array {
        // Inline arrays: [a, b, c]
        if ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) ) {
            $inner = substr( $value, 1, -1 );
            return array_map( fn( $v ) => trim( trim( $v ), '"\'' ), explode( ',', $inner ) );
        }

        // Booleans.
        if ( in_array( strtolower( $value ), [ 'true', 'yes', 'on' ], true ) ) {
            return true;
        }
        if ( in_array( strtolower( $value ), [ 'false', 'no', 'off' ], true ) ) {
            return false;
        }

        // Numbers.
        if ( is_numeric( $value ) ) {
            return str_contains( $value, '.' ) ? (float) $value : (int) $value;
        }

        // Quoted strings — strip quotes.
        if ( ( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) ) ||
             ( str_starts_with( $value, "'" ) && str_ends_with( $value, "'" ) ) ) {
            return substr( $value, 1, -1 );
        }

        return $value;
    }

    private function setNestedValue( array &$array, array $path, mixed $value ): void {
        $current = &$array;
        foreach ( $path as $key ) {
            if ( ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) ) {
                $current[ $key ] = [];
            }
            $current = &$current[ $key ];
        }
        if ( is_array( $value ) && is_array( $current ) ) {
            $current = array_merge( $current, $value );
        } else {
            $current = $value;
        }
    }

    private function &getNestedValue( array &$array, array $path ): mixed {
        $current = &$array;
        foreach ( $path as $key ) {
            if ( ! isset( $current[ $key ] ) ) {
                $current[ $key ] = [];
            }
            $current = &$current[ $key ];
        }
        return $current;
    }
}
```

---

### 3.3: Create `src/Config/ConfigMerger.php`

**File:** `src/Config/ConfigMerger.php` (new)

Merges YAML post-type config with admin Options. Options override YAML where they conflict.

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Config;

use Tclp\WpMarkdownForAgents\Core\Options;

/**
 * Merges YAML config with Options for a given post type.
 *
 * Merge order (lowest → highest priority):
 *   1. YAML defaults
 *   2. YAML post_type_configs[{type}]
 *   3. Options overrides (include_taxonomies, include_meta, meta_keys)
 *
 * @since  1.1.0
 */
class ConfigMerger {

    public function __construct(
        private readonly ConfigReader $config_reader
    ) {}

    /**
     * Get merged config for a post type.
     *
     * @param string              $post_type The post type slug.
     * @param array<string,mixed> $options   Plugin options from Options::get().
     * @return array<string,mixed>
     */
    public function get_config( string $post_type, array $options = [] ): array {
        $defaults  = $this->config_reader->getDefaults();
        $type_conf = $this->config_reader->getPostTypeConfig( $post_type );

        // YAML layers
        $config = array_merge( $defaults, $type_conf );

        // Options overrides
        if ( isset( $options['include_taxonomies'] ) ) {
            $config['include_taxonomies'] = (bool) $options['include_taxonomies'];
        }

        if ( ! empty( $options['include_meta'] ) && ! empty( $options['meta_keys'] ) ) {
            $config['meta_fields'] = (array) $options['meta_keys'];
        }

        return $config;
    }
}
```

---

### 3.4: Wire ConfigReader into Plugin.php

**File:** `src/Core/Plugin.php`

In the `define_generator_hooks()` or equivalent wiring method, instantiate ConfigReader and ConfigMerger and pass the merged config to Generator, FrontmatterBuilder, and TaxonomyCollector.

```php
$config_reader = new \Tclp\WpMarkdownForAgents\Config\ConfigReader();
$config_merger = new \Tclp\WpMarkdownForAgents\Config\ConfigMerger( $config_reader );

// When building Generator / FrontmatterBuilder, pass merged config:
$post_type = $post->post_type; // at generation time
$config    = $config_merger->get_config( $post_type, $options );
```

Note: Since Generator processes different post types, the config merge should happen per-post inside `Generator::generate_post()`, not at plugin boot. Consider passing `ConfigMerger` to the Generator constructor and calling `get_config()` per post.

---

### 3.5: Update TaxonomyCollector to accept allowlist

**File:** `src/Generator/TaxonomyCollector.php`

#### Current: collects ALL taxonomies unconditionally.

#### Change `collect()` signature:

```php
/**
 * @param int      $post_id   The post ID.
 * @param string   $post_type The post type slug.
 * @param string[] $allowlist Optional taxonomy slugs to include. Empty = all.
 * @return array<string, string[]>
 */
public function collect( int $post_id, string $post_type, array $allowlist = [] ): array
```

If `$allowlist` is non-empty, only fetch terms for those taxonomies. Otherwise, keep current behaviour (all registered taxonomies for the post type).

---

### 3.6: Update composer.json autoload

Add the new namespace path:

```json
{
    "autoload": {
        "psr-4": {
            "Tclp\\WpMarkdownForAgents\\": "src/"
        }
    }
}
```

The existing PSR-4 mapping already covers `src/Config/` since it maps the root namespace to `src/`. No change needed — just run `composer dump-autoload`.

---

### 3.7: Tests

Create `tests/Unit/Config/ConfigReaderTest.php` and `tests/Unit/Config/ConfigMergerTest.php`.

For ConfigReader tests, create a fixture YAML file in `tests/fixtures/test-profiles.yaml` and pass its path to the constructor.

Key test cases:
- `getDefaults()` returns defaults section
- `getPostTypeConfig('post')` returns post config
- `getPostTypeConfig('nonexistent')` returns empty array
- Static cache works (second call doesn't re-parse)
- `resetCache()` clears the cache
- `simpleYAMLParse` handles: nested maps, inline arrays, booleans, numbers, quoted strings, comments, array items
- ConfigMerger: YAML + Options merge correctly, Options override YAML

---

## Phase 4: Richer Frontmatter

All items in this phase update `FrontmatterBuilder::build()` and potentially `Generator::generate_post()`.

### T6: Relative featured image paths

**File:** `src/Generator/FrontmatterBuilder.php`

#### Current (`add_featured_image`):

```php
$url = wp_get_attachment_url( $thumbnail_id );
if ( $url ) {
    $frontmatter['featured_image'] = $url;  // Absolute URL
}
```

#### Change to:

```php
$url = wp_get_attachment_url( $thumbnail_id );
if ( $url ) {
    $upload_dir = wp_get_upload_dir();
    $base_url   = $upload_dir['baseurl'] ?? '';
    // Convert to relative path: /uploads/2025/03/image.jpg
    if ( $base_url && str_starts_with( $url, $base_url ) ) {
        $url = '/uploads' . substr( $url, strlen( $base_url ) );
    }
    $frontmatter['featured_image'] = $url;
}
```

Add `wp_get_upload_dir` to the WordPress mocks if not already present:

```php
if ( ! function_exists( 'wp_get_upload_dir' ) ) {
    function wp_get_upload_dir(): array {
        return [
            'basedir' => '/tmp/wp-content/uploads',
            'baseurl' => 'https://example.com/wp-content/uploads',
            'path'    => '/tmp/wp-content/uploads/' . gmdate( 'Y/m' ),
            'url'     => 'https://example.com/wp-content/uploads/' . gmdate( 'Y/m' ),
        ];
    }
}
```

---

### T7: Add Topics section to Markdown body

**Why:** wp-to-file appends a `## Topics` section with linked taxonomy terms at the bottom of the Markdown body. This helps LLMs navigate between related content.

**File:** `src/Generator/Generator.php`

#### In `generate_post()`, after conversion, before writing:

```php
$markdown = $this->converter->convert( $html, $post );

// Append Topics section with linked taxonomy terms
$topics = $this->build_topics_section( $post );
if ( $topics ) {
    $markdown .= "\n\n" . $topics;
}

$yaml    = $this->yaml_formatter->format( $frontmatter );
$content = $yaml . "\n" . $markdown;
```

#### New private method:

```php
/**
 * Build a ## Topics section with linked taxonomy terms.
 *
 * @param \WP_Post $post The post.
 * @return string Markdown section or empty string.
 */
private function build_topics_section( \WP_Post $post ): string {
    if ( empty( $this->options['include_taxonomies'] ) ) {
        return '';
    }

    $taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
    $links      = [];

    foreach ( $taxonomies as $taxonomy ) {
        if ( ! $taxonomy->public ) {
            continue;
        }

        $terms = get_the_terms( $post->ID, $taxonomy->name );
        if ( ! $terms || is_wp_error( $terms ) ) {
            continue;
        }

        foreach ( $terms as $term ) {
            $url = get_term_link( $term );
            if ( ! is_wp_error( $url ) ) {
                $name    = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $links[] = '- [' . $name . '](' . $url . ')';
            }
        }
    }

    if ( empty( $links ) ) {
        return '';
    }

    return "## Topics\n\n" . implode( "\n", $links );
}
```

---

### T4: Hierarchy support

**File:** `src/Generator/HierarchyCollector.php` (new)

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Extracts parent/ancestor hierarchy for hierarchical post types.
 *
 * @since  1.1.0
 */
class HierarchyCollector {

    /**
     * Collect hierarchy data for a post.
     *
     * @param \WP_Post $post The post.
     * @return array{parent?: array{id: int, title: string}, ancestors?: array} Empty if not hierarchical.
     */
    public function collect( \WP_Post $post ): array {
        if ( ! is_post_type_hierarchical( $post->post_type ) ) {
            return [];
        }

        $data = [];

        if ( $post->post_parent ) {
            $parent = get_post( $post->post_parent );
            if ( $parent instanceof \WP_Post ) {
                $data['parent'] = [
                    'id'    => $parent->ID,
                    'title' => wp_strip_all_tags( $parent->post_title ),
                ];
            }
        }

        $ancestor_ids = get_post_ancestors( $post );
        if ( ! empty( $ancestor_ids ) ) {
            $data['ancestors'] = [];
            foreach ( $ancestor_ids as $ancestor_id ) {
                $ancestor = get_post( $ancestor_id );
                if ( $ancestor instanceof \WP_Post ) {
                    $data['ancestors'][] = [
                        'id'    => $ancestor->ID,
                        'title' => wp_strip_all_tags( $ancestor->post_title ),
                    ];
                }
            }
        }

        return $data;
    }
}
```

#### Wire into FrontmatterBuilder:

Inject `HierarchyCollector` via constructor. In `build()`:

```php
if ( ! empty( $config['include_hierarchy'] ) ) {
    $hierarchy = $this->hierarchy_collector->collect( $post );
    if ( ! empty( $hierarchy ) ) {
        $frontmatter = array_merge( $frontmatter, $hierarchy );
    }
}
```

Add mock functions to `tests/mocks/wordpress-mocks.php`:

```php
if ( ! function_exists( 'is_post_type_hierarchical' ) ) {
    function is_post_type_hierarchical( string $post_type ): bool {
        return in_array( $post_type, [ 'page' ], true );
    }
}

if ( ! function_exists( 'get_post_ancestors' ) ) {
    function get_post_ancestors( $post ): array {
        return $GLOBALS['_mock_post_ancestors'][ $post->ID ] ?? [];
    }
}
```

---

### T5: Author metadata (opt-in)

**File:** `src/Generator/FrontmatterBuilder.php`

#### Add to `build()`, controlled by config:

```php
if ( ! empty( $config['author_metadata']['enabled'] ) ) {
    $author_id = (int) $post->post_author;
    $author    = [
        'name' => get_the_author_meta( 'display_name', $author_id ),
    ];

    if ( ! empty( $config['author_metadata']['include_bio'] ) ) {
        $bio = get_the_author_meta( 'description', $author_id );
        if ( $bio ) {
            $author['bio'] = wp_strip_all_tags( $bio );
        }
    }

    if ( ! empty( $config['author_metadata']['include_email'] ) ) {
        $author['email'] = get_the_author_meta( 'user_email', $author_id );
    }

    $frontmatter['author'] = $author;
}
```

Add `get_the_author_meta` mock:

```php
if ( ! function_exists( 'get_the_author_meta' ) ) {
    function get_the_author_meta( string $field, int $user_id = 0 ): string {
        return $GLOBALS['_mock_author_meta'][ $user_id ][ $field ] ?? '';
    }
}
```

---

### T3: ACF field support

**Depends on:** Phase 3 (ConfigReader). The YAML config provides the field list:

```yaml
post_type_configs:
  clause:
    acf_fields:
      frontmatter: [clause_fields.clause_summary, clause_fields.clause_last_updated_date]
      content: [clause_fields.clause_content, clause_fields.clause_updates]
    include_acf_labels: true
```

**File:** `src/Generator/AcfExtractor.php` (new)

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Extracts ACF fields for frontmatter and content injection.
 *
 * Supports dot notation for nested group fields (e.g. "group.field").
 * Returns empty arrays when ACF is not active — no hard dependency.
 *
 * @since  1.1.0
 */
class AcfExtractor {

    /**
     * Extract ACF fields for frontmatter.
     *
     * @param int                $post_id     The post ID.
     * @param array<int,string>  $field_names Field names from config (dot notation supported).
     * @return array<string,mixed> Key-value pairs for frontmatter.
     */
    public function extract_frontmatter( int $post_id, array $field_names ): array {
        if ( ! function_exists( 'get_field' ) ) {
            return [];
        }

        $data = [];

        foreach ( $field_names as $field_name ) {
            $value = $this->get_value( $post_id, $field_name );

            if ( null === $value || '' === $value ) {
                continue;
            }

            // Use the leaf key as the frontmatter key
            $key = str_contains( $field_name, '.' )
                ? substr( $field_name, strrpos( $field_name, '.' ) + 1 )
                : $field_name;

            $data[ $key ] = $this->normalize_value( $value );
        }

        return $data;
    }

    /**
     * Extract ACF fields for content injection (returns HTML).
     *
     * @param int               $post_id       The post ID.
     * @param array<int,string> $field_names   Field names from config.
     * @param bool              $include_labels Wrap each field in an H2 with the field label.
     * @return string HTML to prepend/append to post content.
     */
    public function extract_content( int $post_id, array $field_names, bool $include_labels = false ): string {
        if ( ! function_exists( 'get_field' ) ) {
            return '';
        }

        $html = '';

        foreach ( $field_names as $field_name ) {
            $value = $this->get_value( $post_id, $field_name );

            if ( null === $value || '' === $value ) {
                continue;
            }

            if ( $include_labels && function_exists( 'get_field_object' ) ) {
                $leaf   = str_contains( $field_name, '.' )
                    ? substr( $field_name, strrpos( $field_name, '.' ) + 1 )
                    : $field_name;
                $obj    = get_field_object( $leaf, $post_id );
                $label  = $obj['label'] ?? ucfirst( str_replace( '_', ' ', $leaf ) );
                $html  .= '<h2>' . esc_html( $label ) . '</h2>' . "\n";
            }

            if ( is_array( $value ) ) {
                $html .= '<p>' . esc_html( implode( ', ', array_map( 'strval', $value ) ) ) . '</p>' . "\n";
            } else {
                // Value may contain HTML (WYSIWYG fields)
                $html .= (string) $value . "\n";
            }
        }

        return $html;
    }

    /**
     * Get a field value, supporting dot notation for groups.
     *
     * "clause_fields.clause_summary" → get_field('clause_fields')['clause_summary']
     */
    private function get_value( int $post_id, string $field_name ): mixed {
        if ( ! str_contains( $field_name, '.' ) ) {
            return get_field( $field_name, $post_id );
        }

        $parts = explode( '.', $field_name );
        $value = get_field( $parts[0], $post_id );

        for ( $i = 1; $i < count( $parts ); $i++ ) {
            if ( ! is_array( $value ) || ! isset( $value[ $parts[ $i ] ] ) ) {
                return null;
            }
            $value = $value[ $parts[ $i ] ];
        }

        return $value;
    }

    /**
     * Normalize ACF values for frontmatter (handle images, arrays, objects).
     */
    private function normalize_value( mixed $value ): mixed {
        // Image field (returns array with 'url')
        if ( is_array( $value ) && isset( $value['url'] ) && isset( $value['type'] ) ) {
            return $value['url'];
        }

        // Gallery field (array of image arrays)
        if ( is_array( $value ) && ! empty( $value ) && isset( $value[0]['url'] ) ) {
            return array_column( $value, 'url' );
        }

        // Standard array (e.g. checkbox, select multiple)
        if ( is_array( $value ) ) {
            return array_map( fn( $v ) => is_scalar( $v ) ? $v : (string) $v, $value );
        }

        if ( is_object( $value ) ) {
            return (array) $value;
        }

        if ( is_string( $value ) ) {
            return wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }

        return $value;
    }
}
```

#### Wire into FrontmatterBuilder and Generator:

In `FrontmatterBuilder::build()`:

```php
$acf_fm_fields = $config['acf_fields']['frontmatter'] ?? [];
if ( ! empty( $acf_fm_fields ) ) {
    $acf_data    = $this->acf_extractor->extract_frontmatter( $post->ID, $acf_fm_fields );
    $frontmatter = array_merge( $frontmatter, $acf_data );
}
```

In `Generator::generate_post()`, before conversion:

```php
$acf_content_fields = $config['acf_fields']['content'] ?? [];
if ( ! empty( $acf_content_fields ) ) {
    $include_labels = ! empty( $config['include_acf_labels'] );
    $acf_html       = $this->acf_extractor->extract_content( $post->ID, $acf_content_fields, $include_labels );
    $html          .= $acf_html;
}
```

---

## Phase 5: Content & Serving Improvements

### T2: Improve ContentFilter

**File:** `src/Generator/ContentFilter.php`

#### Current: only strips Gutenberg block comments.

#### Add URL normalisation and HTML sanitisation:

```php
public function filter( string $html ): string {
    if ( '' === $html ) {
        return '';
    }

    // 1. Strip block editor comments (existing)
    $html = preg_replace( '/<!--\s*wp:[^\-]*?-->/s', '', $html ) ?? $html;
    $html = preg_replace( '/<!--\s*\/wp:[^\-]*?-->/s', '', $html ) ?? $html;

    // 2. Normalise upload URLs to relative paths
    $upload_dir = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : [];
    $upload_url = $upload_dir['baseurl'] ?? '';
    if ( $upload_url ) {
        $html = str_replace( $upload_url, '/uploads', $html );
    }

    // 3. Normalise site URLs to relative paths
    if ( function_exists( 'home_url' ) ) {
        $html = str_replace( home_url( '/' ), '/', $html );
    }

    return $html;
}
```

Note: Full HTML sanitisation with `strip_tags()` + allowlist is optional here since the `league/html-to-markdown` converter already handles tag stripping. Only add it if you find problematic tags in output.

---

### G1: Taxonomy archive Markdown support

**File:** `src/Generator/TaxonomyArchiveRenderer.php` (new)

This renders a taxonomy term as a Markdown document with frontmatter, hierarchy, and a post listing. Adapted from wp-to-file-serve's `wptofile_serve_render_term_markdown()`.

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Renders a taxonomy term archive as Markdown.
 *
 * Includes YAML frontmatter (title, taxonomy, slug, description, post_count),
 * hierarchy (parent/children), and a post listing.
 *
 * @since  1.1.0
 */
class TaxonomyArchiveRenderer {

    /**
     * Render a term as Markdown.
     *
     * @param \WP_Term     $term     The term object.
     * @param \WP_Taxonomy $taxonomy The taxonomy object.
     * @param int          $max_posts Maximum posts to list (default 50).
     * @return string Complete Markdown document.
     */
    public function render( \WP_Term $term, \WP_Taxonomy $taxonomy, int $max_posts = 50 ): string {
        $name        = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $description = $term->description ? wp_strip_all_tags( $term->description ) : '';
        $term_url    = get_term_link( $term );

        // Frontmatter
        $lines   = [];
        $lines[] = '---';
        $lines[] = 'title: "' . addcslashes( $name, '"' ) . '"';
        $lines[] = 'type: taxonomy_term';
        $lines[] = 'taxonomy: ' . $term->taxonomy;
        $lines[] = 'slug: ' . $term->slug;
        if ( ! is_wp_error( $term_url ) ) {
            $lines[] = 'url: ' . $term_url;
        }
        if ( $description ) {
            $lines[] = 'description: "' . addcslashes( $description, '"' ) . '"';
        }
        $lines[] = 'post_count: ' . (int) $term->count;
        $lines[] = '---';
        $lines[] = '';

        // Title
        $lines[] = '# ' . $name;
        $lines[] = '';

        if ( $description ) {
            $lines[] = $description;
            $lines[] = '';
        }

        // Hierarchy
        $parent   = $term->parent ? get_term( $term->parent, $term->taxonomy ) : null;
        $children = get_terms( [
            'taxonomy'   => $term->taxonomy,
            'parent'     => $term->term_id,
            'hide_empty' => false,
        ] );

        if ( $parent instanceof \WP_Term || ( is_array( $children ) && ! empty( $children ) ) ) {
            $lines[] = '## Hierarchy';
            $lines[] = '';

            if ( $parent instanceof \WP_Term ) {
                $parent_url  = get_term_link( $parent );
                $parent_name = html_entity_decode( $parent->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $lines[]     = is_wp_error( $parent_url )
                    ? '**Parent:** ' . $parent_name
                    : '**Parent:** [' . $parent_name . '](' . $parent_url . ')';
            }

            if ( is_array( $children ) && ! empty( $children ) ) {
                $lines[] = '**Children:**';
                foreach ( $children as $child ) {
                    $child_url  = get_term_link( $child );
                    $child_name = html_entity_decode( $child->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                    $lines[]    = is_wp_error( $child_url )
                        ? '- ' . $child_name
                        : '- [' . $child_name . '](' . $child_url . ')';
                }
            }
            $lines[] = '';
        }

        // Post listing
        $posts = get_posts( [
            'tax_query'      => [ [
                'taxonomy' => $term->taxonomy,
                'terms'    => $term->term_id,
            ] ],
            'posts_per_page' => $max_posts,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        if ( ! empty( $posts ) ) {
            $lines[] = '## Content (' . $term->count . ' ' . ( $term->count === 1 ? 'post' : 'posts' ) . ')';
            $lines[] = '';
            foreach ( $posts as $p ) {
                $permalink = get_permalink( $p );
                $title     = html_entity_decode( get_the_title( $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $lines[]   = '- [' . $title . '](' . $permalink . ')';
            }
            $lines[] = '';
        }

        return implode( "\n", $lines );
    }
}
```

#### Wire into Negotiator:

Update `maybe_serve_markdown()` to handle taxonomy archives:

```php
public function maybe_serve_markdown(): void {
    // ... existing query param / accept / UA checks ...

    // NEW: taxonomy archive support
    if ( is_tax() || is_category() || is_tag() ) {
        $this->serve_taxonomy_archive( $via_accept );
        return;
    }

    // ... existing singular post logic ...
}

private function serve_taxonomy_archive( bool $via_accept ): void {
    $term = get_queried_object();
    if ( ! $term instanceof \WP_Term ) {
        return;
    }

    $taxonomy = get_taxonomy( $term->taxonomy );
    if ( ! $taxonomy ) {
        return;
    }

    $renderer = new TaxonomyArchiveRenderer();
    $markdown = $renderer->render( $term, $taxonomy );

    $agent_label = /* determine from UA/accept */;
    $this->access_logger->log_access( 0, $agent_label ); // term access

    header( 'Content-Type: text/markdown; charset=utf-8' );
    header( 'X-Markdown-Source: wp-markdown-for-agents' );
    if ( $via_accept ) {
        header( 'Vary: Accept' );
    }

    echo $markdown;
    exit;
}
```

Also update `output_link_tag()` to emit `<link>` tags for taxonomy archives (check `is_tax() || is_category() || is_tag()`).

---

## Phase Summary

| Phase | Items | Effort | Dependencies |
|-------|-------|--------|--------------|
| **1 — Bug fixes** | B1, B2, B3, B4, G4 (Vary fix) | Small | None |
| **2 — HTTP parity** | G2, G3, G6, G7 | Small | None |
| **3 — ConfigReader** | T1, T8 (taxonomy allowlist) | Medium-Large | None |
| **4 — Rich frontmatter** | T3 (ACF), T4 (hierarchy), T5 (author), T6 (relative images), T7 (topics) | Large | Phase 3 for T3 |
| **5 — Content & serving** | T2 (content filter), G1 (taxonomy archives) | Medium | None (G1 benefits from Phase 3) |

Phases 1 and 2 can ship immediately. Phase 3 unblocks Phase 4's ACF support. Phase 5 is independent.

---

## Reference: wp-to-file export-profiles.yaml (this site)

Included for reference when building the new plugin's config file. This is the YAML from `wp-content/mu-plugins/wp-to-file/config/export-profiles.yaml`:

```yaml
defaults:
  post_type: post
  file_type: md
  post_status: publish
  posts_per_page: -1
  orderby: date
  order: ASC
  author_metadata:
    enabled: false
    include_bio: false
    include_avatar: false
    include_email: false

profiles:
  blog-to-11ty:
    description: "Export blog posts for 11ty static site"
    post_type: post
    file_type: md
    layout: "layouts/blog-post.njk"
    include_taxonomies: true

  pages-to-11ty:
    description: "Export pages for 11ty static site"
    post_type: page
    file_type: md
    layout: "layouts/page.njk"

  llm-clauses:
    description: "Export all clauses as Markdown for LLM/AI content negotiation serving"
    post_type: clause
    file_type: md

  llm-guides:
    description: "Export all guides as Markdown for LLM/AI content negotiation serving"
    post_type: guide
    file_type: md

post_type_configs:
  post:
    default_file_type: md
    layout: "layouts/blog-post.njk"
    exclude_content: false
    acf_fields:
      frontmatter: [post_subtitle, post_meta_description, field_group]
      content: [post_subtitle, post_meta_description, field_group]

  page:
    default_file_type: md
    layout: "layouts/page.njk"

  clause:
    default_file_type: md
    exclude_content: true
    include_title_header: true
    include_acf_labels: true
    taxonomies: [clause-application, climate-or-nature-outcome, climate-solution,
                 content-type, contract-lifecycle-stage, direction-of-obligation,
                 jurisdiction, law-or-regulation, legal-concept-activity,
                 maintenance-status, practice-area, primary-user, sector,
                 standard-or-framework, tclp-principle, use-case]
    acf_fields:
      frontmatter: [clause_fields.clause_child_name, clause_fields.clause_summary,
                     clause_fields.clause_last_updated_date, clause_fields.related_clauses]
      content: [clause_fields.clause_child_name, clause_fields.clause_summary,
                clause_fields.clause_what_this_clause_does, clause_fields.clause_recitals,
                clause_fields.clause_content, clause_fields.clause_updates]
    computed_fields:
      lastReviewedDate:
        predicate: "tclp:lastReviewedDate"
        sources:
          - type: acf
            field: clause_fields.clause_last_updated_date
          - type: post
            property: post_modified
        format: date

  guide:
    default_file_type: md
    include_title_header: true
    include_acf_labels: true
    taxonomies: [application, climate-or-nature-outcome, climate-solution,
                 contract-lifecycle-stage, jurisdiction, law-or-regulation,
                 legal-concept-activity, maintenance-status, practice-area,
                 primary-user, sector, standard-or-framework, tclp-principle, use-case]
    acf_fields:
      frontmatter: [guide_fields.guide_last_updated_date]
    computed_fields:
      lastReviewedDate:
        predicate: "tclp:lastReviewedDate"
        sources:
          - type: acf
            field: guide_fields.guide_last_updated_date
          - type: post
            property: post_modified
        format: date
```

## Reference: wp-to-file ConfigReader API

```php
// Constructor — defaults to config/export-profiles.yaml relative to its own directory
$reader = new ConfigReader( string $config_path = '' );

// Read-only accessors
$reader->getDefaults(): array                    // → defaults section
$reader->getPostTypeConfigs(): array             // → all post_type_configs
$reader->getPostTypeConfig( string $type ): array // → single post type config or []

// Static cache management (testing only)
ConfigReader::resetCache(): void
```

**YAML parser fallback:** The built-in `simpleYAMLParse()` handles the YAML subset used in export-profiles.yaml: nested maps (2-space indent), inline arrays (`[a, b, c]`), scalars, booleans (`true/false/yes/no/on/off`), numbers, quoted strings, comments (`#`), and list items (`- value`). It does NOT handle multi-line strings, anchors/aliases, or flow mappings.

# WP Markdown for Agents — Plugin Build Plan

## Overview

A standalone, general-purpose WordPress plugin for public release that makes any
WordPress site's content accessible to AI agents and LLMs via standard HTTP content
negotiation. When a client sends `Accept: text/markdown`, the plugin serves a
pre-generated Markdown file instead of the normal HTML page. The plugin also provides
the tooling to generate and maintain those Markdown files.

**Two core responsibilities:**

1. **Serve** — intercept requests with `Accept: text/markdown`, serve the corresponding
   pre-generated `.md` file, and advertise availability via `<link rel="alternate">` in
   the page head.

2. **Generate** — convert WordPress post content (including taxonomies and custom fields)
   to well-structured Markdown files, stored in a configurable directory, via WP-CLI
   commands and/or an admin UI trigger.

---

## Background and rationale

AI agents and LLMs increasingly fetch web content to answer user queries. Most use
content extraction pipelines that strip HTML structure, navigation, and metadata — and
never see JSON-LD in the document head. Serving clean Markdown with rich frontmatter
gives agents accurate, structured content without guessing.

The HTTP `Accept: text/markdown` header is the correct standards-based signal for this
(RFC 7231 content negotiation). A `Vary: Accept` response header ensures CDN caches
serve the right version to each client type.

---

## Architecture basis: dgwltd-boilerplate

This plugin is built on the `dgwltd-boilerplate` canonical reference implementation.
It follows the boilerplate's PSR-4 namespace structure, `Loader` hook management
pattern, and test infrastructure conventions.

**Key boilerplate patterns adopted:**

- Minimal bootstrap file → delegates to `Plugin` class
- `src/Core/Loader.php` queues hooks, defers execution to `run()`
- `src/Core/Plugin.php` orchestrates all hook registration
- `src/Core/Activator.php` / `Deactivator.php` for lifecycle
- `src/Core/Options.php` for centralised option defaults
- PHPUnit 9.6 with WordPress mocks in `tests/mocks/wordpress-mocks.php`
- `declare(strict_types=1)` on every file
- Constructor property promotion, union types, PHP 8.0+

---

## Lineage from wp-to-file

The existing `wp-content/mu-plugins/wp-to-file/` mu-plugin is a CLI-only batch export
tool. Several of its components contain proven logic that will be extracted and adapted
for this plugin. The table below tracks what is reused, what is adapted, and what is
new.

### Reused (extracted and adapted)

| wp-to-file source | New plugin target | Adaptation needed |
|---|---|---|
| `Processors/MarkdownProcessor` | `src/Generator/Converter.php` | Remove SSG-specific config (`layout`, `.html` permalinks). Use `league/html-to-markdown ^5.1` (wp-to-file uses ^4.9 — API differences to verify). Keep custom `TableConverter` and `CodeBlockConverter`. |
| `Core/Traits/YamlFormatting` | `src/Generator/YamlFormatter.php` | Extract as standalone class (not trait). Same escaping/quoting logic. |
| `Core/Traits/TaxonomySupport` | `src/Generator/TaxonomyCollector.php` | Extract as standalone class. Same normalisation (`post_tag` → `tags`). |
| `Core/AbstractProcessor::prepareMeta()` | `src/Generator/FrontmatterBuilder.php` | Strip SSG keys (`layout`, `eleventyComputed`, `file_type`). Change permalink to canonical absolute URL. Remove `AuthorExtractor` and `ACFSupport`. |
| `Filters/ContentFilter` | `src/Generator/ContentFilter.php` | Remove URL normalisation to relative paths (keep canonical URLs). Keep block comment stripping and HTML sanitisation. |
| `Core/LLMsTxtGenerator` | `src/Generator/LlmsTxtGenerator.php` | Adapt to read from export directory. Optional feature. |

### Not carried over

| wp-to-file component | Reason |
|---|---|
| `ConfigManager` (YAML profiles) | Replaced by WordPress Options API |
| `ContentSelector` (fluent query builder) | Batch generation uses simple paginated `get_posts()` |
| `ManifestGenerator`, `ContentHasher` | Incremental tracking not needed — regenerate on save |
| `CSVProcessor`, `HTMLProcessor`, `JSONProcessor`, `JSONLDProcessor` | Markdown only |
| `AuthorExtractor` | Not in frontmatter spec |
| `ACFSupport` trait | Left to filters per plan |
| `HierarchySupport` trait | Not in frontmatter spec |

### Built new

| Component | Purpose |
|---|---|
| `src/Negotiate/Negotiator.php` | Content negotiation — `template_redirect` + `wp_head` |
| `src/Admin/Admin.php` | Settings page (Settings API), per-post meta box |
| `src/Admin/SettingsPage.php` | Settings page registration and rendering |
| `src/Admin/MetaBox.php` | Per-post meta box for regeneration |
| `src/CLI/Commands.php` | WP-CLI commands under `markdown-agents` |
| `src/Core/Activator.php` | Create export dir, set defaults |
| `src/Core/Deactivator.php` | Flush rewrite rules |
| `uninstall.php` | Clean option removal |

---

## WordPress standards and constraints

- Follow the **dgwltd-boilerplate** structure (PSR-4, `src/` layout)
- **WordPress Coding Standards** throughout (WPCS / PHPCS)
- All user-facing strings wrapped in `__()` / `esc_html_e()` etc. — fully i18n ready
- Text domain: `wp-markdown-for-agents`
- Minimum WordPress: 6.0, minimum PHP: 8.0
- No direct database queries — use the WordPress Options API and post meta API
- Proper capability checks (`manage_options` for settings, `edit_posts` for per-post actions)
- Nonces on all admin forms and AJAX actions
- All output escaped (`esc_html()`, `esc_url()`, `wp_kses()` as appropriate)
- GPL-2.0+ licence
- `uninstall.php` cleans up all options on uninstall
- `readme.txt` in WordPress.org format
- Composer-managed PHP dependencies (`composer.json`)
- The plugin must work with any post type — no assumptions about content structure

---

## Plugin metadata (main file header)

```
Plugin Name:       WP Markdown for Agents
Plugin URI:        https://github.com/dogwonder/wp-markdown-for-agents
Description:       Serve Markdown versions of your content to AI agents and LLMs via HTTP content negotiation, with built-in generation tooling.
Version:           1.0.0
Requires at least: 6.0
Requires PHP:      8.0
Author:            Rich Holman
Author URI:        https://dgw.ltd
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
Text Domain:       wp-markdown-for-agents
Domain Path:       /languages
```

---

## File structure

```
wp-markdown-for-agents/
├── wp-markdown-for-agents.php        # Bootstrap: constants, activation hooks, run()
├── uninstall.php                      # Delete all plugin options on uninstall
├── readme.txt                         # WordPress.org listing format
├── composer.json                      # PHP dependencies
├── phpunit.xml.dist                   # PHPUnit configuration
├── phpcs.xml.dist                     # PHPCS configuration
├── src/
│   ├── Core/
│   │   ├── Plugin.php                 # Main orchestrator: registers all hooks via Loader
│   │   ├── Loader.php                 # Hook/filter registration queue (from boilerplate)
│   │   ├── Activator.php             # Activation: create export dir, set default options
│   │   ├── Deactivator.php           # Deactivation: flush rewrite rules
│   │   └── Options.php               # Centralised option defaults and access
│   ├── Generator/
│   │   ├── Generator.php             # Orchestrates MD file generation for a post
│   │   ├── Converter.php             # HTML → Markdown (wraps league/html-to-markdown)
│   │   ├── FrontmatterBuilder.php    # Builds frontmatter array from WP_Post
│   │   ├── YamlFormatter.php         # Serialises array → YAML string
│   │   ├── TaxonomyCollector.php     # Extracts taxonomy terms for frontmatter
│   │   ├── ContentFilter.php         # Strips block comments, sanitises HTML
│   │   ├── FileWriter.php            # Writes .md files (WP_Filesystem / file_put_contents)
│   │   └── LlmsTxtGenerator.php      # Generates llms.txt index (optional)
│   ├── Negotiate/
│   │   └── Negotiator.php            # Content negotiation: serve MD, output <link> tag
│   ├── Admin/
│   │   ├── Admin.php                 # Enqueue admin assets, coordinate admin features
│   │   ├── SettingsPage.php          # Settings page (Settings API)
│   │   └── MetaBox.php               # Per-post meta box
│   └── CLI/
│       └── Commands.php               # WP-CLI commands
├── tests/
│   ├── bootstrap.php                  # PHPUnit bootstrap
│   ├── mocks/
│   │   └── wordpress-mocks.php        # WordPress function stubs
│   └── Unit/
│       ├── Core/
│       │   ├── PluginTest.php
│       │   ├── LoaderTest.php
│       │   ├── ActivatorTest.php
│       │   ├── DeactivatorTest.php
│       │   └── OptionsTest.php
│       ├── Generator/
│       │   ├── GeneratorTest.php
│       │   ├── ConverterTest.php
│       │   ├── FrontmatterBuilderTest.php
│       │   ├── YamlFormatterTest.php
│       │   ├── TaxonomyCollectorTest.php
│       │   ├── ContentFilterTest.php
│       │   ├── FileWriterTest.php
│       │   └── LlmsTxtGeneratorTest.php
│       ├── Negotiate/
│       │   └── NegotiatorTest.php
│       └── Admin/
│           ├── SettingsPageTest.php
│           └── MetaBoxTest.php
├── languages/
│   └── wp-markdown-for-agents.pot
└── vendor/                            # Composer autoload + league/html-to-markdown
```

---

## Composer dependencies

```json
{
    "name": "tclp/wp-markdown-for-agents",
    "description": "Serve Markdown content to AI agents via HTTP content negotiation",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.0",
        "league/html-to-markdown": "^5.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "squizlabs/php_codesniffer": "^3.7",
        "wp-coding-standards/wpcs": "^3.0",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Tclp\\WpMarkdownForAgents\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tclp\\WpMarkdownForAgents\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit",
        "test:unit": "phpunit --testsuite=unit",
        "test:coverage": "phpunit --coverage-html coverage",
        "phpcs": "phpcs",
        "phpcbf": "phpcbf"
    }
}
```

---

## Plugin options (stored via Options API)

All options under a single array key `wp_mfa_options` for clean uninstall.

```php
[
    'enabled'            => true,               // Master on/off switch
    'post_types'         => ['post', 'page'],   // Post types to enable for
    'export_dir'         => 'wp-mfa-exports',   // Subdirectory within wp-content/
    'auto_generate'      => false,              // Regenerate MD on post save
    'include_taxonomies' => true,               // Include taxonomy terms in frontmatter
    'include_meta'       => false,              // Include post meta in frontmatter
    'meta_keys'          => [],                 // Specific meta keys to include if above is true
    'frontmatter_format' => 'yaml',            // 'yaml' only for now, reserved for toml/json
]
```

Provide sensible defaults via `Options::get_defaults()` and
`get_option( 'wp_mfa_options', Options::get_defaults() )`.

---

## Class responsibilities

### `src/Core/Plugin.php` — Main orchestrator

Following the boilerplate pattern:

```php
public function __construct(private readonly string $version) {
    $this->loader = new Loader();
    $this->define_negotiate_hooks();
    $this->define_admin_hooks();
    $this->define_cli_commands();
}
```

- `define_negotiate_hooks()` — wire `Negotiator` to `template_redirect` and `wp_head`
- `define_admin_hooks()` — wire `Admin`, `SettingsPage`, `MetaBox`
- `define_cli_commands()` — register CLI commands if `WP_CLI` defined
- `run()` — call `$this->loader->run()`

### `src/Negotiate/Negotiator.php` — Content negotiation

**`maybe_serve_markdown()`** — hooked to `template_redirect` at priority 1

1. Check `is_singular()` for a configured post type — bail if not
2. Check `$_SERVER['HTTP_USER_AGENT']` is not a standard browser (optional guard)
3. Parse `$_SERVER['HTTP_ACCEPT']` — bail if `text/markdown` not present
4. Resolve the export file path: `{export_dir}/{post-type}/{post-slug}.md`
5. If file does not exist, return (WordPress renders normally — no 406, fail silently)
6. Send headers: `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`
7. `readfile( $filepath )` and `exit`

**`output_link_tag()`** — hooked to `wp_head` at priority 1

1. Check `is_singular()` for a configured post type
2. Resolve export file path — return if file does not exist
3. Output `<link rel="alternate" type="text/markdown" href="{canonical_url}">`
   (same URL — agents add the Accept header, they do not go to a different endpoint)

### `src/Generator/Generator.php` — File generation orchestrator

The central class that coordinates markdown generation. Delegates to:
- `FrontmatterBuilder` for metadata
- `ContentFilter` for HTML cleaning
- `Converter` for HTML → Markdown
- `YamlFormatter` for YAML serialisation
- `FileWriter` for disk I/O

**`generate_post( WP_Post $post ): bool`**

1. Validate post type is in configured list and post status is `publish`
2. Build frontmatter via `FrontmatterBuilder::build( $post )`
3. Get post content via `apply_filters( 'the_content', $post->post_content )`
4. Clean content via `ContentFilter::filter( $html )`
5. Convert via `Converter::convert( $html )`
6. Serialise frontmatter via `YamlFormatter::format( $frontmatter )`
7. Write file via `FileWriter::write( $path, $yaml . $markdown )`
8. Fire `do_action( 'wp_mfa_file_generated', $filepath, $post )`
9. Return `true` on success, `false` on failure

**`generate_post_type( string $post_type, callable $progress = null ): array`**

- Query all published posts of that type in batches (100 at a time, `offset` pagination
  — do NOT use `posts_per_page: -1` for large sites)
- Call `generate_post()` for each
- Return `['success' => int, 'failed' => int, 'skipped' => int]`

**`delete_post( int $post_id ): bool`** — removes the `.md` file for a post

**`get_export_path( WP_Post|int $post ): string`** — returns the full filesystem path
for a post's `.md` file: `{export_dir}/{post-type}/{post-slug}.md`

**`on_save_post( int $post_id, WP_Post $post )`** — hooked to `save_post` if
`auto_generate` option is enabled; calls `generate_post()` for publish, `delete_post()`
for trash/draft

### `src/Generator/Converter.php` — HTML to Markdown

Wraps `League\HTMLToMarkdown\HtmlConverter`.

**`convert( string $html ): string`**

1. Apply `wp_mfa_pre_convert` filter to the HTML
2. Run `HtmlConverter->convert( $html )`
3. Fix image spacing (extracted from wp-to-file `MarkdownProcessor`)
4. Run `html_entity_decode()` on the result
5. Apply `wp_mfa_post_convert` filter to the Markdown
6. Return

Converter options (ATX headers, `**bold**`, preserve line breaks) set in constructor.
Allow override via `wp_mfa_converter_options` filter.

Custom converters from wp-to-file included:
- `TableConverter` — proper Markdown table output
- `CodeBlockConverter` — fenced code blocks

### `src/Generator/FrontmatterBuilder.php` — Metadata assembly

**`build( WP_Post $post ): array`**

Builds the frontmatter array:
- Core fields: `title`, `date`, `modified`, `permalink` (canonical absolute URL),
  `type`, `status`, `excerpt`, `wpid`
- Taxonomies: via `TaxonomyCollector` if enabled
- Post meta: specified meta keys if enabled
- Media: featured image URL and alt text
- Apply `wp_mfa_frontmatter` filter

**No SSG-specific keys** — no `layout`, no `eleventyComputed`, no `.html` extensions.

### `src/Generator/YamlFormatter.php` — YAML serialisation

Extracted from wp-to-file's `YamlFormatting` trait as a standalone class.

- `format( array $data ): string` — returns `---\n...\n---\n\n`
- Handles nested arrays, lists, proper escaping/quoting
- ISO 8601 dates left unquoted
- Special characters properly escaped

### `src/Generator/TaxonomyCollector.php` — Taxonomy extraction

Extracted from wp-to-file's `TaxonomySupport` trait.

- `collect( int $post_id ): array` — returns taxonomy terms keyed by normalised name
- `post_tag` → `tags`, `category` → `categories`
- Term names as simple string arrays
- HTML entities decoded

### `src/Generator/ContentFilter.php` — Content cleaning

Adapted from wp-to-file's `ContentFilter`.

- Strip WordPress block comments (`<!-- wp:... -->`)
- Sanitise HTML (allowlist of structural tags)
- Apply post format-specific transformations
- **Does NOT normalise URLs to relative paths** (keeps canonical URLs)

### `src/Generator/FileWriter.php` — File I/O

- `write( string $filepath, string $content ): bool`
- `delete( string $filepath ): bool`
- `exists( string $filepath ): bool`
- Ensures export directory exists (`wp_mkdir_p()`)
- Creates `.htaccess` on first write
- Uses `WP_Filesystem` in admin context, `file_put_contents()` in CLI context
- Path validation: prevents traversal outside export base directory

### `src/Generator/LlmsTxtGenerator.php` — llms.txt index

Adapted from wp-to-file's `LLMsTxtGenerator`.

- `generate( string $export_dir ): string|false`
- Scans exported `.md` files, parses YAML frontmatter for title/excerpt
- Builds llms.txt per the llmstxt.org specification
- Optional — triggered via CLI `--with-llmstxt` or admin button

### `src/Admin/Admin.php` — Admin coordinator

Following boilerplate pattern:

```php
public function __construct(
    private readonly string $plugin_name,
    private readonly string $version
) {}
```

- Enqueue admin styles/scripts
- Coordinate `SettingsPage` and `MetaBox`

### `src/Admin/SettingsPage.php` — Settings

Registered under Settings menu via `admin_menu` hook.
Uses the WordPress Settings API (`register_setting`, `add_settings_section`,
`add_settings_field`).

Settings fields:
- Enable plugin (checkbox)
- Post types (checkboxes — populated from `get_post_types(['public' => true])`)
- Export directory name (text input, relative to wp-content, validated for path traversal)
- Auto-generate on save (checkbox)
- Include taxonomies in frontmatter (checkbox)
- Include post meta (checkbox + textarea for meta key list)

**Generate buttons** — one per enabled post type. Handled via `admin_post_wp_mfa_generate`,
protected by nonce and `manage_options` capability check.

### `src/Admin/MetaBox.php` — Per-post meta box

Registered for all enabled post types via `add_meta_boxes`.
Shows: whether a `.md` file exists, last modified time, and a "Regenerate" button
(POST action, nonce-protected, `edit_post` capability).

### `src/CLI/Commands.php` — WP-CLI

Following boilerplate CLI pattern. Register under `markdown-agents` parent command.

```
wp markdown-agents generate [--post-type=<type>] [--post-id=<id>] [--dry-run] [--force]
wp markdown-agents status
wp markdown-agents delete [--post-type=<type>] [--post-id=<id>] [--all]
```

**`generate`**
- `--post-type` — generate all published posts of this type
- `--post-id` — generate a single post
- `--with-llmstxt` — generate llms.txt after export
- `--dry-run` / `--force` — preview or force regeneration
- Progress bar via `WP_CLI\Utils\make_progress_bar`

**`status`**
- Per enabled post type: total published, generated, missing, stale

**`delete`**
- By post type, post ID, or `--all` (with `--yes` confirmation)

---

## Testing framework

### Philosophy

Following the dgwltd-boilerplate TDD pattern. The Generator classes are the heart of
this plugin — they contain the real logic. The Negotiate and Admin layers are thin
wrappers around WordPress APIs and are tested by verifying hook registration.

### Test infrastructure

```
tests/
├── bootstrap.php              # Loads Composer autoloader + WordPress mocks
├── mocks/
│   └── wordpress-mocks.php    # Minimal WP function stubs
└── Unit/
    ├── Core/                  # Verify hook wiring, option defaults
    ├── Generator/             # Deep unit tests — the core logic
    ├── Negotiate/             # Verify hook registration and header logic
    └── Admin/                 # Verify settings registration
```

**PHPUnit configuration** (`phpunit.xml.dist`):

```xml
<phpunit bootstrap="tests/bootstrap.php" colors="true" stopOnFailure="false">
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">./tests/Unit</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">./src</directory>
        </include>
    </coverage>
    <php>
        <const name="WP_MFA_TESTING" value="true"/>
    </php>
</phpunit>
```

### WordPress mocks

Following the boilerplate pattern — minimal stubs:

- `add_action()`, `add_filter()` → track hook calls in global
- `get_option()`, `update_option()` → in-memory option store
- `get_the_terms()` → return configurable test data
- `get_permalink()`, `home_url()` → return predictable URLs
- `wp_mkdir_p()`, `is_writable()` → filesystem stubs
- `is_singular()`, `get_queried_object()` → configurable returns
- `reset_mock_hooks()` / `get_mock_hooks()` — used in `setUp()`

### Test plan by class

| Class | Test focus | Mock needs | Priority |
|---|---|---|---|
| **YamlFormatter** | YAML output correctness: escaping, quoting, nested arrays, dates, booleans, special chars | Zero — pure PHP | P0 |
| **ContentFilter** | Block comment stripping, HTML sanitisation, allowed tag preservation | `strip_tags` only (built-in) | P0 |
| **Converter** | HTML → Markdown conversion, image spacing fix, entity decoding | `league/html-to-markdown` (real dep, not mocked) | P0 |
| **FrontmatterBuilder** | Correct field assembly, canonical permalink, taxonomy inclusion, meta inclusion | `get_permalink`, `home_url`, `get_post_meta`, `get_post_thumbnail_id`, `wp_get_attachment_url` | P1 |
| **TaxonomyCollector** | Term extraction, name normalisation, entity decoding | `get_the_terms` | P1 |
| **Generator** | Orchestration: calls builder → filter → converter → formatter → writer in order | All collaborators mockable via constructor injection | P1 |
| **FileWriter** | Path validation, directory creation, `.htaccess` creation, write/delete | Filesystem stubs | P1 |
| **LlmsTxtGenerator** | Output format, frontmatter parsing, truncation | Filesystem stubs | P2 |
| **Negotiator** | Accept header parsing, file existence check, header output | `$_SERVER` stubs, `is_singular`, `get_queried_object` | P2 |
| **Options** | Default values, option retrieval | `get_option` stub | P2 |
| **Plugin** | Hook registration (template_redirect, wp_head, admin hooks, CLI) | Boilerplate mock pattern | P2 |
| **SettingsPage** | Settings registration calls | `register_setting`, `add_settings_section` stubs | P3 |
| **MetaBox** | Meta box registration | `add_meta_box` stub | P3 |

### TDD execution order

Build from the inside out — pure logic first, then wiring:

1. **YamlFormatter** — zero dependencies, pure input→output
2. **ContentFilter** — near-zero dependencies
3. **Converter** — uses real `league/html-to-markdown`, tests actual conversion
4. **TaxonomyCollector** — single WP function mock
5. **FrontmatterBuilder** — assembles from the above
6. **FileWriter** — filesystem boundary
7. **Generator** — integration of all Generator classes
8. **LlmsTxtGenerator** — standalone utility
9. **Negotiator** — thin HTTP layer
10. **Options / Plugin / Admin** — wiring verification

### Constructor injection for testability

Generator classes accept their collaborators via constructor:

```php
class Generator {
    public function __construct(
        private readonly FrontmatterBuilder $frontmatter_builder,
        private readonly ContentFilter $content_filter,
        private readonly Converter $converter,
        private readonly YamlFormatter $yaml_formatter,
        private readonly FileWriter $file_writer,
    ) {}
}
```

This allows tests to inject mocks/stubs without touching global state.

---

## Export file format

```markdown
---
title: "My Post Title"
date: 2025-03-01T10:00:00Z
modified: 2025-10-15T14:23:00Z
permalink: https://example.com/posts/my-post/
type: post
status: publish
excerpt: "Optional excerpt if set."
wpid: 123
categories:
  - News
  - Sustainability
tags:
  - climate
  - legal
---

# My Post Title

[body content as Markdown]
```

- Dates in ISO 8601 UTC
- `permalink` is the canonical URL (no `.html` suffix, no SSG-specific formatting)
- Taxonomy terms as simple string arrays under their taxonomy name (not slug — use the
  registered taxonomy label as the key, lowercase, hyphens for spaces)
- No SSG-specific keys (`layout`, `eleventyComputed`, etc.)

---

## Filters and actions for extensibility

```php
// Modify the frontmatter array before serialisation
apply_filters( 'wp_mfa_frontmatter', array $frontmatter, WP_Post $post );

// Modify which taxonomy terms appear in frontmatter
apply_filters( 'wp_mfa_frontmatter_taxonomies', array $terms, WP_Post $post );

// Modify HTML before conversion to Markdown
apply_filters( 'wp_mfa_pre_convert', string $html, WP_Post $post );

// Modify Markdown after conversion
apply_filters( 'wp_mfa_post_convert', string $markdown, WP_Post $post );

// Override HtmlConverter options
apply_filters( 'wp_mfa_converter_options', array $options );

// Override the export directory path for a given post
apply_filters( 'wp_mfa_export_path', string $path, WP_Post $post );

// Fired after a file is successfully written
do_action( 'wp_mfa_file_generated', string $filepath, WP_Post $post );

// Fired after a file is deleted
do_action( 'wp_mfa_file_deleted', string $filepath, int $post_id );
```

---

## Security considerations

- Export directory protected by `.htaccess` (`Deny from all`) on Apache; plugin should
  also check that the directory is not publicly accessible and warn in admin if it is
- All file paths validated to stay within the export base directory (no path traversal)
- `readfile()` only called after path validation — never pass user input directly to it
- `$_SERVER['HTTP_ACCEPT']` treated as untrusted input — use `strpos()` check only,
  never eval or include based on it
- File writes use `WP_Filesystem` API (`WP_Filesystem()`, `$wp_filesystem->put_contents()`)
  rather than raw `file_put_contents()` for compatibility and security

---

## Key implementation notes

1. **Content negotiation is passive** — the plugin never redirects, never returns 404
   or 406. If no `.md` file exists, WordPress renders the page normally. This is
   intentional: the site degrades gracefully before files are generated.

2. **`Vary: Accept` is mandatory** — without it, a CDN or Cloudflare cache may serve
   the Markdown response to a regular browser. Always set this header when serving MD.

3. **The `<link rel="alternate">` tag** should only appear when a `.md` file actually
   exists — do not advertise availability for files that have not been generated yet.

4. **Batch generation** — never use `posts_per_page: -1`. Use paginated queries
   (offset + limit of 100) to avoid memory exhaustion on large sites.

5. **WP_Filesystem** — use `WP_Filesystem()` for file writes in admin context;
   `file_put_contents()` is acceptable in WP-CLI context where filesystem API is
   unavailable.

6. **Auto-generate on save** — debounce via a `_wp_mfa_generating` post meta flag to
   prevent recursive triggers. Hook to `save_post` not `wp_insert_post` to ensure all
   meta is saved first.

7. **Plugin should not load negotiate hooks on admin AJAX or REST requests** — check
   `wp_doing_ajax()` and `defined('REST_REQUEST')` before registering
   `template_redirect` hook.

8. **league/html-to-markdown version** — wp-to-file uses `^4.9`, this plugin targets
   `^5.1`. Verify API compatibility when extracting converter logic. The `HtmlConverter`
   constructor and `getConfig()`/`getEnvironment()` APIs may differ between major versions.

---

## What this plugin deliberately does NOT do

- Generate Markdown on-the-fly (files must be pre-generated; this is a deliberate
  performance choice)
- Provide a public URL endpoint for `.md` files (served via content negotiation on the
  canonical URL only)
- Parse or process YAML/ACF field group configuration (that is left to filters)
- Modify or depend on any specific page builder, ACF, or custom post type plugin
- Handle authentication or access control for the Markdown responses (inherits
  whatever access control applies to the HTML page)
- Include SSG-specific frontmatter keys (layout, eleventyComputed, etc.)

---

## Implementation order

Suggested build sequence, inside-out:

### Phase 1 — Generator core (TDD, P0)

1. Scaffold plugin from boilerplate (bootstrap, composer, phpunit, mocks)
2. `YamlFormatter` — TDD, pure PHP
3. `ContentFilter` — TDD, near-pure
4. `Converter` — TDD with real `league/html-to-markdown`
5. `TaxonomyCollector` — TDD with mock
6. `FrontmatterBuilder` — TDD, assembles the above
7. `FileWriter` — TDD with filesystem stubs
8. `Generator` — TDD integration, constructor injection

### Phase 2 — Serve and manage

9. `Options` — defaults and access
10. `Negotiator` — content negotiation hooks
11. `Admin` / `SettingsPage` / `MetaBox`
12. `Commands` — WP-CLI

### Phase 3 — Polish

13. `LlmsTxtGenerator`
14. `uninstall.php`
15. `readme.txt`
16. PHPCS pass

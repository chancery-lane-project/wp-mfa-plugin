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

## WordPress standards and constraints

- Follow the **WordPress Plugin Boilerplate** structure (https://wppb.io)
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
Plugin URI:        https://github.com/[tbd]
Description:       Serve Markdown versions of your content to AI agents and LLMs via HTTP content negotiation, with built-in generation tooling.
Version:           1.0.0
Requires at least: 6.0
Requires PHP:      8.0
Author:            [tbd]
Author URI:        [tbd]
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
Text Domain:       wp-markdown-for-agents
Domain Path:       /languages
```

---

## File structure

```
wp-markdown-for-agents/
├── wp-markdown-for-agents.php          # Bootstrap: constants, activation hooks, run()
├── uninstall.php                        # Delete all plugin options on uninstall
├── readme.txt                           # WordPress.org listing format
├── composer.json                        # PHP dependencies
├── composer.lock
├── includes/
│   ├── class-wp-mfa.php                 # Core class: loads dependencies, registers all hooks
│   ├── class-wp-mfa-loader.php          # Hook/filter registration queue
│   ├── class-wp-mfa-activator.php       # Activation: create export dir, set default options
│   ├── class-wp-mfa-deactivator.php     # Deactivation: flush rewrite rules
│   ├── class-wp-mfa-i18n.php            # load_plugin_textdomain
│   ├── class-wp-mfa-negotiate.php       # Content negotiation: serve MD, output <link> tag
│   ├── class-wp-mfa-generator.php       # Generate MD files from posts
│   └── class-wp-mfa-converter.php       # HTML → Markdown conversion (wraps league/html-to-markdown)
├── admin/
│   ├── class-wp-mfa-admin.php           # Settings page, post meta box, admin notices
│   └── partials/
│       ├── wp-mfa-admin-settings.php    # Settings page template
│       └── wp-mfa-admin-metabox.php     # Per-post meta box template
├── cli/
│   └── class-wp-mfa-cli.php             # WP-CLI commands
├── languages/
│   └── wp-markdown-for-agents.pot
└── vendor/                              # Composer autoload + league/html-to-markdown
```

---

## Composer dependencies

```json
{
    "require": {
        "php": ">=8.0",
        "league/html-to-markdown": "^5.1"
    },
    "autoload": {
        "psr-4": {
            "WPMarkdownForAgents\\": "includes/"
        }
    }
}
```

---

## Plugin options (stored via Options API)

All options under a single array key `wp_mfa_options` for clean uninstall.

```php
[
    'enabled'          => true,               // Master on/off switch
    'post_types'       => ['post', 'page'],   // Post types to enable for
    'export_dir'       => 'wp-mfa-exports',   // Subdirectory within wp-content/
    'auto_generate'    => false,              // Regenerate MD on post save
    'include_taxonomies' => true,             // Include taxonomy terms in frontmatter
    'include_meta'     => false,              // Include post meta in frontmatter
    'meta_keys'        => [],                 // Specific meta keys to include if above is true
    'frontmatter_format' => 'yaml',           // 'yaml' only for now, reserved for toml/json
]
```

Provide sensible defaults via `get_option( 'wp_mfa_options', wp_mfa_defaults() )`.

---

## Class responsibilities

### `class-wp-mfa.php` — Core class

- Defines constants: `WP_MFA_VERSION`, `WP_MFA_PLUGIN_DIR`, `WP_MFA_PLUGIN_URL`
- `load_dependencies()` — require_once all class files, initialise Composer autoloader
- `set_locale()` — instantiate i18n class
- `define_public_hooks()` — wire negotiate class hooks
- `define_admin_hooks()` — wire admin class hooks
- `define_cli_hooks()` — register WP-CLI commands if `WP_CLI` is defined
- `run()` — call `$this->loader->run()`

### `class-wp-mfa-negotiate.php` — Content negotiation

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

### `class-wp-mfa-generator.php` — File generation

**`generate_post( WP_Post $post ): bool`**

1. Validate post type is in configured list and post status is `publish`
2. Ensure export directory exists (`wp_mkdir_p()`); create `.htaccess` blocking direct
   web access on first run
3. Build frontmatter array:
   - Core fields: `title`, `date`, `modified`, `permalink` (canonical URL, no extension),
     `type`, `status`, `excerpt`, `wpid`
   - Taxonomies: loop configured post type's registered taxonomies, include term names
     as arrays — apply `wp_mfa_frontmatter_taxonomies` filter
   - Post meta: if enabled, include specified meta keys
   - Apply `wp_mfa_frontmatter` filter to allow hosts to add/remove fields
4. Get post content via `apply_filters( 'the_content', $post->post_content )`
5. Pass content through `WP_MFA_Converter::convert( $html )`
6. Serialise frontmatter as YAML
7. Write file: `{export_dir}/{post-type}/{post-slug}.md`
8. Return `true` on success, `false` on failure

**`generate_post_type( string $post_type, callable $progress = null ): array`**

- Query all published posts of that type in batches (100 at a time, `offset` pagination
  — do NOT use `posts_per_page: -1` for large sites)
- Call `generate_post()` for each
- Return `['success' => int, 'failed' => int, 'skipped' => int]`

**`delete_post( int $post_id ): bool`** — removes the `.md` file for a post

**`get_export_path( WP_Post|int $post ): string`** — returns the full filesystem path
for a post's `.md` file (used by both negotiate and generator)

**`on_save_post( int $post_id, WP_Post $post )`** — hooked to `save_post` if
`auto_generate` option is enabled; calls `generate_post()` for publish, `delete_post()`
for trash/draft

### `class-wp-mfa-converter.php` — HTML to Markdown

Thin wrapper around `League\HTMLToMarkdown\HtmlConverter`.

**`convert( string $html ): string`**

1. Strip WordPress block comments (`<!-- wp:... -->`)
2. Apply `wp_mfa_pre_convert` filter to the HTML
3. Run `HtmlConverter->convert( $html )`
4. Run `html_entity_decode()` on the result (catches `&amp;` etc. left by the converter)
5. Apply `wp_mfa_post_convert` filter to the Markdown
6. Return

Converter options (ATX headers, `**bold**`, preserve line breaks) set in constructor.
Allow override via `wp_mfa_converter_options` filter.

### `class-wp-mfa-admin.php` — Admin

**Settings page** registered under Settings menu via `admin_menu` hook.
Uses the WordPress Settings API (`register_setting`, `add_settings_section`,
`add_settings_field`) — do not build a custom form.

Settings fields:
- Enable plugin (checkbox)
- Post types (checkboxes — populated from `get_post_types(['public' => true])`)
- Export directory name (text input, relative to wp-content, validated to prevent
  path traversal)
- Auto-generate on save (checkbox)
- Include taxonomies in frontmatter (checkbox)
- Include post meta (checkbox + textarea for meta key list)

**Generate buttons** — below settings, one "Generate all" button per enabled post type.
Handled via admin-post.php action (`admin_post_wp_mfa_generate`), protected by nonce
and `manage_options` capability check. Uses `WP_Background_Process` pattern or, if not
available, processes synchronously with a time limit warning for large sites.

**Per-post meta box** — registered for all enabled post types via `add_meta_boxes`.
Shows: whether a `.md` file exists for this post, the file's last modified time, and a
"Regenerate" button (POST action, nonce-protected, `edit_post` capability).

**Admin notices** — transient-based notices for generation success/failure counts.

### `cli/class-wp-mfa-cli.php` — WP-CLI commands

Register under the `markdown-agents` parent command.

```
wp markdown-agents generate [--post-type=<type>] [--post-id=<id>] [--dry-run] [--force]
wp markdown-agents status
wp markdown-agents delete [--post-type=<type>] [--post-id=<id>] [--all]
```

**`generate`**
- `--post-type` — generate all published posts of this type
- `--post-id` — generate a single post
- If neither given, generate all enabled post types
- `--dry-run` — report what would be generated without writing files
- `--force` — regenerate even if `.md` file already exists and is newer than post
- Show a WP-CLI progress bar (`WP_CLI\Utils\make_progress_bar`)
- Report success/fail counts on completion

**`status`**
- For each enabled post type, show: total published posts, how many have `.md` files,
  how many are missing, how many are stale (post modified > file modified)

**`delete`**
- `--post-type` — delete all `.md` files for a post type
- `--post-id` — delete a single file
- `--all` — delete all generated files across all post types
- Require `--yes` confirmation for `--all`

### `class-wp-mfa-activator.php`

- Create the export base directory (`wp-content/{export_dir}/`) using `wp_mkdir_p()`
- Write `.htaccess` to deny direct web access
- Set default options if not already present (`add_option`, not `update_option`)

### `class-wp-mfa-deactivator.php`

- Flush rewrite rules

### `uninstall.php`

- `delete_option( 'wp_mfa_options' )`
- Optionally delete generated `.md` files (controlled by an option
  `delete_files_on_uninstall` defaulting to `false` — don't delete user data silently)

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

7. **Plugin should not load on admin AJAX or REST requests** — check
   `wp_doing_ajax()` and `defined('REST_REQUEST')` before registering
   `template_redirect` hook.

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

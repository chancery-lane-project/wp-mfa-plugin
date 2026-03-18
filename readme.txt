===  WP Markdown for Agents ===
Contributors: tclp
Tags: markdown, ai, llm, content negotiation, agents
Requires at least: 6.3
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve pre-generated Markdown files to AI agents via HTTP content negotiation.

== Description ==

WP Markdown for Agents converts your WordPress content to Markdown and serves it
to AI agents and language model tools that request it via HTTP content negotiation
(`Accept: text/markdown`).

**How it works:**

1. Posts are converted to Markdown and saved as static files on disk.
2. When a visitor (or AI agent) requests a post with `Accept: text/markdown` in
   the HTTP headers, WordPress serves the pre-generated `.md` file directly.
3. A `<link rel="alternate" type="text/markdown">` tag is added to each post's
   `<head>` so agents can discover Markdown versions automatically.
4. An `llms.txt` index file can be generated to help LLM tools navigate your site.

**Features:**

* Automatic Markdown generation on post save (configurable)
* Manual bulk generation via Settings page or WP-CLI
* Per-post-type field configuration — choose which meta/ACF fields go in frontmatter or body
* ACF support with dot notation for nested group fields (e.g. `group.subfield`)
* Content fields option — use ACF fields as the body content instead of post_content
* Content negotiation (`Vary: Accept` header, proper `Content-Type`)
* `llms.txt` index generation following the llmstxt.org specification
* Manifest generation with content hashes and change tracking per post type
* Incremental export — only re-export changed documents (`--incremental`)
* Delta file (`changes.json`) for RAG system sync
* WP-CLI commands: `wp markdown-agents generate`, `status`, `delete`
* Fully unit-tested

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wp-markdown-for-agents` directory,
   or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the Plugins screen in WordPress.
3. Configure the plugin via **Settings → Markdown for Agents**.

== Frequently Asked Questions ==

= Where are the Markdown files stored? =

By default in `wp-content/markdown-for-agents/`. This is configurable in the
plugin settings. The directory is protected from direct browser access via
`.htaccess`, but files are served by WordPress when content negotiation is triggered.

= Will this slow down my site? =

No. Markdown files are generated ahead of time (on post save or via manual/CLI
bulk generation). Serving them is a simple file read, much faster than rendering
a full WordPress page.

= What is the manifest.json file? =

When you generate with `--with-manifest` or `--incremental`, a `manifest.json` is
created inside each post-type export folder (e.g. `wp-mfa-exports/post/manifest.json`).
It contains a registry of all exported documents with content hashes and change
tracking (new/modified/unchanged/deleted), enabling RAG systems to identify what
changed since the last export without reprocessing all documents.

= How does incremental export work? =

Use `wp markdown-agents generate --incremental` to only re-export documents that
have changed since the last export. The plugin compares content hashes against the
previous manifest.json and skips unchanged posts. This also generates a
`changes.json` delta file listing new, modified, and deleted documents — your RAG
system can read this to know exactly what to re-embed.

= How do I configure fields per post type? =

In **Settings → Markdown for Agents**, each enabled post type has its own
"Field Configuration" section with two textareas:

* **Frontmatter fields** — meta or ACF fields added to the YAML frontmatter.
* **Content fields** — meta or ACF fields used as the body content. When set,
  `post_content` is automatically excluded.

Use dot notation for ACF group fields (e.g. `clause_fields.clause_summary`).
Plain meta keys work too (e.g. `_yoast_wpseo_title`). ACF relationship fields
are automatically converted to a list of post titles.

= Can I customise the Markdown output? =

Yes. Several filters are available:
* `wp_mfa_pre_convert` — filter HTML before conversion
* `wp_mfa_post_convert` — filter Markdown after conversion
* `wp_mfa_file_generated` — fired after a file is written
* `wp_mfa_file_deleted` — fired after a file is deleted

== Screenshots ==

1. Settings page with export options.
2. Post meta box showing file status and regenerate button.
3. WP-CLI status output.

== Changelog ==

= 1.1.0 =
* Per-post-type field configuration for frontmatter and content fields.
* ACF support with dot notation for nested group fields.
* Content fields option — use ACF/meta fields as body content instead of post_content.
* ACF relationship fields automatically normalised to post titles.
* Added manifest.json generation with content hashes and change tracking.
* New `--with-manifest` flag for `wp markdown-agents generate`.
* Manifest is generated per post-type folder for independent change tracking.
* Incremental export via `--incremental` — skips unchanged documents.
* Delta file (`changes.json`) generated for RAG system integration.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Per-post-type field configuration, ACF support, and manifest-based change tracking.

= 1.0.0 =
Initial release.

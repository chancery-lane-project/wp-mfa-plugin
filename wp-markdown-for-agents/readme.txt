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
* Configurable post types, export directory, and meta key export
* Content negotiation (`Vary: Accept` header, proper `Content-Type`)
* `llms.txt` index generation following the llmstxt.org specification
* Manifest generation with content hashes and change tracking per post type
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

When you generate with `--with-manifest`, a `manifest.json` is created inside each
post-type export folder (e.g. `wp-mfa-exports/post/manifest.json`). It contains a
registry of all exported documents with content hashes and change tracking
(new/modified/unchanged/deleted), enabling RAG systems to identify what changed
since the last export without reprocessing all documents.

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
* Added manifest.json generation with content hashes and change tracking.
* New `--with-manifest` flag for `wp markdown-agents generate`.
* Manifest is generated per post-type folder for independent change tracking.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
New manifest generation feature for RAG/AI pipeline change tracking.

= 1.0.0 =
Initial release.

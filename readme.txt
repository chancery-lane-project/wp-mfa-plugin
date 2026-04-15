===  Markdown for Agents and Statistics ===
Contributors: chancerylaneproject
Tags: markdown, ai, llm, content negotiation, agents
Requires at least: 6.3
Tested up to: 6.9
Stable tag: 1.3.0
Requires PHP: 8.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Serve pre-generated Markdown files to AI agents via HTTP content negotiation.

== Description ==

Markdown for Agents and Statistics converts your WordPress content to Markdown and serves it
to AI agents and language model tools that request it via HTTP content negotiation
(`Accept: text/markdown`).

**How it works:**

1. Posts and taxonomy archive pages are converted to Markdown and saved as static
   files on disk inside `wp-content/uploads/`.
2. When a visitor (or AI agent) requests a page with `Accept: text/markdown` in
   the HTTP headers, WordPress serves the pre-generated `.md` file directly —
   no page render required.
3. A `<link rel="alternate" type="text/markdown">` tag is added to each page's
   `<head>` so agents can discover Markdown versions automatically.
4. An `llms.txt` index file can be generated to help LLM tools navigate your site.

**Features:**

* Content negotiation (`Accept: text/markdown`, `?output_format=md`, or known AI User-Agents)
* **Taxonomy archive support** — category, tag, and custom taxonomy term pages served as Markdown post listings
* Automatic Markdown generation on post save; taxonomy archives auto-update when any post in the term changes
* AJAX bulk generation with live progress counter — no page timeouts on large sites
* Per-post-type field configuration — choose which meta/ACF fields go in frontmatter or body
* ACF support with dot notation for nested group fields (e.g. `group.subfield`)
* Content fields option — use ACF fields as the body content instead of post_content
* `llms.txt` index generation following the llmstxt.org specification
* Manifest generation with content hashes and change tracking per post type
* Incremental export — only re-export changed documents (`--incremental`)
* Delta file (`changes.json`) for RAG system sync
* Access statistics — logs AI agent requests with a dedicated stats admin page
* **Optional frontmatter fields** — hierarchy (parent/ancestors/children IDs), author display name, root-relative featured image paths
* **Topics section** — appends a `## Topics` section with linked taxonomy terms to the Markdown body
* **Export preview** — preview generated Markdown inline in the post editor without writing to disk
* WP-CLI commands: `generate`, `generate-taxonomies`, `prune-stats`, `status`, `delete`
* Fully unit-tested

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/markdown-for-agents` directory,
   or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the Plugins screen in WordPress.
3. Configure the plugin via **Settings → Markdown for Agents**.

== Frequently Asked Questions ==

= Where are the Markdown files stored? =

Inside `wp-content/uploads/{export_dir}/` (configurable in Settings). Post files
live under `{export_dir}/{post-type}/{slug}.md`. Taxonomy archive files live under
`{export_dir}/taxonomy/{taxonomy}/{term-slug}.md`. The directory is served by
WordPress when content negotiation is triggered.

= Will this slow down my site? =

No. Markdown files are generated ahead of time (on post save or via manual/CLI
bulk generation). Serving them is a simple file read, much faster than rendering
a full WordPress page.

= What are taxonomy archive files? =

For every public taxonomy term (categories, tags, custom taxonomies) the plugin
generates a Markdown file listing all published posts in that term with links and
excerpts. These are served automatically when an AI agent requests a taxonomy
archive URL. This lets agents navigate your site structure by exploring term listings,
not just individual posts.

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

* `markdown_for_agents_pre_convert` — filter HTML before conversion
* `markdown_for_agents_post_convert` — filter Markdown after conversion
* `markdown_for_agents_frontmatter` — modify frontmatter fields for a post
* `markdown_for_agents_taxonomy_frontmatter` — modify frontmatter fields for a taxonomy archive
* `markdown_for_agents_serve_enabled` — enable/disable serving for a specific post
* `markdown_for_agents_serve_taxonomies` — enable/disable serving for taxonomy archive pages
* `markdown_for_agents_file_generated` — action fired after a file is written
* `markdown_for_agents_file_deleted` — action fired after a file is deleted

= How do I generate taxonomy archives via WP-CLI? =

```
wp markdown-agents generate-taxonomies
wp markdown-agents generate-taxonomies --taxonomy=category
wp markdown-agents generate-taxonomies --dry-run
```

== Screenshots ==

1. Settings page with export options and bulk generation.
2. Post meta box showing file status, regenerate button, and inline Markdown preview.
3. WP-CLI status output.

== Changelog ==

= 1.3.0 =
* Optional hierarchy frontmatter fields (`parent`, `ancestors`, `children` IDs) for hierarchical post types (pages, etc.).
* Optional author display name in frontmatter.
* Optional root-relative paths for featured images (survives domain migrations).
* Optional `## Topics` section appended to the Markdown body with linked taxonomy terms.
* Export preview — "Preview Markdown" button in the post meta box renders generated Markdown inline without writing to disk.
* New WP-CLI command: `wp markdown-agents prune-stats [--days=<n>] [--yes]` — removes access stats older than N days.
* Manifest hash now covers taxonomy term slugs — incremental export correctly detects posts whose terms changed.

= 1.2.0 =
* Taxonomy archive support — generates Markdown index files for all public taxonomy terms (categories, tags, custom taxonomies), served via content negotiation.
* Taxonomy archives auto-regenerate when any post in the term is saved or deleted.
* AJAX bulk generation for taxonomy archives on the Settings page with live progress counter.
* New WP-CLI command: `wp markdown-agents generate-taxonomies [--taxonomy=<slug>] [--dry-run]`.
* `<link rel="alternate" type="text/markdown">` tag now emitted on taxonomy archive pages.
* New filter: `markdown_for_agents_serve_taxonomies` to enable/disable taxonomy archive serving globally.
* New filter: `markdown_for_agents_taxonomy_frontmatter` to modify taxonomy archive frontmatter before serialisation.
* Bulk generation buttons converted to AJAX with live counter — no more page timeouts on large sites.

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
* Access statistics — logs AI agent requests; dedicated stats admin page.
* UA detection — configurable User-Agent strings force Markdown serving.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
New optional frontmatter fields (hierarchy, author, relative image paths), a Topics body section, inline Markdown preview, and the prune-stats WP-CLI command. All features are opt-in via Settings. No breaking changes or database migrations required.

= 1.2.0 =
Adds taxonomy archive support and AJAX bulk generation. No breaking changes. Taxonomy archive files will be generated on the next post save or via Settings → Generate All Taxonomy Archives.

= 1.1.0 =
Per-post-type field configuration, ACF support, and manifest-based change tracking.

= 1.0.0 =
Initial release.

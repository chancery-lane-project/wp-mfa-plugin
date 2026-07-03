===  Markdown for Agents and Statistics ===
Contributors: chancerylaneproject
Tags: markdown, ai, llm, content negotiation, agents
Requires at least: 6.3
Tested up to: 7.0
Stable tag: 1.6.0
Requires PHP: 8.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Serve pre-generated Markdown files to AI agents via HTTP content negotiation.

== Description ==

Markdown for Agents and Statistics converts your WordPress content to Markdown and serves it
to AI agents and language model tools that request it via HTTP content negotiation
(`Accept: text/markdown`).

The Chancery Lane Project is a charity that helps organisations reduce emissions using the power of legal documents and processes. We've published this plugin as we believe that making content more legible for AI Agents makes a meaningful difference to their energy usage - not only by reducing the amount of tokens required (by up to 90% over HTML) to consume the content, but also minimising the server resources required to render, process and display pages at source.

**How it works:**

1. Posts and taxonomy archive pages are converted to Markdown and saved as static
   files on disk inside `wp-content/uploads/`.
2. When a visitor (or AI agent) requests a page with `Accept: text/markdown` in
   the HTTP headers, WordPress serves the pre-generated `.md` file directly —
   no page render required.
3. A `<link rel="alternate" type="text/markdown">` tag is added to each page's
   `<head>` so agents can discover Markdown versions automatically.

**Features:**

* Content negotiation (`Accept: text/markdown`, `?output_format=md`, or known AI User-Agents)
* **Taxonomy archive support** — category, tag, and custom taxonomy term pages served as Markdown post listings
* Automatic Markdown generation on post save; taxonomy archives auto-update when any post in the term changes
* AJAX bulk generation with live progress counter — no page timeouts on large sites
* Per-post-type field configuration — choose which meta/ACF fields go in frontmatter or body
* ACF support with dot notation for nested group fields (e.g. `group.subfield`)
* Content fields option — use ACF fields as the body content instead of post_content
* Manifest generation with content hashes and change tracking per post type
* Incremental export — only re-export changed documents (`--incremental`)
* Delta file (`changes.json`) for RAG system sync
* Access statistics — logs AI agent requests with a dedicated stats admin page
* Access grouping by class of agent
* **Optional frontmatter fields** — hierarchy (parent/ancestors/children IDs), author display name, root-relative featured image paths
* **Topics section** — appends a `## Topics` section with linked taxonomy terms to the Markdown body
* **Export preview** — preview generated Markdown inline in the post editor without writing to disk
* **OKF directory indexes** — `index.md` listings at the export root and in every post-type and taxonomy directory (Open Knowledge Format), kept current automatically
* **OKF compatibility mode** — optional toggle adding `timestamp` and flat cross-taxonomy `tags` frontmatter keys, and rewriting internal links to point at the Markdown file versions
* WP-CLI commands: `generate`, `generate-taxonomies`, `generate-indexes`, `prune-stats`, `status`, `delete`
* Fully unit-tested

== Installation ==

1. Upload the plugin to `/wp-content/plugins/markdown-for-agents/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Visit **Settings → Markdown for Agents** and choose which post types and taxonomies to generate.
4. Enable **Auto-generate on save** so files stay in sync as you publish or edit content (optional).
5. Click **Generate All** to create Markdown for your existing content. On large sites you can also run `wp markdown-agents generate` and `wp markdown-agents generate-taxonomies` from WP-CLI.
6. Verify by appending `?output_format=md` to any post URL (or using an AI User-Agent) to confirm Markdown is served.

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

= AI agents are getting HTML instead of Markdown. Why? =

Almost always this is a CDN, firewall, or page cache sitting in front of
WordPress — not the plugin. On many hosts (for example Cloudflare in front of WP
Engine) the edge answers a request before it ever reaches the plugin: a full-page
cache can return the cached HTML, or a bot/WAF rule can block a known AI crawler
with a 403/429.

The reliable route is the query parameter: append `?output_format=md` to any post
or archive URL. Because that is a distinct URL, caches store it separately and
firewalls treat it as an ordinary request, so it reaches the plugin even on a
hardened stack. The plugin advertises this URL automatically via a
`<link rel="alternate" type="text/markdown">` tag in each page's `<head>`, so
agents that read the page can discover and follow it.

The `Accept: text/markdown` header and User-Agent routes also work, but only if
your CDN/cache is configured to let them through (see the next question).

= How do I let my CDN or cache serve Markdown to agents? =

This is host/CDN configuration, not a plugin setting. Two changes help:

* **Page cache (WP Engine, LiteSpeed, Varnish, nginx):** exclude agent-shaped
  requests from the full-page cache — any request whose `Accept` header contains
  `text/markdown`, whose query string contains `output_format=md`, or whose
  User-Agent is a known AI bot. Do **not** add User-Agent to the cache *key*; that
  fragments the cache for every visitor. Exclude from caching, do not key on it.
* **Firewall / bot rules (Cloudflare):** add a skip/allow rule for the AI
  User-Agents you want to serve (for example GPTBot, ClaudeBot, PerplexityBot,
  Google-Extended). Otherwise they receive a 403/429 and get nothing.

If you skip this, nothing breaks — agents simply use the `?output_format=md` URL
via discovery instead. The plugin already protects against the reverse problem:
Markdown responses are sent with `Cache-Control: private, no-store` and
`Vary: Accept, User-Agent`, so a shared cache cannot replay the Markdown to a
human browser on the same URL.

= How can I check what an agent actually receives? =

Request a page the way an agent would and inspect the response headers:

```
# Query-param route (the reliable one)
curl -sI 'https://example.com/your-post/?output_format=md'

# Accept-header route
curl -sI -H 'Accept: text/markdown' 'https://example.com/your-post/'
```

A genuine Markdown response from the plugin has `Content-Type: text/markdown` and
an `X-Markdown-Source: markdown-for-agents` header. If you instead see
`Content-Type: text/html`, the request was answered by a cache or firewall before
reaching the plugin (see the previous questions). Note that running these from
your own server may bypass your CDN; testing from an external network shows what
real agents experience.

= Should I publish an llms.txt file? =

`llms.txt` is a proposed convention for a single Markdown index of your site at
`https://example.com/llms.txt`, aimed at AI tools that look for a site-level
manifest. It is an emerging community convention, not an official standard, and
there is limited evidence that the major AI crawlers consume it yet — so treat it
as low-cost, optional, and complementary to the per-page discovery this plugin
already provides.

This plugin does not generate `llms.txt`. If you want one, publish a static file at your web root listing your
key pages with their `?output_format=md` URLs, and keep it in sync with published
and retired content or it will point agents at missing pages.

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
* `markdown_for_agents_cache_headers` — override the cache-related headers sent with the Markdown response
* `markdown_for_agents_file_generated` — action fired after a file is written
* `markdown_for_agents_file_deleted` — action fired after a file is deleted

= Can I let CDNs/full-page caches cache the Markdown responses? =

By default the Markdown response is sent with `Cache-Control: private, no-store, max-age=0` (plus `X-LiteSpeed-Cache-Control`, `X-Accel-Expires` and `Vary: Accept, User-Agent`). This is deliberate: the Markdown is negotiated on the *same URL* as the HTML page, so a shared cache that ignores or normalises `Vary` could otherwise store the Markdown variant and replay it to ordinary browsers expecting HTML.

If your CDN/cache layer honours `Vary` correctly (or you serve Markdown from distinct URLs), you can relax this with the `markdown_for_agents_cache_headers` filter. Map any header to an empty string to omit it entirely:

```
add_filter( 'markdown_for_agents_cache_headers', function ( array $headers, string $filepath ) {
	$headers['Cache-Control']             = 'public, max-age=300';
	$headers['X-LiteSpeed-Cache-Control'] = '';
	$headers['X-Accel-Expires']           = '';
	return $headers;
}, 10, 2 );
```

This filter governs only the cache-related headers listed above. The `Content-Signal` and `X-Markdown-Source` headers are sent separately and are unaffected (`Content-Signal` has its own `markdown_for_agents_content_signal` filter).

Override with caution — incorrectly cached Markdown will be served to browsers.

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

= 1.6.0 =
* Add OKF (Open Knowledge Format) directory indexes: `index.md` listings generated at the export root and in every post-type and taxonomy directory, regenerated automatically as content changes. New `wp markdown-agents generate-indexes` command (with `--dry-run`); `status` and `delete --all` are index-aware.
* Add optional OKF compatibility mode (Settings → Markdown for Agents): adds `timestamp` and flat cross-taxonomy `tags` frontmatter keys, and rewrites internal links in Markdown bodies and taxonomy archives to point at the `.md` file versions. Off by default — existing output is unchanged unless enabled.
* New filters `markdown_for_agents_flat_tags` and `markdown_for_agents_index_content`; new actions `markdown_for_agents_taxonomy_file_generated` and `markdown_for_agents_taxonomy_file_deleted`.

= 1.5.1 =
* Add `markdown_for_agents_cache_headers` filter so the cache-related headers on Markdown responses can be customised (e.g. to allow CDN caching where `Vary` is honoured). Defaults are unchanged and remain cache-bypassing.

= 1.5.0 =
* Add new 'skipped' grouping on generating MD files to show those that have been skipped for good reason (password or draft etc) rather than failed.
* Add new 'Agent Class' graph display on Agent Stats page which mimics Known Agents classifications to help understand traffic patterns
* Better documentation for caching and generation logic

= 1.4.5 =
* Fix: Issues where memcache could cause problems on CLI invoked rebuilds on large sites. Also resolves minor issues with <script> and <style> outputs generated by post filters appearing in MD output, while allowing for same in <code> blocks where needed.

= 1.4.4 =
* Fix: full-page caches (LiteSpeed, Varnish, nginx fastcgi_cache) could store the Markdown response under a page URL when an AI agent or `?output_format=md` request hit it first, then replay the `.md` body to subsequent HTML browser requests. Markdown responses now send `Cache-Control: private, no-store`, `X-LiteSpeed-Cache-Control: no-cache`, `X-Accel-Expires: 0`, and `Vary: Accept, User-Agent` unconditionally.

= 1.4.3 =
* Update to fix deleting posts on status change outside of auto-update flow

= 1.4.2 =
* Fixed issue with private/draft posts being created as MD files and added checkbox to post edit pages to exclude posts from MD generation. Also fixes small issue in unusual taxonomy slugs prodducing incorrect URLs in Topics secion of MD body. Adds Strauss namespacing to html-to-markdown/Composer includes to avoid collisions.

= 1.4.1 =
* Removed `llms.txt` index generation. The `LlmsTxtGenerator` class, its `--with-llmstxt` WP-CLI flag on `wp markdown-agents generate`, and the corresponding unit tests have been dropped.

= 1.4.0 =
* Add notices and copy around generating and regenerating content on install and updates to Settings
* Add transient to store and note when content needs regenerating

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

= 1.4.4 =
Fixes a cache-poisoning issue where full-page caches (e.g. LiteSpeed) could serve the Markdown variant to regular browsers. Recommended upgrade if you run any reverse-proxy or page cache. Purge your cache after upgrading.

= 1.4.3 =
* Update to fix deleting posts on status change outside of auto-update flow

= 1.4.2 =
* Fixed issue with private/draft posts being created as MD files and added checkbox to post edit pages to exclude posts from MD generation. Also fixes small issue in unusual taxonomy slugs prodducing incorrect URLs in Topics secion of MD body. Adds Strauss namespacing to html-to-markdown/Composer includes to avoid collisions.

= 1.4.1 =
Removes `llms.txt` index generation, including the `--with-llmstxt` WP-CLI flag. If you relied on this output, stay on 1.3.x or generate `llms.txt` externally.

= 1.3.0 =
New optional frontmatter fields (hierarchy, author, relative image paths), a Topics body section, inline Markdown preview, and the prune-stats WP-CLI command. All features are opt-in via Settings. No breaking changes or database migrations required.

= 1.2.0 =
Adds taxonomy archive support and AJAX bulk generation. No breaking changes. Taxonomy archive files will be generated on the next post save or via Settings → Generate All Taxonomy Archives.

= 1.1.0 =
Per-post-type field configuration, ACF support, and manifest-based change tracking.

= 1.0.0 =
Initial release.

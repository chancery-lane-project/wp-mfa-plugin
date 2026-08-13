===  Markdown for Agents and Statistics ===
Contributors: chancerylaneproject
Tags: markdown, ai, llm, content negotiation, agents
Requires at least: 6.3
Tested up to: 7.0
Stable tag: 1.7.0
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
* Background bulk generation — runs as a WP-Cron job with live progress, so it survives closing the tab and never times out a page request
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
* **OKF-compliant frontmatter and links** — `timestamp` and flat cross-taxonomy `tags` frontmatter keys, and internal links rewritten to point at the Markdown file versions, always on
* **Downloadable OKF bundle** — optional `.zip` archive of the export tree with relative internal links, `manifest.json`, and an ARD discovery catalog panel, kept fresh via bulk-generation rebuilds and a debounced WP-Cron schedule
* **ARD catalog generation** — `ai-catalog.json` document for manual deployment to `/.well-known/`, discoverable by AI agent directories, shown automatically whenever the bundle toggle is on
* WP-CLI commands: `generate`, `generate-taxonomies`, `generate-indexes`, `prune-stats`, `status`, `delete`, `bundle`
* Fully unit-tested

== Installation ==

1. Upload the plugin to `/wp-content/plugins/markdown-for-agents/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Visit **Settings → Markdown for Agents** and choose which post types and taxonomies to generate.
4. Enable **Auto-generate on save** so files stay in sync as you publish or edit content (optional).
5. Click **Generate everything** to create Markdown for your existing content. On large sites you can also run `wp markdown-agents generate` and `wp markdown-agents generate-taxonomies` from WP-CLI.
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

= Does bulk generation need anything special on my host? =

Bulk generation (the "Generate everything" and per-post-type/taxonomy buttons)
runs in the background via WP-Cron, so WP-Cron needs to be working on the site.
If you have set `DISABLE_WP_CRON` and use a system cron to call `wp-cron.php`
instead, that cron must run as the same user as your web server, or the files
it writes will fail permission checks.

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

A `manifest.json` is created inside each post-type export folder (e.g.
`wp-mfa-exports/post/manifest.json`) whenever the downloadable bundle toggle is
on (it's refreshed automatically before every bundle rebuild), or on demand via
`--with-manifest` or `--incremental`. It contains a registry of all exported
documents with content hashes and change tracking (new/modified/unchanged/deleted),
enabling RAG systems to identify what changed since the last export without
reprocessing all documents.

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
* `markdown_for_agents_cache_headers` — override the cache-related headers sent with the Markdown response (receives the access method since 1.6.1)
* `markdown_for_agents_html_headers` — modify or omit the `Link` and `Vary: Accept` headers added to HTML responses that have a Markdown alternate
* `markdown_for_agents_file_generated` — action fired after a file is written
* `markdown_for_agents_file_deleted` — action fired after a file is deleted

= Can I let CDNs/full-page caches cache the Markdown responses? =

By default the Markdown response is sent with `Cache-Control: private, no-store, max-age=0` (plus `X-LiteSpeed-Cache-Control`, `X-Accel-Expires` and `Vary: Accept, User-Agent`). This is deliberate: the Markdown is negotiated on the *same URL* as the HTML page, so a shared cache that ignores or normalises `Vary` could otherwise store the Markdown variant and replay it to ordinary browsers expecting HTML.

The safe way to relax this is per access method, which the `markdown_for_agents_cache_headers` filter receives as its third argument (since 1.6.1). Requests via `?output_format=md` are on their own URL — and therefore their own cache key — so they can be cached publicly with no risk of variant confusion. Requests negotiated via the `Accept` header or detected by User-Agent share the page URL with the HTML and should stay private unless you are certain every cache layer in front of the site keys on `Accept`. Map any header to an empty string to omit it entirely:

```
add_filter( 'markdown_for_agents_cache_headers', function ( array $headers, string $filepath, string $access_method ) {
	// Safe: ?output_format=md is a distinct URL with its own cache key.
	if ( 'query-param' === $access_method ) {
		$headers['Cache-Control']             = 'public, max-age=300';
		$headers['X-LiteSpeed-Cache-Control'] = '';
		$headers['X-Accel-Expires']           = '';
	}
	// 'accept-header' and 'ua' responses share the HTML page's URL — leave
	// them private: a cache that ignores `Vary` (many do, including some
	// managed WordPress hosts and CDNs) would replay Markdown to browsers.
	return $headers;
}, 10, 3 );
```

The default values on `$filepath` and `$access_method` are deliberate: plugin versions before 1.6.1 pass fewer arguments to this filter, and required parameters would fatal every Markdown response with an `ArgumentCountError` if the snippet outlives a plugin downgrade (or is deployed ahead of the upgrade). With the defaults it is a safe no-op on older versions and activates automatically on 1.6.1+.

This filter governs only the cache-related headers listed above. The `Content-Signal` and `X-Markdown-Source` headers are sent separately and are unaffected (`Content-Signal` has its own `markdown_for_agents_content_signal` filter).

One caveat on caching the query-param URL: if a cache in front of the site is configured to *ignore query strings* when building cache keys, `?output_format=md` collapses onto the page URL's key and a public Markdown response could be served to browsers. The default therefore stays private; opt in only when you know your cache keys include the query string.

= Why do HTML pages send `Vary: Accept` and a `Link` header? =

Since 1.6.1, pages that have a Markdown alternate send two extra headers with the HTML response: `Link: <…?output_format=md>; rel="alternate"; type="text/markdown"` (protocol-level discovery, visible to HEAD requests and clients that do not parse HTML) and `Vary: Accept` (tells spec-correct shared caches not to replay a stored HTML variant to a client asking for `text/markdown` on the same URL — without it, a primed page cache answers agents with HTML before WordPress runs).

Both can be modified or removed with the `markdown_for_agents_html_headers` filter (map a header to an empty string to omit it). You may want to omit `Vary: Accept` if your cache layer refuses to store responses whose `Vary` lists anything beyond `Accept-Encoding`, as full-page caching then stops working entirely; in that case rely on the query-param URL for agent access instead.

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

= 1.7.0 =
* Bulk generation now runs as a background WP-Cron job: starting a run returns immediately, and it keeps going after you close the tab or the page loses contact with the server.
* Fixed a bug where a run containing posts skipped for good reason (password-protected, draft, excluded from export) could run forever instead of finishing.
* Bulk generation no longer slows down the further it progresses through a large site.
* Generating taxonomy archives no longer loads every term of every public taxonomy into memory before starting, and the export bundle (`.zip`) is rebuilt in its own step rather than tacked onto whichever request happens to finish last.
* A run that is interrupted (e.g. by a server restart) now resumes automatically, without needing to be started again by hand.
* Progress now shows skipped counts separately from errors, and starting a second run while one is already in progress shows that run's live progress instead of an error.
* Added the `markdown_for_agents_tick_budget` filter, for site owners who want to tune how many seconds each background tick may spend.

= 1.6.1 =
* Harden content negotiation against full-page caches that ignore `Vary` (observed on managed WordPress hosting: a cached HTML variant is served at the edge before WordPress runs, so `Accept: text/markdown` requests receive HTML). HTML responses for pages with a Markdown alternate now send `Vary: Accept` plus an HTTP `Link: <…>; rel="alternate"; type="text/markdown"` header, so HEAD-only clients and non-HTML-parsing agents can discover the query-param URL, which is immune to cache-variant confusion. New `markdown_for_agents_html_headers` filter to modify or omit either header.
* The `markdown_for_agents_cache_headers` filter now receives the access method (`query-param`, `accept-header` or `ua`) as a third argument, so cache policy can be relaxed only for query-param requests (a distinct URL with its own cache key) while same-URL negotiated responses stay uncacheable. Defaults are unchanged.
* Performance and scaling fixes: bundle builds on large exports no longer risk timing out (the `.zip` writer now scales linearly with file count instead of quadratically), and internal links written against a post's previous slug are resolved correctly again.
* Bulk generation errors are now surfaced in the admin UI, with per-item detail (post/term and reason) rather than a silent failure or bare count.
* Add a "Settings" link to the plugin's entry on the Plugins list page.

= 1.6.0 =
* Add OKF (Open Knowledge Format) directory indexes: `index.md` listings generated at the export root and in every post-type and taxonomy directory, regenerated automatically as content changes. New `wp markdown-agents generate-indexes` command (with `--dry-run`); `status` and `delete --all` are index-aware.
* Add OKF-compliant frontmatter: `timestamp` and flat cross-taxonomy `tags` frontmatter keys, and internal links in Markdown bodies and taxonomy archives rewritten to point at the `.md` file versions. Always on.
* New filters `markdown_for_agents_flat_tags` and `markdown_for_agents_index_content`; new actions `markdown_for_agents_taxonomy_file_generated` and `markdown_for_agents_taxonomy_file_deleted`.
* Add optional downloadable OKF bundle (Settings → Markdown for Agents → "Build downloadable bundle (.zip + manifest)"): packages the export tree into a `.zip` archive with internal links rewritten to relative form and a freshly regenerated `manifest.json`, so an extracted bundle is traversable offline and change-trackable. Also displays an ARD discovery catalog (`ai-catalog.json`) for manual deployment to `/.well-known/ai-catalog.json` — the plugin never serves this path itself. Rebuilt synchronously after bulk generation and on a debounced WP-Cron schedule after individual saves; also available via `wp markdown-agents bundle`. Off by default. New filter `markdown_for_agents_ai_catalog`.
* Fix: uninstall now removes the actual `.zip` bundle (it previously targeted a stale `.tar.gz` path), and also cleans up the stats table, DB-version option, pending-regeneration transient and scheduled bundle rebuild.
* Fix: table `<caption>` text is no longer dropped from converted Markdown tables, and Gutenberg code-block extraction no longer raises a deprecation notice on PHP 8.5.
* Internal clean-up: single coordinator for manifest-then-bundle rebuilds across CLI, admin and cron; removed dead code left from earlier designs (including the unused `frontmatter_format` option); expanded test coverage for table/code-block conversion.

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

# Markdown for Agents and Statistics

> Serve pre-generated Markdown files to AI agents via HTTP content negotiation.

[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://www.php.net/)
[![WordPress 6.3+](https://img.shields.io/badge/WordPress-6.3%2B-blue)](https://wordpress.org/)
[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

A WordPress plugin for [The Chancery Lane Project](https://chancerylane.uk) that converts post content to Markdown and serves it to AI agents and LLM tools via standard HTTP content negotiation.

---

## How it works

1. Posts and taxonomy archive pages are converted to Markdown and saved as static files on disk inside `wp-content/uploads/`.
2. When a request arrives with `Accept: text/markdown`, the `?output_format=md` query parameter, or a known AI User-Agent, WordPress serves the pre-generated `.md` file directly - no page render required.
3. A `<link rel="alternate" type="text/markdown">` tag is injected into each page's `<head>` so agents can discover Markdown versions automatically.

---

## Features

- **Content negotiation** - serves Markdown on `Accept: text/markdown`, `?output_format=md`, or known AI User-Agent strings
- **Taxonomy archive support** - category, tag, and custom taxonomy archives served as Markdown post listings
- **Auto-generation** - files regenerated on post save; taxonomy archives regenerated when any post in the term changes
- **Bulk generation** - generate all files via the admin settings page (AJAX with live progress counter) or WP-CLI
- **Per-post-type field configuration** - choose which meta/ACF fields appear in frontmatter or body
- **ACF support** - dot-notation for nested group fields (e.g. `group.subfield`); relationship fields normalised to post titles
- **Manifest + incremental export** - content-hash manifest, refreshed automatically before every bundle rebuild or on demand via `--with-manifest`/`--incremental`; `changes.json` delta for RAG sync
- **OKF directory indexes** - `index.md` listings at the export root and in every post-type and taxonomy directory ([Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog) §6), kept current automatically
- **OKF-compliant frontmatter and links** - `timestamp` and flat cross-taxonomy `tags` frontmatter keys, and internal links rewritten to point at the Markdown file versions, always on
- **Access statistics** - logs AI agent requests with filterable stats page showing per-agent, per-post, and per-access-method breakdowns with date range filtering and pagination
- **WP-CLI commands** - `generate`, `status`, `delete`, `generate-taxonomies`, `generate-indexes`
- **Filterable** - numerous WordPress filters to customise output, frontmatter, and serving behaviour
- **Fully unit-tested** - PHPUnit 9.6 test suite

---

## Requirements

- PHP 8.0+
- WordPress 6.3+

---

## Installation

### From source

```bash
git clone https://github.com/chancery-lane-project/wp-mfa-plugin.git markdown-for-agents
cd markdown-for-agents
composer install        # populates vendor-prefixed/ via Strauss
composer install --no-dev   # then strip dev deps for release
```

The first `composer install` runs [Strauss](https://github.com/BrianHenryIE/strauss) automatically to namespace-prefix `league/html-to-markdown` into `vendor-prefixed/`, isolating the plugin from any other plugin shipping the same library. The second pass with `--no-dev` removes Strauss and its build-time dependencies; `vendor/league/html-to-markdown/` is reinstalled at this point but is stripped from the SVN deploy via `.distignore` so only the prefixed copy ships.

Upload to `wp-content/plugins/markdown-for-agents/` and activate through the WordPress Plugins screen.

### WordPress.org

Search for **Markdown for Agents and Statistics** in the WordPress plugin directory, or install directly from **Plugins → Add New**.

---

## Configuration

Navigate to **Settings → Markdown for Agents**.

| Setting | Description |
|---|---|
| Export directory | Subdirectory inside `wp-content/uploads/` where `.md` files are stored |
| Post types | Which post types to generate Markdown for |
| Auto-generate | Regenerate files automatically on post save |
| User-Agent detection | Force Markdown serving for specific AI User-Agent strings |
| Field configuration | Per-post-type frontmatter and content field mappings |
| Build downloadable bundle | Maintains a `.zip` of the export tree with relative internal links, generates `manifest.json`, and displays an ARD discovery catalog panel (`ai-catalog.json`, for manual deployment to `/.well-known/`) (off by default; no other toggle required) |

---

## File structure

```
wp-content/uploads/{export_dir}/
  index.md                     ← OKF root index (declares okf_version)
  {post-type}/
    index.md                   ← directory listing (title + excerpt per post)
    {slug}.md                  ← singular post file
    manifest.json              ← content hashes + change tracking
    changes.json               ← delta since last export (for RAG sync)
  taxonomy/
    index.md                   ← taxonomy directory listing
    {taxonomy}/
      index.md                 ← term listing for this taxonomy
      {term-slug}.md           ← taxonomy archive file
```

---

## Markdown format

### Singular post

```markdown
---
title: "Post Title"
date: 2025-01-15T00:00:00Z
modified: 2025-03-10T00:00:00Z
permalink: "https://example.com/post-slug/"
type: post
status: publish
excerpt: ""
wpid: 42
featured_image: "https://example.com/wp-content/uploads/image.jpg"
# ... taxonomy terms and any configured custom fields
---

Post content converted to Markdown.
```

### Taxonomy archive

```markdown
---
title: "Climate Law"
type: taxonomy_archive
taxonomy: category
slug: climate-law
term_id: 42
permalink: "https://example.com/category/climate-law/"
post_count: 23
description: "Posts about climate law and policy."
---

# Climate Law

Posts in this archive: 23

- [Post Title One](https://example.com/post-title-one/) - Excerpt text here.
- [Post Title Two](https://example.com/post-title-two/)
```

---

## OKF (Open Knowledge Format)

The export tree follows [OKF v0.1](https://github.com/GoogleCloudPlatform/knowledge-catalog) conventions so agents can discover and traverse the corpus. OKF-compliant frontmatter and link rewriting are always on — there is no toggle.

**Always on:**

- `index.md` directory listings (see file structure above) are generated at the export root and in every post-type and taxonomy directory, and regenerated automatically when files change. The root index declares `okf_version: "0.1"`.
- Frontmatter gains `timestamp` (mirrors `modified`) and `tags` — a flat, deduplicated list of term names across **all** taxonomies. `tags` replaces the previous post_tag-only value; per-taxonomy keys (`categories`, custom slugs) are unchanged. New `markdown_for_agents_flat_tags` filter.
- Internal links in post bodies and taxonomy archive listings are rewritten to absolute `.md` upload URLs (e.g. `https://site/wp-content/uploads/{export_dir}/post/other-slug.md`), so cross-links work whether the file is fetched from the uploads tree or served via content negotiation.

**Limitations:**

- Links inside fenced code blocks are rewritten like any other link.
- A post relocated via the `markdown_for_agents_export_path` filter is not seen by the link resolver, so links to it point at the default path (OKF §5.3 tolerates broken links).
- A post or term slugged `index` keeps its existing export file — the directory listing for that location is skipped, and such a corpus cannot be fully OKF §9-conformant (reserved filenames, §3.1). Posts slugged `log` similarly shadow the reserved `log.md` name.
- Rewritten links are domain-absolute; a corpus copied to another domain needs regeneration. The `.zip` bundle (below) rewrites links to relative form instead, so an extracted bundle is traversable offline regardless of domain.

### Bundle (.zip)

**Behind the "Build downloadable bundle" toggle (off by default; no other toggle required):** the plugin packages the entire export tree into a single zip archive (deflated entries) at `wp-content/uploads/{site-name}-okf.zip`, where `{site-name}` is the site's title (`sanitize_file_name(get_bloginfo('name'))`), falling back to `{export_dir}` if the site name sanitizes to an empty string. Every bundle rebuild also (re)generates `manifest.json` and, on the settings page, displays the ARD discovery catalog panel described below — both are automatic whenever this toggle is on.

- **Contents:** everything under the export tree except `changes.json` (a sync delta, not content) — all `.md` files (posts, taxonomy archives, indexes) and `manifest.json`.
- **Links:** internal links inside bundled `.md` files are rewritten from absolute upload URLs to paths relative to each linking file (OKF §5.2, e.g. `](../post/other-slug.md)`), so the extracted bundle can be traversed offline without knowing the source domain. Relative form is used rather than root-absolute (`](/post/…)`) because many consumers treat a leading `/` as an external link and drop it.
- **Location and stability:** the archive is built at a temporary path and atomically renamed into place, so the public URL is always stable and a concurrent download never sees a partial file.
- **Rebuild policy:** the bundle is rebuilt synchronously at the end of bulk generation (the admin "Generate everything" button or `wp markdown-agents generate` / `generate-taxonomies`). A single post save instead marks the bundle stale and schedules a debounced WP-Cron event five minutes out, collapsing a burst of edits into one rebuild. That delay assumes the default WP-Cron behaviour of firing on the next page load; on sites with `DISABLE_WP_CRON` set, the rebuild instead fires on the next system-cron hit to `wp-cron.php`. You can also force a rebuild with `wp markdown-agents bundle`.
- **Statistics caveat:** bundle downloads are served directly by the web server as a static file and never touch PHP, so they do not appear in the plugin's access statistics.

### ARD discovery (/.well-known/ai-catalog.json)

**Automatic whenever the "Build downloadable bundle" toggle is on** — there is no separate ARD toggle.

The plugin *generates* an [ARD](https://github.com/agent-readable/agent-readable-directory) catalog document (`ai-catalog.json`) and displays it as read-only JSON on the settings page, but it never serves it itself: no routes or rewrite rules are registered for `/.well-known/`. To publish it, copy the JSON into a `/.well-known/ai-catalog.json` file at your web root yourself (or symlink to a file you manage). The catalog content is deliberately stable across bundle rebuilds — it omits the ARD-optional `updatedAt` and `version` fields — so a one-time copy stays valid; you don't need to re-copy it after every rebuild.

The catalog entry's `type` and `mediaType` use `application/okf-bundle+zip`, a de-facto media type value (registration is pending upstream, tracked in [knowledge-catalog#111](https://github.com/GoogleCloudPlatform/knowledge-catalog/issues/111)).

---

## WP-CLI

```bash
# Generate all post files
wp markdown-agents generate

# Generate incrementally (skips unchanged)
wp markdown-agents generate --incremental

# Generate with manifest + changes.json
wp markdown-agents generate --with-manifest

# Generate a single post type
wp markdown-agents generate --post-type=post

# Dry run
wp markdown-agents generate --dry-run

# Generate taxonomy archives
wp markdown-agents generate-taxonomies

# Generate taxonomy archives for one taxonomy
wp markdown-agents generate-taxonomies --taxonomy=category

# Dry run taxonomy generation
wp markdown-agents generate-taxonomies --dry-run

# Rebuild all index.md directory listings
wp markdown-agents generate-indexes

# Preview which indexes would be written
wp markdown-agents generate-indexes --dry-run

# Show status
wp markdown-agents status

# Delete all generated files
wp markdown-agents delete --all --yes

# Build the OKF bundle (.zip) — requires the bundle toggle enabled
wp markdown-agents bundle

# Only rebuild the bundle if it is stale
wp markdown-agents bundle --if-stale
```

---

## Filters

| Filter | Signature | Description |
|---|---|---|
| `markdown_for_agents_serve_enabled` | `(bool $enabled, WP_Post $post)` | Enable/disable serving for a specific post |
| `markdown_for_agents_serve_post_types` | `(array $types)` | Modify the list of serveable post types |
| `markdown_for_agents_serve_taxonomies` | `(bool $enabled)` | Enable/disable serving for taxonomy archives |
| `markdown_for_agents_frontmatter` | `(array $fm, WP_Post $post)` | Modify frontmatter before serialisation |
| `markdown_for_agents_taxonomy_frontmatter` | `(array $fm, WP_Term $term)` | Modify taxonomy archive frontmatter |
| `markdown_for_agents_pre_convert` | `(string $html, WP_Post $post)` | Filter HTML before Markdown conversion |
| `markdown_for_agents_post_convert` | `(string $markdown, WP_Post $post)` | Filter Markdown after conversion |
| `markdown_for_agents_content_signal` | `(string $signal)` | Modify the `Content-Signal` header value |
| `markdown_for_agents_cache_headers` | `(array $headers, string $filepath, string $access_method)` | Override the cache-related headers on the Markdown response; `$access_method` is `query-param`, `accept-header` or `ua` |
| `markdown_for_agents_html_headers` | `(array $headers, string $url)` | Modify or omit the `Link` and `Vary: Accept` headers added to HTML responses that have a Markdown alternate |
| `markdown_for_agents_flat_tags` | `(array $tags, WP_Post $post)` | Modify the flat cross-taxonomy tags list |
| `markdown_for_agents_index_content` | `(string $content, string $relative_path)` | Modify an `index.md` body before it is written |
| `markdown_for_agents_ai_catalog` | `(array $catalog)` | Modify the ARD catalog document before display |
| `markdown_for_agents_converter_options` | `(array $options)` | Override the HTML→Markdown converter options |
| `markdown_for_agents_agent_categories` | `(array $map)` | Modify the intent-category → UA-substring map used to classify agents in stats |

---

## Actions

| Action | Description |
|---|---|
| `markdown_for_agents_file_generated` | Fired after a `.md` file is written |
| `markdown_for_agents_file_deleted` | Fired after a `.md` file is deleted |
| `markdown_for_agents_taxonomy_file_generated` | Fired after a taxonomy archive `.md` file is written |
| `markdown_for_agents_taxonomy_file_deleted` | Fired after a taxonomy archive `.md` file is deleted |
| `markdown_for_agents_unresolved_link` | `(string $url, string $reason)` — fired when a same-host link in post content could not be mapped to an exported document, so it stays an HTML permalink. `$reason` is `not_found` or `ineligible`. Hook to audit export coverage |

---

## Development

```bash
composer install
composer test          # run PHPUnit
composer phpcs         # run WordPress Coding Standards
```

Tests use PHPUnit 9.6 with namespace-scoped function mocks (no extensions required).

---

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE) for details.

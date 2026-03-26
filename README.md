# Markdown for Agents and Statistics

> Serve pre-generated Markdown files to AI agents via HTTP content negotiation.

[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://www.php.net/)
[![WordPress 6.3+](https://img.shields.io/badge/WordPress-6.3%2B-blue)](https://wordpress.org/)
[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

A WordPress plugin for [The Chancery Lane Project](https://chancerylane.uk) that converts post content to Markdown and serves it to AI agents and LLM tools via standard HTTP content negotiation.

---

## How it works

1. Posts and taxonomy archive pages are converted to Markdown and saved as static files on disk inside `wp-content/uploads/`.
2. When a request arrives with `Accept: text/markdown`, the `?output_format=md` query parameter, or a known AI User-Agent, WordPress serves the pre-generated `.md` file directly — no page render required.
3. A `<link rel="alternate" type="text/markdown">` tag is injected into each page's `<head>` so agents can discover Markdown versions automatically.
4. An `llms.txt` index file can be generated to help LLM tools navigate the site.

---

## Features

- **Content negotiation** — serves Markdown on `Accept: text/markdown`, `?output_format=md`, or known AI User-Agent strings
- **Taxonomy archive support** — category, tag, and custom taxonomy archives served as Markdown post listings
- **Auto-generation** — files regenerated on post save; taxonomy archives regenerated when any post in the term changes
- **Bulk generation** — generate all files via the admin settings page (AJAX with live progress counter) or WP-CLI
- **Per-post-type field configuration** — choose which meta/ACF fields appear in frontmatter or body
- **ACF support** — dot-notation for nested group fields (e.g. `group.subfield`); relationship fields normalised to post titles
- **Manifest + incremental export** — content-hash manifest with `--incremental` flag; `changes.json` delta for RAG sync
- **llms.txt generation** — follows the [llmstxt.org](https://llmstxt.org) specification
- **Access statistics** — logs AI agent requests with filterable stats page showing per-agent, per-post, and per-access-method breakdowns with date range filtering and pagination
- **WP-CLI commands** — `generate`, `status`, `delete`, `generate-taxonomies`, `generate-llms-txt`
- **Filterable** — numerous WordPress filters to customise output, frontmatter, and serving behaviour
- **Fully unit-tested** — PHPUnit 9.6 test suite

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
composer install --no-dev
```

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

---

## File structure

```
wp-content/uploads/{export_dir}/
  {post-type}/
    {slug}.md                  ← singular post file
    manifest.json              ← content hashes + change tracking
    changes.json               ← delta since last export (for RAG sync)
  taxonomy/
    {taxonomy}/
      {term-slug}.md           ← taxonomy archive file
  llms.txt                     ← site-level LLM index
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

- [Post Title One](https://example.com/post-title-one/) — Excerpt text here.
- [Post Title Two](https://example.com/post-title-two/)
```

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

# Show status
wp markdown-agents status

# Delete all generated files
wp markdown-agents delete --all --yes

# Generate llms.txt
wp markdown-agents generate-llms-txt
```

---

## Filters

| Filter | Signature | Description |
|---|---|---|
| `wp_mfa_serve_enabled` | `(bool $enabled, WP_Post $post)` | Enable/disable serving for a specific post |
| `wp_mfa_serve_post_types` | `(array $types)` | Modify the list of serveable post types |
| `wp_mfa_serve_taxonomies` | `(bool $enabled)` | Enable/disable serving for taxonomy archives |
| `wp_mfa_frontmatter` | `(array $fm, WP_Post $post)` | Modify frontmatter before serialisation |
| `wp_mfa_taxonomy_frontmatter` | `(array $fm, WP_Term $term)` | Modify taxonomy archive frontmatter |
| `wp_mfa_pre_convert` | `(string $html, WP_Post $post)` | Filter HTML before Markdown conversion |
| `wp_mfa_post_convert` | `(string $markdown, WP_Post $post)` | Filter Markdown after conversion |
| `wp_mfa_content_signal` | `(string $signal)` | Modify the `Content-Signal` header value |

---

## Actions

| Action | Description |
|---|---|
| `wp_mfa_file_generated` | Fired after a `.md` file is written |
| `wp_mfa_file_deleted` | Fired after a `.md` file is deleted |

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

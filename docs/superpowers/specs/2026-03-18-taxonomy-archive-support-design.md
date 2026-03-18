# Taxonomy Archive Support — Design Spec

**Date:** 2026-03-18
**Status:** Approved
**Feature:** Serve taxonomy archive pages (category, tag, custom taxonomies) as Markdown files for AI agent navigation.

---

## Overview

The plugin currently serves singular posts as pre-generated Markdown files via HTTP content negotiation. This feature extends that to taxonomy archive pages — category, tag, and custom taxonomy term archives — so AI agents can navigate site structure by exploring term listings.

---

## Scope

- All registered public taxonomies (`get_taxonomies(['public' => true])`)
- Pre-generated files on disk, auto-refreshed when posts are saved
- Same content negotiation as singular posts (Accept header, `?output_format=md`, known AI User-Agents)
- No cap on posts listed per term archive

Out of scope: paginated archives, private taxonomies, draft post inclusion.

---

## File Format

### Path convention

```
{export_dir}/taxonomy/{taxonomy-slug}/{term-slug}.md
```

Examples:
```
wp-mfa-exports/taxonomy/category/climate-law.md
wp-mfa-exports/taxonomy/post_tag/legal.md
wp-mfa-exports/taxonomy/practice-area/arbitration.md
```

### Frontmatter

```yaml
---
title: "Climate Law"
type: taxonomy_archive
taxonomy: category
slug: climate-law
term_id: 42
description: "Posts about climate law and policy."
permalink: "https://example.com/category/climate-law/"
post_count: 23
---
```

- `type: taxonomy_archive` distinguishes these from singular post files
- `description` is omitted if the term has no description
- `post_count` reflects published posts at generation time

### Body

```markdown
# Climate Law

Posts in this archive: 23

- [Post Title One](https://example.com/post-title-one/) — Excerpt text here.
- [Post Title Two](https://example.com/post-title-two/) — Excerpt text here.
- [Post Title Three](https://example.com/post-title-three/)
```

- Bullet list: linked title + em dash + excerpt (excerpt omitted if empty)
- Posts ordered by date descending
- Excerpt is plain text (stripped of HTML)

---

## Architecture

### New class: `TaxonomyArchiveGenerator`

**Location:** `src/Generator/TaxonomyArchiveGenerator.php`

Responsibilities:
1. Accept a `WP_Term` object
2. Build frontmatter from term data
3. Query all published posts in that term (ordered by date desc)
4. Assemble Markdown body as a post listing
5. Write to disk via the existing `FileWriter`

Dependencies injected: `FileWriter`, `YamlFormatter`, `Options` — consistent with `Generator`.

Public interface:
```php
public function generate_term( WP_Term $term ): bool;
public function delete_term( WP_Term $term ): bool;
public function generate_all( string $taxonomy = '' ): array; // returns [success, skip, error] counts
```

### Classes modified

| Class | Change |
|---|---|
| `Negotiator` | Add taxonomy archive serving branch |
| `Generator` | Extend `save_post` and `before_delete_post` hooks to regenerate term archives |
| `Admin` | Add taxonomy bulk-generate section and AJAX handler |
| `CLI/Commands` | Add `generate-taxonomies` sub-command |
| `Plugin` | Wire `TaxonomyArchiveGenerator` into the container |

---

## Serving Layer

### `Negotiator::maybe_serve_markdown()` changes

Add a second branch after the existing `is_singular()` check:

```
if is_singular()         → existing singular post logic
if is_tax()
|| is_category()
|| is_tag()              → new taxonomy archive branch
```

Taxonomy branch:
1. `get_queried_object()` → `WP_Term`
2. Construct path: `{export_dir}/taxonomy/{taxonomy}/{term-slug}.md`
3. Validate with existing `is_safe_path()`
4. Serve with identical headers (Content-Type, X-Markdown-Source, Vary)

### Link tag

`Negotiator::output_link_tag()` extended to emit on taxonomy archive pages:

```html
<link rel="alternate" type="text/markdown" href="/category/climate-law/?output_format=md">
```

Only emitted if the `.md` file exists — same guard as singular posts.

### Filters

- `wp_mfa_serve_taxonomies` (bool, default `true`) — global on/off for taxonomy serving
- `wp_mfa_taxonomy_frontmatter` — modify frontmatter array before serialisation, mirrors `wp_mfa_frontmatter`

The existing `wp_mfa_serve_enabled` filter receives `0` as post ID for taxonomy requests (no applicable post).

---

## Auto-regeneration

### `save_post` hook extension

After generating the singular post file, `Generator::on_save_post()` regenerates term archives for all terms on the saved post:

```
on_save_post($post_id):
  → generate singular post file (existing)
  → get_taxonomies(['public' => true])
  → for each taxonomy:
      → wp_get_post_terms($post_id, $taxonomy)
      → for each term: TaxonomyArchiveGenerator::generate_term($term)
```

Gate: only fires when `auto_generate` option is enabled — same as singular generation.

### `before_delete_post` hook

Same loop as `save_post` — regenerates all term archives for the post's terms before the post is deleted.

### Eventual consistency note

When a post is removed from a term, the old term archive is not regenerated immediately. It will be regenerated the next time any post in that term is saved. Files are eventually consistent, not real-time. This is acceptable at typical content volumes.

---

## Admin UI

A new section appended to the existing settings page:

```
── Taxonomy Archives ───────────────────────────────
Generate Markdown archives for all public taxonomy terms.

[Generate All Taxonomy Archives]
```

- Button triggers `wp_ajax_mfa_generate_taxonomy_batch` AJAX endpoint
- Same `bulk-generate.js` pattern — paginated batches with live counter
- Counter displays: "23 / 148 terms processed"
- Batch size: 50 terms per request
- Progress and error display consistent with existing post-type bulk generation

---

## WP-CLI

New sub-command:

```bash
wp markdown-agents generate-taxonomies [--taxonomy=<slug>] [--dry-run]
```

| Flag | Behaviour |
|---|---|
| _(none)_ | Generate archives for all public taxonomies |
| `--taxonomy=category` | Generate only terms in the specified taxonomy |
| `--dry-run` | Report what would be generated without writing files |

Output format mirrors the existing `generate` command: one line per term showing success/skip/error.

---

## Testing

Unit tests required for:

- `TaxonomyArchiveGenerator::generate_term()` — frontmatter fields, post listing, empty term, term with no description
- `TaxonomyArchiveGenerator` path construction and `FileWriter` delegation
- `Negotiator` taxonomy branch — file exists, file missing, `is_safe_path` guard, link tag emission
- `Generator::on_save_post()` — verify term regeneration is triggered, auto_generate gate
- `CLI/Commands` — `generate-taxonomies` with and without `--taxonomy` flag, `--dry-run` output

Integration concerns (not unit-testable): WordPress query context for `is_tax()`, `get_queried_object()`.

---

## Open Questions (none — all resolved during design)

All design decisions confirmed by user:
- Format: frontmatter + post index listing (Option A)
- Generation: pre-generated, auto-refresh on save (Option C)
- Scope: all registered public taxonomies (Option C)
- Post cap: none (Option A)
- Architecture: new `TaxonomyArchiveGenerator` class, extend `Negotiator` and `Generator` (Approach A)

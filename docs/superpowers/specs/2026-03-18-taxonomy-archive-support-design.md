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

Both `{taxonomy-slug}` and `{term-slug}` are passed through `sanitize_file_name()` before path construction, consistent with how `Generator` handles post type and post slug.

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
// Used by save_post hook and CLI
public function generate_term( WP_Term $term ): bool;

// Used by delete_term hook
public function delete_term_file( WP_Term $term ): bool;

// Returns file path for a term — used by Negotiator and internally; applies sanitize_file_name()
public function get_export_path( WP_Term $term ): string;

// Used by WP-CLI generate-taxonomies command
public function generate_all( string $taxonomy = '' ): array; // returns ['success' => int, 'skipped' => int, 'failed' => int]

// Used by Admin AJAX batch endpoint — mirrors Generator::generate_batch() signature
public function generate_batch( int $offset, int $limit ): array; // returns ['total' => int, 'processed' => int, 'errors' => string[]]
```

`generate_batch()` mirrors `Generator::generate_batch()` exactly so that `Admin.php` and `bulk-generate.js` can use the same response shape (`data.total`, `data.processed`, `data.errors`).

### Classes modified

| Class | Change |
|---|---|
| `Negotiator` | Add taxonomy archive serving branch; inject `TaxonomyArchiveGenerator` for path resolution |
| `Generator` | Extend `save_post` hook to regenerate term archives; add `delete_term` hook handler |
| `Admin` | Add taxonomy bulk-generate section, AJAX handler, and extend `bulk-generate.js` |
| `CLI/Commands` | Add `generate-taxonomies` sub-command |
| `Plugin` | Wire `TaxonomyArchiveGenerator` into the container; inject into `Negotiator` |

---

## Serving Layer

### `Negotiator` constructor change

`TaxonomyArchiveGenerator` is injected into `Negotiator` so path construction stays in one place:

```php
public function __construct(
    private readonly array $options,
    private readonly Generator $generator,
    private readonly TaxonomyArchiveGenerator $taxonomy_generator,
    private readonly AgentDetector $agent_detector,
    private readonly AccessLogger $access_logger,
)
```

`Plugin::define_negotiate_hooks()` is updated to pass the `TaxonomyArchiveGenerator` instance.

### `Negotiator::maybe_serve_markdown()` changes

Add a second branch after the existing `is_singular()` check:

```
if is_singular()         → existing singular post logic
if is_tax()
|| is_category()
|| is_tag()              → new taxonomy archive branch
```

Taxonomy branch:
1. Check `wp_mfa_serve_taxonomies` filter (bool, default `true`) — bail if false
2. `get_queried_object()` → `WP_Term`
3. `$this->taxonomy_generator->get_export_path( $term )` → absolute file path
4. Validate with existing `is_safe_path()`
5. Serve with identical headers (Content-Type, X-Markdown-Source, Vary)

The existing `wp_mfa_serve_enabled` filter is **not** used for taxonomy requests — it expects a `WP_Post` second argument. Taxonomy serving is controlled exclusively by the new `wp_mfa_serve_taxonomies` filter.

### Link tag

`Negotiator::output_link_tag()` extended to emit on taxonomy archive pages:

```html
<link rel="alternate" type="text/markdown" href="/category/climate-law/?output_format=md">
```

Only emitted if the `.md` file exists — same guard as singular posts.

### Filters

- `wp_mfa_serve_taxonomies` (bool, default `true`) — global on/off for taxonomy archive serving
- `wp_mfa_taxonomy_frontmatter` (array, WP_Term) — modify frontmatter array before serialisation, mirrors `wp_mfa_frontmatter`

---

## Auto-regeneration

### `save_post` hook extension

After generating the singular post file, `Generator::on_save_post()` regenerates term archives for all terms on the saved post. Term regeneration happens **after** the recursion guard meta is cleared, so it runs outside the guard block and cannot cause infinite loops:

```
on_save_post($post_id):
  → [existing recursion guard set]
  → generate singular post file (existing)
  → [recursion guard cleared]
  → if auto_generate enabled:
      → get_taxonomies(['public' => true])
      → for each taxonomy:
          → wp_get_post_terms($post_id, $taxonomy)
          → for each term: TaxonomyArchiveGenerator::generate_term($term)
```

Gate: only fires when `auto_generate` option is enabled — same as singular generation.

### `delete_term` hook

When a taxonomy term is deleted, its archive file is removed:

```
WordPress hook: delete_term( int $term_id, int $tt_id, string $taxonomy, WP_Term $deleted_term )
→ TaxonomyArchiveGenerator::delete_term_file( $deleted_term )
```

Registered in `Plugin.php` alongside other hooks.

### Post deletion

When a post is deleted, term archives are regenerated **after** deletion (using the `after_delete_post` hook, not `before_delete_post`) so the regenerated files no longer include the deleted post:

```
WordPress hook: after_delete_post( int $post_id, WP_Post $post )
→ collect terms from $post object (post is already deleted, so use stored term data)
→ for each term: TaxonomyArchiveGenerator::generate_term($term)
```

Note: `wp_get_post_terms()` will return empty after deletion. Terms must be collected before deletion and passed through. Implementation should hook `before_delete_post` to cache terms, then `after_delete_post` to regenerate using the cached set.

### Eventual consistency note

When a post is **removed from a term** (term relationship changed without deleting the post), the old term archive will not reflect the change immediately. The `set_object_terms` WordPress hook could close this gap, but it fires on every term relationship write and could trigger many simultaneous file rewrites on bulk operations. For now, the archive is regenerated on the next `save_post` for any post in that term. This is acceptable at typical content volumes. The `set_object_terms` approach is noted as a future improvement if real-time consistency becomes a requirement.

---

## Admin UI

A new section appended to the existing settings page:

```
── Taxonomy Archives ───────────────────────────────
Generate Markdown archives for all public taxonomy terms.

[Generate All Taxonomy Archives]
```

- Button triggers `wp_ajax_mfa_generate_taxonomy_batch` AJAX endpoint
- `bulk-generate.js` is extended to support a `data-action` attribute on buttons, so the taxonomy button can specify a different AJAX action while reusing the same batch loop and progress counter logic
- Counter displays: "23 / 148 terms processed"
- Batch size: 50 terms per request (terms are cheaper to generate than posts)
- Response shape from `TaxonomyArchiveGenerator::generate_batch()` matches `Generator::generate_batch()`: `{total, processed, errors[]}` — no JS changes needed beyond the action attribute

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

Uses `TaxonomyArchiveGenerator::generate_all()` which returns `['success', 'skipped', 'failed']` counts. Output format mirrors the existing `generate` command: one line per term showing success/skip/error.

---

## Testing

Unit tests required for:

- `TaxonomyArchiveGenerator::generate_term()` — frontmatter fields, post listing, empty term, term with no description
- `TaxonomyArchiveGenerator::get_export_path()` — path construction, `sanitize_file_name` applied to taxonomy and term slug
- `TaxonomyArchiveGenerator::delete_term_file()` — deletes file when it exists, returns true; returns false gracefully when file is missing
- `TaxonomyArchiveGenerator::generate_batch()` — response shape matches `{total, processed, errors[]}`
- `Negotiator` taxonomy branch — file exists, file missing, `is_safe_path` guard, link tag emission, `wp_mfa_serve_taxonomies` filter
- `Generator::on_save_post()` — term regeneration triggered after recursion guard cleared, `auto_generate` gate respected
- Post deletion — terms cached in `before_delete_post`, archives regenerated in `after_delete_post`
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

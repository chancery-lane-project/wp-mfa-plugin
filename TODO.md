# WP Markdown for Agents — TODO

Current state: v1.0.0, production-ready with PSR-4 structure, full test suite, admin UI, CLI, stats tracking, and incremental export with manifest/change detection.

---

## Serving layer

- [x] **Export path moved to uploads** — Now uses `wp-content/uploads/{export_dir}/{post_type}/{slug}.md` via centralised `Options::get_export_base()` helper. All path construction goes through one method.
- [ ] **Taxonomy archive support** — Currently singular posts only. Taxonomy archive pages (category, tag, custom taxonomies) are a key navigation surface for AI agents exploring site structure.

## Frontmatter & content

- [ ] **Hierarchy support** — Add `parent`, `ancestors`, `children` fields for hierarchical post types (pages, hierarchical CPTs).
- [ ] **Author metadata** — Optional, privacy-aware author field in frontmatter (opt-in per the wp-to-file pattern).
- [ ] **Topics section in body** — Append a `## Topics` section with linked taxonomy terms at the end of the Markdown body (aids LLM navigation between related content).
- [ ] **Relative image paths** — `FrontmatterBuilder` uses absolute `wp_get_attachment_url()` for featured images. Consider normalising to relative paths so exports survive domain changes (staging → production).

## Stats & maintenance

- [ ] **Stats retention policy** — `wp_mfa_access_stats` table grows indefinitely. Add a WP-CLI prune command and/or cron-based cleanup (e.g. drop records older than 90 days).
- [ ] **Manifest hash coverage** — Change detection hashes content + title + modified date but not taxonomy or custom field changes. Expand hash to include these so `--incremental` catches all meaningful edits.

## Admin UX

- [ ] **AJAX bulk generation** — Current "Generate all" buttons use synchronous form POST which can timeout on large sites. Switch to AJAX with progress feedback.
- [ ] **Export preview** — Show a preview of the generated Markdown before writing to disk (useful for debugging frontmatter field mappings).

## Documentation

- [ ] **ACF field type compatibility** — Document which ACF field types are supported by `resolve_field_value()` (text, textarea, select, group with dot notation). Note limitations with repeaters, flexible content, and relationship fields.
- [ ] **readme.txt** — Review and update for WordPress.org format if planning public release.

## Done (from comparison.md)

- [x] Bug fixes B1-B4 (LlmsTxt parser, mock leak, link tag href, query param support)
- [x] HTTP parity — Content-Signal header, X-Markdown-Source header, conditional Vary
- [x] Per-post kill-switch filter (`wp_mfa_serve_enabled`)
- [x] Filterable post type allowlist (`wp_mfa_serve_post_types`)
- [x] Per-post-type field configuration via admin UI (supersedes ConfigReader/YAML)
- [x] ACF dot-notation field resolution in FrontmatterBuilder
- [x] Incremental export with manifest + changes.json for RAG systems

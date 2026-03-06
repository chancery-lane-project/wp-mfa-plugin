# wp-markdown-for-agents vs wp-to-file / wp-to-file-serve

Comparison report covering bugs, technical concerns, improvements, and gaps.

**Date:** 2026-03-06
**Scope:** wp-markdown-for-agents (new), wp-to-file (mu-plugin), wp-to-file-serve (plugin)

---

## 1. Architecture Overview

| Aspect | wp-to-file | wp-to-file-serve | wp-markdown-for-agents |
|--------|-----------|------------------|------------------------|
| **Type** | CLI bulk export | HTTP delivery layer | Standalone batch + HTTP serve |
| **Output formats** | MD, HTML, JSON, JSON-LD, CSV | Markdown (via wp-to-file) | Markdown only |
| **Markdown engine** | Own `MarkdownProcessor` | Delegates to wp-to-file | Own `Converter` class |
| **Dependencies** | `league/html-to-markdown` | wp-to-file (hard dep) | `league/html-to-markdown` |
| **Config system** | YAML (`export-profiles.yaml`) + `ConfigReader` | Shares wp-to-file's `ConfigReader` | WordPress options API (`wp_mfa_options`) |
| **Admin UI** | None (CLI only) | None (filter-only config) | Settings page, per-post meta box, stats dashboard |
| **Stats/analytics** | None | None | Custom DB table (`wp_mfa_access_stats`) |
| **WP-CLI** | Primary interface | None | `wp markdown-agents` (generate, status, delete) |
| **Auto-generate** | Manual CLI only | N/A | `save_post` hook (opt-in) |

### How each serves Markdown over HTTP

- **wp-to-file-serve** generates Markdown on-the-fly using wp-to-file's `MarkdownProcessor`, caches in transients, and exits before template loading. Supports both singular posts and taxonomy archives.
- **wp-markdown-for-agents** serves pre-generated `.md` files from disk via `readfile()`. No taxonomy archive support. Files must be generated in advance (CLI or admin action or `save_post` hook).

---

## 2. Bugs

### B1. `LlmsTxtGenerator::parse_frontmatter()` crashes on nested YAML

**File:** `src/Generator/LlmsTxtGenerator.php:129-131`

The simple frontmatter parser uses `explode(':', $trimmed, 2)` but only processes lines that contain a colon. Nested YAML array items (e.g. `  - News` under `categories:`) are silently skipped — so `categories`, `tags`, and any list-valued frontmatter is never parsed. This is a data loss issue for llms.txt generation if excerpt or title ever appears after a list block.

More critically, if a future frontmatter key contains no colon (malformed file), the `[$key, $value] = explode(...)` destructuring would produce an undefined index.

**Severity:** Medium — affects llms.txt quality, not core export.

### B2. `LlmsTxtGenerator.php` contains a mock function in production code

**File:** `src/Generator/LlmsTxtGenerator.php:157-161`

```php
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return $GLOBALS['_mock_bloginfo'][$show] ?? '';
    }
}
```

A test mock has leaked into the source file. This should be in `tests/mocks/wordpress-mocks.php` only. In production WordPress this is harmless (the `function_exists` guard prevents redefinition), but it's a code smell and would cause issues if autoloaded before WordPress core.

**Severity:** Low — no runtime impact, but should be removed.

### B3. `Negotiator::output_link_tag()` href points to HTML URL

**File:** `src/Negotiate/Negotiator.php:108-109`

```php
$url = esc_url( get_permalink( $post->ID ) );
echo '<link rel="alternate" type="text/markdown" href="' . $url . '">';
```

The `href` is the canonical HTML permalink. Agents following this link get the HTML page, not Markdown — unless they also send `Accept: text/markdown`. Compare wp-to-file-serve which appends `?output_format=md`:

```php
// wp-to-file-serve line 324
esc_url( add_query_arg( 'output_format', 'md', get_permalink( $post ) ) )
```

Without a query parameter entry point, the `<link>` tag is only useful if the agent already knows to send the correct Accept header — defeating the purpose of discovery.

**Severity:** High — breaks the primary discovery mechanism for agents that follow `<link rel="alternate">` with a standard GET.

### B4. No query parameter entry point

wp-markdown-for-agents only supports `Accept: text/markdown` and User-Agent detection. There is no `?output_format=md` or `?format=markdown` query parameter.

This means:
- Browser testing requires header-manipulation tools
- Simple curl testing is harder (`curl -H "Accept: text/markdown"` vs `curl url?output_format=md`)
- CDN/edge cache rules can't key on query params
- The `<link rel="alternate">` tag (B3) has nothing actionable to point to

**Severity:** High — significantly reduces discoverability and developer ergonomics.

---

## 3. Technical Concerns

### T1. No ConfigReader / export-profiles.yaml integration

wp-to-file's `ConfigReader` provides a powerful, declarative config system:

```yaml
post_type_configs:
  clause:
    taxonomies: [clause-application, climate-solution, ...]
    acf_fields:
      frontmatter: [clause_fields.clause_summary, ...]
      content: [clause_fields.clause_content, ...]
    computed_fields:
      lastReviewedDate:
        sources:
          - type: acf
            field: clause_fields.clause_last_updated_date
          - type: post
            property: post_modified
```

wp-markdown-for-agents has none of this. Its `Options` class stores a flat list of enabled post types and a boolean `include_taxonomies` toggle. There is no per-post-type configuration, no taxonomy allowlist per type, no ACF field mapping, no computed fields, and no profile system.

**Impact:** Cannot produce the same rich frontmatter as wp-to-file for custom post types (clause, guide). The new plugin is limited to generic post/page metadata.

**Recommendation:** Either integrate `ConfigReader` directly (like wp-to-file-serve does) or build a simplified YAML config reader. The admin UI can coexist with a YAML config layer — admin settings for runtime toggles, YAML for structural post-type config.

### T2. ContentFilter is too simple

wp-markdown-for-agents `ContentFilter` only strips Gutenberg block comments:

```php
// Strips: <!-- wp:block-name --> and <!-- /wp:block-name -->
```

wp-to-file's `ContentFilter` also:
- Normalises `wp-content/uploads/` URLs to relative paths (`/_images/`)
- Normalises `home_url('/')` to `/`
- Sanitises HTML with an extensive allowlist (preserving semantic tags, tables, code blocks)
- Handles post format-specific wrapping (e.g. quote format → `>` prefix)

**Impact:** wp-markdown-for-agents output may contain absolute upload URLs that break on domain change, and may pass through non-semantic HTML that the Markdown converter handles poorly.

### T3. No ACF field support

`FrontmatterBuilder` has no ACF integration. wp-to-file's `ACFSupport` trait handles:
- Frontmatter ACF fields (dot notation: `clause_fields.clause_summary`)
- Content ACF fields injected into the body
- Image/gallery field processing
- Relationship fields
- Nested group fields

For sites with ACF-heavy custom post types, this is a significant gap.

### T4. No hierarchy support

No parent/child/ancestor data in frontmatter. wp-to-file's `HierarchySupport` trait adds `parent`, `ancestors`, and `children` for hierarchical post types. This matters for pages and any hierarchical CPT.

### T5. No author metadata

wp-to-file has `AuthorExtractor` with privacy-aware opt-in (`author_metadata.enabled`). wp-markdown-for-agents has no author data at all.

### T6. Featured image uses absolute URL

`FrontmatterBuilder::add_featured_image()` stores the full `wp_get_attachment_url()`. wp-to-file strips the upload base URL to produce a relative path. Absolute URLs break when the domain changes (staging → production, migration).

### T7. No Topics section in Markdown body

wp-to-file's `MarkdownProcessor::buildTopicsSection()` appends a `## Topics` section with linked taxonomy terms at the end of the Markdown body. This is valuable for LLM navigation between related content. wp-markdown-for-agents puts taxonomy terms only in frontmatter — they are not navigable links in the body.

### T8. TaxonomyCollector collects all taxonomies unconditionally

`TaxonomyCollector::collect()` fetches terms for every registered taxonomy on the post type. wp-to-file allows a per-post-type `taxonomies` array in the YAML config, so you can include `[category, post_tag]` for posts but `[clause-application, jurisdiction, practice-area, ...]` for clauses.

This is both a performance concern (unnecessary queries) and a data quality concern (irrelevant taxonomies in frontmatter).

---

## 4. Gaps vs wp-to-file-serve

### G1. No taxonomy archive support

wp-to-file-serve renders taxonomy terms as Markdown with frontmatter, hierarchy, and a post listing. wp-markdown-for-agents is singular-post only. Taxonomy archives are a key navigation surface for AI agents exploring a site's content structure.

### G2. No Content-Signal header

wp-to-file-serve sends `Content-Signal: ai-input=yes, search=yes` (Cloudflare convention, filterable). This tells crawlers and CDNs that the content is suitable for AI ingestion. wp-markdown-for-agents does not send this header.

### G3. No X-Markdown-Source header

wp-to-file-serve sends `X-Markdown-Source: wp-to-file-serve` for debugging and analytics. Useful when multiple plugins could be serving Markdown.

### G4. Vary header is unconditional

wp-to-file-serve only sends `Vary: Accept` when the request was negotiated via the `Accept` header (not when triggered by query param). wp-markdown-for-agents sends `Vary: Accept` on every Markdown response, even those triggered by User-Agent detection. This can cause unnecessary cache fragmentation at CDN/edge level.

### G5. No transient/in-memory caching for HTTP responses

wp-to-file-serve caches the generated Markdown in a WordPress transient keyed on `{post_id}_{post_modified_gmt}` (self-invalidating on edit). wp-markdown-for-agents reads from disk on every request — fast for SSDs, but no protection against I/O storms on high-traffic pages. The file-based approach does avoid transient bloat though.

### G6. No kill-switch filter

wp-to-file-serve provides `wptofile_serve_enabled` which receives the `$post` object, allowing per-post opt-out. wp-markdown-for-agents has no equivalent — if the post type is enabled, all published posts of that type are served.

### G7. No filterable post type / taxonomy allowlist for serving

wp-to-file-serve has `wptofile_serve_post_types` and `wptofile_serve_taxonomies` filters that default to all public types. wp-markdown-for-agents uses only the admin-configured `post_types` option — no runtime filter override.

---

## 5. Improvements wp-markdown-for-agents Has Over the Existing Plugins

### I1. Agent access statistics

Custom `wp_mfa_access_stats` table with per-post, per-agent, per-day counters. Admin dashboard with filtering and pagination. Neither wp-to-file nor wp-to-file-serve track who is accessing the Markdown. This is a genuinely useful feature for understanding AI crawler behaviour.

### I2. User-Agent detection

`AgentDetector` class with configurable agent string list (13 known LLM crawlers). Serves Markdown proactively to known AI agents even without the `Accept` header. wp-to-file-serve relies entirely on explicit content negotiation.

### I3. Admin UI

Settings page with WordPress Settings API, per-post meta box showing file status and regenerate button, transient-based admin notices. This is much more accessible than wp-to-file-serve's filter-only configuration.

### I4. Auto-generation on save

`save_post` hook with autosave/revision guards and recursive-trigger protection. Keeps exports in sync without manual CLI runs. wp-to-file requires `wp wptofile` CLI command; wp-to-file-serve generates on each request (no pre-generation).

### I5. Standalone — no wp-to-file dependency

Self-contained mu-plugin with its own `league/html-to-markdown` dependency. Does not require the wp-to-file mu-plugin or its autoloader. Simpler deployment for sites that don't need wp-to-file's full multi-format export capabilities.

### I6. Comprehensive test infrastructure

17 unit test classes with extensive WordPress mock system (689 lines). Well-structured PHPUnit setup. wp-to-file-serve has no tests at all.

### I7. Batch processing with memory management

`Generator::generate_post_type()` processes in batches of 100 with `no_found_rows=true`. wp-to-file's default `posts_per_page: -1` can exhaust memory on large sites.

### I8. Clean uninstall

`uninstall.php` removes the options key. Export files are preserved (user data). wp-to-file-serve has no uninstall handler.

---

## 6. ConfigReader Integration — The Key Gap

The single most impactful improvement would be integrating wp-to-file's `ConfigReader` (or a compatible config reader) into wp-markdown-for-agents. This would:

1. **Share configuration** between CLI export and HTTP serving — one YAML file, consistent output
2. **Enable per-post-type config** — taxonomy lists, ACF field mappings, content exclusion
3. **Support ACF frontmatter** — the YAML `acf_fields.frontmatter` and `acf_fields.content` arrays
4. **Support computed fields** — fallback chains for derived metadata
5. **Enable profiles** — different export configs for different use cases

### How wp-to-file-serve integrates ConfigReader

```php
// wp-to-file-serve.php:97-109
$reader         = new \WPToFile\Core\ConfigReader();
$default_config = $reader->getPostTypeConfig( $post->post_type );

if ( empty( $default_config ) ) {
    $default_config = [
        'include_title_header' => true,
        'taxonomies'           => [ 'category', 'post_tag' ],
        'include_hierarchy'    => true,
        'author'               => [ 'enabled' => false ],
    ];
}

$config    = apply_filters( 'wptofile_serve_config', $default_config, $post );
$processor = new \WPToFile\Processors\MarkdownProcessor( $config );
$markdown  = $processor->process( $post );
```

### What wp-markdown-for-agents could do

Two approaches:

**A) Bundle a copy of ConfigReader** — Copy `ConfigReader.php` into the plugin's `src/` namespace, ship a default `config/export-profiles.yaml`. Zero external dependency. The admin UI settings override YAML values where they conflict.

**B) Read wp-to-file's config if available** — Check for wp-to-file's autoloader, use its `ConfigReader` if present, fall back to current Options-only approach. This mirrors wp-to-file-serve's pattern but without the hard dependency.

Option A is preferred for a standalone plugin.

---

## 7. Summary of Recommended Actions

### Must Fix (Bugs)

| # | Issue | Effort |
|---|-------|--------|
| B1 | LlmsTxtGenerator frontmatter parser — handle nested YAML / guard against missing colon | Small |
| B2 | Remove mock function from `LlmsTxtGenerator.php` production code | Trivial |
| B3 | Fix `<link rel="alternate">` href to include a query param or format indicator | Small |
| B4 | Add `?format=markdown` query parameter support to Negotiator | Medium |

### Should Fix (Technical Concerns)

| # | Issue | Effort |
|---|-------|--------|
| T1 | Add ConfigReader or YAML config support for per-post-type settings | Large |
| T2 | Improve ContentFilter (URL normalisation, HTML allowlist) | Medium |
| T6 | Use relative paths for featured images | Small |
| T7 | Add Topics section to Markdown body | Small |
| T8 | Make TaxonomyCollector configurable per post type | Medium |

### Nice to Have (Gaps)

| # | Issue | Effort |
|---|-------|--------|
| G1 | Taxonomy archive Markdown support | Medium |
| G2 | Content-Signal header | Trivial |
| G3 | X-Markdown-Source header | Trivial |
| G4 | Conditional Vary header | Small |
| G6 | Per-post kill-switch filter | Small |
| T3 | ACF field support | Large |
| T4 | Hierarchy support | Medium |
| T5 | Author metadata | Small |

### Do Not Change (Strengths to Preserve)

- Agent access statistics (I1)
- User-Agent detection (I2)
- Admin UI (I3)
- Auto-generation on save (I4)
- Standalone architecture (I5)
- Test infrastructure (I6)
- Batch processing (I7)

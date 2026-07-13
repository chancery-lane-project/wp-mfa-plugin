# OKF Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Markdown export tree a well-formed OKF v0.1 corpus — internal cross-linking between `.md` files (absolute upload URLs, so links work in every HTTP context), a flat cross-taxonomy `tags` frontmatter list, `timestamp`, and `index.md` directory listings — with zero change to existing output unless the new OKF compatibility toggle is enabled (indexes excepted: they are new files and always generated).

**Architecture:** Three new Generator collaborators. `LinkRewriter` (pure string/logic, resolver injected) rewrites internal permalinks in converted Markdown to absolute `.md` upload URLs. `InternalUrlResolver` maps site URLs → bundle paths using `url_to_postid()` and a lazily built term-link map. `IndexGenerator` builds OKF §6 `index.md` files per directory, regenerated via a dirty-directory set flushed on `shutdown`. `FrontmatterBuilder` gains toggle-gated `timestamp` + flat `tags`. All wiring stays in `Core/Plugin.php`, consistent with the existing no-container pattern. `src/Negotiate/` is untouched.

**Tech Stack:** PHP 8.1, WordPress 6.3+, PHPUnit 9.6 (WP function stubs in `tests/mocks/wordpress-mocks.php`), WPCS via `composer phpcs`.

---

## Decisions already signed off by Felix (2026-07-03)

These are fixed; do not re-litigate:

1. **Frontmatter additions (OKF toggle on): `timestamp` + `tags` only.** `timestamp` mirrors `modified`. No `description`/`resource` mirrors.
2. **Flat `tags` replaces the post_tag-derived `tags` key.** With the toggle on, `tags` is the deduplicated flat list of term *names* across ALL public taxonomies of the post. The old post_tag-only `tags` key is dropped (its values are inside the flat list anyway). Other per-taxonomy keys (`categories`, custom taxonomy slugs) are unchanged. Toggle off → exactly today's output.
3. **Internal links: absolute `.md` upload URLs** (`https://site/wp-content/uploads/{export_dir}/post/other-slug.md`). Revised 2026-07-03, superseding an earlier relative-links choice, after weighing serve-path fragility: absolute URLs work in *every* HTTP context — negotiated at permalinks, fetched from uploads, followed by any agent — with zero serving-path changes. Relativisation for offline bundle traversal happens once, at `.tar.gz` build time (Phase 3, separate plan), where the transform is rare, deterministic, and validatable. Matches the URL style the existing `## Topics` section already uses.
4. **Toggle scope:** frontmatter additions + link rewriting behind a single `okf_compat` option (off by default). `index.md` generation is always on — indexes are new files existing consumers never fetch. Index-file entries use plain relative targets (`slug.md`, `category/`) per the OKF §6 examples — indexes are only ever fetched from the uploads tree (never negotiated at a permalink), so relative targets always resolve.

**Known trade-off (documented, accepted):** the raw on-disk/HTTP tree is not offline-traversable as an extracted *directory* (concept links are domain-absolute); only the future Phase 3 tarball — whose build rewrites links to relative form — will be. OKF §5 permits either link form. Note in README.

**Reserved-name guard:** a post whose slug is `index` or `log` already exports to `index.md`/`log.md` today. Existing behaviour wins: `IndexGenerator` must *skip* writing an index for any directory where the index/log filename is claimed by a concept file (i.e. a published post with that slug in that post type). Never overwrite, never rename existing exports.

---

## File structure

| File | Status | Responsibility |
|---|---|---|
| `src/Core/Options.php` | Modify | Add `okf_compat => false` default |
| `src/Generator/FrontmatterBuilder.php` | Modify | Toggle-gated `timestamp` + flat `tags` (replacing post_tag key) |
| `src/Generator/LinkRewriter.php` | Create | Pure Markdown-link rewriting: find links, resolve via injected callable, emit absolute `.md` upload URLs |
| `src/Generator/InternalUrlResolver.php` | Create | URL → bundle-path resolution (posts via `url_to_postid`, terms via cached map) |
| `src/Generator/Generator.php` | Modify | Wire LinkRewriter into `generate_post()`/`get_post_markdown()` |
| `src/Generator/TaxonomyArchiveGenerator.php` | Modify | Toggle-gated `.md`-URL post links in archive bodies; fire generated/deleted actions |
| `src/Generator/IndexGenerator.php` | Create | Build root / post-type / taxonomy `index.md` files; dirty-set + shutdown flush |
| `src/Core/Plugin.php` | Modify | Instantiate + wire new collaborators and index-regeneration hooks |
| `src/CLI/Commands.php` | Modify | `generate-indexes` subcommand; `generate` and `generate-taxonomies` finish with index build; `status`/`delete` awareness |
| `src/Admin/SettingsPage.php` | Modify | "OKF compatibility mode" checkbox + sanitisation |
| `tests/mocks/wordpress-mocks.php` | Modify | Add `url_to_postid()` stub (+ any missing stubs surfaced by tests) |
| `tests/Unit/...` | Create/Modify | `LinkRewriterTest`, `InternalUrlResolverTest`, `IndexGeneratorTest`; extend `OptionsTest`, `FrontmatterBuilderTest`, `GeneratorTest`, `TaxonomyArchiveGeneratorTest`, `SettingsPageTest` |
| `README.md`, `readme.txt`, `markdown-for-agents.php` | Modify | Docs, filters table, changelog, version 1.6.0 |

Conventions to follow throughout: `declare(strict_types=1)`, tabs, WPCS (`composer phpcs` must pass), UK English in comments, `@since 1.6.0` on new symbols, never import `League\HTMLToMarkdown\` unprefixed. Run tests with `vendor/bin/phpunit tests/Unit/<path>` per file, `composer test` for the suite.

---

### Task 1: `okf_compat` option default

**Files:**
- Modify: `src/Core/Options.php` (defaults array, ~line 33)
- Test: `tests/Unit/Core/OptionsTest.php`

- [ ] **Step 1: Write the failing test**

Add to `OptionsTest`:

```php
public function test_defaults_include_okf_compat_disabled(): void {
	$defaults = Options::get_defaults();

	$this->assertArrayHasKey( 'okf_compat', $defaults );
	$this->assertFalse( $defaults['okf_compat'] );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Core/OptionsTest.php --filter test_defaults_include_okf_compat_disabled`
Expected: FAIL — "Failed asserting that an array has the key 'okf_compat'".

- [ ] **Step 3: Implement**

In `Options::get_defaults()` add after `'include_taxonomy_topics' => false,`:

```php
'okf_compat'                => false,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Core/OptionsTest.php`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Options.php tests/Unit/Core/OptionsTest.php
git commit -m "feat: add okf_compat option default (off)"
```

---

### Task 2: FrontmatterBuilder — `timestamp` + flat `tags`

**Files:**
- Modify: `src/Generator/FrontmatterBuilder.php`
- Test: `tests/Unit/Generator/FrontmatterBuilderTest.php`

Behaviour spec:
- `okf_compat` off → output byte-identical to today (regression assertion).
- `okf_compat` on →
  - `timestamp` key appended, same value as `modified`.
  - `tags` key = flat deduplicated list of term names across ALL taxonomies returned by `TaxonomyCollector::collect()` (which already covers every registered taxonomy for the post type), in collector order.
  - The post_tag-derived per-taxonomy `tags` entry is superseded (the flat list is written to `tags` *after* the taxonomy merge, so it overwrites). Other per-taxonomy keys (`categories`, custom slugs) untouched.
  - Flat list also built when `include_taxonomies` is off (OKF tags are independent of the per-taxonomy keys option) — collect terms explicitly in that case.
  - New filter `markdown_for_agents_flat_tags( array $tags, \WP_Post $post )` applied to the flat list before it is set (runs before the existing `markdown_for_agents_frontmatter` filter, which still runs last).

- [ ] **Step 1: Write the failing tests**

Add to `FrontmatterBuilderTest` (follow the file's existing fixture/mock patterns for posts and terms — read the file first; it already exercises `include_taxonomies`):

```php
public function test_okf_compat_off_produces_no_timestamp_or_flat_tags_changes(): void {
	// Build with okf_compat absent/false and a post that has post_tag + category terms.
	// Assert: no 'timestamp' key; 'tags' equals the post_tag-only list (today's behaviour).
}

public function test_okf_compat_on_adds_timestamp_mirroring_modified(): void {
	// options: ['okf_compat' => true]
	// Assert: $fm['timestamp'] === $fm['modified'] and is ISO 8601.
}

public function test_okf_compat_on_flat_tags_across_taxonomies_deduped(): void {
	// Post with categories [News, Climate], post_tag [net-zero, Climate], custom tax sector [Energy].
	// Assert: $fm['tags'] === ['News', 'Climate', 'net-zero', 'Energy'] (deduped, order preserved).
	// Assert: $fm['categories'] === ['News', 'Climate'] still present (include_taxonomies on).
}

public function test_okf_compat_on_builds_flat_tags_even_when_include_taxonomies_off(): void {
	// options: ['okf_compat' => true, 'include_taxonomies' => false]
	// Assert: 'tags' present and flat; no 'categories' key.
}

public function test_flat_tags_filter_applied(): void {
	// Register markdown_for_agents_flat_tags filter via the mock hook system to append 'extra'.
	// Assert 'extra' is in $fm['tags'].
}
```

Write them as real tests (the sketches above define intent; use the existing test file's post/term mock helpers).

- [ ] **Step 2: Run to verify failures**

Run: `vendor/bin/phpunit tests/Unit/Generator/FrontmatterBuilderTest.php`
Expected: new tests FAIL, existing tests PASS.

- [ ] **Step 3: Implement**

In `FrontmatterBuilder::build()`, immediately before the final `apply_filters(...)` return:

```php
if ( ! empty( $this->options['okf_compat'] ) ) {
	$frontmatter['timestamp'] = $frontmatter['modified'];

	$collected = $this->taxonomy_collector->collect( $post->ID, $post->post_type );
	$flat      = array();

	foreach ( $collected as $names ) {
		foreach ( (array) $names as $name ) {
			if ( ! in_array( $name, $flat, true ) ) {
				$flat[] = $name;
			}
		}
	}

	/**
	 * Modify the flat OKF tags list before it is written to frontmatter.
	 *
	 * @since  1.6.0
	 * @param  string[]  $flat The deduplicated cross-taxonomy tag list.
	 * @param  \WP_Post  $post The post.
	 */
	$frontmatter['tags'] = (array) apply_filters( 'markdown_for_agents_flat_tags', $flat, $post );
}
```

Note: when `include_taxonomies` is on, `collect()` runs twice (once for per-taxonomy keys, once here). Cache the first result in a local variable and reuse rather than calling twice — restructure the method minimally to do so.

Guard: only set `timestamp` when `$frontmatter['modified']` is non-empty — `to_iso8601()` returns `''` for `0000-00-00 00:00:00`, and `timestamp: ''` is not valid ISO 8601 per SPEC §4.1. Add a test for this case.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Generator/FrontmatterBuilderTest.php`
Expected: PASS (all, including pre-existing).

- [ ] **Step 5: Commit**

```bash
git add src/Generator/FrontmatterBuilder.php tests/Unit/Generator/FrontmatterBuilderTest.php
git commit -m "feat: OKF timestamp and flat cross-taxonomy tags in frontmatter (toggle-gated)"
```

---

### Task 3: LinkRewriter (pure logic)

**Files:**
- Create: `src/Generator/LinkRewriter.php`
- Create: `tests/Unit/Generator/LinkRewriterTest.php`

Design: fully unit-testable with zero WP dependencies. Constructor takes an injected resolver callable `fn( string $url ): ?string` returning a bundle path (`post/other-slug.md`) or `null` (leave the link alone), plus the export base URL (from `Options::get_export_base_url()`, no trailing slash). Rewritten links are `{base_url}/{bundle_path}` — absolute `.md` upload URLs.

Behaviour spec:
- Rewrites inline Markdown links `[text](url)` whose URL the resolver maps to a path.
- Does NOT rewrite image links (`![alt](url)`).
- Preserves `#fragment` suffixes (`[x](https://site/a/#s)` → `[x](https://site/…/post/a.md#s)`).
- Strips query strings on rewritten links (a permalink query has no meaning against a static file).
- Leaves external links, relative links, `mailto:`, anchors-only (`#foo`), and unresolvable URLs untouched.
- Fail-safe by design: URLs the regex can't match (containing `)` or spaces) are simply not rewritten — add one test asserting this.
- **Documented non-goal:** links inside fenced code blocks WILL be rewritten if resolvable, and a genuine link directly preceded by a literal `!` in prose ("Amazing![link](url)") is skipped by the image lookbehind. Both are edge cases accepted for v1 — record them in the README limitation note (Task 11) rather than complicating the regex.
- No relative-path computation: relativisation is deliberately deferred to the Phase 3 bundle build (separate plan). Do not add a `relative_path()` helper here (YAGNI).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Generator/LinkRewriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\LinkRewriter;

class LinkRewriterTest extends TestCase {

	private const BASE = 'https://example.com/wp-content/uploads/wp-mfa-exports';

	private function rewriter( array $map ): LinkRewriter {
		return new LinkRewriter( fn( string $url ): ?string => $map[ $url ] ?? null, self::BASE );
	}

	public function test_rewrites_resolvable_internal_link_to_absolute_md_url(): void {
		$r  = $this->rewriter( [ 'https://example.com/other-post/' => 'post/other-post.md' ] );
		$md = 'See [the other post](https://example.com/other-post/) for details.';

		$this->assertSame(
			'See [the other post](' . self::BASE . '/post/other-post.md) for details.',
			$r->rewrite( $md )
		);
	}

	public function test_rewrites_term_archive_link(): void {
		$r  = $this->rewriter( [ 'https://example.com/category/climate/' => 'taxonomy/category/climate.md' ] );
		$md = '[Climate](https://example.com/category/climate/)';

		$this->assertSame(
			'[Climate](' . self::BASE . '/taxonomy/category/climate.md)',
			$r->rewrite( $md )
		);
	}

	public function test_leaves_unresolvable_and_external_links_untouched(): void {
		$r  = $this->rewriter( [] );
		$md = '[ext](https://elsewhere.org/x/) [rel](./a.md) [mail](mailto:a@b.c) [anchor](#top)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}

	public function test_does_not_rewrite_image_links(): void {
		$r  = $this->rewriter( [ 'https://example.com/img-page/' => 'post/img-page.md' ] );
		$md = '![alt text](https://example.com/img-page/)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}

	public function test_preserves_fragment_and_strips_query(): void {
		$r  = $this->rewriter( [ 'https://example.com/other/' => 'post/other.md' ] );
		$md = '[a](https://example.com/other/#section) [b](https://example.com/other/?utm=x)';

		$this->assertSame(
			'[a](' . self::BASE . '/post/other.md#section) [b](' . self::BASE . '/post/other.md)',
			$r->rewrite( $md )
		);
	}

	public function test_unmatchable_urls_fail_safe_untouched(): void {
		// URLs containing ')' or spaces don't match the link regex — they must
		// pass through unchanged rather than being mangled.
		$r  = $this->rewriter( [ 'https://example.com/weird/' => 'post/weird.md' ] );
		$md = '[a](https://example.com/x(1)/) [b](https://example.com/a b/)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Generator/LinkRewriterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`src/Generator/LinkRewriter.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Rewrites internal site links in Markdown to absolute `.md` upload URLs.
 *
 * Pure string logic: URL resolution is delegated to an injected callable so
 * the class is fully unit-testable without WordPress. Relativisation for the
 * OKF bundle happens at bundle build time, not here.
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class LinkRewriter {

	/**
	 * @since  1.6.0
	 * @param  \Closure $resolver Maps an absolute URL to a bundle path
	 *                            (e.g. `post/other.md`) or null.
	 * @param  string   $base_url Export base URL, no trailing slash
	 *                            (Options::get_export_base_url()).
	 */
	public function __construct(
		private readonly \Closure $resolver,
		private readonly string $base_url
	) {}

	/**
	 * Rewrite internal links in a Markdown document.
	 *
	 * @since  1.6.0
	 * @param  string $markdown The Markdown body.
	 * @return string
	 */
	public function rewrite( string $markdown ): string {
		// Inline links only; (?<!\!) excludes image syntax.
		$pattern = '/(?<!\!)(\[(?:[^\[\]]|\[[^\]]*\])*\])\(([^)\s]+)\)/';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $m ): string {
				$url      = $m[2];
				$fragment = '';

				$hash = strpos( $url, '#' );
				if ( false !== $hash ) {
					$fragment = substr( $url, $hash );
					$url      = substr( $url, 0, $hash );
				}

				$query = strpos( $url, '?' );
				if ( false !== $query ) {
					$url = substr( $url, 0, $query );
				}

				$target = ( $this->resolver )( $url );

				if ( null === $target ) {
					return $m[0];
				}

				return $m[1] . '(' . $this->base_url . '/' . $target . $fragment . ')';
			},
			$markdown
		);
	}
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Generator/LinkRewriterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Generator/LinkRewriter.php tests/Unit/Generator/LinkRewriterTest.php
git commit -m "feat: LinkRewriter for internal Markdown cross-links"
```

---

### Task 4: InternalUrlResolver

**Files:**
- Create: `src/Generator/InternalUrlResolver.php`
- Modify: `tests/mocks/wordpress-mocks.php` (add `url_to_postid()` stub)
- Create: `tests/Unit/Generator/InternalUrlResolverTest.php`

Behaviour spec:
- `resolve( string $url ): ?string`.
- **Memoised**: results cached per URL for the instance lifetime (one `array $resolved = []` property). `url_to_postid()` does rewrite-rule matching on every call and this runs once per link per post during bulk generation.
- Rejects URLs whose host differs from `home_url()`'s host → `null`.
- Post resolution: `url_to_postid( $url )` → `get_post()` → must be an enabled post type (`post_types` option), `publish`, no password, not `_markdown_for_agents_excluded` → `{sanitized-type}/{sanitized-slug}.md` (same sanitisation as `Generator::get_export_path`).
- Term resolution: on first term lookup, build a map of `get_term_link( $term ) → taxonomy/{tax}/{slug}.md` for every term of every public taxonomy (mirrors `TaxonomyArchiveGenerator::get_export_path` segments); cached for the lifetime of the instance. URLs compared with trailing slash normalised.
- Anything else → `null`.

**CPT reliability risk:** `url_to_postid()` is known to be unreliable for custom post type permalinks under some rewrite configurations — and CPTs (clauses, guides) are the primary content on TCLP sites. Mitigations: (a) the memoisation above; (b) Task 11 smoke test MUST verify a link *between two CPT posts* rewrites correctly on the ddev site; (c) if it fails there, add a lazy permalink→path map for enabled post types (mirroring the term map, `get_permalink` per published post, batched) as fallback when `url_to_postid()` returns 0 — build it in this task only if the smoke test demands it (YAGNI otherwise, but the executor should know the fallback design up front).

The mock stub, following the file's existing pattern:

```php
if (!function_exists('url_to_postid')) {
    function url_to_postid(string $url): int {
        return $GLOBALS['_mock_url_to_postid'][$url] ?? 0;
    }
}
```

- [ ] **Step 1: Write the failing tests**

Cover: external host → null; resolvable post → `post/slug.md`; post of a disabled type → null; excluded/passworded/non-publish post → null; term URL → `taxonomy/category/slug.md`; term map built once (call twice, assert `get_terms` mock invocation count if the mock records calls — if not, skip the memoisation assertion); trailing-slash-insensitive term match. Use the existing mock-post/mock-term patterns from `GeneratorTest`/`TaxonomyArchiveGeneratorTest`.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Generator/InternalUrlResolverTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Resolves absolute site URLs to bundle-relative Markdown file paths.
 *
 * Posts resolve via url_to_postid(); taxonomy term archives via a lazily
 * built map of term links. Results mirror the path logic used by
 * Generator::get_export_path() and TaxonomyArchiveGenerator::get_export_path().
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class InternalUrlResolver {

	/** @var array<string, string>|null Term link (untrailingslashed) → bundle path. */
	private ?array $term_map = null;

	/** @var array<string, string|null> Memoised results keyed by URL. */
	private array $resolved = array();

	/**
	 * @since  1.6.0
	 * @param  array<string, mixed> $options Plugin options.
	 */
	public function __construct( private readonly array $options ) {}

	/**
	 * Resolve a URL to a bundle-relative path, or null if not an exported document.
	 *
	 * @since  1.6.0
	 * @param  string $url Absolute URL.
	 * @return string|null e.g. `post/my-post.md` or `taxonomy/category/climate.md`.
	 */
	public function resolve( string $url ): ?string {
		if ( array_key_exists( $url, $this->resolved ) ) {
			return $this->resolved[ $url ];
		}

		$this->resolved[ $url ] = $this->do_resolve( $url );

		return $this->resolved[ $url ];
	}

	private function do_resolve( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( empty( $host ) || strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== 0 ) {
			return null;
		}

		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			return $this->post_path( $post_id );
		}

		return $this->term_path( $url );
	}

	private function post_path( int $post_id ): ?string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$enabled = (array) ( $this->options['post_types'] ?? array() );

		if ( ! in_array( $post->post_type, $enabled, true )
			|| 'publish' !== $post->post_status
			|| '' !== $post->post_password
			|| get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
			return null;
		}

		return sanitize_file_name( $post->post_type ) . '/' . sanitize_file_name( $post->post_name ) . '.md';
	}

	private function term_path( string $url ): ?string {
		if ( null === $this->term_map ) {
			$this->term_map = $this->build_term_map();
		}

		return $this->term_map[ untrailingslashit( $url ) ] ?? null;
	}

	/** @return array<string, string> */
	private function build_term_map(): array {
		$map = array();

		foreach ( array_keys( get_taxonomies( array( 'public' => true ) ) ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );

				if ( is_wp_error( $link ) || ! is_string( $link ) ) {
					continue;
				}

				$map[ untrailingslashit( $link ) ] = 'taxonomy/'
					. sanitize_file_name( $term->taxonomy ) . '/'
					. sanitize_file_name( $term->slug ) . '.md';
			}
		}

		return $map;
	}
}
```

Add `wp_parse_url` / `untrailingslashit` stubs to the mocks if not already present (check first — `trailingslashit` exists; `untrailingslashit` may not).

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Generator/InternalUrlResolverTest.php` then full `composer test` (mock file changed — ensure nothing else broke).
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Generator/InternalUrlResolver.php tests/Unit/Generator/InternalUrlResolverTest.php tests/mocks/wordpress-mocks.php
git commit -m "feat: InternalUrlResolver maps site URLs to bundle paths"
```

---

### Task 5: Wire link rewriting into Generator

**Files:**
- Modify: `src/Generator/Generator.php`
- Modify: `src/Core/Plugin.php` (constructor wiring)
- Test: `tests/Unit/Generator/GeneratorTest.php`

Behaviour spec:
- New optional constructor param `?LinkRewriter $link_rewriter = null` (after `$taxonomy_generator`, keeping existing test constructions valid).
- In both `generate_post()` and `get_post_markdown()`: after the Topics section is appended, if `okf_compat` is truthy AND a rewriter is present, `$markdown = $this->link_rewriter->rewrite( $markdown );`.
- `build_topics_section()` needs NO change — it already emits absolute `.md` upload URLs, which is exactly the chosen link style. (Its links don't resolve through `InternalUrlResolver` anyway; leave them as built.)
- `Plugin::define_generator()`: instantiate the resolver ONCE, outside the closure, so its memoisation and term-map cache persist across links:

```php
$url_resolver  = new InternalUrlResolver( $options );
$link_rewriter = new LinkRewriter(
	fn( string $url ): ?string => $url_resolver->resolve( $url ),
	Options::get_export_base_url( $options )
);
```

  Pass `$link_rewriter` to the Generator constructor. Do NOT construct the resolver inside the closure — that would create a fresh instance per link and defeat the caching entirely.

- [ ] **Step 1: Write the failing tests**

Extend `GeneratorTest` (reuse its existing fixture style):
- `test_okf_compat_off_does_not_rewrite_links` — body containing an internal permalink stays untouched.
- `test_okf_compat_on_rewrites_internal_links_to_md_urls` — inject a `LinkRewriter` with a stub resolver + base URL; assert written file content contains the absolute `.md` upload URL.
- `test_no_rewriter_injected_is_a_noop_even_with_toggle_on` — guards the nullable param.

- [ ] **Step 2: Run to verify failures**

Run: `vendor/bin/phpunit tests/Unit/Generator/GeneratorTest.php`
Expected: new FAIL, existing PASS.

- [ ] **Step 3: Implement** (as specced above; `get_export_path` untouched)

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Generator/GeneratorTest.php` then `composer test`.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Generator/Generator.php src/Core/Plugin.php tests/Unit/Generator/GeneratorTest.php
git commit -m "feat: rewrite internal links to Markdown file URLs in generated output (toggle-gated)"
```

---

### Task 6: TaxonomyArchiveGenerator — `.md` body links + lifecycle actions

**Files:**
- Modify: `src/Generator/TaxonomyArchiveGenerator.php`
- Modify: `tests/mocks/wordpress-mocks.php` (record fired actions — see below)
- Test: `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php`

Behaviour spec:
- `build_body()`: when `okf_compat` on, each post line links to the post's `.md` file as an absolute upload URL: `{export_base_url}/{sanitized-type}/{sanitized-slug}.md` (via `Options::get_export_base_url()`, same segment sanitisation as `Generator::get_export_path`). Toggle off → absolute permalinks as today. Only posts of enabled post types get `.md` links; posts outside `post_types` keep their permalink (their file does not exist).
- Fire new actions so index regeneration can hook in (additive, mirrors the post-file actions):
  - `markdown_for_agents_taxonomy_file_generated( string $path, \WP_Term $term )` after a successful `generate_term()` write.
  - `markdown_for_agents_taxonomy_file_deleted( string $path, \WP_Term $term )` after a successful `delete_term_file()`.

**Mock prerequisite:** the current `do_action()` stub in `tests/mocks/wordpress-mocks.php` is a no-op, and `get_mock_actions()` returns *registered* callbacks, not fired actions. Extend the `do_action` stub to record fires and add a reader:

```php
function do_action(string $hook_name, mixed ...$args): void {
    $GLOBALS['_mock_fired_actions'][$hook_name][] = $args;
}

function get_mock_fired_actions(string $hook_name): array {
    return $GLOBALS['_mock_fired_actions'][$hook_name] ?? [];
}
```

Reset `$GLOBALS['_mock_fired_actions']` in `reset_mock_hooks()`. Run the FULL suite after this mock change — other tests construct around the previous no-op (e.g. `GeneratorTest` comments on it) and must stay green.

- [ ] **Step 1: Write the failing tests** — `.md`-URL body links (toggle on), unchanged body (toggle off), permalink kept for non-enabled post types, both actions fired (assert via the new `get_mock_fired_actions()`).

- [ ] **Step 2: Run to verify failures**

Run: `vendor/bin/phpunit tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php`

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run tests** — file PASS, then `composer test`.

- [ ] **Step 5: Commit**

```bash
git add src/Generator/TaxonomyArchiveGenerator.php tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php
git commit -m "feat: Markdown file links in taxonomy archives; fire taxonomy file actions"
```

---

### Task 7: IndexGenerator — content building

**Files:**
- Create: `src/Generator/IndexGenerator.php`
- Create: `tests/Unit/Generator/IndexGeneratorTest.php`

Behaviour spec (OKF §6 — always on, NOT gated by `okf_compat`):
- **Root `index.md`** (at export base): frontmatter block containing only `okf_version: "0.1"` (the sole place index frontmatter is permitted, §11), then:

```markdown
# Content

* [post](post/) - 42 documents
* [page](page/) - 7 documents

# Taxonomies

* [taxonomy](taxonomy/) - Term archives grouped by taxonomy
```

  Only enabled post types with ≥1 exported document appear. The `# Taxonomies` section appears only if the `taxonomy/` directory exists.
- **Per-post-type `{type}/index.md`**: no frontmatter; single section:

```markdown
# {Post type label}

* [Post Title](post-slug.md) - Excerpt text.
* [Another](another.md)
```

  Entries from batched `get_posts` (batch 100, mirror `Generator::generate_post_type` pagination; never `posts_per_page: -1`), published + eligible only, ordered by title. Description = stripped excerpt, omitted (no trailing ` - `) when empty.
- **`taxonomy/index.md`**: SPEC §6 + §9(3) make section headings a conformance requirement for reserved files — every index gets at least one heading. Here: `# Taxonomies` followed by `* [Category](category/) - {n} terms`.
- **`taxonomy/{tax}/index.md`**: `# {Taxonomy label}` heading, then `* [Term Name](term-slug.md) - Term description.`
- **Counts**: root-index document counts and the "≥1 exported document" test use a disk glob of `{type}/*.md` *excluding* `index.md` — consistent with what is actually on disk (excluded/passworded posts have no file) and with the Task 9 `status` fix.
- **Reserved-name guard**: before writing any `index.md`, check whether a *concept* file would claim that name (a published post with slug `index` for that post type). If so, skip that directory's index and, when `WP_DEBUG`, `error_log` a notice (follow the existing error_log pattern with phpcs ignore comment). Same check for `log` is unnecessary (we never write `log.md`) but never *delete* an existing `log.md`.
- Writes via injected `FileWriter`. Public API:

```php
public function __construct( array $options, FileWriter $file_writer ) {}
public function generate_all(): array;              // ['written' => int, 'skipped' => int]
public function generate_for_post_type( string $post_type ): bool;
public function generate_for_taxonomy( string $taxonomy ): bool;
public function generate_root(): bool;
public function generate_taxonomy_root(): bool;
public function delete_all(): int;                  // removes every index.md it manages
```

- New filter `markdown_for_agents_index_content( string $content, string $relative_path )` applied to each index body before write (additive escape hatch, consistent with the plugin's filter-everything approach).

- [ ] **Step 1: Write the failing tests** — root content shape (okf_version frontmatter, sections, counts), post-type listing with/without excerpts, ordering, taxonomy listings, reserved-slug skip, filter applied, `delete_all` removes only index files.

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/Unit/Generator/IndexGeneratorTest.php`

- [ ] **Step 3: Implement.** Keep each index builder a private method returning a string; write logic shared in one place.

- [ ] **Step 4: Run tests** — PASS, then `composer test`.

- [ ] **Step 5: Commit**

```bash
git add src/Generator/IndexGenerator.php tests/Unit/Generator/IndexGeneratorTest.php
git commit -m "feat: OKF index.md generation for root, post-type and taxonomy directories"
```

---

### Task 8: Index regeneration triggers (dirty-set + shutdown flush)

**Files:**
- Modify: `src/Generator/IndexGenerator.php`
- Modify: `src/Core/Plugin.php`
- Test: `tests/Unit/Generator/IndexGeneratorTest.php`

Behaviour spec:
- `IndexGenerator` gains hook-callback methods:
  - `on_file_generated( string $path, \WP_Post $post )` / `on_file_deleted( string $path, int $post_id )` → mark `{post_type}` dir + root dirty (derive post type from the path's parent directory name for the deleted case, since the post may be gone).
  - `on_taxonomy_file_generated( string $path, \WP_Term $term )` / `on_taxonomy_file_deleted(...)` → mark `taxonomy/{tax}`, `taxonomy`, and root dirty.
  - `flush_dirty(): void` → regenerate each dirty directory's index exactly once, then clear the set. Idempotent; no-op when the set is empty.
- `generate_all()` (Task 7) must also clear the dirty set — otherwise a CLI run that calls `generate_all()` explicitly (Task 9) gets every index rebuilt a second time by the `shutdown` flush (WP-CLI fires `shutdown` too). Add a test: mark dirty → `generate_all()` → `flush_dirty()` is a no-op.
- Rationale: within any single request (a save, an AJAX batch of N posts, a full CLI run) each affected index is rebuilt at most once, on `shutdown` — no O(n²) rebuild during bulk generation.
- `Plugin::define_generator()` wires:

```php
$index_generator = new IndexGenerator( $options, $this->file_writer );
$this->index_generator = $index_generator;

$this->loader->add_action( 'markdown_for_agents_file_generated', $index_generator, 'on_file_generated', 10, 2 );
$this->loader->add_action( 'markdown_for_agents_file_deleted', $index_generator, 'on_file_deleted', 10, 2 );
$this->loader->add_action( 'markdown_for_agents_taxonomy_file_generated', $index_generator, 'on_taxonomy_file_generated', 10, 2 );
$this->loader->add_action( 'markdown_for_agents_taxonomy_file_deleted', $index_generator, 'on_taxonomy_file_deleted', 10, 2 );
$this->loader->add_action( 'shutdown', $index_generator, 'flush_dirty' );
```

- [ ] **Step 1: Write the failing tests** — dirty marking per callback; `flush_dirty` regenerates each dir once and clears; second flush is a no-op; deleted-post path derives type from path.

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement**, including the Plugin wiring (add a `PluginTest`-style assertion only if a Plugin test file exists — check; if not, wiring is covered by the WP-CLI/manual smoke test in Task 11).

- [ ] **Step 4: Run** `composer test` — PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Generator/IndexGenerator.php src/Core/Plugin.php tests/Unit/Generator/IndexGeneratorTest.php
git commit -m "feat: regenerate index.md files via dirty-set flushed on shutdown"
```

---

### Task 9: WP-CLI integration

**Files:**
- Modify: `src/CLI/Commands.php`
- Modify: `src/Core/Plugin.php` (pass `IndexGenerator` into `Commands`)
- Test: extend the existing CLI coverage pattern (check whether `tests/Unit/` has a Commands test; if not, cover the index calls indirectly and rely on smoke testing)

Behaviour spec:
- New subcommand `wp markdown-agents generate-indexes` → `IndexGenerator::generate_all()`, prints written/skipped counts. Supports `--dry-run` (report what would be written; add a `dry_run` flag to `generate_all()` or a separate `plan()` method — pick the smaller diff).
- `generate` and `generate-taxonomies` call `IndexGenerator::generate_all()` once at the end of the run (explicit call — do not rely on the shutdown hook inside CLI; `generate_all()` clears the dirty set per Task 8 so the shutdown flush is then a no-op).
- **`status` fix (verified against `src/CLI/Commands.php:129-132`):** `status()` counts `glob( $type_dir . '/*.md' )`, so `{type}/index.md` would inflate `generated` by 1 per type and corrupt `missing` (could mask a genuinely missing post file). Exclude `index.md` from the per-type count. Add a separate "index files: N" line.
- **`delete --all` fix (verified):** it iterates `delete_type()` per enabled post type with the same glob — it WILL remove `{type}/index.md` but will NOT touch the root `index.md` or anything under `taxonomy/`. Call `IndexGenerator::delete_all()` as part of `delete --all` (this is required, not something to re-verify).
- Update the command docblocks (WP-CLI reads them for `--help`).

- [ ] **Step 1: Read `src/CLI/Commands.php` fully** (planning verified the glob claims above; read the whole file before writing tests to match its structure).

- [ ] **Step 2: Write failing tests** for whatever is testable in the existing pattern.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run** `composer test` — PASS.

- [ ] **Step 5: Commit**

```bash
git add src/CLI/Commands.php src/Core/Plugin.php tests/
git commit -m "feat: generate-indexes CLI command; indexes built after bulk generation"
```

---

### Task 10: Settings UI — OKF compatibility toggle

**Files:**
- Modify: `src/Admin/SettingsPage.php`
- Test: `tests/Unit/Admin/SettingsPageTest.php`

Behaviour spec:
- New checkbox "OKF compatibility mode" in the existing settings form, description: "Adds OKF frontmatter keys (timestamp, flat tags) and rewrites internal links to point at the Markdown file versions. Regenerate files after changing this." Follow the exact markup/registration pattern of the existing boolean options (e.g. `include_taxonomy_topics`).
- Sanitisation callback handles the new key as bool (mirror existing boolean handling — read the sanitise method first).

- [ ] **Step 1: Write the failing test** — sanitisation maps checkbox presence/absence to true/false; field rendered (whatever the existing test file asserts for other checkboxes).

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Unit/Admin/SettingsPageTest.php` then `composer test` — PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "feat: OKF compatibility mode setting"
```

---

### Task 11: Docs, version bump, full verification

**Files:**
- Modify: `README.md`, `readme.txt`, `markdown-for-agents.php`, `CLAUDE.md` (only if commands/architecture summary changed)

- [ ] **Step 1: README** — new "OKF (Open Knowledge Format)" section: what the toggle does, index files, link style (absolute `.md` upload URLs; the raw tree is not offline-traversable as an extracted directory — the future tarball bundle will carry relative links), new CLI command. Add rows to the Filters table: `markdown_for_agents_flat_tags`, `markdown_for_agents_index_content`; add the two new taxonomy file actions to the Actions table. Include a "Limitations" list: (a) links inside fenced code blocks are rewritten like any other; (b) a post relocated via the `markdown_for_agents_export_path` filter is not seen by the link resolver, so links to it point at the default path (OKF §5.3 tolerates broken links); (c) a site with a post slugged `index` or `log` keeps its existing concept file, which violates OKF §3.1 reserved names — such a corpus cannot be fully §9-conformant (existing behaviour wins by design); (d) rewritten links are domain-absolute, so a corpus copied to another domain needs regeneration.

- [ ] **Step 2: readme.txt** — changelog entry + description update; bump `Stable tag` to 1.6.0.

- [ ] **Step 3: Version bump** — `markdown-for-agents.php` header + `MARKDOWN_FOR_AGENTS_VERSION` to 1.6.0 (matches `readme.txt`; all three places per repo convention).

- [ ] **Step 4: Full verification**

Run: `composer test` — all PASS.
Run: `composer phpcs` — clean.
Smoke test on ddev (if site available): enable toggle, `wp markdown-agents generate && wp markdown-agents generate-taxonomies`, inspect a post file (timestamp + flat tags present, internal links rewritten to `.md` upload URLs and `curl`-able), **verify a link between two custom-post-type posts (e.g. clause → clause) is rewritten** (the `url_to_postid()` CPT risk from Task 4 — if it returns 0 for CPT permalinks, implement the fallback permalink map described there), `curl` an index.md from uploads, run `wp markdown-agents status` (index files not counted as generated posts), confirm toggle OFF regeneration restores byte-identical pre-existing output for a sample post.

- [ ] **Step 5: Commit**

```bash
git add README.md readme.txt markdown-for-agents.php CLAUDE.md
git commit -m "docs: OKF alignment documentation; bump to 1.6.0"
```

---

## Regression guarantees (verify before calling done)

1. Toggle OFF (default): a regenerated post file is byte-identical to pre-change output (frontmatter keys, order, body links). Covered by existing tests remaining green + smoke diff in Task 11.
2. Serving path (`Negotiator`) untouched — zero diff under `src/Negotiate/`.
3. `index.md` files are the ONLY new artefacts when toggle is off.
4. Posts slugged `index` keep their existing export file; no index overwrites it.
5. `composer test` and `composer phpcs` green at every commit.

## Out of scope (explicitly)

- ARD catalog (`/.well-known/ai-catalog.json`) — Phase 1 of the ARD/OKF plan doc; separate plan.
- `.tar.gz` bundle distribution — Phase 3; separate plan (depends on this one). That plan owns link relativisation: at build time it rewrites the absolute `.md` upload URLs this plan produces into relative bundle links (the inverse mapping is unambiguous — strip the export base URL), making the tarball offline-traversable per OKF §5.
- `log.md` update logs — optional in spec, no consumer demand yet (YAGNI).
- Manifest tracking of index files — revisit with the bundle work.

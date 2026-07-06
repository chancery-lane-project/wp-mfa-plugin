# OKF Bundle + ARD Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `.tar.gz` OKF bundle (Phase 3 of `docs/plans/2026-03-06-okf-ard-plan.md`) and an ARD catalog as a *generated, manually deployed* artefact (revised Phase 1), organised as a three-level progression in the settings page.

**Architecture:** New `BundleGenerator` (PharData, atomic rename, tree-state staleness hash, debounced wp-cron rebuild) packages the export tree with links rewritten from absolute `.md` upload URLs to OKF bundle-absolute form (`/post/slug.md`). New `Discovery\ArdCatalog` builds the `ai-catalog.json` document; the settings page displays it for the user to copy into a physical `/.well-known/ai-catalog.json` — the plugin serves NOTHING from `.well-known` and registers no routes/rewrites (zero collision surface, by explicit decision). Three cumulative toggles: OKF mode (built) → bundle → ARD.

**Tech Stack:** PHP 8.1, PharData (works with `phar.readonly=1` — that ini only restricts `.phar` executables, not tar/zip data archives), WP-Cron single events, PHPUnit 9.6 with WP mocks.

---

## Decisions signed off by Felix (2026-07-06)

Fixed; do not re-litigate:

1. **No serving code for `.well-known`.** No rewrite rules, no request interception, no physical-file writing outside uploads. The ARD catalog is generated content the site owner deploys manually; the settings page + README document how. This was chosen explicitly over routing approaches to avoid trampling other plugins/server config.
2. **ARD output is admin-display only.** No `ai-catalog.json` file is written anywhere. Because deployment is a manual copy, the catalog deliberately **omits** the ARD-optional `updatedAt` and `version` fields — the pasted file must not go stale when the bundle rebuilds. The bundle URL is stable across rebuilds (atomic replace at the same path).
3. **Cumulative gating:** bundle toggle requires `okf_compat` on; ARD toggle requires bundle on. Enforced server-side in sanitisation; rendered as disabled checkboxes with explanatory text when the prerequisite is off.
4. **Rebuild policy per the Phase 3 spec:** synchronous rebuild at the end of bulk/CLI generation; single-post saves mark the bundle stale and schedule a debounced wp-cron single event; manual `wp markdown-agents bundle`; `status` shows freshness.
5. **Bundle link form: bundle-absolute** (`/post/slug.md`, OKF §5.1 — the spec-RECOMMENDED form). Produced by stripping the export base URL prefix from link targets while adding files to the archive. Deterministic inverse of what `LinkRewriter` produces; position-independent (no per-file relative-path computation).
6. **Media type `application/okf-bundle+gzip`** (unregistered; Joost's de-facto value, tracked upstream in knowledge-catalog#111). Used in the ARD entry's `type` AND `mediaType` (ard-spec#27 field-name workaround: emit both, identical).
7. Version stays **1.6.0** — this lands on the unreleased `feat/okf-alignment` branch.

**Deviation from the Phase 3 spec doc (justified):** the spec says bundle freshness compares "manifest hash vs hash embedded at build time", but `manifest.json` only exists when generation runs `--with-manifest`. Instead, freshness uses a **tree-state hash**: `md5` of the sorted list of `(relative path, mtime, size)` for every file that would enter the bundle. Always computable, no manifest dependency, cheap (stat-only, no content reads).

## Bundle contents & exclusions

- Everything under `{export_base}/` EXCEPT: `changes.json` (sync delta, not content) and `ai-catalog.json` if one ever appears there (defensive).
- Includes: all `.md` (posts, taxonomy archives, indexes) and `manifest.json` when present.
- Archive paths are bundle-relative (`post/slug.md`, `taxonomy/…`, `index.md` at root).
- `.md` files have `]({export_base_url}/` rewritten to `](/` on the way in (link targets only — the `](` prefix anchors the replacement so frontmatter values like `permalink` are untouched).
- Output: `{uploads}/{export_dir}.tar.gz` (sibling of the export dir). Built as `{export_dir}.tar.gz.tmp-{pid}` (PharData builds `.tar` then `compress(Phar::GZ)`), then `rename()`d into place — a concurrent download never sees a partial archive. Intermediate `.tar` unlinked.

## New options

| Key | Default | Meaning |
|---|---|---|
| `bundle_enabled` | `false` | Level 2: build/maintain the tarball |
| `ard_enabled` | `false` | Level 3: show the ARD catalog panel |

Stored staleness state (not user options): `markdown_for_agents_bundle_hash` (option; tree-state hash at last build, deleted on uninstall).

## File structure

| File | Status | Responsibility |
|---|---|---|
| `src/Core/Options.php` | Modify | Two new defaults |
| `src/Generator/BundleGenerator.php` | Create | Build/compress/atomically-place the tarball; link rewrite on ingest; tree-hash; `is_stale()`; `delete()`; `mark_stale()`/`schedule_rebuild()`/cron callback |
| `src/Discovery/ArdCatalog.php` | Create | Build the catalog array + JSON (pure-ish; filterable) |
| `src/Core/Plugin.php` | Modify | Instantiate + wire staleness hooks and the cron action |
| `src/CLI/Commands.php` | Modify | `bundle` subcommand; end-of-run rebuild in `generate`/`generate-taxonomies`/`generate_single`; `status` freshness line; `delete --all` removes bundle |
| `src/Admin/Admin.php` | Modify | Thread BundleGenerator/ArdCatalog down to SettingsPage; synchronous bundle rebuild in the final-batch AJAX branches |
| `src/Admin/SettingsPage.php` | Modify | "Agent discovery (OKF / ARD)" section: move `okf_compat` field in, add the two gated toggles, ARD JSON panel + deployment instructions |
| `uninstall.php` | Modify | Delete bundle hash option; delete bundle file when `delete_files_on_uninstall` (creating that conditional — see Task 7) |
| `tests/mocks/wordpress-mocks.php` | Modify | Stubs: `wp_schedule_single_event`, `wp_next_scheduled`, `get_bloginfo` variants as needed |
| `tests/Unit/Generator/BundleGeneratorTest.php` | Create | Real tmp-tree archive tests |
| `tests/Unit/Discovery/ArdCatalogTest.php` | Create | Catalog shape tests |
| `tests/Unit/Admin/SettingsPageTest.php` | Modify | Gating + new field tests |
| `README.md`, `readme.txt` | Modify | Bundle + ARD docs, `.well-known` deployment instructions, changelog |

Conventions: strict_types, tabs, WPCS long `array()`, UK English, `@since 1.6.0`, no new phpcs violations, full suite green at every commit. Use `ExportPolicy` for any eligibility/path needs. Never import `League\HTMLToMarkdown\` unprefixed.

---

### Task 1: Options defaults

**Files:** Modify `src/Core/Options.php`; test `tests/Unit/Core/OptionsTest.php`.

- [ ] **Step 1:** Failing tests: `test_defaults_include_bundle_and_ard_disabled` asserting both keys exist and are false.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Unit/Core/OptionsTest.php` — FAIL.
- [ ] **Step 3:** Add `'bundle_enabled' => false,` and `'ard_enabled' => false,` after `okf_compat`.
- [ ] **Step 4:** File + full suite PASS.
- [ ] **Step 5:** Commit `feat: bundle_enabled and ard_enabled option defaults (off)`.

---

### Task 2: BundleGenerator — archive building (core)

**Files:** Create `src/Generator/BundleGenerator.php`; create `tests/Unit/Generator/BundleGeneratorTest.php`.

Public API:

```php
public function __construct( array $options ) {}
public function bundle_path(): string;            // {uploads}/{export_dir}.tar.gz
public function bundle_url(): string;             // public URL of same
public function build(): bool;                    // build + atomic place + store hash; false if export dir missing
public function is_stale(): bool;                 // no bundle || stored hash !== current tree hash
public function delete(): bool;                   // remove bundle + stored hash option
public function tree_hash(): string;              // md5 of sorted (relpath|mtime|size) lines
```

Behaviour spec:
- `build()`: iterate the export tree (RecursiveDirectoryIterator), skip `changes.json`/`ai-catalog.json`; for `.md` files call `addFromString( $rel, str_replace( '](' . $base_url . '/', '](/', $content ) )`; other files (`manifest.json`) `addFromString` verbatim. Build `PharData` at `{bundle_path}.tmp-{pid}.tar`, `compress(Phar::GZ)` → `.tar.gz`, `rename()` into `bundle_path()`, unlink both temporaries. Store `tree_hash()` in the `markdown_for_agents_bundle_hash` option. Wrap PharData work in try/catch — on `\Throwable`, clean temporaries, `error_log` under WP_DEBUG (existing idiom), return false.
- `tree_hash()`: stat-only; sorted lines `relpath|mtime|size`; md5. Empty/missing dir → hash of empty string.
- `is_stale()`: true when the bundle file is absent OR stored option ≠ `tree_hash()`.
- `bundle_path()`: `dirname( Options::get_export_base() ) . '/' . sanitize_file_name( export_dir ) . '.tar.gz'`; `bundle_url()` mirrors with `get_export_base_url()`'s parent.

Tests (real tmp tree, like FileWriter/IndexGenerator tests): build creates a valid gzip tar (`PharData` can reopen it and list expected entries); `changes.json` excluded; `.md` link rewritten to `](/post/other.md)` while `permalink:` frontmatter line untouched; `manifest.json` included verbatim; `is_stale()` false right after build, true after touching a file (set mtime forward explicitly — don't rely on sleep) or adding one; `delete()` removes file + option; missing export dir → `build()` false, no file.

- [ ] **Step 1:** Write failing tests. **Step 2:** RED. **Step 3:** Implement. **Step 4:** File + full suite green; `vendor/bin/phpcs src/Generator/BundleGenerator.php` clean. **Step 5:** Commit `feat: BundleGenerator builds OKF tarball with bundle-absolute links`.

---

### Task 3: Staleness triggers + debounced cron rebuild

**Files:** Modify `src/Generator/BundleGenerator.php`, `src/Core/Plugin.php`, `tests/mocks/wordpress-mocks.php`; test `tests/Unit/Generator/BundleGeneratorTest.php`.

Behaviour spec:
- `BundleGenerator::mark_stale_and_schedule(): void` — hook callback (accepts and ignores hook args): no-op unless `bundle_enabled`; deletes the stored hash option (cheap stale marker) and, if `! wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' )`, calls `wp_schedule_single_event( time() + 300, 'markdown_for_agents_rebuild_bundle' )`. The 5-minute delay + not-rescheduling-while-pending = the debounce: an editing session of N saves yields one rebuild.
- `on_rebuild_bundle(): void` — cron callback: no-op unless `bundle_enabled`; `build()`.
- `Plugin::define_generator()` wiring (unconditional registration, gated inside the callbacks — matches the plugin's existing pattern):
```php
$bundle_generator = new BundleGenerator( $options );
$this->bundle_generator = $bundle_generator;
$this->loader->add_action( 'markdown_for_agents_file_generated', $bundle_generator, 'mark_stale_and_schedule' );
$this->loader->add_action( 'markdown_for_agents_file_deleted', $bundle_generator, 'mark_stale_and_schedule' );
$this->loader->add_action( 'markdown_for_agents_taxonomy_file_generated', $bundle_generator, 'mark_stale_and_schedule' );
$this->loader->add_action( 'markdown_for_agents_taxonomy_file_deleted', $bundle_generator, 'mark_stale_and_schedule' );
$this->loader->add_action( 'markdown_for_agents_rebuild_bundle', $bundle_generator, 'on_rebuild_bundle' );
```
- Mocks: `wp_schedule_single_event` records into `$GLOBALS['_mock_scheduled_events'][]`; `wp_next_scheduled` reads it; reset alongside the other `_mock_*` globals. Follow file style. (No `wp_clear_scheduled_hook` stub — nothing calls it.)

Tests: mark_stale schedules exactly once across repeated calls (debounce); no scheduling when `bundle_enabled` off; cron callback builds when enabled, no-ops when not.

- [ ] Steps 1–5 as usual. Commit `feat: debounced bundle rebuild on content changes`.

---

### Task 4: CLI + admin bulk-generation integration

**Files:** Modify `src/CLI/Commands.php`, `src/Admin/Admin.php`, `src/Core/Plugin.php` (pass BundleGenerator into Commands — nullable last param, established pattern — and into Admin, see below).

Behaviour spec:
- New `bundle` subcommand: errors politely when `bundle_enabled` off or generator null; `--if-stale` flag skips when fresh; success message with path + size (`size_format` if mocked-able, else bytes).
- `generate`, `generate_single`, `generate_taxonomies`: after `rebuild_indexes()`, when `bundle_enabled` and non-dry-run: `build()` + log line. Private `rebuild_bundle()` helper mirroring `rebuild_indexes()`.
- **Admin AJAX bulk generation** (Decision 4's "synchronous at end of bulk generation" applies to the Settings-page "Generate all" path too — the surface non-CLI users actually use): in `Admin::handle_generate_batch_ajax()`'s EXISTING final-batch branch (line ~189: `if ( ( $offset + $limit ) >= (int) $result['total'] )`), call `BundleGenerator::build()` when `bundle_enabled`. **`handle_generate_taxonomy_batch_ajax()` has NO such branch today** (verified — it sends the result straight back); introduce the same `( $offset + $limit ) >= (int) $result['total']` condition there, gating only the new bundle-build call (do not otherwise change its response flow). `Admin` gains the BundleGenerator as a nullable trailing constructor param; `Plugin::define_admin_hooks()` passes `$this->bundle_generator` (available — `define_generator()` runs first in `define_hooks()`).
- `status`: when `bundle_enabled`, log bundle freshness — disambiguating the mid-debounce window (a save deletes the hash immediately, so "stale" alone is ambiguous): `Bundle: fresh ({path})` | `Bundle: stale — rebuild scheduled ({path})` (when `wp_next_scheduled( 'markdown_for_agents_rebuild_bundle' )` is truthy) | `Bundle: stale — no rebuild scheduled ({path})` | `Bundle: missing`.
- `delete --all`: `BundleGenerator::delete()` + count line.
- Docblocks updated (WP-CLI `--help`).

Tests: as feasible given no WP_CLI stub (same posture as Task 9 of the previous plan — cover what's testable in BundleGenerator; note the CLI gap).

- [ ] Steps 1–5. Commit `feat: bundle CLI command; bundle rebuilt after bulk generation`.

---

### Task 5: ArdCatalog

**Files:** Create `src/Discovery/ArdCatalog.php`; create `tests/Unit/Discovery/ArdCatalogTest.php`.

```php
namespace Tclp\WpMarkdownForAgents\Discovery;

class ArdCatalog {
	public function __construct( private readonly array $options, private readonly BundleGenerator $bundle_generator ) {}
	public function build(): array;
	public function to_json(): string;   // wp_json_encode( build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
}
```

`build()` returns:

```php
array(
	'specVersion' => '1.0',
	'host'        => array(
		'displayName' => get_bloginfo( 'name' ),
		'identifier'  => wp_parse_url( home_url(), PHP_URL_HOST ),
	),
	'entries'     => array(
		array(
			'identifier'  => 'urn:air:' . $host . ':knowledge:markdown-bundle',
			'displayName' => get_bloginfo( 'name' ) . ' Markdown knowledge bundle',
			'description' => 'OKF-structured Markdown export of site content, packaged as a downloadable bundle.',
			'type'        => 'application/okf-bundle+gzip',
			'mediaType'   => 'application/okf-bundle+gzip',
			'url'         => $this->bundle_generator->bundle_url(),
		),
	),
);
```

then `apply_filters( 'markdown_for_agents_ai_catalog', $catalog )` (documented, @since 1.6.0). NO `updatedAt`/`version` (Decision 2). `type`/`mediaType` duplication per Decision 6.

Tests: shape assertions (specVersion, host from mocked bloginfo/home_url, URN format, both media-type fields identical, bundle URL); filter override via the `$GLOBALS['_mock_apply_filters']` pattern; JSON is valid and pretty-printed with unescaped slashes.

- [ ] Steps 1–5. Commit `feat: ARD ai-catalog document builder`.

---

### Task 6: Settings UI — three-level section

**Files:** Modify `src/Admin/SettingsPage.php`; test `tests/Unit/Admin/SettingsPageTest.php`.

Behaviour spec:
- New settings section `markdown_for_agents_discovery` titled "Agent discovery (OKF / ARD)" with intro text. MOVE the existing `okf_compat` field registration into it (renderer unchanged); ADD `bundle_enabled` ("Build downloadable bundle (.tar.gz)") and `ard_enabled` ("ARD catalog for /.well-known/") checkbox fields following the established pattern.
- Cumulative gating, both layers:
  - Render: `bundle_enabled` checkbox gets `disabled` attribute when `okf_compat` off (+ hint "Enable OKF compatibility mode first."); same for `ard_enabled` vs `bundle_enabled`.
  - Sanitise (after the `okf_compat` line so `$clean['okf_compat']` exists): `$clean['bundle_enabled'] = $clean['okf_compat'] && ! empty( $input['bundle_enabled'] );` and `$clean['ard_enabled'] = $clean['bundle_enabled'] && ! empty( $input['ard_enabled'] );` (disabled inputs don't submit, so this also covers the UI case).
  - `maybe_flag_regeneration()`: add `bundle_enabled` to the changed-comparison (flipping it on warrants a rebuild); `ard_enabled` does NOT flag (display-only).
- ARD panel: when `ard_enabled` is on, `ard_enabled` field renderer additionally prints:
  - `<textarea readonly>` containing `ArdCatalog::to_json()` (esc_textarea), sized sensibly;
  - deployment instructions: create `/.well-known/ai-catalog.json` at the web root containing this JSON (manual copy; or symlink to a file you manage); note that the content is deliberately stable across bundle rebuilds so the copy does not go stale; note the plugin never serves this path itself.
  - **Construction chain (no existing nullable pattern on SettingsPage — create it):** `SettingsPage::__construct( array $options, Generator $generator )` today, instantiated inside `Admin::__construct()`, which `Plugin::define_admin_hooks()` builds with `( $options, $generator, $taxonomy_generator )`. Add a nullable trailing `?ArdCatalog $ard_catalog = null` to BOTH `Admin` and `SettingsPage` constructors, with `Admin` passing it through to `new SettingsPage(...)`, and `Plugin::define_admin_hooks()` constructing `new ArdCatalog( $options, $this->bundle_generator )` (bundle generator exists by then — `define_generator()` runs first). Null ArdCatalog → the panel renders a fallback notice instead of JSON.

Tests: gating sanitisation (bundle on + okf off → false; ard on + bundle off → false; all on → all true); regen flagged on bundle toggle, NOT on ard toggle; fields registered in the new section; ARD JSON textarea rendered only when enabled.

- [ ] Steps 1–5. Commit `feat: three-level agent discovery settings section with ARD catalog panel`.

---

### Task 7: Uninstall, docs, verification

**Files:** Modify `uninstall.php`, `README.md`, `readme.txt`.

- [ ] **Step 1:** `uninstall.php`. **Current reality (verified):** the file is unconditional — it only calls `delete_option( 'markdown_for_agents_options' )`; the `delete_files_on_uninstall` option exists in defaults/sanitisation but is consumed NOWHERE (pre-existing dead option; the export tree is never deleted on uninstall). Scope here: read the saved options BEFORE deleting them; always `delete_option( 'markdown_for_agents_bundle_hash' )`; when `delete_files_on_uninstall` is truthy, unlink `{uploads}/{export_dir}.tar.gz` (compute the path from the saved `export_dir` value without loading plugin classes — uninstall.php runs standalone). Do NOT take on deleting the whole export tree — that's the pre-existing gap; record it as a follow-up task instead.
- [ ] **Step 2:** README: extend the OKF section with "Bundle (.tar.gz)" (contents, bundle-absolute links per OKF §5.1, rebuild policy incl. the 5-minute debounce — with the caveat that the delay assumes default WP-Cron page-load triggering; on sites with `DISABLE_WP_CRON` set, rebuilds fire on the next system-cron hit to `wp-cron.php` — `wp markdown-agents bundle`, stats caveat: downloads bypass PHP so they don't appear in access statistics) and "ARD discovery" (three-level toggles; the plugin generates the catalog but the site owner deploys it manually to `/.well-known/ai-catalog.json` — no routes registered, by design; symlink option; unregistered media type note). Filters table: `markdown_for_agents_ai_catalog`. CLI examples: `bundle`, `bundle --if-stale`.
- [ ] **Step 3:** readme.txt: extend the 1.6.0 changelog entry + features list.
- [ ] **Step 4:** Full verification: `composer test` green; `composer phpcs` no new violations vs baseline; ddev smoke test — enable all three levels, `wp markdown-agents generate && wp markdown-agents bundle`, extract the tarball and verify: tree matches disk (minus changes.json), a clause file's internal link reads `](/clause/…​.md)` and resolves within the extracted dir, root `index.md` present; `status` shows bundle fresh; touch a post → save → single cron event queued (`wp cron event list`); copy the admin JSON, validate with `jq`; confirm toggle-off leaves no bundle behaviour.
- [ ] **Step 5:** Commit `docs: bundle and ARD documentation` and push.

## Regression guarantees

1. All three new behaviours off by default; suite green throughout; `src/Negotiate/` zero-diff (still).
2. No rewrite rules, no `.well-known` handling, no writes outside uploads.
3. Existing OKF output unchanged (bundle reads the tree, never mutates it).

## Out of scope

- MCP server catalog entry (site-specific; via the `markdown_for_agents_ai_catalog` filter when ready).
- Download statistics for the bundle (bypasses PHP by design; documented).
- Serving `/.well-known/ai-catalog.json` from the plugin (explicitly rejected).

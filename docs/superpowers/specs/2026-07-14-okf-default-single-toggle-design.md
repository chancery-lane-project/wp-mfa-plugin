# OKF Compliance by Default, Single Bundle Toggle

**Date:** 2026-07-14
**Status:** Approved

## Problem

The plugin currently exposes three cascading settings checkboxes (`Settings → Markdown for Agents → Agent discovery (OKF / ARD)`):

- `okf_compat` — adds `timestamp` and flat cross-taxonomy `tags` frontmatter keys, and rewrites internal links to point at `.md` file versions.
- `bundle_enabled` — packages the export tree into a downloadable `.zip`, with links further rewritten to relative form inside the archive. Requires `okf_compat` on (enforced both by a disabled checkbox and by server-side sanitization).
- `ard_enabled` — renders an ARD (`ai-catalog.json`) discovery document in a settings-page panel. Requires `bundle_enabled` on.

Having reviewed the OKF/ai-catalog output, the first checkbox no longer makes sense as an opt-in: OKF-compliant frontmatter and link rewriting should simply be what the plugin always produces. The three-level cascade also splits "build the zip" from "build the manifest" from "show the catalog," when in practice a site either wants the full downloadable/discoverable bundle or doesn't.

## Approach

### 1. OKF-compliant output becomes unconditional (remove `okf_compat`)

**`src/Generator/FrontmatterBuilder.php`** (~line 94): remove the `if ( ! empty( $this->options['okf_compat'] ) )` guard around the `timestamp` and flat `tags` block. Both are always computed and added, joining `type` (already unconditional, `FrontmatterBuilder.php` top of `build()`) as permanent core frontmatter.

**`src/Generator/Generator.php`** (lines ~68 and ~118, two call sites): remove the `! empty( $this->options['okf_compat'] )` half of `if ( ! empty( $this->options['okf_compat'] ) && null !== $this->link_rewriter )`, leaving `if ( null !== $this->link_rewriter )`. `$link_rewriter` is already unconditionally constructed and passed in by `Plugin.php` (lines ~99-115), so in practice this makes link rewriting to `.md` URLs always run — the null-check remains only because the constructor parameter is still typed nullable (no reason to change that signature for this).

**`src/Generator/TaxonomyArchiveGenerator.php`** (~lines 295-315): remove the `$okf_compat = ! empty( $this->options['okf_compat'] );` variable and its use in the `if ( $okf_compat && in_array(...) )` condition, leaving `if ( in_array( $post->post_type, $enabled_post_types, true ) )`. Taxonomy archive links to enabled post types always point at `.md` URLs; other post types keep falling back to `get_permalink()` as today.

### 2. Delete `okf_compat` and `ard_enabled` options entirely

Per this codebase's existing convention (no compat shims for confirmed-unused things), these aren't kept as dead always-true flags:

- **`src/Core/Options.php`** `get_defaults()`: remove the `'okf_compat' => false` and `'ard_enabled' => false` entries. `'bundle_enabled' => false` remains.
- **`src/Admin/SettingsPage.php`**:
  - `sanitize_options()`: replace the three-line cascade
    ```php
    $clean['okf_compat']     = ! empty( $input['okf_compat'] );
    $clean['bundle_enabled'] = $clean['okf_compat'] && ! empty( $input['bundle_enabled'] );
    $clean['ard_enabled']    = $clean['bundle_enabled'] && ! empty( $input['ard_enabled'] );
    ```
    with a single line: `$clean['bundle_enabled'] = ! empty( $input['bundle_enabled'] );`
  - Remove `field_okf_compat()` and its field registration (previously id `markdown_for_agents_okf_compat`, line ~100).
  - Remove `field_ard_enabled()` and its field registration (previously id `markdown_for_agents_ard_enabled`, line ~102) as a standalone checkbox. Its `render_ard_panel()` logic (the ARD JSON textarea + deployment instructions) is preserved but now called directly from `field_bundle_enabled()` whenever `$this->options['bundle_enabled']` is true — see §3.
  - `maybe_flag_regeneration()` (~lines 240-246): drop the `okf_compat` comparison; keep the `bundle_enabled` comparison as the sole regeneration-triggering change (frontmatter/link-rewriting output no longer varies by option, so no option toggle other than `bundle_enabled` changes generated file content).
  - Rewrite `section_discovery_intro()` (~line 301): drop the "these three toggles are cumulative" cascade language; new copy explains OKF-compliant Markdown is always produced, and the one remaining toggle adds a downloadable bundle.
  - Rename the settings section from "Agent discovery (OKF / ARD)" — keep as-is or lightly retitle (e.g. "Bundle & discovery") since it now covers one toggle; not a functional change, just cleanup for consistency with the reduced scope.
- **`src/Discovery/ArdCatalog.php`**: no code change — it was already unconditional internally (`build()`/`to_json()` never checked any option). Only its *display* was gated; that gating moves as described in §3.

### 3. `bundle_enabled` becomes the single remaining checkbox

**`src/Admin/SettingsPage.php`** `field_bundle_enabled()` (~line 459):
- Remove the `$disabled = empty( $this->options['okf_compat'] );` / `disabled( $disabled, true )` logic — the checkbox is never disabled now.
- Update the label to `'Build downloadable bundle (.zip + manifest)'` and description to explain it produces the zip archive, `manifest.json`, and (per below) the ARD catalog panel.
- After the checkbox markup, if `$this->options['bundle_enabled']` is true, call `render_ard_panel()` (the same method previously invoked from `field_ard_enabled()`) so the ARD JSON textarea and deployment instructions display "as standard" whenever bundling is on — no separate toggle gates it.

### 4. Manifest generation wired into every bundle-rebuild path

Manifest generation (`src/Generator/ManifestGenerator.php`) is currently CLI-only, invoked transiently inside `src/CLI/Commands.php` behind `--with-manifest`/`--incremental`, and never wired to the admin AJAX or cron rebuild paths.

**Centralized in `BundleGenerator::build()`** (`src/Generator/BundleGenerator.php`, ~line 72 onward, the method that currently opens the `PharData` and adds entries): before building the archive, `build()` now also runs manifest generation for the export tree and includes `manifest.json` in the same way it already does for a pre-existing one ("manifest.json when present" per current bundling logic — this makes it always present). Concretely: `BundleGenerator` gains a `ManifestGenerator` collaborator (constructor-injected, following the existing pattern by which it already takes `$options`), and `build()` calls `$this->manifest_generator->generate(...)` for the export tree before assembling the zip.

This means every caller of `BundleGenerator::build()` gets manifest generation for free with no per-caller duplication:
- Admin AJAX final-batch cleanup (`src/Admin/Admin.php`, `maybe_rebuild_bundle()`, ~line 278).
- The debounced cron rebuild (`src/Generator/BundleGenerator.php`, `on_rebuild_bundle()` / `mark_stale_and_schedule()` cron callback).
- The CLI `wp markdown-agents bundle` command (`src/CLI/Commands.php`, ~line 143 onward).

**CLI `--with-manifest`/`--incremental` flags on `generate`/`generate-taxonomies` are unaffected** — they remain independent, standalone manifest-generation entry points for users who want `manifest.json` without necessarily building the bundle. The bundle-triggered manifest generation is additive, not a replacement for that flag.

### 5. Tests

- **`FrontmatterBuilderTest.php`**: remove/update any test asserting `timestamp`/`tags` are absent when `okf_compat` is false — that state no longer exists. Existing "present when true" tests become the only-path assertions (option no longer passed).
- **`GeneratorTest.php`** and **`TaxonomyArchiveGeneratorTest.php`**: same — remove the "link rewriting off when `okf_compat` false" cases; keep/promote the "on" case as the only behavior.
- **`SettingsPageTest.php`**: remove tests for `field_okf_compat()`/`field_ard_enabled()` and the three-way sanitize cascade; add a test that `field_bundle_enabled()` renders the ARD panel content when `bundle_enabled` is true and omits it when false; update the regeneration-flag test to drop the `okf_compat` case.
- **`BundleGeneratorTest.php`**: add coverage that `build()` produces a `manifest.json` entry in the resulting archive (extending the existing `read_archive_entries()` helper assertions).
- **`ArdCatalogTest.php`**: unaffected in substance (the class was already unconditional) — confirm no test there references the removed options.
- **`CommandsTest.php`**: confirm `--with-manifest`/`--incremental` CLI paths are untouched by this change; add/update a case confirming `wp markdown-agents bundle` now also produces `manifest.json`.

### 6. Documentation

- **`README.md`**: rewrite the "OKF (Open Knowledge Format)" section (~lines 148-178) to drop the three-toggle cascade description. New structure: OKF-compliant frontmatter and link rewriting are always on (no toggle); the "Build downloadable bundle" toggle adds the `.zip` + `manifest.json` + ARD catalog panel, all together. Update the settings table (~lines 78-79) to remove the "OKF compatibility mode" row and revise the "Build downloadable bundle" row's description accordingly. Update the feature bullet at line 31 and the `markdown_for_agents_flat_tags` filter doc (~line 246) to drop "(OKF compatibility mode)" since it's no longer a mode.
- **`readme.txt`**: update the feature bullets (~lines 48-50) and add a changelog entry describing the removal of `okf_compat`/`ard_enabled` and the new default-on behavior, per this project's convention of bumping changelog/version together with such a behavior change (`CLAUDE.md`: "Version lives in three places... Bump all together").
- **Version bump**: this is a breaking behavioral change for any site with `okf_compat` off today (their generated files will change on next regenerate). Per `CLAUDE.md` conventions, bump `MARKDOWN_FOR_AGENTS_VERSION` / plugin header / `readme.txt` stable tag together, and note in the changelog that regeneration is required to see the new default frontmatter/links on existing sites (mirroring how the original `okf_compat` addition was itself flagged as regeneration-triggering).

## Out of scope

- No change to the `.zip` bundle's internal relative-link rewriting (unaffected — already independent of `okf_compat`).
- No change to `ManifestGenerator`'s internal hashing/delta logic — only where/when it's invoked.
- No change to the ARD catalog's JSON schema or content (`ArdCatalog::build()`) — only its display gating.

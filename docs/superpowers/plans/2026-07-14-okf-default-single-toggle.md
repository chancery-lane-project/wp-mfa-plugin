# OKF Default Frontmatter + Single Bundle Toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make OKF-compliant frontmatter (`timestamp`, flat `tags`) and `.md`-link rewriting unconditional defaults, delete the `okf_compat`/`ard_enabled` options, collapse the settings UI to a single `bundle_enabled` checkbox that builds the zip + `manifest.json` + shows the ARD catalog panel together, and wire manifest generation into every bundle-rebuild path.

**Architecture:** Remove three `okf_compat`/`link_rewriter`-gated branches (FrontmatterBuilder, Generator, TaxonomyArchiveGenerator) so their OKF behavior is unconditional. Delete the two retired options and their settings-page cascade. Extract the existing `Commands::generate_manifest()` per-post-type manifest orchestration into a new `Generator::write_manifests()` method, and call it from every place that rebuilds the bundle (CLI `bundle` command, CLI `rebuild_bundle()` helper, admin AJAX `maybe_rebuild_bundle()`, and the cron rebuild hook) before `BundleGenerator::build()`.

**Tech Stack:** PHP 8.1+, PHPUnit 9.6, WordPress plugin conventions (see `CLAUDE.md`).

**Spec:** `docs/superpowers/specs/2026-07-14-okf-default-single-toggle-design.md`

---

## Phase A — OKF-compliant output becomes unconditional

### Task A1: FrontmatterBuilder always adds `timestamp` and flat `tags`

**Files:**
- Modify: `src/Generator/FrontmatterBuilder.php:94-120`
- Test: `tests/Unit/Generator/FrontmatterBuilderTest.php`

- [ ] **Step 1: Delete the now-obsolete "off" test**

  Delete `test_okf_compat_off_produces_no_timestamp_or_flat_tags_changes` (`FrontmatterBuilderTest.php:277-286` approx.) — this test asserts behavior that no longer exists once the gate is removed.

- [ ] **Step 2: Update the remaining "on" tests to drop the option**

  In `test_okf_compat_on_adds_timestamp_mirroring_modified`, `test_okf_compat_on_no_timestamp_when_modified_empty`, `test_okf_compat_on_flat_tags_across_taxonomies_deduped`, `test_okf_compat_on_builds_flat_tags_even_when_include_taxonomies_off`, and `test_flat_tags_filter_applied`: remove the `'okf_compat' => true,` line from each test's options array (the behavior is now unconditional, so the key is unnecessary). Rename the four `test_okf_compat_on_*` methods to drop the `okf_compat_on_` prefix, e.g. `test_okf_compat_on_adds_timestamp_mirroring_modified` → `test_adds_timestamp_mirroring_modified`. Leave `test_flat_tags_filter_applied`'s name as-is (it isn't an okf_compat-named test).

- [ ] **Step 3: Run tests to confirm the renamed/updated tests still pass against current (unmodified) production code**

  Run: `vendor/bin/phpunit tests/Unit/Generator/FrontmatterBuilderTest.php`
  Expected: PASS — production code is unchanged in this step, and these tests were already exercising the `okf_compat => true` path, which still exists.

- [ ] **Step 4: Remove the `okf_compat` gate in production code**

  In `src/Generator/FrontmatterBuilder.php`, replace:
  ```php
  		if ( ! empty( $this->options['okf_compat'] ) ) {
  			if ( '' !== $frontmatter['modified'] ) {
  				$frontmatter['timestamp'] = $frontmatter['modified'];
  			}

  			if ( null === $collected ) {
  				$collected = $this->taxonomy_collector->collect( $post->ID, $post->post_type );
  			}

  			$flat = array();
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
  with:
  ```php
  		if ( '' !== $frontmatter['modified'] ) {
  			$frontmatter['timestamp'] = $frontmatter['modified'];
  		}

  		if ( null === $collected ) {
  			$collected = $this->taxonomy_collector->collect( $post->ID, $post->post_type );
  		}

  		$flat = array();
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
  ```
  (i.e. drop the `if ( ! empty( $this->options['okf_compat'] ) ) {` wrapper and its closing `}`, de-indenting the body by one level.)

- [ ] **Step 5: Run tests again to confirm nothing broke**

  Run: `vendor/bin/phpunit tests/Unit/Generator/FrontmatterBuilderTest.php`
  Expected: PASS (same tests as step 3, now against updated production code — this confirms the gate removal didn't change behavior for the `true` path, and there's no more `false` path to test).

- [ ] **Step 6: Commit**

  ```bash
  git add src/Generator/FrontmatterBuilder.php tests/Unit/Generator/FrontmatterBuilderTest.php
  git commit -m "feat: always add OKF timestamp and flat tags to frontmatter"
  ```

---

### Task A2: Generator always rewrites internal links to `.md` URLs

**Files:**
- Modify: `src/Generator/Generator.php:29, 40, 68-70, 118-120`
- Test: `tests/Unit/Generator/GeneratorTest.php`

- [ ] **Step 1: Delete the obsolete "off" test**

  Delete `test_okf_compat_off_does_not_rewrite_links` (`GeneratorTest.php:704-728` approx.).

- [ ] **Step 2: Update and rename the remaining tests**

  In `test_okf_compat_on_rewrites_internal_links_to_md_urls` and `test_no_rewriter_injected_is_a_noop_even_with_toggle_on`: remove the `'okf_compat' => true,` line from each options array. Rename `test_okf_compat_on_rewrites_internal_links_to_md_urls` → `test_rewrites_internal_links_to_md_urls`. Rename `test_no_rewriter_injected_is_a_noop_even_with_toggle_on` → `test_no_rewriter_injected_is_a_noop`.

- [ ] **Step 3: Run tests — confirm pass against unmodified production code**

  Run: `vendor/bin/phpunit tests/Unit/Generator/GeneratorTest.php`
  Expected: PASS.

- [ ] **Step 4: Remove the `okf_compat` half of the guard at both call sites**

  In `src/Generator/Generator.php`, at both line ~68 and line ~118, replace:
  ```php
  		if ( ! empty( $this->options['okf_compat'] ) && null !== $this->link_rewriter ) {
  ```
  with:
  ```php
  		if ( null !== $this->link_rewriter ) {
  ```
  (Two occurrences — same replacement at both sites. Leave the `$link_rewriter` constructor property and its nullable type at lines 29/40 unchanged — no reason to touch the signature for this.)

- [ ] **Step 5: Run tests again**

  Run: `vendor/bin/phpunit tests/Unit/Generator/GeneratorTest.php`
  Expected: PASS.

- [ ] **Step 6: Commit**

  ```bash
  git add src/Generator/Generator.php tests/Unit/Generator/GeneratorTest.php
  git commit -m "feat: always rewrite internal links to .md URLs when a rewriter is present"
  ```

---

### Task A3: TaxonomyArchiveGenerator always links enabled post types to `.md` URLs

**Files:**
- Modify: `src/Generator/TaxonomyArchiveGenerator.php:302, 309`
- Test: `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php`

- [ ] **Step 1: Delete the obsolete "off" test**

  Delete `test_okf_compat_off_body_keeps_permalinks` (`TaxonomyArchiveGeneratorTest.php:277-301` approx.).

- [ ] **Step 2: Update and rename the remaining tests**

  In `test_okf_compat_on_body_links_to_md_files` and `test_okf_compat_on_keeps_permalink_for_non_enabled_post_type`: remove the `'okf_compat' => true,` line from each options array. Rename to `test_body_links_to_md_files` and `test_keeps_permalink_for_non_enabled_post_type` respectively.

- [ ] **Step 3: Run tests — confirm pass against unmodified production code**

  Run: `vendor/bin/phpunit tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php`
  Expected: PASS.

- [ ] **Step 4: Remove the `$okf_compat` variable and its use**

  In `src/Generator/TaxonomyArchiveGenerator.php`, replace:
  ```php
  		$okf_compat         = ! empty( $this->options['okf_compat'] );
  		$enabled_post_types = ExportPolicy::enabled_post_types( $this->options );

  		foreach ( $posts as $post ) {
  			$title   = wp_strip_all_tags( $post->post_title );
  			$excerpt = wp_strip_all_tags( $post->post_excerpt );

  			if ( $okf_compat && in_array( $post->post_type, $enabled_post_types, true ) ) {
  ```
  with:
  ```php
  		$enabled_post_types = ExportPolicy::enabled_post_types( $this->options );

  		foreach ( $posts as $post ) {
  			$title   = wp_strip_all_tags( $post->post_title );
  			$excerpt = wp_strip_all_tags( $post->post_excerpt );

  			if ( in_array( $post->post_type, $enabled_post_types, true ) ) {
  ```

- [ ] **Step 5: Run tests again**

  Run: `vendor/bin/phpunit tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php`
  Expected: PASS.

- [ ] **Step 6: Commit**

  ```bash
  git add src/Generator/TaxonomyArchiveGenerator.php tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php
  git commit -m "feat: always link enabled post types to .md URLs in taxonomy archives"
  ```

---

## Phase B — Delete the retired options and collapse the settings UI

**Tasks B1 and B2 must be executed back-to-back without a review/pause checkpoint in between.** Task B1's commit intentionally leaves `composer test` red (`SettingsPageTest.php` fails against the removed option defaults) as a deliberate intermediate step, not a stopping point — if execution is interrupted after B1's commit and before B2's, the repository sits at a broken `HEAD` with no passing-suite commit to fall back to short of reverting B1. Do not treat the B1 commit as a place to end a session, request review, or hand off — proceed directly into B2 in the same sitting.

### Task B1: Remove `okf_compat`/`ard_enabled` from `Options::get_defaults()`

**Files:**
- Modify: `src/Core/Options.php:42-44`
- Test: none directly (covered transitively by SettingsPageTest changes in B2)

- [ ] **Step 1: Remove the two option defaults**

  In `src/Core/Options.php`, remove lines:
  ```php
  			'okf_compat'                => false,
  ```
  and
  ```php
  			'ard_enabled'               => false,
  ```
  Leave `'bundle_enabled' => false,` in place.

- [ ] **Step 2: Run the full suite to find every place that now breaks**

  Run: `composer test`
  Expected: FAIL — `SettingsPageTest.php` and possibly others will fail on references to the removed keys. This is expected; Task B2 fixes `SettingsPage.php` and its tests. Do not fix failures here — just confirm they're the expected `okf_compat`/`ard_enabled`-related ones before moving to B2.

- [ ] **Step 3: Commit**

  ```bash
  git add src/Core/Options.php
  git commit -m "feat: remove okf_compat and ard_enabled option defaults"
  ```

---

### Task B2: Collapse SettingsPage to a single `bundle_enabled` checkbox

**Files:**
- Modify: `src/Admin/SettingsPage.php` (sections/fields registration ~lines 93-102, `sanitize_options()` ~lines 172-174, `maybe_flag_regeneration()` ~line 245, `section_discovery_intro()` ~line 431-433, `field_okf_compat()` ~lines 440-449, `field_bundle_enabled()` ~lines 459-472, `field_ard_enabled()` ~lines 485-501)
- Test: `tests/Unit/Admin/SettingsPageTest.php`

- [ ] **Step 1: Delete tests for removed fields and cascade behavior**

  Delete these tests entirely:
  - `test_register_adds_okf_compat_field`
  - `test_sanitize_okf_compat_cast_to_bool`
  - `test_sanitize_flags_regen_when_okf_compat_changes`
  - `test_sanitize_bundle_enabled_requires_okf_compat`
  - `test_sanitize_ard_enabled_requires_bundle_enabled`
  - `test_sanitize_all_three_discovery_toggles_on`
  - `test_field_bundle_enabled_disabled_when_okf_compat_off`
  - `test_field_bundle_enabled_not_disabled_when_okf_compat_on`
  - `test_field_ard_enabled_disabled_when_bundle_enabled_off`

- [ ] **Step 2: Rewrite `test_register_adds_discovery_fields`**

  Replace its three-field assertion (`markdown_for_agents_okf_compat`, `markdown_for_agents_bundle_enabled`, `markdown_for_agents_ard_enabled`) with a single assertion that `register()` adds only `markdown_for_agents_bundle_enabled` under the discovery section, and asserts `markdown_for_agents_okf_compat`/`markdown_for_agents_ard_enabled` are NOT present (to lock in the removal).

- [ ] **Step 3: Rewrite the regen-flag test for `bundle_enabled`**

  Replace `test_sanitize_flags_regen_when_bundle_enabled_changes`'s setup to change `bundle_enabled` directly (no `okf_compat` precondition needed any more — it's the only discovery-related flag now):
  ```php
  public function test_sanitize_flags_regen_when_bundle_enabled_changes(): void {
      $old = array_merge( Options::get_defaults(), array( 'post_types' => array( 'post' ), 'bundle_enabled' => false ) );
      $new = array( 'post_types' => array( 'post' ), 'bundle_enabled' => '1' );

      $page = $this->make_page( $old );
      $page->sanitize_options( $new );

      $this->assertSame( array( 'post' ), get_transient( 'markdown_for_agents_needs_regen' ) );
  }
  ```
  (Adjust `make_page()`/helper usage to match this file's existing conventions — check how other sanitize tests in this file construct `$page` and call `sanitize_options()`, and mirror that exactly rather than introducing a new pattern.)

- [ ] **Step 4: Delete `test_sanitize_does_not_flag_regen_when_only_ard_enabled_changes`**

  `ard_enabled` no longer exists as a stored option, so this test has nothing to assert. Delete it.

- [ ] **Step 5: Rewrite the ARD-panel display tests to key off `bundle_enabled` instead of `ard_enabled`**

  Rename and rewrite:
  - `test_field_ard_enabled_renders_json_panel_when_enabled` → `test_field_bundle_enabled_renders_ard_panel_when_bundle_enabled`: set `bundle_enabled => true` (no `okf_compat`/`ard_enabled` keys), call `field_bundle_enabled()`, assert the output contains a `<textarea>` with `specVersion`/`ai-catalog.json` content (same catalog-mocking setup as before).
  - `test_field_ard_enabled_shows_fallback_when_catalog_null` → `test_field_bundle_enabled_shows_ard_fallback_when_catalog_null`: `bundle_enabled => true`, `$ard_catalog = null`, assert no `<textarea>` renders.
  - `test_field_ard_enabled_no_panel_when_disabled` → `test_field_bundle_enabled_no_ard_panel_when_bundle_disabled`: `bundle_enabled => false`, assert no `<textarea>` renders even with a catalog present.

- [ ] **Step 6: Run tests to confirm they fail against current production code**

  Run: `vendor/bin/phpunit tests/Unit/Admin/SettingsPageTest.php`
  Expected: FAIL — production code still has the three-checkbox cascade and doesn't call `render_ard_panel()` from `field_bundle_enabled()`.

- [ ] **Step 7: Simplify `sanitize_options()`**

  In `src/Admin/SettingsPage.php`, replace:
  ```php
  		$clean['okf_compat']              = ! empty( $input['okf_compat'] );
  		$clean['bundle_enabled']          = $clean['okf_compat'] && ! empty( $input['bundle_enabled'] );
  		$clean['ard_enabled']             = $clean['bundle_enabled'] && ! empty( $input['ard_enabled'] );
  ```
  with:
  ```php
  		$clean['bundle_enabled']          = ! empty( $input['bundle_enabled'] );
  ```

- [ ] **Step 8: Simplify `maybe_flag_regeneration()`**

  Remove the line:
  ```php
  			|| ! empty( $old['okf_compat'] ) !== ! empty( $new['okf_compat'] )
  ```
  from the `$changed` boolean expression in `maybe_flag_regeneration()` (~line 245), leaving the `bundle_enabled` comparison and the others intact.

- [ ] **Step 9: Remove the `okf_compat` and `ard_enabled` field registrations**

  Replace:
  ```php
  		add_settings_field( 'markdown_for_agents_okf_compat', __( 'OKF compatibility mode', 'markdown-for-agents-and-statistics' ), array( $this, 'field_okf_compat' ), self::PAGE_SLUG, 'markdown_for_agents_discovery' );
  		add_settings_field( 'markdown_for_agents_bundle_enabled', __( 'Build downloadable bundle (.zip)', 'markdown-for-agents-and-statistics' ), array( $this, 'field_bundle_enabled' ), self::PAGE_SLUG, 'markdown_for_agents_discovery' );
  		add_settings_field( 'markdown_for_agents_ard_enabled', __( 'ARD catalog for /.well-known/', 'markdown-for-agents-and-statistics' ), array( $this, 'field_ard_enabled' ), self::PAGE_SLUG, 'markdown_for_agents_discovery' );
  ```
  with:
  ```php
  		add_settings_field( 'markdown_for_agents_bundle_enabled', __( 'Build downloadable bundle (.zip + manifest)', 'markdown-for-agents-and-statistics' ), array( $this, 'field_bundle_enabled' ), self::PAGE_SLUG, 'markdown_for_agents_discovery' );
  ```

- [ ] **Step 10: Rewrite `section_discovery_intro()`**

  Replace:
  ```php
  	public function section_discovery_intro(): void {
  		echo '<p>' . esc_html__( 'These three toggles are cumulative: OKF compatibility mode produces spec-aligned Markdown files; the bundle packages them into a single downloadable archive; the ARD catalog advertises that bundle for automated discovery. Each level requires the one before it.', 'markdown-for-agents-and-statistics' ) . '</p>';
  	}
  ```
  with:
  ```php
  	public function section_discovery_intro(): void {
  		echo '<p>' . esc_html__( 'Exported Markdown files are always OKF-compliant (flat tags, timestamp, and internal links pointing at the Markdown file versions). Enable the toggle below for a downloadable bundle of the whole export tree, complete with a manifest and an ARD discovery catalog.', 'markdown-for-agents-and-statistics' ) . '</p>';
  	}
  ```
  Also update the docblock immediately above (currently describes "the three-level progression") to describe the single toggle instead.

- [ ] **Step 11: Delete `field_okf_compat()` entirely**

  Remove the whole method (including its docblock), currently at `SettingsPage.php:435-449`.

- [ ] **Step 12: Update `field_bundle_enabled()` — remove the disabled-gate, update copy, render the ARD panel**

  Replace:
  ```php
  	/**
  	 * Render the bundle-build checkbox field.
  	 *
  	 * Gated on OKF compatibility mode (Decision 3): disabled with an
  	 * explanatory hint when the prerequisite level is off.
  	 *
  	 * @since  1.6.0
  	 */
  	public function field_bundle_enabled(): void {
  		$checked  = ! empty( $this->options['bundle_enabled'] );
  		$disabled = empty( $this->options['okf_compat'] );
  		?>
  		<label>
  			<input type="checkbox" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[bundle_enabled]"
  					value="1" <?php checked( $checked, true ); ?> <?php disabled( $disabled, true ); ?>>
  			<?php esc_html_e( 'Build downloadable bundle (.zip)', 'markdown-for-agents-and-statistics' ); ?>
  		</label>
  		<?php if ( $disabled ) : ?>
  			<p class="description"><?php esc_html_e( 'Enable OKF compatibility mode first.', 'markdown-for-agents-and-statistics' ); ?></p>
  		<?php endif; ?>
  		<?php
  	}
  ```
  with:
  ```php
  	/**
  	 * Render the bundle-build checkbox field.
  	 *
  	 * When checked, also renders the ARD catalog JSON panel and deployment
  	 * instructions directly below (previously a separate `ard_enabled`
  	 * checkbox) — the bundle, its manifest, and the catalog are now one unit.
  	 *
  	 * @since  1.6.0
  	 */
  	public function field_bundle_enabled(): void {
  		$checked = ! empty( $this->options['bundle_enabled'] );
  		?>
  		<label>
  			<input type="checkbox" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[bundle_enabled]"
  					value="1" <?php checked( $checked, true ); ?>>
  			<?php esc_html_e( 'Build the export tree into a downloadable .zip bundle with a manifest.json and relative internal links, and publish an ARD discovery catalog for it below.', 'markdown-for-agents-and-statistics' ); ?>
  		</label>
  		<?php
  		if ( $checked ) {
  			$this->render_ard_panel();
  		}
  	}
  ```

- [ ] **Step 13: Delete `field_ard_enabled()` entirely**

  Remove the whole method (including its docblock), currently at `SettingsPage.php:474-501`. `render_ard_panel()` (the private method it called) stays unchanged — it's now called from `field_bundle_enabled()` instead.

- [ ] **Step 14: Run tests again**

  Run: `vendor/bin/phpunit tests/Unit/Admin/SettingsPageTest.php`
  Expected: PASS.

- [ ] **Step 15: Run the full suite**

  Run: `composer test`
  Expected: PASS — this confirms Phase A + Task B1 + B2 together leave no other test referencing the removed options. If anything still fails, grep for `okf_compat` and `ard_enabled` across `tests/` and `src/` to find what's missed before proceeding.

- [ ] **Step 16: Commit**

  ```bash
  git add src/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
  git commit -m "feat: collapse OKF/bundle/ARD settings into a single bundle_enabled toggle"
  ```

---

## Phase C — Wire manifest generation into every bundle-rebuild path

### Task C1: Extract `Generator::write_manifests()` from `Commands::generate_manifest()`

**Files:**
- Modify: `src/Generator/Generator.php` (add new public method)
- Modify: `src/CLI/Commands.php:12, 569-617` (delegate instead of implementing)
- Test: create `tests/Unit/Generator/GeneratorTest.php` additions; check `tests/Unit/CLI/CommandsTest.php` for existing manifest-related coverage to relocate

- [ ] **Step 1: Confirm there's no existing manifest coverage in `CommandsTest.php` to relocate**

  Run: `grep -n "manifest\|Manifest" tests/Unit/CLI/CommandsTest.php`
  Expected: no matches — confirmed there is currently no test exercising `--with-manifest`/`--incremental`/`generate_manifest()` in this file. There is nothing to relocate; Step 7 below only needs to confirm the full CLI suite still passes after the delegation change, not adapt pre-existing manifest-specific assertions.

- [ ] **Step 2: Write a new test for `Generator::write_manifests()`**

  This test suite (`tests/Unit/Generator/GeneratorTest.php`) uses `PHPUnit\Framework\TestCase` with a **mocked** `FileWriter` (`$this->file_writer = $this->createMock( FileWriter::class )`, set up in `setUp()` at `GeneratorTest.php:64`) and a real temp directory only for path resolution (`$this->base_dir`, `GeneratorTest.php:50-51`) — no file is ever actually written to disk in this test file's existing tests, and there is no `self::factory()` (that's a `WP_UnitTestCase`-only API, unavailable here). `get_posts()` is mocked via `$GLOBALS['_mock_posts']` (see `tests/mocks/wordpress-mocks.php:393-405`) and ignores `post_type`/pagination args, simply returning whatever array is set.

  Because `$file_writer` is mocked, `write_manifests()`'s internal `file_exists( $full_path )` check (a real, unmocked PHP builtin against the real filesystem) will always be `false` — no post's `.md` file actually exists on disk — so `ManifestGenerator::add_document()` is never called for any post, and the resulting manifest has an empty `documents` array. That's fine: the test only needs to confirm `write_manifests()` drives `ManifestGenerator::save()` (which calls `$file_writer->write(...)`, see `ManifestGenerator.php:139-143`) and returns `true`, not that real files land on disk.

  Add to `tests/Unit/Generator/GeneratorTest.php`, following this file's existing `make_post()`/`$GLOBALS['_mock_posts']` conventions (see e.g. `test_generate_post_calls_collaborators_in_order` for the established pattern):

  ```php
  public function test_write_manifests_saves_a_manifest_per_post_type(): void {
      $GLOBALS['_mock_posts'] = [ $this->make_post( [ 'post_type' => 'post' ] ) ];

      $this->file_writer->expects( $this->once() )
          ->method( 'write' )
          ->with( $this->stringContains( 'post/manifest.json' ) )
          ->willReturn( true );

      $result = $this->generator->write_manifests( $this->base_dir, [ 'post' ] );

      $this->assertTrue( $result );
  }
  ```

- [ ] **Step 3: Run the new test to confirm it fails**

  Run: `vendor/bin/phpunit tests/Unit/Generator/GeneratorTest.php --filter test_write_manifests_saves_a_manifest_per_post_type`
  Expected: FAIL with "Call to undefined method ...Generator::write_manifests()".

- [ ] **Step 4: Add `write_manifests()` to `Generator.php`**

  Add a `use Tclp\WpMarkdownForAgents\Generator\ManifestGenerator;` import if not already present (check — it may need `use ManifestGenerator;` since it's in the same namespace, in which case no import is needed at all; verify by checking the namespace of `ManifestGenerator.php`, which is `Tclp\WpMarkdownForAgents\Generator`, the same as `Generator.php` — so no import needed).

  Add this method to `src/Generator/Generator.php`, moved verbatim from `Commands::generate_manifest()` (`Commands.php:569-617`), with `$this->file_writer` and `$this->get_export_path()` already available as existing `Generator` members:

  ```php
  	/**
  	 * Build and save a manifest.json per post-type folder.
  	 *
  	 * Each post type gets its own manifest inside its export subdirectory,
  	 * enabling independent change tracking per content type.
  	 *
  	 * @since  1.7.0
  	 * @param  string   $export_base Absolute path to the export base directory.
  	 * @param  string[] $post_types  Post type slugs to include.
  	 * @return bool True if all manifests saved successfully.
  	 */
  	public function write_manifests( string $export_base, array $post_types ): bool {
  		$success    = true;
  		$batch_size = 100;

  		foreach ( $post_types as $post_type ) {
  			$type_dir = trailingslashit( $export_base ) . $post_type . '/';
  			$manifest = new ManifestGenerator( $type_dir, $this->file_writer );
  			$type_ids = array();
  			$offset   = 0;

  			do {
  				$posts = get_posts(
  					array(
  						'post_type'      => $post_type,
  						'post_status'    => 'publish',
  						'posts_per_page' => $batch_size, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
  						'offset'         => $offset,
  						'orderby'        => 'ID',
  						'order'          => 'ASC',
  						'no_found_rows'  => true,
  					)
  				);

  				$fetched = count( $posts );

  				foreach ( $posts as $post ) {
  					$full_path     = $this->get_export_path( $post );
  					$relative_path = sanitize_file_name( $post->post_name ) . '.md';
  					$type_ids[]    = $post->ID;

  					if ( file_exists( $full_path ) ) {
  						$manifest->add_document( $post, $relative_path );
  					}

  					clean_post_cache( $post );
  				}

  				$offset += $batch_size;
  			} while ( $fetched === $batch_size );

  			$manifest->mark_deleted_documents( $type_ids );

  			if ( ! $manifest->save() ) {
  				$success = false;
  			}
  		}

  		return $success;
  	}
  ```
  Confirm `get_export_path()` on `Generator` is already `public` (it's called from `Commands.php:595` as `$this->generator->get_export_path( $post )`, so it must already be public) — no visibility change needed.

- [ ] **Step 5: Run the new test — confirm it passes**

  Run: `vendor/bin/phpunit tests/Unit/Generator/GeneratorTest.php --filter test_write_manifests_saves_a_manifest_per_post_type`
  Expected: PASS.

- [ ] **Step 6: Make `Commands::generate_manifest()` delegate to `Generator::write_manifests()`**

  In `src/CLI/Commands.php`, replace the entire body of `generate_manifest()` (`Commands.php:569-617`):
  ```php
  	private function generate_manifest( string $export_base, array $post_types ): bool {
  		$success    = true;
  		$batch_size = 100;

  		foreach ( $post_types as $post_type ) {
  			// ... (full existing body) ...
  		}

  		return $success;
  	}
  ```
  with:
  ```php
  	private function generate_manifest( string $export_base, array $post_types ): bool {
  		return $this->generator->write_manifests( $export_base, $post_types );
  	}
  ```
  Remove the now-unused `use Tclp\WpMarkdownForAgents\Generator\ManifestGenerator;` import at `Commands.php:12`.

- [ ] **Step 7: Run the full `CommandsTest.php` suite to confirm the delegation change breaks nothing**

  Run: `vendor/bin/phpunit tests/Unit/CLI/CommandsTest.php`
  Expected: PASS — `generate_manifest()`'s external behavior (via `--with-manifest`/`--incremental`) is unchanged; only its internals moved. No test in this file asserted on `generate_manifest()`'s internals directly (confirmed in Step 1), so no test edits are expected here.

- [ ] **Step 8: Run the full suite**

  Run: `composer test`
  Expected: PASS.

- [ ] **Step 9: Commit**

  ```bash
  git add src/Generator/Generator.php src/CLI/Commands.php tests/Unit/Generator/GeneratorTest.php
  git commit -m "refactor: extract manifest orchestration into Generator::write_manifests()"
  ```

---

### Task C2: Call `write_manifests()` before every CLI bundle build

**Files:**
- Modify: `src/CLI/Commands.php:137-166` (`bundle()` command), `:662-671` (`rebuild_bundle()` helper)
- Test: `tests/Unit/CLI/CommandsTest.php`

- [ ] **Step 1: Read the existing `bundle`-command and `rebuild_bundle()`-helper tests**

  Run: `grep -n "function test.*bundle\|function test.*Bundle" tests/Unit/CLI/CommandsTest.php`

  Read each to understand this file's mocking convention for `$this->bundle_generator` and `$this->generator` (likely PHPUnit mocks with `expects()`/`method()`).

- [ ] **Step 2: Write a failing test asserting `write_manifests()` runs before `build()` in the `bundle` command**

  Add to `tests/Unit/CLI/CommandsTest.php`, matching this file's existing mock-setup conventions:
  ```php
  public function test_bundle_command_writes_manifests_before_building(): void {
      $generator = $this->createMock( Generator::class );
      $generator->expects( $this->once() )->method( 'write_manifests' );

      $bundle_generator = $this->createMock( BundleGenerator::class );
      $bundle_generator->method( 'build' )->willReturn( true );
      $bundle_generator->method( 'bundle_path' )->willReturn( '/tmp/test-okf.zip' );

      $commands = /* construct with $generator, $bundle_generator, options including bundle_enabled => true — match this file's existing constructor-call convention */;

      $commands->bundle( array(), array() );
  }
  ```
  (Fill in the exact `Commands` construction using whatever helper/pattern this test file already uses elsewhere — check an existing `bundle`-related test for the constructor argument order.)

- [ ] **Step 3: Run to confirm it fails**

  Run: `vendor/bin/phpunit tests/Unit/CLI/CommandsTest.php --filter test_bundle_command_writes_manifests_before_building`
  Expected: FAIL — `write_manifests()` is never called.

- [ ] **Step 4: Add the `write_manifests()` call to `bundle()`**

  In `src/CLI/Commands.php`, in the `bundle()` method, replace:
  ```php
  		if ( isset( $assoc_args['if-stale'] ) && ! $this->bundle_generator->is_stale() ) {
  			\WP_CLI::log( 'Bundle is up to date; skipping.' );
  			return;
  		}

  		if ( ! $this->bundle_generator->build() ) {
  ```
  with:
  ```php
  		if ( isset( $assoc_args['if-stale'] ) && ! $this->bundle_generator->is_stale() ) {
  			\WP_CLI::log( 'Bundle is up to date; skipping.' );
  			return;
  		}

  		$export_base = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );
  		$this->generator->write_manifests( $export_base, ExportPolicy::enabled_post_types( $this->options ) );

  		if ( ! $this->bundle_generator->build() ) {
  ```

- [ ] **Step 5: Add the same call to `rebuild_bundle()`**

  In `src/CLI/Commands.php`, in `rebuild_bundle()` (~line 662), replace:
  ```php
  	private function rebuild_bundle(): void {
  		if ( null === $this->bundle_generator || empty( $this->options['bundle_enabled'] ) ) {
  			return;
  		}

  		if ( $this->bundle_generator->build() ) {
  ```
  with:
  ```php
  	private function rebuild_bundle(): void {
  		if ( null === $this->bundle_generator || empty( $this->options['bundle_enabled'] ) ) {
  			return;
  		}

  		$export_base = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );
  		$this->generator->write_manifests( $export_base, ExportPolicy::enabled_post_types( $this->options ) );

  		if ( $this->bundle_generator->build() ) {
  ```

- [ ] **Step 6: Run the new test — confirm it passes**

  Run: `vendor/bin/phpunit tests/Unit/CLI/CommandsTest.php --filter test_bundle_command_writes_manifests_before_building`
  Expected: PASS.

- [ ] **Step 7: Run the full CLI test file and the full suite**

  Run: `vendor/bin/phpunit tests/Unit/CLI/CommandsTest.php && composer test`
  Expected: PASS.

- [ ] **Step 8: Commit**

  ```bash
  git add src/CLI/Commands.php tests/Unit/CLI/CommandsTest.php
  git commit -m "feat: write manifests before every CLI bundle build"
  ```

---

### Task C3: Call `write_manifests()` before the admin AJAX bundle rebuild

**Files:**
- Modify: `src/Admin/Admin.php:1-11` (imports), `:277-290` (`maybe_rebuild_bundle()`)
- Test: `tests/Unit/Admin/AdminAjaxTest.php` (confirmed — `tests/Unit/Admin/` contains `AdminAjaxTest.php`, `MetaBoxTest.php`, `SettingsPageTest.php`; no `AdminTest.php` exists, and `maybe_rebuild_bundle()` is AJAX-triggered, so `AdminAjaxTest.php` is the correct target)

- [ ] **Step 1: Find `maybe_rebuild_bundle()` coverage in `AdminAjaxTest.php`**

  Run: `grep -n "maybe_rebuild_bundle\|function test.*[Rr]ebuild" tests/Unit/Admin/AdminAjaxTest.php`

  Read the matching test(s) to learn this file's `Admin` construction and mocking convention. If nothing matches (i.e. no existing test currently exercises this method directly), add the new test to this same file anyway, following its existing `Admin`-construction/mock conventions for other AJAX-handler tests.

- [ ] **Step 2: Write a failing test**

  Add a test (in whichever file Step 1 identifies) asserting that when `maybe_rebuild_bundle()` runs (bundle enabled, bundle stale), the injected `Generator` mock's `write_manifests()` is called once before `BundleGenerator::build()`. Mirror the existing test(s) for `maybe_rebuild_bundle()`'s stale/build behavior found in Step 1, adding a `$generator->expects( $this->once() )->method( 'write_manifests' )` expectation.

- [ ] **Step 3: Run to confirm it fails**

  Run: `vendor/bin/phpunit <file from Step 1> --filter <new test name>`
  Expected: FAIL.

- [ ] **Step 4: Add the `ExportPolicy` import and the `write_manifests()` call**

  In `src/Admin/Admin.php`, add to the imports block (after line 9, alphabetically among the existing `use` statements):
  ```php
  use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;
  ```
  Then in `maybe_rebuild_bundle()`, replace:
  ```php
  	private function maybe_rebuild_bundle(): void {
  		if ( null === $this->bundle_generator || empty( $this->options['bundle_enabled'] ) ) {
  			return;
  		}

  		if ( ! $this->bundle_generator->is_stale() ) {
  			return;
  		}

  		if ( ! $this->bundle_generator->build() && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
  ```
  with:
  ```php
  	private function maybe_rebuild_bundle(): void {
  		if ( null === $this->bundle_generator || empty( $this->options['bundle_enabled'] ) ) {
  			return;
  		}

  		if ( ! $this->bundle_generator->is_stale() ) {
  			return;
  		}

  		$export_base = Options::get_export_base( $this->options );
  		$this->generator->write_manifests( $export_base, ExportPolicy::enabled_post_types( $this->options ) );

  		if ( ! $this->bundle_generator->build() && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
  ```
  (`Options` is already imported at `Admin.php:7`.)

- [ ] **Step 5: Run the new test — confirm it passes**

  Run: `vendor/bin/phpunit <file from Step 1> --filter <new test name>`
  Expected: PASS.

- [ ] **Step 6: Run the full suite**

  Run: `composer test`
  Expected: PASS.

- [ ] **Step 7: Commit**

  ```bash
  git add src/Admin/Admin.php tests/Unit/Admin/
  git commit -m "feat: write manifests before the admin AJAX bundle rebuild"
  ```

---

### Task C4: Repoint the cron rebuild hook to write manifests before rebuilding

**Files:**
- Modify: `src/Core/Plugin.php:145-152`
- Test: `tests/Unit/Core/PluginTest.php` (confirm exact filename via `ls tests/Unit/Core/`)

- [ ] **Step 1: Confirm `Loader::add_action()`'s signature**

  Read `src/Core/Loader.php` around its `add_action()` method to confirm it stores `array( $component, $callback )` and that `run()` invokes it as a normal PHP callable — confirming a `Closure` passed as `$component` with `'__invoke'` as `$callback` will work (per the approved spec, §4).

- [ ] **Step 2: Check for existing Plugin-wiring test coverage of the cron hook**

  Run: `ls tests/Unit/Core/ && grep -rn "markdown_for_agents_rebuild_bundle\|on_rebuild_bundle" tests/Unit/`

  If a test already asserts this hook is registered with `$bundle_generator`/`'on_rebuild_bundle'`, it needs updating in Step 5 to assert the closure form instead (or, if it only checks the hook name is registered at all, it may need no change — read it first to decide).

- [ ] **Step 3: Update the hook registration if a test requires the change (per Step 2 findings)**

  If no existing test constrains this, skip ahead to Step 4 directly — this wiring is difficult to unit-test meaningfully (it's a `Closure` capturing local variables registered against a real `Loader`), and the spec doesn't require new test coverage here specifically, only that the behavior is correct. If Step 2 found a test asserting the old registration form, update it to assert the hook is registered with a `Closure` (e.g. `$this->assertInstanceOf( \Closure::class, ... )` against whatever the test's existing assertion style inspects).

- [ ] **Step 4: Repoint the hook in `Plugin.php`**

  In `src/Core/Plugin.php`, inside `define_generator()`, replace:
  ```php
  		$this->loader->add_action( 'markdown_for_agents_rebuild_bundle', $bundle_generator, 'on_rebuild_bundle' );
  ```
  with:
  ```php
  		$rebuild_with_manifest = function () use ( $generator, $bundle_generator, $options ): void {
  			$generator->write_manifests(
  				Options::get_export_base( $options ),
  				ExportPolicy::enabled_post_types( $options )
  			);
  			$bundle_generator->on_rebuild_bundle();
  		};

  		$this->loader->add_action( 'markdown_for_agents_rebuild_bundle', $rebuild_with_manifest, '__invoke' );
  ```
  Add `use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;` to `Plugin.php`'s imports if not already present — check the existing `use` block first (`ExportPolicy` may already be imported for another purpose; grep before adding a duplicate).

- [ ] **Step 5: Update the test from Step 2/3, if any, to match**

  Adjust per Step 3's finding.

- [ ] **Step 6: Run the full suite**

  Run: `composer test`
  Expected: PASS.

- [ ] **Step 7: Manually verify the cron path** (this closure can't be fully exercised by unit tests since it depends on a real WP-Cron firing)

  Per project convention (`CLAUDE.md`/`run` skill), start the local `ddev` environment, enable the bundle toggle, save a post to trigger `mark_stale_and_schedule()`, then either wait for or manually trigger the scheduled `markdown_for_agents_rebuild_bundle` cron event (e.g. via `wp cron event run markdown_for_agents_rebuild_bundle` if WP-CLI is available in the dev environment), and confirm both `manifest.json` (inside each post-type export subdirectory) and the `.zip` bundle are freshly written.

- [ ] **Step 8: Commit**

  ```bash
  git add src/Core/Plugin.php
  git commit -m "feat: write manifests before the cron-triggered bundle rebuild"
  ```

---

## Phase D — Documentation and version bump

### Task D1: Update README.md, readme.txt, and bump the version

**Files:**
- Modify: `README.md:31, 78-79, 148-178, 246`
- Modify: `readme.txt:48-50` (feature bullets), changelog section
- Modify: `markdown-for-agents.php:7` (`Version:` header), `:27` (`MARKDOWN_FOR_AGENTS_VERSION`)
- Modify: `readme.txt` stable tag line

- [ ] **Step 1: Bump to 1.7.0**

  `readme.txt:6` already has `Stable tag: 1.6.0`, and the changelog's most recent entry is `= 1.6.0 =` — i.e. 1.6.0 is the current released version, not in-progress. Per `CLAUDE.md` convention ("version lives in three places... bump all together"), and because this is a breaking behavioral change for any site currently running with `okf_compat` off (their exported files will change on next regeneration), bump to **1.7.0** in all three places (Step 7 below) with a new `@since 1.7.0` used for any new code added in this plan (e.g. `Generator::write_manifests()` in Task C1 — go back and correct its docblock from `@since 1.7.0` if this plan drafted it with a different placeholder version).

- [ ] **Step 2: Update `README.md` feature bullet**

  At `README.md:31`, replace:
  ```
  - **OKF compatibility mode** - optional toggle adding `timestamp` and flat cross-taxonomy `tags` frontmatter keys, and rewriting internal links to point at the Markdown file versions
  ```
  with:
  ```
  - **OKF-compliant frontmatter and links** - every export includes `timestamp` and flat cross-taxonomy `tags` frontmatter keys, with internal links rewritten to point at the Markdown file versions
  ```

- [ ] **Step 3: Update the settings table**

  At `README.md:78-79`, remove the "OKF compatibility mode" row entirely, and update the "Build downloadable bundle" row's description to mention it now also produces `manifest.json` and the ARD catalog panel, and no longer requires a prerequisite toggle.

- [ ] **Step 4: Rewrite the "OKF (Open Knowledge Format)" section**

  At `README.md:148-178`, rewrite to: state OKF-compliant frontmatter/links are always on (no toggle, cite `okf_version` pin per this file's existing convention); keep the "Bundle (.zip)" subsection but merge in that it now also writes `manifest.json` and shows the ARD catalog automatically; remove all "requires X toggle on"/cascade language.

- [ ] **Step 5: Update the filter doc**

  At `README.md:246`, remove the "(OKF compatibility mode)" qualifier from the `markdown_for_agents_flat_tags` filter description, since it's no longer a mode.

- [ ] **Step 6: Update `readme.txt`**

  Update the feature bullets at `readme.txt:48-50` to match the `README.md` changes in Step 2. Add a new changelog entry under the version decided in Step 1, describing: OKF-compliant frontmatter/links are now always on; `okf_compat` and `ard_enabled` settings are removed (existing sites should regenerate to see updated output); the bundle toggle now also produces `manifest.json` and the ARD catalog panel.

- [ ] **Step 7: Bump the version**

  Update `markdown-for-agents.php:7` (`Version:` header comment) and `:27` (`MARKDOWN_FOR_AGENTS_VERSION` constant) to the version decided in Step 1. Update `readme.txt`'s "Stable tag" line to match.

- [ ] **Step 8: Run the full suite one final time**

  Run: `composer test && composer phpcs`
  Expected: PASS.

- [ ] **Step 9: Commit**

  ```bash
  git add README.md readme.txt markdown-for-agents.php
  git commit -m "docs: document OKF-default behavior and single bundle toggle; bump version"
  ```

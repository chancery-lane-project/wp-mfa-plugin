# Source Class Technical Debt Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove three concrete technical debt items from the source classes: duplicated WHERE clause logic in `StatsRepository`, WordPress helper stubs leaked into a production source file, and a cross-class static coupling between `Generator` and `FrontmatterBuilder`.

**Architecture:** Three independent, sequentially committed refactors. No public API changes. No new features. Each task leaves the full test suite passing before moving on.

**Tech Stack:** PHP 8.0+, PHPUnit 9.6, WordPress plugin conventions.

---

## File Map

| File | Change |
|------|--------|
| `src/Stats/StatsRepository.php` | Extract `build_where()` private method |
| `src/Admin/SettingsPage.php` | Remove `checked()` and `esc_textarea()` stub definitions |
| `tests/mocks/wordpress-mocks.php` | Add `checked()` and `esc_textarea()` stubs |
| `src/Generator/FieldResolver.php` | **New** — contains `resolve()` instance method |
| `src/Generator/FrontmatterBuilder.php` | Inject `FieldResolver`, remove `resolve_field_value()` static method |
| `src/Generator/Generator.php` | Inject `FieldResolver`, use it in `get_post_content()` |
| `src/Core/Plugin.php` | Instantiate `FieldResolver`, pass to both collaborators |
| `tests/Unit/Generator/FieldResolverTest.php` | **New** — covers the `resolve()` method |
| `tests/Unit/Generator/FrontmatterBuilderTest.php` | Update `make_builder()` factory to pass `FieldResolver` |
| `tests/Unit/Generator/GeneratorTest.php` | Update `make_generator()` factory to pass `FieldResolver` |

---

## Task 1: Extract `build_where()` from `StatsRepository`

**Files:**
- Modify: `wp-markdown-for-agents/src/Stats/StatsRepository.php`

No test changes — the public method signatures and behaviour are identical; the existing `StatsRepositoryTest` will confirm nothing regressed.

- [ ] **Step 1: Run the existing stats tests to establish a baseline**

```bash
cd wp-markdown-for-agents && ./vendor/bin/phpunit --no-coverage tests/Unit/Stats/StatsRepositoryTest.php
```

Expected: all pass.

- [ ] **Step 2: Add the `build_where()` private method**

Open `src/Stats/StatsRepository.php`. After the closing `}` of `get_total_count()` (around line 144) and before `get_distinct_agents()`, insert:

```php
/**
 * Build a WHERE clause and prepared values from a filters array.
 *
 * Supports 'post_id' (int) and 'agent' (string) keys.
 *
 * @since  1.2.0
 * @param  array<string, mixed> $filters
 * @return array{sql: string, values: list<mixed>}
 */
private function build_where( array $filters ): array {
    $where  = array();
    $values = array();

    if ( ! empty( $filters['post_id'] ) ) {
        $where[]  = 'post_id = %d';
        $values[] = (int) $filters['post_id'];
    }

    if ( ! empty( $filters['agent'] ) ) {
        $where[]  = 'agent = %s';
        $values[] = (string) $filters['agent'];
    }

    return array(
        'sql'    => ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '',
        'values' => $values,
    );
}
```

- [ ] **Step 3: Replace the duplicated logic in `get_stats()`**

In `get_stats()`, replace the block that builds `$where`, `$values`, and `$where_sql` (lines ~88–101) with:

```php
$clause    = $this->build_where( $filters );
$where_sql = $clause['sql'];
$values    = $clause['values'];
```

Keep the `$limit` and `$offset` lines and the SQL construction below them unchanged.

- [ ] **Step 4: Replace the duplicated logic in `get_total_count()`**

In `get_total_count()`, replace the block that builds `$where`, `$values`, and `$where_sql` (lines ~126–136) with:

```php
$clause    = $this->build_where( $filters );
$where_sql = $clause['sql'];
$values    = $clause['values'];
```

Keep the SQL construction and `get_var()` call unchanged.

- [ ] **Step 5: Run the stats tests**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Stats/StatsRepositoryTest.php
```

Expected: all pass.

- [ ] **Step 6: Run the full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all 169 tests pass.

- [ ] **Step 7: Commit**

```bash
git add wp-markdown-for-agents/src/Stats/StatsRepository.php
git commit -m "refactor: extract build_where() in StatsRepository to eliminate duplication"
```

---

## Task 2: Move WP helper stubs out of `SettingsPage`

**Files:**
- Modify: `wp-markdown-for-agents/src/Admin/SettingsPage.php`
- Modify: `wp-markdown-for-agents/tests/mocks/wordpress-mocks.php`

- [ ] **Step 1: Run the settings page tests to establish a baseline**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Admin/SettingsPageTest.php
```

Expected: all pass.

- [ ] **Step 2: Add the stubs to the mocks file**

Open `tests/mocks/wordpress-mocks.php`. Locate the "Form helper stubs for StatsPage" section near the bottom (around line 698). Immediately before it, insert a new section:

```php
// ---------------------------------------------------------------------------
// Form helper stubs for SettingsPage
// ---------------------------------------------------------------------------

if (!function_exists('checked')) {
    function checked(mixed $helper, mixed $current, bool $echo = true): string {
        $result = $helper === $current ? ' checked="checked"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

```

- [ ] **Step 3: Remove the stubs from `SettingsPage.php`**

Open `src/Admin/SettingsPage.php`. Delete everything after the closing `}` of the `SettingsPage` class — specifically the comment and two `if (!function_exists(...))` blocks at lines 363–378:

```php
// WordPress helper stub — defined here to avoid redefining if WP is loaded.
if ( ! function_exists( 'checked' ) ) {
    function checked( mixed $helper, mixed $current, bool $echo = true ): string {
        ...
    }
}

if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( string $text ): string {
        ...
    }
}
```

The file should end with the closing `}` of the `SettingsPage` class followed by a blank line and the closing `?>` if present (there is none — it ends at `}`).

- [ ] **Step 4: Run the settings page tests**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Admin/SettingsPageTest.php
```

Expected: all pass.

- [ ] **Step 5: Run the full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all 169 tests pass.

- [ ] **Step 6: Commit**

```bash
git add wp-markdown-for-agents/src/Admin/SettingsPage.php \
        wp-markdown-for-agents/tests/mocks/wordpress-mocks.php
git commit -m "refactor: move checked() and esc_textarea() stubs from SettingsPage to test mocks"
```

---

## Task 3: Extract `FieldResolver` and fix the static boundary

**Files:**
- Create: `wp-markdown-for-agents/src/Generator/FieldResolver.php`
- Create: `wp-markdown-for-agents/tests/Unit/Generator/FieldResolverTest.php`
- Modify: `wp-markdown-for-agents/src/Generator/FrontmatterBuilder.php`
- Modify: `wp-markdown-for-agents/src/Generator/Generator.php`
- Modify: `wp-markdown-for-agents/src/Core/Plugin.php`
- Modify: `wp-markdown-for-agents/tests/Unit/Generator/FrontmatterBuilderTest.php`
- Modify: `wp-markdown-for-agents/tests/Unit/Generator/GeneratorTest.php`

### Step 3a — Create FieldResolver with tests (TDD)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Generator/FieldResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\FieldResolver
 */
class FieldResolverTest extends TestCase {

    private FieldResolver $resolver;

    protected function setUp(): void {
        $this->resolver            = new FieldResolver();
        $GLOBALS['_mock_post_meta'] = [];
    }

    public function test_resolves_plain_meta_key(): void {
        $GLOBALS['_mock_post_meta'][42]['my_field'] = 'my value';

        $result = $this->resolver->resolve( 42, 'my_field' );

        $this->assertSame( 'my value', $result );
    }

    public function test_returns_null_for_missing_meta_key(): void {
        $result = $this->resolver->resolve( 42, 'nonexistent_field' );

        $this->assertNull( $result );
    }

    public function test_returns_null_for_empty_string_meta_value(): void {
        $GLOBALS['_mock_post_meta'][42]['empty_field'] = '';

        $result = $this->resolver->resolve( 42, 'empty_field' );

        $this->assertNull( $result );
    }

    public function test_returns_null_for_dot_notation_when_get_field_unavailable(): void {
        // get_field() is not defined in the unit test environment, so dot-notation
        // paths always return null without ACF.
        $result = $this->resolver->resolve( 42, 'group.subfield' );

        $this->assertNull( $result );
    }
}
```

- [ ] **Step 2: Run to verify it fails (class not found)**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Generator/FieldResolverTest.php
```

Expected: error — `Class "Tclp\WpMarkdownForAgents\Generator\FieldResolver" not found`.

- [ ] **Step 3: Create `src/Generator/FieldResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Resolves custom field values for a post.
 *
 * Handles plain meta keys and ACF dot-notation paths. This is the
 * single place where field resolution logic lives — injected into
 * both FrontmatterBuilder and Generator.
 *
 * @since  1.2.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class FieldResolver {

	/**
	 * Resolve a field value for a post.
	 *
	 * - Plain key (e.g. `_yoast_wpseo_title`): uses get_post_meta().
	 * - Dot notation (e.g. `group.subfield`): uses get_field() and traverses the array.
	 *
	 * @since  1.2.0
	 * @param  int    $post_id    The post ID.
	 * @param  string $field_path Field key or dot-notation path.
	 * @return mixed Field value or null if not found.
	 */
	public function resolve( int $post_id, string $field_path ): mixed {
		// ACF dot notation: group.subfield.
		if ( str_contains( $field_path, '.' ) ) {
			$segments = explode( '.', $field_path );
			$root_key = $segments[0];

			if ( function_exists( 'get_field' ) ) {
				$root_value = get_field( $root_key, $post_id );

				if ( is_array( $root_value ) ) {
					$value = $root_value;
					for ( $i = 1; $i < count( $segments ); $i++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed
						if ( ! is_array( $value ) || ! isset( $value[ $segments[ $i ] ] ) ) {
							return null;
						}
						$value = $value[ $segments[ $i ] ];
					}
					return $value;
				}
			}

			return null;
		}

		// Plain meta key — try ACF first (handles type processing), fall back to post meta.
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_path, $post_id );
			if ( null !== $value && false !== $value ) {
				return $value;
			}
		}

		return get_post_meta( $post_id, $field_path, true ) ?: null;
	}
}
```

- [ ] **Step 4: Run FieldResolver tests**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Generator/FieldResolverTest.php
```

Expected: all 4 pass.

- [ ] **Step 5: Run the full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all existing tests still pass (FieldResolver not yet wired in).

### Step 3b — Update FrontmatterBuilder

- [ ] **Step 6: Update the `FrontmatterBuilderTest` factory**

Open `tests/Unit/Generator/FrontmatterBuilderTest.php`.

Add the import after the existing `use` statements:
```php
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
```

Update `make_builder()` to pass `FieldResolver` as the first argument:
```php
private function make_builder( array $options = [] ): FrontmatterBuilder {
    $defaults = [
        'include_taxonomies' => false,
        'include_meta'       => false,
        'meta_keys'          => [],
    ];
    return new FrontmatterBuilder(
        new FieldResolver(),
        new TaxonomyCollector(),
        array_merge( $defaults, $options )
    );
}
```

- [ ] **Step 7: Run FrontmatterBuilder tests to confirm they now fail (constructor mismatch)**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Generator/FrontmatterBuilderTest.php
```

Expected: failures — `FrontmatterBuilder::__construct()` not yet updated.

- [ ] **Step 8: Update `FrontmatterBuilder`**

Open `src/Generator/FrontmatterBuilder.php`.

**Add the import** after the namespace declaration:
```php
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
```

**Replace the constructor** (currently takes `TaxonomyCollector, array`):
```php
/**
 * @since  1.0.0
 * @param  FieldResolver        $field_resolver     Resolves custom field values.
 * @param  TaxonomyCollector    $taxonomy_collector Injected collector for testability.
 * @param  array<string, mixed> $options            Plugin options.
 */
public function __construct(
    private readonly FieldResolver $field_resolver,
    private readonly TaxonomyCollector $taxonomy_collector,
    private readonly array $options = array()
) {}
```

**In `build()`**, find the line (around line 59):
```php
$value = $this->resolve_field_value( $post->ID, $field_path );
```
Replace with:
```php
$value = $this->field_resolver->resolve( $post->ID, $field_path );
```

**Delete the entire `resolve_field_value()` static method** (lines ~117–150 — the full `public static function resolve_field_value(...)` block including its docblock).

- [ ] **Step 9: Run FrontmatterBuilder tests**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Generator/FrontmatterBuilderTest.php
```

Expected: all pass.

### Step 3c — Update Generator

- [ ] **Step 10: Update the `GeneratorTest` factory**

Open `tests/Unit/Generator/GeneratorTest.php`.

Add the import:
```php
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
```

Update `make_generator()` to pass a `FieldResolver` as the last argument:
```php
private function make_generator( array $options = [] ): Generator {
    $defaults = [
        'post_types' => [ 'post', 'page' ],
        'export_dir' => $this->export_subdir,
    ];
    return new Generator(
        array_merge( $defaults, $options ),
        $this->frontmatter_builder,
        $this->content_filter,
        $this->converter,
        $this->yaml_formatter,
        $this->file_writer,
        new FieldResolver()
    );
}
```

- [ ] **Step 11: Run GeneratorTest to confirm it now fails (constructor mismatch)**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Generator/GeneratorTest.php
```

Expected: failures.

- [ ] **Step 12: Update `Generator`**

Open `src/Generator/Generator.php`.

**Add the import** after the namespace declaration:
```php
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
```

**Add `FieldResolver` as the last constructor parameter:**
```php
public function __construct(
    private readonly array $options,
    private readonly FrontmatterBuilder $frontmatter_builder,
    private readonly ContentFilter $content_filter,
    private readonly Converter $converter,
    private readonly YamlFormatter $yaml_formatter,
    private readonly FileWriter $file_writer,
    private readonly FieldResolver $field_resolver
) {}
```

**In `get_post_content()`**, find the line (around line 244):
```php
$value = FrontmatterBuilder::resolve_field_value( $post->ID, $field_path );
```
Replace with:
```php
$value = $this->field_resolver->resolve( $post->ID, $field_path );
```

- [ ] **Step 13: Run GeneratorTest**

```bash
./vendor/bin/phpunit --no-coverage tests/Unit/Generator/GeneratorTest.php
```

Expected: all pass.

### Step 3d — Wire into Plugin and final verification

- [ ] **Step 14: Update `Plugin::define_generator()`**

Open `src/Core/Plugin.php`.

Add the import with the other `use` statements:
```php
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
```

In `define_generator()`, add `$field_resolver` before the `Generator` construction and pass it to both collaborators:

```php
private function define_generator( array $options ): void {
    $export_base       = Options::get_export_base( $options );
    $this->file_writer = new FileWriter( $export_base );

    $field_resolver = new FieldResolver();

    $generator = new Generator(
        $options,
        new FrontmatterBuilder( $field_resolver, new TaxonomyCollector(), $options ),
        new ContentFilter(),
        new Converter(),
        new YamlFormatter(),
        $this->file_writer,
        $field_resolver
    );

    // Store on object so other methods can access it.
    $this->generator = $generator;

    if ( ! empty( $options['auto_generate'] ) ) {
        $this->loader->add_action( 'save_post', $generator, 'on_save_post', 10, 2 );
    }
}
```

- [ ] **Step 15: Run the full suite**

```bash
./vendor/bin/phpunit --no-coverage
```

Expected: all tests pass. Count should be 169 existing + 4 new FieldResolver tests = **173 tests**.

- [ ] **Step 16: Commit**

```bash
git add wp-markdown-for-agents/src/Generator/FieldResolver.php \
        wp-markdown-for-agents/src/Generator/FrontmatterBuilder.php \
        wp-markdown-for-agents/src/Generator/Generator.php \
        wp-markdown-for-agents/src/Core/Plugin.php \
        wp-markdown-for-agents/tests/Unit/Generator/FieldResolverTest.php \
        wp-markdown-for-agents/tests/Unit/Generator/FrontmatterBuilderTest.php \
        wp-markdown-for-agents/tests/Unit/Generator/GeneratorTest.php
git commit -m "refactor: extract FieldResolver to fix cross-class static coupling in Generator"
```

---

## Final verification

- [ ] Run the full suite one last time and confirm all success criteria from the spec:

```bash
./vendor/bin/phpunit --no-coverage
```

1. `FrontmatterBuilder::resolve_field_value()` no longer exists
2. `SettingsPage.php` contains no function definitions outside its class
3. `StatsRepository` has no duplicated WHERE clause logic
4. All tests pass

# Source Class Technical Debt — Design Spec

**Date:** 2026-03-17
**Scope:** Three targeted refactors across `StatsRepository`, `SettingsPage`, and `FrontmatterBuilder`/`Generator`. No feature additions or public API changes.

---

## 1. StatsRepository — extract `build_where()`

### Problem
`get_stats()` and `get_total_count()` both build an identical WHERE clause from the same `$filters` array — roughly 30 lines duplicated verbatim.

### Solution
Extract a private method:

```php
private function build_where(array $filters): array {
    // returns ['sql' => string, 'values' => list<mixed>]
}
```

- Returns `['sql' => '', 'values' => []]` when no filters apply
- Returns `['sql' => 'WHERE post_id = %d', 'values' => [42]]` etc. when filters are present
- Both `get_stats()` and `get_total_count()` call `build_where()` and use the result
- No change to public method signatures or behaviour

### Affected files
- `src/Stats/StatsRepository.php` — extract method
- `tests/Unit/Stats/StatsRepositoryTest.php` — no changes needed (behaviour unchanged)

---

## 2. SettingsPage — move WP helper stubs to test mocks

### Problem
Two WordPress helper function stubs (`checked()` and `esc_textarea()`) are defined at file scope at the bottom of `src/Admin/SettingsPage.php`. These are test scaffolding that has leaked into a production source file.

### Solution
- Remove the `checked()` and `esc_textarea()` stub definitions from `SettingsPage.php`
- Add them to `tests/mocks/wordpress-mocks.php` alongside the existing WP function stubs, wrapped in `if (!function_exists(...))` guards

### Affected files
- `src/Admin/SettingsPage.php` — remove stubs
- `tests/mocks/wordpress-mocks.php` — add stubs
- No test changes needed — stubs remain available to the test suite from the mocks file

---

## 3. FieldResolver — fix the leaky static boundary

### Problem
`FrontmatterBuilder::resolve_field_value()` is `public static`, and `Generator` calls it directly via `FrontmatterBuilder::resolve_field_value(...)`. This is a cross-class static coupling: `Generator` reaches into `FrontmatterBuilder`'s internals rather than using an injected collaborator.

### Solution
Create a new class `src/Generator/FieldResolver.php` with a single public instance method:

```php
public function resolve(int $post_id, string $field_path): mixed
```

This contains the ACF dot-notation traversal and plain `get_post_meta()` fallback logic currently in `FrontmatterBuilder::resolve_field_value()`.

`normalize_value()` remains in `FrontmatterBuilder` — it is purely internal and not shared.

#### Constructor changes
- `FrontmatterBuilder::__construct()` gains `FieldResolver $field_resolver` as a new parameter; replaces the `$this->resolve_field_value()` call inside `build()` with `$this->field_resolver->resolve()`
- `Generator::__construct()` gains `FieldResolver $field_resolver` as a new parameter; `get_post_content()` calls `$this->field_resolver->resolve()` directly — replacing the `FrontmatterBuilder::resolve_field_value()` static cross-class call at line 244 of `Generator.php`
- `Plugin::define_generator()` creates a single `FieldResolver` instance and passes it to both

#### Removals
- `FrontmatterBuilder::resolve_field_value()` public static method is deleted entirely

#### Tests
- New `tests/Unit/Generator/FieldResolverTest.php` covering: plain meta key, ACF dot notation, missing key, `get_field()` not available
- `tests/Unit/Generator/FrontmatterBuilderTest.php` — update factory to pass a `FieldResolver` instance
- `tests/Unit/Generator/GeneratorTest.php` — update `make_generator()` factory to pass a `FieldResolver` instance

### Affected files
- `src/Generator/FieldResolver.php` — new
- `src/Generator/FrontmatterBuilder.php` — inject FieldResolver, remove static method
- `src/Generator/Generator.php` — inject FieldResolver, remove cross-class static call
- `src/Core/Plugin.php` — instantiate FieldResolver, pass to both
- `tests/Unit/Generator/FieldResolverTest.php` — new
- `tests/Unit/Generator/FrontmatterBuilderTest.php` — update factory
- `tests/Unit/Generator/GeneratorTest.php` (if exists) — update factory

---

## Architecture summary

```
Plugin::define_generator()
  ├── new FieldResolver()           ← single instance, shared
  ├── new FrontmatterBuilder(FieldResolver, TaxonomyCollector, $options)
  └── new Generator($options, FrontmatterBuilder, ContentFilter,
                    Converter, YamlFormatter, FileWriter, FieldResolver)
```

All existing 169 tests must pass after each change. Changes are independent and can be committed separately.

---

## Success criteria

1. No public API changes visible to plugin consumers
2. `FrontmatterBuilder::resolve_field_value()` no longer exists
3. `SettingsPage.php` contains no function definitions outside its class
4. `StatsRepository` has no duplicated WHERE clause logic
5. All 169 tests pass after each step

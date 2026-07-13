# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin ("Markdown for Agents and Statistics") for The Chancery Lane Project. It converts posts and taxonomy archives to Markdown, saves them as static files under `wp-content/uploads/`, and serves them to AI agents via HTTP content negotiation (`Accept: text/markdown`, `?output_format=md`, or known AI User-Agent). It also logs agent access statistics.

## Commands

```bash
composer install       # also runs Strauss to populate vendor-prefixed/
composer test          # full PHPUnit suite
vendor/bin/phpunit tests/Unit/Negotiate/NegotiatorTest.php            # single test file
vendor/bin/phpunit --filter test_method_name                          # single test
composer phpcs         # WordPress Coding Standards (src/ only, see phpcs.xml)
composer phpcbf        # auto-fix phpcs violations
bin/build-release.sh   # build clean vendor/ for WordPress.org release
```

Local dev hosting uses ddev; WP-CLI commands are registered as `wp markdown-agents <generate|status|delete|generate-taxonomies>`.

## Architecture

Namespace `Tclp\WpMarkdownForAgents\`, PSR-4 from `src/`. All wiring happens in `src/Core/Plugin.php`, which instantiates every class and registers hooks through `Core/Loader.php` — there is no service container. To understand what runs when, read `Plugin.php` first.

Two halves:

1. **Generation** (`src/Generator/`): `Generator` orchestrates `FrontmatterBuilder` (uses `FieldResolver` for meta/ACF fields incl. dot-notation groups, and `TaxonomyCollector`), `ContentFilter`, `Converter` (HTML→Markdown), `YamlFormatter`, and `FileWriter`. `TaxonomyArchiveGenerator` produces term-archive `.md` files; `ManifestGenerator` writes `manifest.json` content hashes and `changes.json` deltas for incremental export. Triggered by `save_post` (when auto-generate on), admin AJAX batches, or WP-CLI (`src/CLI/Commands.php`).

2. **Serving** (`src/Negotiate/`): `Negotiator` hooks `template_redirect` at priority 1; `AgentDetector` matches AI User-Agent strings. On a match it streams the pre-generated `.md` file (no page render) and `Stats/AccessLogger` records the hit via `StatsRepository` (custom DB table, migrated by `Core/Migrator` on every `plugins_loaded`).

Hook-ordering constraints are deliberate and commented in `Plugin.php`: meta-box save runs at priority 5 so exclusion meta exists before `Generator::on_save_post` at 10; deletion cleanup hooks are registered unconditionally even when auto-generate is off.

### Strauss / vendor-prefixed (important)

`league/html-to-markdown` is namespace-prefixed by Strauss into `vendor-prefixed/` as `Tclp\WpMarkdownForAgents\Vendor\League\HTMLToMarkdown\...` to avoid autoloader collisions with other plugins. **Never import `League\HTMLToMarkdown\` directly in `src/`** — always the `Vendor\`-prefixed namespace. `tests/Unit/StaticImportCheckTest.php` fails the build if you do. `.distignore` + `bin/build-release.sh` ensure only the prefixed copy ships.

## Tests

PHPUnit 9.6 unit tests only (`tests/Unit/`, mirrors `src/` structure). No WordPress install needed: `tests/mocks/wordpress-mocks.php` stubs WP functions (add stubs there as required), and `tests/mocks/namespace-mocks.php` shims PHP built-ins like `header()` via namespace-scoped functions (sent headers land in `$GLOBALS['_mock_sent_headers']`). Tests define the `WP_MFA_TESTING` constant.

## Conventions

- PHP 8.1+, `declare(strict_types=1)`, tabs for indentation, WordPress Coding Standards (with the exclusions in `phpcs.xml`).
- Global prefixes: `markdown_for_agents_` for filters/actions, `mfa_`/`tclp_mfa` for other globals.
- UK English spelling in comments and docs.
- Version lives in three places: the plugin header and `MARKDOWN_FOR_AGENTS_VERSION` in `markdown-for-agents.php`, and the stable tag/changelog in `readme.txt`. Bump all together.
- Public filters/actions are documented in `README.md` — update it when adding one.
- The root `index.md` export declares `okf_version: "0.1"` (OKF spec pin, see `docs/plans/SPEC.md`). Review this pin against the current OKF spec on every major release of the plugin.

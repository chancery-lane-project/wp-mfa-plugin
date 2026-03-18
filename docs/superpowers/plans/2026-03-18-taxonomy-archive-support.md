# Taxonomy Archive Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate pre-built Markdown files for all public taxonomy term archives and serve them via the existing content negotiation layer.

**Architecture:** A new `TaxonomyArchiveGenerator` class handles term-to-file generation (frontmatter + post listing). `Negotiator` is extended to detect `is_tax()/is_category()/is_tag()` contexts and serve files. `Generator::on_save_post` regenerates affected term archives after saving a singular post, and a two-hook pattern (before/after_delete_post) handles post deletions.

**Tech Stack:** PHP 8.0+, PHPUnit 9.6, WordPress hook system, existing `FileWriter`/`YamlFormatter`/`Options` classes.

---

## File Map

| Action | File | What changes |
|---|---|---|
| Create | `src/Generator/TaxonomyArchiveGenerator.php` | New class: path resolution, generation, deletion, batch |
| Create | `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php` | Full test suite for new class |
| Modify | `tests/mocks/wordpress-mocks.php` | Add `WP_Term`, `is_tax`, `is_category`, `is_tag`, `get_taxonomies`, `wp_get_post_terms`, `get_terms`, `get_term_link` stubs |
| Modify | `src/Negotiate/Negotiator.php` | Constructor + `maybe_serve_markdown` + `output_link_tag` |
| Modify | `tests/Unit/Negotiate/NegotiatorTest.php` | Update factory + add taxonomy tests |
| Modify | `src/Generator/Generator.php` | Constructor + `on_save_post` + two delete-post helpers |
| Modify | `tests/Unit/Generator/GeneratorTest.php` | Add taxonomy regeneration tests |
| Modify | `src/Core/Plugin.php` | Wire new class into all subsystems |
| Modify | `src/Admin/Admin.php` | Constructor + new AJAX handler |
| Modify | `src/Admin/SettingsPage.php` | Add taxonomy section to `render_generate_buttons` |
| Modify | `tests/Unit/Admin/AdminAjaxTest.php` | Add taxonomy batch AJAX test |
| Modify | `assets/js/bulk-generate.js` | Support `data-action` attribute |
| Modify | `src/CLI/Commands.php` | Inject `TaxonomyArchiveGenerator`, add `generate_taxonomies` |

---

## Task 1: WordPress mock stubs

Adds the WordPress stubs needed by all subsequent tasks. No implementation code yet — just test infrastructure.

**Files:**
- Modify: `tests/mocks/wordpress-mocks.php`

- [ ] **Step 1: Add `WP_Term` class stub** — append to `tests/mocks/wordpress-mocks.php`:

```php
// ---------------------------------------------------------------------------
// WP_Term stub
// ---------------------------------------------------------------------------

if (!class_exists('WP_Term')) {
    class WP_Term {
        public int    $term_id     = 0;
        public string $name        = '';
        public string $slug        = '';
        public string $taxonomy    = '';
        public string $description = '';
        public int    $count       = 0;

        public function __construct(array $props = []) {
            foreach ($props as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}
```

- [ ] **Step 2: Add taxonomy query function stubs** — append to `tests/mocks/wordpress-mocks.php`:

```php
// ---------------------------------------------------------------------------
// Taxonomy function stubs
// ---------------------------------------------------------------------------

$GLOBALS['_mock_is_tax']         = false;
$GLOBALS['_mock_taxonomies']     = ['category' => 'category', 'post_tag' => 'post_tag'];
$GLOBALS['_mock_post_terms']     = [];
$GLOBALS['_mock_taxonomy_terms'] = [];
$GLOBALS['_mock_term_link']      = [];

if (!function_exists('is_tax')) {
    function is_tax(string $taxonomy = '', int|string|array $term = ''): bool {
        return $GLOBALS['_mock_is_tax'] ?? false;
    }
}

if (!function_exists('is_category')) {
    function is_category(int|string|array $category = ''): bool {
        return $GLOBALS['_mock_is_tax'] ?? false;
    }
}

if (!function_exists('is_tag')) {
    function is_tag(int|string|array $tag = ''): bool {
        return $GLOBALS['_mock_is_tax'] ?? false;
    }
}

if (!function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names'): array {
        return $GLOBALS['_mock_taxonomies'] ?? ['category' => 'category', 'post_tag' => 'post_tag'];
    }
}

if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms(int $post_id, string $taxonomy, array $args = []): array|\WP_Error {
        return $GLOBALS['_mock_post_terms'][$post_id][$taxonomy] ?? [];
    }
}

if (!function_exists('get_terms')) {
    function get_terms(array|string $args = []): array|\WP_Error {
        $taxonomy = is_array($args) ? ($args['taxonomy'] ?? '') : $args;
        return $GLOBALS['_mock_taxonomy_terms'][$taxonomy] ?? [];
    }
}

if (!function_exists('get_term_link')) {
    function get_term_link(\WP_Term|int|string $term, string $taxonomy = ''): string|\WP_Error {
        if ($term instanceof \WP_Term) {
            return $GLOBALS['_mock_term_link'][$term->term_id]
                ?? 'https://example.com/' . $term->taxonomy . '/' . $term->slug . '/';
        }
        return 'https://example.com/term/' . (int) $term . '/';
    }
}
```

- [ ] **Step 3: Run full test suite to confirm baseline**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test
```
Expected: all tests pass (no regressions from stub additions)

- [ ] **Step 4: Commit**

```bash
git add tests/mocks/wordpress-mocks.php
git commit -m "test: add WP_Term and taxonomy function stubs to mock layer"
```

---

## Task 2: TaxonomyArchiveGenerator

New class responsible for all taxonomy archive operations.

**Files:**
- Create: `src/Generator/TaxonomyArchiveGenerator.php`
- Create: `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\FileWriter;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Generator\YamlFormatter;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator
 */
class TaxonomyArchiveGeneratorTest extends TestCase {

    private string $export_subdir;
    private string $base_dir;

    /** @var FileWriter&MockObject */
    private FileWriter $file_writer;

    /** @var YamlFormatter&MockObject */
    private YamlFormatter $yaml_formatter;

    private TaxonomyArchiveGenerator $generator;

    protected function setUp(): void {
        $this->export_subdir = 'wp-mfa-tax-' . uniqid();
        $this->base_dir      = sys_get_temp_dir() . '/' . $this->export_subdir;
        mkdir( $this->base_dir, 0755, true );

        $GLOBALS['_mock_upload_dir'] = [
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'https://example.com/wp-content/uploads',
        ];
        $GLOBALS['_mock_posts']          = [];
        $GLOBALS['_mock_taxonomy_terms'] = [];
        $GLOBALS['_mock_taxonomies']     = ['category' => 'category', 'post_tag' => 'post_tag'];

        $this->file_writer    = $this->createMock( FileWriter::class );
        $this->yaml_formatter = $this->createMock( YamlFormatter::class );

        $this->generator = $this->make_generator();
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->base_dir );
        unset( $GLOBALS['_mock_upload_dir'] );
    }

    private function make_generator( array $options = [] ): TaxonomyArchiveGenerator {
        return new TaxonomyArchiveGenerator(
            array_merge( ['export_dir' => $this->export_subdir], $options ),
            $this->yaml_formatter,
            $this->file_writer,
        );
    }

    private function make_term( array $props = [] ): \WP_Term {
        return new \WP_Term( array_merge([
            'term_id'     => 10,
            'name'        => 'Climate Law',
            'slug'        => 'climate-law',
            'taxonomy'    => 'category',
            'description' => 'Posts about climate law.',
            'count'       => 3,
        ], $props ) );
    }

    // -----------------------------------------------------------------------
    // get_export_path
    // -----------------------------------------------------------------------

    public function test_get_export_path_uses_taxonomy_and_slug(): void {
        $term = $this->make_term();
        $path = $this->generator->get_export_path( $term );
        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR . 'taxonomy' . DIRECTORY_SEPARATOR . 'category' . DIRECTORY_SEPARATOR . 'climate-law.md',
            $path
        );
    }

    public function test_get_export_path_applies_sanitize_file_name(): void {
        // sanitize_file_name replaces spaces and special chars with hyphens.
        $term = $this->make_term( ['taxonomy' => 'my taxonomy', 'slug' => 'my slug'] );
        $path = $this->generator->get_export_path( $term );
        $this->assertStringContainsString( 'my-taxonomy', $path );
        $this->assertStringEndsWith( 'my-slug.md', $path );
    }

    // -----------------------------------------------------------------------
    // generate_term — frontmatter
    // -----------------------------------------------------------------------

    public function test_generate_term_builds_frontmatter_with_all_standard_fields(): void {
        $term = $this->make_term();

        $this->yaml_formatter->expects( $this->once() )
            ->method( 'format' )
            ->with( $this->callback( function ( array $fm ) {
                return 'Climate Law'       === $fm['title']
                    && 'taxonomy_archive'  === $fm['type']
                    && 'category'          === $fm['taxonomy']
                    && 'climate-law'       === $fm['slug']
                    && 10                  === $fm['term_id']
                    && isset( $fm['permalink'] )
                    && 0                   === $fm['post_count']; // no posts in $GLOBALS['_mock_posts']
            } ) )
            ->willReturn( "---\ntitle: Climate Law\n---\n" );

        $this->file_writer->method( 'write' )->willReturn( true );

        $this->generator->generate_term( $term );
    }

    public function test_generate_term_includes_description_when_set(): void {
        $term = $this->make_term( ['description' => 'About climate law.'] );

        $this->yaml_formatter->expects( $this->once() )
            ->method( 'format' )
            ->with( $this->callback( fn( array $fm ) => 'About climate law.' === $fm['description'] ) )
            ->willReturn( '' );

        $this->file_writer->method( 'write' )->willReturn( true );
        $this->generator->generate_term( $term );
    }

    public function test_generate_term_omits_description_when_empty(): void {
        $term = $this->make_term( ['description' => ''] );

        $this->yaml_formatter->expects( $this->once() )
            ->method( 'format' )
            ->with( $this->callback( fn( array $fm ) => ! array_key_exists( 'description', $fm ) ) )
            ->willReturn( '' );

        $this->file_writer->method( 'write' )->willReturn( true );
        $this->generator->generate_term( $term );
    }

    // -----------------------------------------------------------------------
    // generate_term — body
    // -----------------------------------------------------------------------

    public function test_generate_term_body_lists_posts_with_excerpt(): void {
        $GLOBALS['_mock_posts'] = [
            new \WP_Post( ['ID' => 1, 'post_title' => 'Post One', 'post_excerpt' => 'About one.', 'post_name' => 'post-one'] ),
        ];
        $GLOBALS['_mock_permalink'] = 'https://example.com/post-one/';

        $term = $this->make_term();

        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );

        $captured_content = null;
        $this->file_writer->expects( $this->once() )
            ->method( 'write' )
            ->with(
                $this->anything(),
                $this->callback( function ( string $content ) use ( &$captured_content ) {
                    $captured_content = $content;
                    return true;
                } )
            )
            ->willReturn( true );

        $this->generator->generate_term( $term );

        $this->assertStringContainsString( '- [Post One](https://example.com/post-one/) — About one.', $captured_content );
    }

    public function test_generate_term_body_omits_excerpt_when_empty(): void {
        $GLOBALS['_mock_posts']     = [
            new \WP_Post( ['ID' => 1, 'post_title' => 'Post One', 'post_excerpt' => '', 'post_name' => 'post-one'] ),
        ];
        $GLOBALS['_mock_permalink'] = 'https://example.com/post-one/';

        $this->yaml_formatter->method( 'format' )->willReturn( '' );

        $captured = null;
        $this->file_writer->expects( $this->once() )
            ->method( 'write' )
            ->with( $this->anything(), $this->callback( function ( string $c ) use ( &$captured ) {
                $captured = $c;
                return true;
            } ) )
            ->willReturn( true );

        $this->generator->generate_term( $this->make_term() );
        $this->assertStringContainsString( '- [Post One](https://example.com/post-one/)', $captured );
        $this->assertStringNotContainsString( ' — ', $captured );
    }

    public function test_generate_term_body_includes_header_and_count(): void {
        $GLOBALS['_mock_posts'] = [];

        $this->yaml_formatter->method( 'format' )->willReturn( '' );

        $captured = null;
        $this->file_writer->expects( $this->once() )
            ->method( 'write' )
            ->with( $this->anything(), $this->callback( function ( string $c ) use ( &$captured ) {
                $captured = $c;
                return true;
            } ) )
            ->willReturn( true );

        $this->generator->generate_term( $this->make_term() );

        $this->assertStringContainsString( '# Climate Law', $captured );
        $this->assertStringContainsString( 'Posts in this archive: 0', $captured );
    }

    // -----------------------------------------------------------------------
    // delete_term_file
    // -----------------------------------------------------------------------

    public function test_delete_term_file_returns_false_when_file_missing(): void {
        $term = $this->make_term();
        // No file on disk — file_writer->delete should not be called
        $this->file_writer->expects( $this->never() )->method( 'delete' );
        $result = $this->generator->delete_term_file( $term );
        $this->assertFalse( $result );
    }

    public function test_delete_term_file_delegates_to_file_writer_when_file_exists(): void {
        $term = $this->make_term();
        $path = $this->generator->get_export_path( $term );

        // Create the directory and a real file so file_exists() returns true.
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        file_put_contents( $path, '# Test' );

        $this->file_writer->expects( $this->once() )
            ->method( 'delete' )
            ->with( $path )
            ->willReturn( true );

        $result = $this->generator->delete_term_file( $term );
        $this->assertTrue( $result );
    }

    // -----------------------------------------------------------------------
    // generate_batch — response shape
    // -----------------------------------------------------------------------

    public function test_generate_batch_returns_correct_shape(): void {
        $term = $this->make_term();
        $GLOBALS['_mock_taxonomy_terms']['category'] = [ $term ];

        $this->yaml_formatter->method( 'format' )->willReturn( '' );
        $this->file_writer->method( 'write' )->willReturn( true );

        $result = $this->generator->generate_batch( 0, 10 );

        $this->assertArrayHasKey( 'total', $result );
        $this->assertArrayHasKey( 'processed', $result );
        $this->assertArrayHasKey( 'errors', $result );
        $this->assertIsInt( $result['total'] );
        $this->assertIsInt( $result['processed'] );
        $this->assertIsArray( $result['errors'] );
    }

    public function test_generate_batch_with_zero_limit_returns_empty(): void {
        $result = $this->generator->generate_batch( 0, 0 );
        $this->assertSame( ['total' => 0, 'processed' => 0, 'errors' => []], $result );
    }

    public function test_generate_batch_paginates_correctly(): void {
        $terms = [
            $this->make_term( ['term_id' => 1, 'slug' => 'term-1'] ),
            $this->make_term( ['term_id' => 2, 'slug' => 'term-2'] ),
            $this->make_term( ['term_id' => 3, 'slug' => 'term-3'] ),
        ];
        $GLOBALS['_mock_taxonomy_terms']['category'] = $terms;

        $this->yaml_formatter->method( 'format' )->willReturn( '' );
        $this->file_writer->method( 'write' )->willReturn( true );

        // Fetch second page: offset=2, limit=2 — should return 1 term
        $result = $this->generator->generate_batch( 2, 2 );
        $this->assertSame( 3, $result['total'] );
        $this->assertSame( 1, $result['processed'] );
    }

    // -----------------------------------------------------------------------
    // on_delete_term
    // -----------------------------------------------------------------------

    public function test_on_delete_term_delegates_to_delete_term_file(): void {
        $term = $this->make_term();
        $path = $this->generator->get_export_path( $term );
        $dir  = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        file_put_contents( $path, '# Test' );

        $this->file_writer->expects( $this->once() )->method( 'delete' )->willReturn( true );
        $this->generator->on_delete_term( $term->term_id, 0, $term->taxonomy, $term );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function remove_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( scandir( $dir ) as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $t = $dir . '/' . $item;
            is_dir( $t ) ? $this->remove_dir( $t ) : unlink( $t );
        }
        rmdir( $dir );
    }
}
```

- [ ] **Step 2: Run tests — expect failure (class not found)**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test -- --filter TaxonomyArchiveGeneratorTest
```
Expected: `Error: Class "Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator" not found`

- [ ] **Step 3: Create `src/Generator/TaxonomyArchiveGenerator.php`**

```php
<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Generates and manages Markdown archive files for taxonomy terms.
 *
 * File path pattern: {export_dir}/taxonomy/{taxonomy}/{term-slug}.md
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class TaxonomyArchiveGenerator {

    /**
     * @since  1.1.0
     * @param  array<string, mixed> $options        Plugin options.
     * @param  YamlFormatter        $yaml_formatter Serialises frontmatter to YAML.
     * @param  FileWriter           $file_writer    Handles filesystem I/O.
     */
    public function __construct(
        private readonly array $options,
        private readonly YamlFormatter $yaml_formatter,
        private readonly FileWriter $file_writer,
    ) {}

    /**
     * Return the full filesystem path for a term's Markdown archive file.
     *
     * Path pattern: {export_dir}/taxonomy/{taxonomy}/{term-slug}.md
     * Both segments are passed through sanitize_file_name().
     *
     * @since  1.1.0
     * @param  \WP_Term $term The term.
     * @return string
     */
    public function get_export_path( \WP_Term $term ): string {
        $base     = \Tclp\WpMarkdownForAgents\Core\Options::get_export_base( $this->options );
        $taxonomy = sanitize_file_name( $term->taxonomy );
        $slug     = sanitize_file_name( $term->slug );

        return $base
            . DIRECTORY_SEPARATOR . 'taxonomy'
            . DIRECTORY_SEPARATOR . $taxonomy
            . DIRECTORY_SEPARATOR . $slug . '.md';
    }

    /**
     * Generate and write the Markdown archive file for a term.
     *
     * @since  1.1.0
     * @param  \WP_Term $term The term.
     * @return bool True on success.
     */
    public function generate_term( \WP_Term $term ): bool {
        $posts = $this->get_term_posts( $term );

        $frontmatter = [
            'title'      => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
            'type'       => 'taxonomy_archive',
            'taxonomy'   => $term->taxonomy,
            'slug'       => $term->slug,
            'term_id'    => $term->term_id,
            'permalink'  => get_term_link( $term ),
            'post_count' => count( $posts ),
        ];

        if ( '' !== $term->description ) {
            $frontmatter['description'] = $term->description;
        }

        /**
         * Modify the frontmatter array for a taxonomy archive before serialisation.
         *
         * @since  1.1.0
         * @param  array<string, mixed> $frontmatter The frontmatter array.
         * @param  \WP_Term             $term        The term.
         */
        $frontmatter = (array) apply_filters( 'wp_mfa_taxonomy_frontmatter', $frontmatter, $term );

        $yaml    = $this->yaml_formatter->format( $frontmatter );
        $body    = $this->build_body( $term, $posts );
        $content = $yaml . "\n" . $body;

        return $this->file_writer->write( $this->get_export_path( $term ), $content );
    }

    /**
     * Delete the archive file for a term.
     *
     * Returns false (not an error) if the file does not exist.
     *
     * @since  1.1.0
     * @param  \WP_Term $term The term.
     * @return bool True if deleted, false if file was not found or deletion failed.
     */
    public function delete_term_file( \WP_Term $term ): bool {
        $path = $this->get_export_path( $term );

        if ( ! file_exists( $path ) ) {
            return false;
        }

        return $this->file_writer->delete( $path );
    }

    /**
     * Hook callback for delete_term — removes the term's archive file.
     *
     * @since  1.1.0
     * @param  int      $term_id      Term ID.
     * @param  int      $tt_id        Term taxonomy ID.
     * @param  string   $taxonomy     Taxonomy slug.
     * @param  \WP_Term $deleted_term The deleted term object.
     */
    public function on_delete_term( int $term_id, int $tt_id, string $taxonomy, \WP_Term $deleted_term ): void {
        $this->delete_term_file( $deleted_term );
    }

    /**
     * Generate archives for all public taxonomy terms (or one taxonomy).
     *
     * @since  1.1.0
     * @param  string $taxonomy Optional. Limit to one taxonomy slug.
     * @return array{success: int, skipped: int, failed: int}
     */
    public function generate_all( string $taxonomy = '' ): array {
        $results    = [ 'success' => 0, 'skipped' => 0, 'failed' => 0 ];
        $taxonomies = $taxonomy
            ? [ $taxonomy ]
            : array_keys( get_taxonomies( [ 'public' => true ] ) );

        foreach ( $taxonomies as $tax ) {
            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );

            if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                continue;
            }

            foreach ( $terms as $term ) {
                if ( $this->generate_term( $term ) ) {
                    ++$results['success'];
                } else {
                    ++$results['failed'];
                }
            }
        }

        return $results;
    }

    /**
     * Generate a paginated batch of term archives across all public taxonomies.
     *
     * Mirrors Generator::generate_batch() — returns the same response shape so
     * the Admin AJAX handler and bulk-generate.js can treat them identically.
     *
     * @since  1.1.0
     * @param  int $offset Zero-based offset into the full term list.
     * @param  int $limit  Maximum terms to process in this batch.
     * @return array{total: int, processed: int, errors: list<array{term_id: int, message: string}>}
     */
    public function generate_batch( int $offset, int $limit ): array {
        if ( $limit <= 0 ) {
            return [ 'total' => 0, 'processed' => 0, 'errors' => [] ];
        }

        $all_terms = $this->get_all_public_terms();
        $total     = count( $all_terms );
        $batch     = array_slice( $all_terms, $offset, $limit );
        $processed = 0;
        $errors    = [];

        foreach ( $batch as $term ) {
            try {
                if ( $this->generate_term( $term ) ) {
                    ++$processed;
                }
            } catch ( \Throwable $e ) {
                $errors[] = [
                    'term_id' => $term->term_id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'total'     => $total,
            'processed' => $processed,
            'errors'    => $errors,
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Collect all terms across every public taxonomy.
     *
     * @since  1.1.0
     * @return \WP_Term[]
     */
    private function get_all_public_terms(): array {
        $taxonomies = array_keys( get_taxonomies( [ 'public' => true ] ) );
        $all_terms  = [];

        foreach ( $taxonomies as $tax ) {
            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );

            if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
                $all_terms = array_merge( $all_terms, $terms );
            }
        }

        return $all_terms;
    }

    /**
     * Fetch all published posts in a term, batched to avoid memory exhaustion.
     *
     * @since  1.1.0
     * @param  \WP_Term $term The term.
     * @return \WP_Post[]
     */
    private function get_term_posts( \WP_Term $term ): array {
        $batch_size = 100;
        $offset     = 0;
        $all_posts  = [];

        do {
            $posts = get_posts( // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
                [
                    'post_status'    => 'publish',
                    'posts_per_page' => $batch_size,
                    'offset'         => $offset,
                    'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                        [
                            'taxonomy' => $term->taxonomy,
                            'field'    => 'term_id',
                            'terms'    => $term->term_id,
                        ],
                    ],
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'no_found_rows'  => true,
                ]
            );

            $all_posts = array_merge( $all_posts, $posts );
            $offset   += $batch_size;
        } while ( count( $posts ) === $batch_size );

        return $all_posts;
    }

    /**
     * Build the Markdown body for a term archive.
     *
     * @since  1.1.0
     * @param  \WP_Term    $term  The term.
     * @param  \WP_Post[]  $posts Published posts in this term.
     * @return string
     */
    private function build_body( \WP_Term $term, array $posts ): string {
        $name  = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
        $count = count( $posts );

        $lines = [
            '# ' . $name,
            '',
            'Posts in this archive: ' . $count,
            '',
        ];

        foreach ( $posts as $post ) {
            $title   = strip_tags( $post->post_title );
            $url     = get_permalink( $post->ID );
            $excerpt = strip_tags( $post->post_excerpt );

            $line = '- [' . $title . '](' . $url . ')';

            if ( '' !== $excerpt ) {
                $line .= ' — ' . $excerpt;
            }

            $lines[] = $line;
        }

        return implode( "\n", $lines ) . "\n";
    }
}
```

**Important:** The em dash in `build_body` must be the literal UTF-8 character `—`, not the Unicode escape `\u2014` (PHP strings don't expand those). Replace `\u2014` with the literal `—` character when writing the file.

- [ ] **Step 4: Run tests — expect pass**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test -- --filter TaxonomyArchiveGeneratorTest
```
Expected: all tests in `TaxonomyArchiveGeneratorTest` pass

- [ ] **Step 5: Commit**

```bash
git add src/Generator/TaxonomyArchiveGenerator.php tests/Unit/Generator/TaxonomyArchiveGeneratorTest.php
git commit -m "feat: add TaxonomyArchiveGenerator with full test suite"
```

---

## Task 3: Negotiator changes

Inject `TaxonomyArchiveGenerator`, add taxonomy serving branch to `maybe_serve_markdown`, add taxonomy link tag to `output_link_tag`.

**Files:**
- Modify: `src/Negotiate/Negotiator.php`
- Modify: `tests/Unit/Negotiate/NegotiatorTest.php`

- [ ] **Step 1: Write the failing tests** — add to `NegotiatorTest.php`

First update the `make_negotiator` factory and add a `TaxonomyArchiveGenerator` mock field to the test class:

```php
// At class level, add:
/** @var TaxonomyArchiveGenerator&MockObject */
private TaxonomyArchiveGenerator $taxonomy_generator;
```

In `setUp()`, add:
```php
$this->taxonomy_generator = $this->createMock( TaxonomyArchiveGenerator::class );
$GLOBALS['_mock_is_tax']  = false;
```

In `tearDown()`, add:
```php
unset( $GLOBALS['_mock_is_tax'] );
```

Update `make_negotiator()` to pass the new dependency:
```php
private function make_negotiator( array $options = [] ): Negotiator {
    $merged = array_merge( [
        'post_types'       => [ 'post', 'page' ],
        'export_dir'       => 'wp-mfa-exports',
        'ua_force_enabled' => false,
        'ua_agent_strings' => [],
    ], $options );
    return new Negotiator(
        $merged,
        $this->generator,
        $this->taxonomy_generator,
        new AgentDetector( $merged ),
        $this->logger
    );
}
```

Add these new test methods:

```php
// -----------------------------------------------------------------------
// maybe_serve_markdown — taxonomy archive branch
// -----------------------------------------------------------------------

public function test_does_nothing_for_taxonomy_when_no_markdown_signal(): void {
    $GLOBALS['_mock_is_singular'] = false;
    $GLOBALS['_mock_is_tax']      = true;
    $_SERVER['HTTP_ACCEPT']       = 'text/html';

    $this->taxonomy_generator->expects( $this->never() )->method( 'get_export_path' );

    $this->make_negotiator()->maybe_serve_markdown();
}

public function test_taxonomy_branch_does_nothing_when_serve_taxonomies_filter_returns_false(): void {
    $GLOBALS['_mock_is_singular'] = false;
    $GLOBALS['_mock_is_tax']      = true;
    $_SERVER['HTTP_ACCEPT']       = 'text/markdown';

    $GLOBALS['_mock_apply_filters']['wp_mfa_serve_taxonomies'] = fn( bool $v ): bool => false;
    $this->taxonomy_generator->expects( $this->never() )->method( 'get_export_path' );

    $this->make_negotiator()->maybe_serve_markdown();

    unset( $GLOBALS['_mock_apply_filters']['wp_mfa_serve_taxonomies'] );
}

public function test_taxonomy_branch_does_nothing_when_queried_object_is_not_wp_term(): void {
    $GLOBALS['_mock_is_singular']    = false;
    $GLOBALS['_mock_is_tax']         = true;
    $GLOBALS['_mock_queried_object'] = (object) ['ID' => 1]; // WP_Post, not WP_Term
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    $this->taxonomy_generator->expects( $this->never() )->method( 'get_export_path' );

    $this->make_negotiator()->maybe_serve_markdown();
}

public function test_taxonomy_branch_does_nothing_when_md_file_missing(): void {
    $GLOBALS['_mock_is_singular']    = false;
    $GLOBALS['_mock_is_tax']         = true;
    $GLOBALS['_mock_queried_object'] = new \WP_Term( ['term_id' => 1, 'taxonomy' => 'category', 'slug' => 'news'] );
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    $this->taxonomy_generator->method( 'get_export_path' )->willReturn( '/nonexistent/news.md' );

    $this->make_negotiator()->maybe_serve_markdown();
    $this->addToAssertionCount( 1 );
}

public function test_taxonomy_branch_serves_file_when_exists(): void {
    $md_file = $this->tmp_dir . '/news.md';
    file_put_contents( $md_file, '# News' );

    $GLOBALS['_mock_is_singular']    = false;
    $GLOBALS['_mock_is_tax']         = true;
    $GLOBALS['_mock_queried_object'] = new \WP_Term( ['term_id' => 1, 'taxonomy' => 'category', 'slug' => 'news'] );
    $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

    $this->taxonomy_generator->method( 'get_export_path' )->willReturn( $md_file );

    $neg = $this->make_negotiator();
    try {
        $neg->maybe_serve_markdown();
    } catch ( \RuntimeException $e ) {
        // readfile() throws in tests — expected
    }

    $this->assertContains( 'Content-Type: text/markdown; charset=utf-8', $GLOBALS['_mock_sent_headers'] );
    $this->assertSame( $md_file, $GLOBALS['_mock_readfile_path'] );
}

// -----------------------------------------------------------------------
// output_link_tag — taxonomy branch
// -----------------------------------------------------------------------

public function test_taxonomy_link_tag_not_output_when_no_md_file(): void {
    $GLOBALS['_mock_is_singular']    = false;
    $GLOBALS['_mock_is_tax']         = true;
    $GLOBALS['_mock_queried_object'] = new \WP_Term( ['term_id' => 1, 'taxonomy' => 'category', 'slug' => 'news'] );

    $this->taxonomy_generator->method( 'get_export_path' )->willReturn( '/nonexistent/news.md' );

    ob_start();
    $this->make_negotiator()->output_link_tag();
    $output = ob_get_clean();

    $this->assertSame( '', $output );
}

public function test_taxonomy_link_tag_output_when_md_file_exists(): void {
    $md_file = $this->tmp_dir . '/news.md';
    file_put_contents( $md_file, '# News' );

    $GLOBALS['_mock_is_singular']    = false;
    $GLOBALS['_mock_is_tax']         = true;
    $GLOBALS['_mock_queried_object'] = new \WP_Term( ['term_id' => 1, 'taxonomy' => 'category', 'slug' => 'news'] );

    $this->taxonomy_generator->method( 'get_export_path' )->willReturn( $md_file );

    ob_start();
    $this->make_negotiator()->output_link_tag();
    $output = ob_get_clean();

    $this->assertStringContainsString( 'rel="alternate"', $output );
    $this->assertStringContainsString( 'type="text/markdown"', $output );
    $this->assertStringContainsString( 'output_format=md', $output );
}
```

- [ ] **Step 2: Run the new tests — expect failure**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test -- --filter NegotiatorTest
```
Expected: new tests fail (constructor mismatch or wrong branch logic)

- [ ] **Step 3: Update `src/Negotiate/Negotiator.php`**

Add the import at the top:
```php
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
```

Update the constructor:
```php
public function __construct(
    private readonly array $options,
    private readonly Generator $generator,
    private readonly TaxonomyArchiveGenerator $taxonomy_generator,
    private readonly AgentDetector $agent_detector,
    private readonly AccessLogger $access_logger
) {}
```

Replace `maybe_serve_markdown()` entirely:
```php
public function maybe_serve_markdown(): void {
    $accept    = $_SERVER['HTTP_ACCEPT'] ?? '';          // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $format_qp = sanitize_key( $_GET['output_format'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    $matched_agent = $this->agent_detector->get_matched_agent( $ua );
    $via_accept    = str_contains( $accept, 'text/markdown' );
    $via_query     = in_array( $format_qp, array( 'md', 'markdown' ), true );

    if ( ! $via_accept && ! $via_query && null === $matched_agent ) {
        return;
    }

    $agent_label = $matched_agent ?? ( $via_accept ? 'accept-header' : 'query-param' );

    if ( $this->is_eligible_singular() ) {
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        /** @see maybe_serve_markdown docblock for filter docs */
        if ( ! apply_filters( 'wp_mfa_serve_enabled', true, $post ) ) {
            return;
        }

        $filepath = $this->generator->get_export_path( $post );

        if ( ! file_exists( $filepath ) || ! $this->is_safe_filepath( $filepath ) ) {
            return;
        }

        $this->access_logger->log_access( $post->ID, $agent_label );
        $this->send_markdown_file( $filepath, $via_accept );
        return;
    }

    if ( is_tax() || is_category() || is_tag() ) {
        /**
         * Whether to serve Markdown for taxonomy archive pages.
         *
         * @since 1.1.0
         * @param bool $enabled Whether serving is enabled. Default true.
         */
        if ( ! apply_filters( 'wp_mfa_serve_taxonomies', true ) ) {
            return;
        }

        $term = get_queried_object();
        if ( ! $term instanceof \WP_Term ) {
            return;
        }

        $filepath = $this->taxonomy_generator->get_export_path( $term );

        if ( ! file_exists( $filepath ) || ! $this->is_safe_filepath( $filepath ) ) {
            return;
        }

        $this->send_markdown_file( $filepath, $via_accept );
    }
}
```

Replace `output_link_tag()` entirely:
```php
public function output_link_tag(): void {
    if ( $this->is_eligible_singular() ) {
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $filepath = $this->generator->get_export_path( $post );
        if ( ! file_exists( $filepath ) ) {
            return;
        }

        $url = esc_url( add_query_arg( 'output_format', 'md', get_permalink( $post->ID ) ) );
        echo '<link rel="alternate" type="text/markdown" href="' . $url . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return;
    }

    if ( is_tax() || is_category() || is_tag() ) {
        $term = get_queried_object();
        if ( ! $term instanceof \WP_Term ) {
            return;
        }

        $filepath = $this->taxonomy_generator->get_export_path( $term );
        if ( ! file_exists( $filepath ) ) {
            return;
        }

        $term_link = get_term_link( $term );
        if ( is_wp_error( $term_link ) ) {
            return;
        }

        $url = esc_url( add_query_arg( 'output_format', 'md', $term_link ) );
        echo '<link rel="alternate" type="text/markdown" href="' . $url . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
```

Add the private `send_markdown_file()` helper (replaces the inline header block):
```php
/**
 * Send HTTP headers and stream the Markdown file to the client.
 *
 * @since  1.1.0
 * @param  string $filepath   Absolute path to the .md file.
 * @param  bool   $via_accept True when negotiated via Accept header (adds Vary).
 */
private function send_markdown_file( string $filepath, bool $via_accept ): void {
    header( 'Content-Type: text/markdown; charset=utf-8' );

    if ( $via_accept ) {
        header( 'Vary: Accept' );
    }

    header( 'X-Markdown-Source: wp-markdown-for-agents' );

    /**
     * Filter the Content-Signal header value.
     *
     * @since 1.1.0
     * @param string $signal The default signal value.
     */
    $content_signal = str_replace(
        array( "\r", "\n" ),
        '',
        (string) apply_filters( 'wp_mfa_content_signal', 'ai-input=yes, search=yes' )
    );

    if ( $content_signal ) {
        header( 'Content-Signal: ' . $content_signal );
    }

    readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    exit;
}
```

- [ ] **Step 4: Run all Negotiator tests**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test -- --filter NegotiatorTest
```
Expected: all tests pass (including pre-existing tests)

- [ ] **Step 5: Run the full suite**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test
```
Expected: all tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Negotiate/Negotiator.php tests/Unit/Negotiate/NegotiatorTest.php
git commit -m "feat: extend Negotiator to serve taxonomy archive Markdown files"
```

---

## Task 4: Generator — save_post extension and post deletion hooks

**Files:**
- Modify: `src/Generator/Generator.php`
- Modify: `tests/Unit/Generator/GeneratorTest.php`

- [ ] **Step 1: Write the failing tests** — add to `GeneratorTest.php`

Add import and mock field:
```php
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;

/** @var TaxonomyArchiveGenerator&MockObject */
private TaxonomyArchiveGenerator $taxonomy_generator;
```

In `setUp()`, add:
```php
$this->taxonomy_generator = $this->createMock( TaxonomyArchiveGenerator::class );
$GLOBALS['_mock_post_terms'] = [];
```

Update `make_generator()` to pass the new optional dependency when needed (keep existing calls without it so existing tests still pass):
```php
private function make_generator_with_taxonomy( array $options = [] ): Generator {
    $defaults = [
        'post_types'    => [ 'post', 'page' ],
        'export_dir'    => $this->export_subdir,
        'auto_generate' => true,
    ];
    return new Generator(
        array_merge( $defaults, $options ),
        $this->frontmatter_builder,
        $this->content_filter,
        $this->converter,
        $this->yaml_formatter,
        $this->file_writer,
        $this->createMock( FieldResolver::class ),
        $this->taxonomy_generator,
    );
}
```

Add test methods:
```php
// -----------------------------------------------------------------------
// on_save_post — taxonomy archive regeneration
// -----------------------------------------------------------------------

public function test_on_save_post_regenerates_term_archives_for_published_post(): void {
    $term = new \WP_Term( ['term_id' => 10, 'taxonomy' => 'category', 'slug' => 'news'] );
    $GLOBALS['_mock_post_terms'][1]['category'] = [ $term ];
    $GLOBALS['_mock_taxonomies'] = ['category' => 'category'];

    $post = new \WP_Post( ['ID' => 1, 'post_type' => 'post', 'post_status' => 'publish'] );

    $this->frontmatter_builder->method( 'build' )->willReturn( [] );
    $this->content_filter->method( 'filter' )->willReturnArgument( 0 );
    $this->converter->method( 'convert' )->willReturn( '' );
    $this->yaml_formatter->method( 'format' )->willReturn( '' );
    $this->file_writer->method( 'write' )->willReturn( true );

    $this->taxonomy_generator->expects( $this->once() )
        ->method( 'generate_term' )
        ->with( $term );

    $gen = $this->make_generator_with_taxonomy();
    $gen->on_save_post( 1, $post );
}

public function test_on_save_post_does_not_regenerate_terms_when_auto_generate_disabled(): void {
    $term = new \WP_Term( ['term_id' => 10, 'taxonomy' => 'category', 'slug' => 'news'] );
    $GLOBALS['_mock_post_terms'][1]['category'] = [ $term ];
    $GLOBALS['_mock_taxonomies'] = ['category' => 'category'];

    $post = new \WP_Post( ['ID' => 1, 'post_type' => 'post', 'post_status' => 'publish'] );

    $this->frontmatter_builder->method( 'build' )->willReturn( [] );
    $this->content_filter->method( 'filter' )->willReturnArgument( 0 );
    $this->converter->method( 'convert' )->willReturn( '' );
    $this->yaml_formatter->method( 'format' )->willReturn( '' );
    $this->file_writer->method( 'write' )->willReturn( true );

    $this->taxonomy_generator->expects( $this->never() )->method( 'generate_term' );

    // auto_generate = false
    $gen = $this->make_generator_with_taxonomy( ['auto_generate' => false] );
    $gen->on_save_post( 1, $post );
}

// -----------------------------------------------------------------------
// cache_post_terms / regenerate_term_archives_after_delete
// -----------------------------------------------------------------------

public function test_cache_and_regen_after_delete_regenerates_cached_terms(): void {
    $term = new \WP_Term( ['term_id' => 10, 'taxonomy' => 'category', 'slug' => 'news'] );
    $GLOBALS['_mock_post_terms'][5]['category'] = [ $term ];
    $GLOBALS['_mock_taxonomies'] = ['category' => 'category'];

    $this->taxonomy_generator->expects( $this->once() )
        ->method( 'generate_term' )
        ->with( $term );

    $gen  = $this->make_generator_with_taxonomy();
    $post = new \WP_Post( ['ID' => 5, 'post_type' => 'post', 'post_status' => 'publish'] );

    $gen->cache_post_terms( 5 );
    $gen->regenerate_term_archives_after_delete( 5, $post );
}

public function test_cache_does_nothing_when_auto_generate_disabled(): void {
    $term = new \WP_Term( ['term_id' => 10, 'taxonomy' => 'category', 'slug' => 'news'] );
    $GLOBALS['_mock_post_terms'][5]['category'] = [ $term ];
    $GLOBALS['_mock_taxonomies'] = ['category' => 'category'];

    $this->taxonomy_generator->expects( $this->never() )->method( 'generate_term' );

    $gen  = $this->make_generator_with_taxonomy( ['auto_generate' => false] );
    $post = new \WP_Post( ['ID' => 5, 'post_type' => 'post'] );

    $gen->cache_post_terms( 5 );
    $gen->regenerate_term_archives_after_delete( 5, $post );
}
```

- [ ] **Step 2: Run the new tests — expect failure**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test -- --filter GeneratorTest
```
Expected: new tests fail (Generator doesn't accept TaxonomyArchiveGenerator yet)

- [ ] **Step 3: Update `src/Generator/Generator.php`**

Add import at the top of the namespace block (after the namespace declaration):
```php
// Add at top of class body — no `use` needed since same namespace
```

Update constructor — add optional parameter at end:
```php
public function __construct(
    private readonly array $options,
    private readonly FrontmatterBuilder $frontmatter_builder,
    private readonly ContentFilter $content_filter,
    private readonly Converter $converter,
    private readonly YamlFormatter $yaml_formatter,
    private readonly FileWriter $file_writer,
    private readonly FieldResolver $field_resolver,
    private readonly ?TaxonomyArchiveGenerator $taxonomy_generator = null,
) {}
```

Update `on_save_post()` — append after `delete_post_meta`:
```php
    delete_post_meta( $post_id, '_wp_mfa_generating' );

    // Regenerate taxonomy archives for all terms on this post (outside guard block).
    if ( ! empty( $this->options['auto_generate'] ) && null !== $this->taxonomy_generator ) {
        $this->regenerate_term_archives( $post_id );
    }
}
```

Add two new public methods and one private helper:
```php
/**
 * Cache the taxonomy terms for a post before it is deleted.
 *
 * Call this from a before_delete_post hook, then call
 * regenerate_term_archives_after_delete() from after_delete_post.
 *
 * @since  1.1.0
 * @param  int $post_id The post ID about to be deleted.
 */
public function cache_post_terms( int $post_id ): void {
    if ( empty( $this->options['auto_generate'] ) || null === $this->taxonomy_generator ) {
        return;
    }

    $taxonomies = array_keys( get_taxonomies( array( 'public' => true ) ) );
    $cached     = array();

    foreach ( $taxonomies as $taxonomy ) {
        $terms = wp_get_post_terms( $post_id, $taxonomy );

        if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
            $cached = array_merge( $cached, $terms );
        }
    }

    $this->pending_deletion_terms[ $post_id ] = $cached;
}

/**
 * Regenerate term archives for a post that has just been deleted.
 *
 * Must be preceded by a call to cache_post_terms() for the same post ID.
 *
 * @since  1.1.0
 * @param  int      $post_id The post ID that was deleted.
 * @param  \WP_Post $post    The post object (already deleted from DB).
 */
public function regenerate_term_archives_after_delete( int $post_id, \WP_Post $post ): void {
    if ( empty( $this->options['auto_generate'] ) || null === $this->taxonomy_generator ) {
        return;
    }

    $terms = $this->pending_deletion_terms[ $post_id ] ?? array();
    unset( $this->pending_deletion_terms[ $post_id ] );

    foreach ( $terms as $term ) {
        $this->taxonomy_generator->generate_term( $term );
    }
}

/** @var array<int, \WP_Term[]> */
private array $pending_deletion_terms = array();
```

Add private helper (used by `on_save_post`):
```php
/**
 * Regenerate archives for every public taxonomy term the post belongs to.
 *
 * @since  1.1.0
 * @param  int $post_id The post ID.
 */
private function regenerate_term_archives( int $post_id ): void {
    $taxonomies = array_keys( get_taxonomies( array( 'public' => true ) ) );

    foreach ( $taxonomies as $taxonomy ) {
        $terms = wp_get_post_terms( $post_id, $taxonomy );

        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            continue;
        }

        foreach ( $terms as $term ) {
            $this->taxonomy_generator->generate_term( $term );
        }
    }
}
```

- [ ] **Step 4: Run tests**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test
```
Expected: all tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Generator/Generator.php tests/Unit/Generator/GeneratorTest.php
git commit -m "feat: extend Generator to regenerate taxonomy archives on post save/delete"
```

---

## Task 5: Plugin.php — wire everything together

**Files:**
- Modify: `src/Core/Plugin.php`

- [ ] **Step 1: Update `src/Core/Plugin.php`**

Add import at the top:
```php
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
```

In `define_generator()`, after the `$generator = new Generator(...)` block, add:
```php
$taxonomy_generator = new TaxonomyArchiveGenerator(
    $options,
    new YamlFormatter(),
    $this->file_writer
);

// Store for use by other methods.
$this->taxonomy_generator = $taxonomy_generator;
```

Rebuild the `$generator` instantiation to pass `$taxonomy_generator` as the last argument:
```php
$generator = new Generator(
    $options,
    new FrontmatterBuilder( $field_resolver, new TaxonomyCollector(), $options ),
    new ContentFilter(),
    new Converter(),
    new YamlFormatter(),
    $this->file_writer,
    $field_resolver,
    $taxonomy_generator,
);
```

At the end of `define_generator()`, add the `delete_term` and deletion hooks (outside the `auto_generate` gate — these always register so files are cleaned up regardless):
```php
$this->loader->add_action( 'delete_term', $taxonomy_generator, 'on_delete_term', 10, 4 );

if ( ! empty( $options['auto_generate'] ) ) {
    $this->loader->add_action( 'save_post', $generator, 'on_save_post', 10, 2 );
    $this->loader->add_action( 'before_delete_post', $generator, 'cache_post_terms', 10, 1 );
    $this->loader->add_action( 'after_delete_post',  $generator, 'regenerate_term_archives_after_delete', 10, 2 );
}
```

(Remove the existing `if (!empty($options['auto_generate']))` block that only wires `save_post` and replace it with the above.)

In `define_negotiate_hooks()`, update the `$negotiator` construction to pass `$this->taxonomy_generator`:
```php
$negotiator = new Negotiator(
    $options,
    $this->generator,
    $this->taxonomy_generator,
    $agent_detector,
    $access_logger
);
```

In `define_admin_hooks()`, update `$admin` construction:
```php
$admin = new Admin( $options, $this->generator, $this->taxonomy_generator );
```

In `define_cli_commands()`, update `Commands` construction:
```php
\WP_CLI::add_command(
    'markdown-agents',
    new Commands( $options, $this->generator, new LlmsTxtGenerator( $options ), $this->file_writer, $this->taxonomy_generator )
);
```

Add the property declaration at the bottom of the class:
```php
/** @var TaxonomyArchiveGenerator */
private TaxonomyArchiveGenerator $taxonomy_generator;
```

- [ ] **Step 2: Run full test suite**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test
```
Expected: all tests pass

- [ ] **Step 3: Commit**

```bash
git add src/Core/Plugin.php
git commit -m "feat: wire TaxonomyArchiveGenerator into Plugin container"
```

---

## Task 6: Admin AJAX + settings page section

**Files:**
- Modify: `src/Admin/Admin.php`
- Modify: `src/Admin/SettingsPage.php`
- Modify: `tests/Unit/Admin/AdminAjaxTest.php`

- [ ] **Step 1: Write the failing test** — make these changes to `AdminAjaxTest.php`

The existing file constructs `$this->admin` directly in `setUp()`. Follow the same pattern.

**a) Add import at the top of the file (after existing `use` lines):**
```php
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
```

**b) Add mock field declaration (after existing `private Generator $generator;`):**
```php
/** @var TaxonomyArchiveGenerator&MockObject */
private TaxonomyArchiveGenerator $taxonomy_generator;
```

**c) In `setUp()`, add these two lines — the second updates the existing `$this->admin` line:**
```php
$this->taxonomy_generator = $this->createMock( TaxonomyArchiveGenerator::class );
$this->admin = new Admin( Options::get_defaults(), $this->generator, $this->taxonomy_generator );
```
(The updated `$this->admin` line replaces the existing `new Admin( Options::get_defaults(), $this->generator )` line.)

**d) Add the new test method:**
```php
public function test_handle_generate_taxonomy_batch_ajax_returns_batch_result(): void {
    $this->taxonomy_generator->expects( $this->once() )
        ->method( 'generate_batch' )
        ->with( 2, 10 )
        ->willReturn( [ 'total' => 50, 'processed' => 10, 'errors' => [] ] );

    $_POST['offset'] = '2';
    $_POST['limit']  = '10';

    $this->admin->handle_generate_taxonomy_batch_ajax();

    $response = $GLOBALS['_mock_json_response'];
    $this->assertTrue( $response['success'] );
    $this->assertSame( 50, $response['data']['total'] );
    $this->assertSame( 10, $response['data']['processed'] );
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test -- --filter AdminAjaxTest
```
Expected: existing tests fail (Admin constructor now requires 3 args) and new test fails (method not found)

- [ ] **Step 3: Update `src/Admin/Admin.php`**

Add import:
```php
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
```

Update constructor:
```php
public function __construct(
    private readonly array $options,
    private readonly Generator $generator,
    private readonly TaxonomyArchiveGenerator $taxonomy_generator,
) {
    $this->settings_page = new SettingsPage( $options, $generator );
    $this->meta_box      = new MetaBox( $options, $generator );
}
```

Add new AJAX handler method:
```php
/**
 * Handle the AJAX taxonomy-batch-generate request.
 *
 * Hooked to `wp_ajax_mfa_generate_taxonomy_batch`.
 *
 * @since  1.1.0
 */
public function handle_generate_taxonomy_batch_ajax(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorised' ), 403 );
        return;
    }

    check_ajax_referer( 'mfa_generate_batch', 'nonce' );

    $offset = absint( $_POST['offset'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $limit  = min( absint( $_POST['limit'] ?? 10 ), 50 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

    $result = $this->taxonomy_generator->generate_batch( $offset, $limit );

    wp_send_json_success( $result );
    return;
}
```

- [ ] **Step 4: Update `src/Admin/SettingsPage.php`**

In `render_generate_buttons()`, append a taxonomy section after the existing post-type buttons loop:
```php
    <?php endforeach; // end existing post-type loop ?>

    <hr>
    <h2><?php esc_html_e( 'Taxonomy Archives', 'wp-markdown-for-agents' ); ?></h2>
    <p><?php esc_html_e( 'Generate Markdown archive files for all public taxonomy terms.', 'wp-markdown-for-agents' ); ?></p>
    <p>
        <button type="button" class="button button-secondary" data-action="mfa_generate_taxonomy_batch">
            <?php esc_html_e( 'Generate All Taxonomy Archives', 'wp-markdown-for-agents' ); ?>
        </button>
    </p>
    <?php
```

(The `<?php endforeach; ?>` line already exists — insert just before the closing `?>` at the end of the method.)

- [ ] **Step 5: Run full test suite**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test
```
Expected: all tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Admin/Admin.php src/Admin/SettingsPage.php tests/Unit/Admin/AdminAjaxTest.php
git commit -m "feat: add taxonomy batch AJAX endpoint and settings page section"
```

---

## Task 7: bulk-generate.js — data-action support

**Files:**
- Modify: `assets/js/bulk-generate.js`

- [ ] **Step 1: Update `assets/js/bulk-generate.js`**

The key changes:
1. `sendBatch` takes `action` as a parameter instead of hardcoding `mfa_generate_batch`
2. `handleGenerateClick` reads `data-action` (new) or falls back to `mfa_generate_batch` (existing)
3. `data-action`-only buttons don't send a `post_type` param
4. The DOMContentLoaded selector catches both `[data-post-type]` and `[data-action]` buttons

Replace the entire file:

```js
/* global mfaBulkGenerate */
/* WordPress admin bulk-generate AJAX loop.
 * Intercepts clicks on [data-post-type] and [data-action] buttons, drives
 * sequential AJAX batch requests, and updates a live counter.
 */
(function () {
    'use strict';

    var BATCH_SIZE = 10;

    /**
     * Send one batch request and recurse until all items are processed.
     *
     * @param {string}            action      AJAX action name.
     * @param {string|null}       postType    Post type slug, or null for taxonomy batches.
     * @param {number}            offset
     * @param {{processed: number, errors: Array}} accumulated
     * @param {HTMLButtonElement} button
     */
    function sendBatch(action, postType, offset, accumulated, button) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', mfaBulkGenerate.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            if (!response || !response.success) {
                button.textContent = 'Error \u2014 generation stopped';
                button.disabled = false;
                return;
            }

            var data = response.data;
            accumulated.processed += data.processed;
            accumulated.errors    = accumulated.errors.concat(data.errors);

            button.textContent = accumulated.processed + ' / ' + data.total;

            if (accumulated.processed < data.total) {
                sendBatch(action, postType, offset + BATCH_SIZE, accumulated, button);
            } else {
                var errorSummary = accumulated.errors.length
                    ? ', ' + accumulated.errors.length + ' error(s)'
                    : '';
                button.textContent = 'Done: ' + accumulated.processed + ' processed' + errorSummary;
                button.disabled = false;
            }
        };

        xhr.onerror = function () {
            button.textContent = 'Error \u2014 generation stopped';
            button.disabled = false;
        };

        var params = 'action='  + encodeURIComponent(action)
            + '&nonce='         + encodeURIComponent(mfaBulkGenerate.nonce)
            + '&offset='        + encodeURIComponent(offset)
            + '&limit='         + encodeURIComponent(BATCH_SIZE);

        if (postType) {
            params += '&post_type=' + encodeURIComponent(postType);
        }

        xhr.send(params);
    }

    /**
     * @param {MouseEvent} event
     */
    function handleGenerateClick(event) {
        var button   = /** @type {HTMLButtonElement} */ (event.currentTarget);
        var postType = button.dataset.postType || null;
        var action   = button.dataset.action || 'mfa_generate_batch';

        button.disabled    = true;
        button.textContent = '0 / \u2026';

        var accumulated = { processed: 0, errors: [] };
        sendBatch(action, postType, 0, accumulated, button);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('button[data-post-type], button[data-action]');
        buttons.forEach(function (button) {
            button.addEventListener('click', handleGenerateClick);
        });
    });
}());
```

- [ ] **Step 2: Run the full test suite to confirm no PHP regressions**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test
```
Expected: all tests pass (JS has no automated tests)

- [ ] **Step 3: Commit**

```bash
git add assets/js/bulk-generate.js
git commit -m "feat: extend bulk-generate.js to support data-action attribute for taxonomy batches"
```

---

## Task 8: CLI — generate-taxonomies command

**Files:**
- Modify: `src/CLI/Commands.php`

- [ ] **Step 1: Update `src/CLI/Commands.php`**

Add import:
```php
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
```

Update constructor — add optional parameter at end:
```php
public function __construct(
    private readonly array $options,
    private readonly Generator $generator,
    private readonly ?LlmsTxtGenerator $llms_txt = null,
    private readonly ?FileWriter $file_writer = null,
    private readonly ?TaxonomyArchiveGenerator $taxonomy_generator = null,
) {}
```

Add the new public command method:
```php
/**
 * Generate Markdown archive files for taxonomy terms.
 *
 * ## OPTIONS
 *
 * [--taxonomy=<slug>]
 * : Generate only terms in this taxonomy. Omit to generate all public taxonomies.
 *
 * [--dry-run]
 * : Report what would be generated without writing files.
 *
 * ## EXAMPLES
 *
 *   wp markdown-agents generate-taxonomies
 *   wp markdown-agents generate-taxonomies --taxonomy=category
 *   wp markdown-agents generate-taxonomies --dry-run
 *
 * @since  1.1.0
 * @param  array<int, string>    $args
 * @param  array<string, string> $assoc_args
 */
public function generate_taxonomies( array $args, array $assoc_args ): void {
    if ( null === $this->taxonomy_generator ) {
        \WP_CLI::error( 'TaxonomyArchiveGenerator is not available.' );
        return;
    }

    $taxonomy = $assoc_args['taxonomy'] ?? '';
    $dry_run  = isset( $assoc_args['dry-run'] );

    $taxonomies = $taxonomy
        ? array( $taxonomy )
        : array_keys( get_taxonomies( array( 'public' => true ) ) );

    if ( $dry_run ) {
        foreach ( $taxonomies as $tax ) {
            \WP_CLI::log( "[dry-run] Would generate all terms in taxonomy: {$tax}" );
        }
        return;
    }

    $results = $this->taxonomy_generator->generate_all( $taxonomy );

    \WP_CLI::success(
        sprintf(
            'Taxonomy archives: %d generated, %d failed.',
            $results['success'],
            $results['failed']
        )
    );
}
```

- [ ] **Step 2: Run full test suite**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test
```
Expected: all tests pass

- [ ] **Step 3: Commit**

```bash
git add src/CLI/Commands.php
git commit -m "feat: add generate-taxonomies WP-CLI command"
```

---

## Final verification

- [ ] **Run full test suite one last time**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer test
```
Expected: all tests pass, no failures or errors

- [ ] **Check for any obvious issues**

```bash
cd /Users/felix/Sites/tclp/wp-mfa-plugin && composer phpcs -- --standard=WordPress src/Generator/TaxonomyArchiveGenerator.php src/Negotiate/Negotiator.php src/Generator/Generator.php src/Admin/Admin.php src/CLI/Commands.php
```
Expected: no errors (or only pre-existing warnings)

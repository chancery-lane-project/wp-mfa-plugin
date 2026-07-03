<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\FileWriter;
use Tclp\WpMarkdownForAgents\Generator\IndexGenerator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\IndexGenerator
 */
class IndexGeneratorTest extends TestCase {

    private string $export_subdir;
    private string $base_dir;
    private FileWriter $file_writer;
    private IndexGenerator $generator;

    protected function setUp(): void {
        $this->export_subdir = 'wp-mfa-index-' . uniqid();
        $this->base_dir      = sys_get_temp_dir() . '/' . $this->export_subdir;
        mkdir( $this->base_dir, 0755, true );

        $GLOBALS['_mock_upload_dir'] = [
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'https://example.com/wp-content/uploads',
        ];
        $GLOBALS['_mock_posts']              = [];
        $GLOBALS['_mock_post_meta']          = [];
        $GLOBALS['_mock_taxonomy_terms']     = [];
        $GLOBALS['_mock_taxonomies']         = [ 'category' => 'category' ];
        $GLOBALS['_mock_post_type_objects']  = [
            'post' => (object) [ 'name' => 'post', 'labels' => (object) [ 'name' => 'Posts' ] ],
            'page' => (object) [ 'name' => 'page', 'labels' => (object) [ 'name' => 'Pages' ] ],
        ];
        $GLOBALS['_mock_taxonomy_objects']   = [
            'category' => (object) [ 'name' => 'category', 'labels' => (object) [ 'name' => 'Category' ] ],
        ];
        unset( $GLOBALS['_mock_apply_filters'] );

        // Real FileWriter — the tests assert against files on disk.
        $this->file_writer = new FileWriter( $this->base_dir );

        $this->generator = $this->make_generator();
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->base_dir );
        unset( $GLOBALS['_mock_upload_dir'], $GLOBALS['_mock_apply_filters'] );
    }

    private function make_generator( array $options = [] ): IndexGenerator {
        return new IndexGenerator(
            array_merge( [ 'export_dir' => $this->export_subdir, 'post_types' => [ 'post', 'page' ] ], $options ),
            $this->file_writer
        );
    }

    private function make_dir( string $relative ): string {
        $dir = $this->base_dir . '/' . $relative;
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        return $dir;
    }

    private function touch_md( string $relative_dir, string $filename ): void {
        $dir = $this->make_dir( $relative_dir );
        file_put_contents( $dir . '/' . $filename, '# stub' );
    }

    private function make_post( array $props = [] ): \WP_Post {
        return new \WP_Post( array_merge( [
            'ID'           => 1,
            'post_title'   => 'A Title',
            'post_excerpt' => '',
            'post_name'    => 'a-title',
            'post_type'    => 'post',
            'post_status'  => 'publish',
        ], $props ) );
    }

    private function make_term( array $props = [] ): \WP_Term {
        return new \WP_Term( array_merge( [
            'term_id'     => 1,
            'name'        => 'Climate Law',
            'slug'        => 'climate-law',
            'taxonomy'    => 'category',
            'description' => '',
        ], $props ) );
    }

    // -----------------------------------------------------------------------
    // Root index
    // -----------------------------------------------------------------------

    public function test_generate_root_exact_content_with_counts_and_taxonomy_section(): void {
        $this->touch_md( 'post', 'a.md' );
        $this->touch_md( 'post', 'b.md' );
        $this->touch_md( 'post', 'index.md' ); // must be excluded from the count
        $this->touch_md( 'page', 'about.md' );
        $this->make_dir( 'taxonomy' );

        $result = $this->generator->generate_root();

        $this->assertTrue( $result );
        $content = file_get_contents( $this->base_dir . '/index.md' );

        $expected = "---\nokf_version: \"0.1\"\n---\n\n"
            . "# Content\n\n"
            . "* [post](post/) - 2 documents\n"
            . "* [page](page/) - 1 document\n\n"
            . "# Taxonomies\n\n"
            . "* [taxonomy](taxonomy/) - Term archives grouped by taxonomy\n";

        $this->assertSame( $expected, $content );
    }

    public function test_generate_root_omits_taxonomies_section_when_no_taxonomy_dir(): void {
        $this->touch_md( 'post', 'a.md' );

        $this->generator->generate_root();
        $content = file_get_contents( $this->base_dir . '/index.md' );

        $this->assertStringNotContainsString( '# Taxonomies', $content );
    }

    public function test_generate_root_skips_post_types_with_zero_documents_or_missing_dirs(): void {
        $this->touch_md( 'post', 'a.md' );
        // 'page' directory never created.

        $this->generator->generate_root();
        $content = file_get_contents( $this->base_dir . '/index.md' );

        $this->assertStringContainsString( '[post](post/)', $content );
        $this->assertStringNotContainsString( '[page]', $content );
    }

    public function test_generate_root_skips_post_type_dir_containing_only_index_md(): void {
        $this->touch_md( 'post', 'index.md' );

        $this->generator->generate_root();
        $content = file_get_contents( $this->base_dir . '/index.md' );

        $this->assertStringNotContainsString( '[post]', $content );
    }

    // -----------------------------------------------------------------------
    // Post-type index
    // -----------------------------------------------------------------------

    public function test_generate_for_post_type_exact_content(): void {
        $GLOBALS['_mock_posts'] = [
            $this->make_post( [ 'ID' => 2, 'post_title' => 'Zeta', 'post_name' => 'zeta', 'post_excerpt' => 'Zeta excerpt.' ] ),
            $this->make_post( [ 'ID' => 1, 'post_title' => 'Alpha', 'post_name' => 'alpha', 'post_excerpt' => '' ] ),
        ];

        $result = $this->generator->generate_for_post_type( 'post' );

        $this->assertTrue( $result );
        $content = file_get_contents( $this->base_dir . '/post/index.md' );

        $expected = "# Posts\n\n"
            . "* [Alpha](alpha.md)\n"
            . "* [Zeta](zeta.md) - Zeta excerpt.\n";

        $this->assertSame( $expected, $content );
    }

    public function test_generate_for_post_type_falls_back_to_slug_label_when_no_post_type_object(): void {
        $GLOBALS['_mock_post_type_objects'] = [];
        $GLOBALS['_mock_posts']             = [ $this->make_post( [ 'post_type' => 'climate_contract' ] ) ];

        $this->generator->generate_for_post_type( 'climate_contract' );
        $content = file_get_contents( $this->base_dir . '/climate_contract/index.md' );

        $this->assertStringStartsWith( "# climate_contract\n", $content );
    }

    public function test_generate_for_post_type_skips_passworded_and_excluded_posts(): void {
        $GLOBALS['_mock_posts']     = [
            $this->make_post( [ 'ID' => 1, 'post_title' => 'Visible', 'post_name' => 'visible' ] ),
            $this->make_post( [ 'ID' => 2, 'post_title' => 'Locked', 'post_name' => 'locked', 'post_password' => 'secret' ] ),
            $this->make_post( [ 'ID' => 3, 'post_title' => 'Excluded', 'post_name' => 'excluded' ] ),
        ];
        $GLOBALS['_mock_post_meta'] = [
            3 => [ '_markdown_for_agents_excluded' => true ],
        ];

        $this->generator->generate_for_post_type( 'post' );
        $content = file_get_contents( $this->base_dir . '/post/index.md' );

        $this->assertStringContainsString( 'Visible', $content );
        $this->assertStringNotContainsString( 'Locked', $content );
        $this->assertStringNotContainsString( 'Excluded', $content );
    }

    // -----------------------------------------------------------------------
    // Reserved-name guard — post types
    // -----------------------------------------------------------------------

    public function test_generate_for_post_type_skips_when_published_post_slugged_index(): void {
        $GLOBALS['_mock_posts'] = [
            $this->make_post( [ 'ID' => 1, 'post_title' => 'Index Page', 'post_name' => 'index' ] ),
        ];

        $result = $this->generator->generate_for_post_type( 'post' );

        $this->assertFalse( $result );
        $this->assertFileDoesNotExist( $this->base_dir . '/post/index.md' );
    }

    public function test_generate_all_counts_reserved_name_skip(): void {
        $this->touch_md( 'post', 'index.md' ); // pretend the concept file already exists
        $GLOBALS['_mock_posts'] = [
            $this->make_post( [ 'ID' => 1, 'post_title' => 'Index Page', 'post_name' => 'index' ] ),
        ];
        // Only 'post' enabled so we can isolate the count.
        $generator = $this->make_generator( [ 'post_types' => [ 'post' ] ] );

        $result = $generator->generate_all();

        // 'post' type index is skipped (reserved slug); root index is still written.
        $this->assertSame( [ 'written' => 1, 'skipped' => 1 ], $result );
    }

    public function test_generate_all_on_fully_empty_export_base_still_writes_root(): void {
        // No directories exist at all beyond the freshly-created base_dir.
        $result = $this->generator->generate_all();

        // 'post' and 'page' dirs are both missing -> 2 skipped; no taxonomy dir
        // exists so the taxonomy root is never attempted; the bundle root is
        // always generated.
        $this->assertSame( [ 'written' => 1, 'skipped' => 2 ], $result );
        $this->assertFileExists( $this->base_dir . '/index.md' );

        $content = file_get_contents( $this->base_dir . '/index.md' );
        $this->assertStringContainsString( "# Content\n", $content );
        $this->assertStringNotContainsString( '# Taxonomies', $content );
    }

    // -----------------------------------------------------------------------
    // Cache hygiene
    // -----------------------------------------------------------------------

    public function test_generate_for_post_type_cleans_post_cache_and_skips_meta_cache_priming(): void {
        $GLOBALS['_mock_cleaned_post_caches'] = [];
        $GLOBALS['_mock_posts']               = [
            $this->make_post( [ 'ID' => 5, 'post_title' => 'Alpha' ] ),
            $this->make_post( [ 'ID' => 6, 'post_title' => 'Beta', 'post_name' => 'beta' ] ),
        ];

        $this->generator->generate_for_post_type( 'post' );

        $this->assertSame( [ 5, 6 ], $GLOBALS['_mock_cleaned_post_caches'] );
        $this->assertFalse( $GLOBALS['_mock_get_posts_args']['update_post_meta_cache'] ?? null );
    }

    // -----------------------------------------------------------------------
    // Taxonomy root + per-taxonomy index
    // -----------------------------------------------------------------------

    public function test_generate_taxonomy_root_exact_content(): void {
        $this->touch_md( 'taxonomy/category', 'climate-law.md' );
        $this->touch_md( 'taxonomy/category', 'index.md' ); // excluded from count

        $result = $this->generator->generate_taxonomy_root();

        $this->assertTrue( $result );
        $content = file_get_contents( $this->base_dir . '/taxonomy/index.md' );

        $expected = "# Taxonomies\n\n"
            . "* [Category](category/) - 1 term\n";

        $this->assertSame( $expected, $content );
    }

    public function test_generate_for_taxonomy_exact_content_with_description_collapsed(): void {
        $GLOBALS['_mock_taxonomy_terms']['category'] = [
            $this->make_term( [ 'term_id' => 2, 'name' => 'Zeta Term', 'slug' => 'zeta-term', 'description' => '' ] ),
            $this->make_term( [ 'term_id' => 1, 'name' => 'Alpha Term', 'slug' => 'alpha-term', 'description' => "Line one\nLine two" ] ),
        ];

        $result = $this->generator->generate_for_taxonomy( 'category' );

        $this->assertTrue( $result );
        $content = file_get_contents( $this->base_dir . '/taxonomy/category/index.md' );

        $expected = "# Category\n\n"
            . "* [Alpha Term](alpha-term.md) - Line one Line two\n"
            . "* [Zeta Term](zeta-term.md)\n";

        $this->assertSame( $expected, $content );
    }

    public function test_generate_for_taxonomy_strips_html_from_description(): void {
        $GLOBALS['_mock_taxonomy_terms']['category'] = [
            $this->make_term( [ 'name' => 'Alpha Term', 'slug' => 'alpha-term', 'description' => '<p>Bold <strong>text</strong>.</p>' ] ),
        ];

        $this->generator->generate_for_taxonomy( 'category' );
        $content = file_get_contents( $this->base_dir . '/taxonomy/category/index.md' );

        $this->assertStringContainsString( '- Bold text.', $content );
        $this->assertStringNotContainsString( '<p>', $content );
        $this->assertStringNotContainsString( '<strong>', $content );
    }

    public function test_generate_for_taxonomy_skips_when_term_slugged_index(): void {
        $GLOBALS['_mock_taxonomy_terms']['category'] = [
            $this->make_term( [ 'slug' => 'index' ] ),
        ];

        $result = $this->generator->generate_for_taxonomy( 'category' );

        $this->assertFalse( $result );
        $this->assertFileDoesNotExist( $this->base_dir . '/taxonomy/category/index.md' );
    }

    // -----------------------------------------------------------------------
    // Filter
    // -----------------------------------------------------------------------

    public function test_index_content_filter_is_applied(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_index_content'] = function ( $content, $relative_path ) {
            return $content . "\nFILTERED:" . $relative_path;
        };
        $GLOBALS['_mock_posts'] = [ $this->make_post() ];

        $this->generator->generate_for_post_type( 'post' );
        $content = file_get_contents( $this->base_dir . '/post/index.md' );

        $this->assertStringContainsString( 'FILTERED:post/index.md', $content );
    }

    // -----------------------------------------------------------------------
    // delete_all
    // -----------------------------------------------------------------------

    public function test_delete_all_removes_managed_index_files_and_returns_count(): void {
        $this->touch_md( '', 'index.md' );
        $this->touch_md( 'post', 'index.md' );
        $this->touch_md( 'page', 'index.md' );
        $this->touch_md( 'taxonomy', 'index.md' );
        $this->touch_md( 'taxonomy/category', 'index.md' );
        $this->touch_md( 'post', 'concept.md' ); // must survive

        $count = $this->generator->delete_all();

        $this->assertSame( 5, $count );
        $this->assertFileDoesNotExist( $this->base_dir . '/index.md' );
        $this->assertFileDoesNotExist( $this->base_dir . '/post/index.md' );
        $this->assertFileDoesNotExist( $this->base_dir . '/page/index.md' );
        $this->assertFileDoesNotExist( $this->base_dir . '/taxonomy/index.md' );
        $this->assertFileDoesNotExist( $this->base_dir . '/taxonomy/category/index.md' );
        $this->assertFileExists( $this->base_dir . '/post/concept.md' );
    }

    public function test_delete_all_does_not_delete_reserved_name_owned_index(): void {
        $this->touch_md( 'post', 'index.md' ); // this is really the concept file for the 'index' post
        $GLOBALS['_mock_posts'] = [
            $this->make_post( [ 'ID' => 1, 'post_title' => 'Index Page', 'post_name' => 'index' ] ),
        ];

        $count = $this->generator->delete_all();

        $this->assertSame( 0, $count );
        $this->assertFileExists( $this->base_dir . '/post/index.md' );
    }

    public function test_delete_all_reserved_slug_check_uses_targeted_query_not_full_batch(): void {
        $this->touch_md( 'post', 'index.md' );
        $this->touch_md( 'page', 'index.md' );
        $GLOBALS['_mock_posts'] = [];

        $this->generator->delete_all();

        // The last post-type check made ('page', per enabled_post_types order)
        // should be a single narrow lookup, not a batch-of-100 full scan.
        $args = $GLOBALS['_mock_get_posts_args'];
        $this->assertSame( 'index', $args['name'] ?? null );
        $this->assertSame( 1, $args['posts_per_page'] ?? null );
    }

    // -----------------------------------------------------------------------
    // Dirty-set triggers + shutdown flush
    // -----------------------------------------------------------------------

    public function test_on_file_generated_marks_type_and_root_dirty_and_flush_regenerates_once(): void {
        $this->touch_md( 'post', 'a.md' );
        $post = $this->make_post( [ 'post_type' => 'post' ] );

        $this->generator->on_file_generated( $this->base_dir . '/post/a.md', $post );
        $this->generator->on_file_generated( $this->base_dir . '/post/a.md', $post );
        $this->generator->flush_dirty();

        $this->assertFileExists( $this->base_dir . '/post/index.md' );
        $this->assertFileExists( $this->base_dir . '/index.md' );

        // Second flush must be a no-op (dirty set cleared by the first flush).
        unlink( $this->base_dir . '/post/index.md' );
        unlink( $this->base_dir . '/index.md' );

        $this->generator->flush_dirty();

        $this->assertFileDoesNotExist( $this->base_dir . '/post/index.md' );
        $this->assertFileDoesNotExist( $this->base_dir . '/index.md' );
    }

    public function test_on_file_deleted_derives_type_from_path(): void {
        $this->generator->on_file_deleted( $this->base_dir . '/post/gone.md', 42 );
        $this->generator->flush_dirty();

        $this->assertFileExists( $this->base_dir . '/post/index.md' );
    }

    public function test_flush_dirty_is_idempotent(): void {
        $post = $this->make_post( [ 'post_type' => 'post' ] );
        $this->generator->on_file_generated( $this->base_dir . '/post/a.md', $post );
        $this->generator->flush_dirty();

        $this->assertFileExists( $this->base_dir . '/post/index.md' );
        $this->assertFileExists( $this->base_dir . '/index.md' );

        unlink( $this->base_dir . '/post/index.md' );
        unlink( $this->base_dir . '/index.md' );

        $this->generator->flush_dirty();

        $this->assertFileDoesNotExist( $this->base_dir . '/post/index.md' );
        $this->assertFileDoesNotExist( $this->base_dir . '/index.md' );
    }

    public function test_taxonomy_callbacks_mark_three_scopes(): void {
        $term = $this->make_term();
        $this->touch_md( 'taxonomy/category', 'climate-law.md' );

        $this->generator->on_taxonomy_file_generated( $this->base_dir . '/taxonomy/category/climate-law.md', $term );
        $this->generator->flush_dirty();

        $this->assertFileExists( $this->base_dir . '/taxonomy/category/index.md' );
        $this->assertFileExists( $this->base_dir . '/taxonomy/index.md' );
        $this->assertFileExists( $this->base_dir . '/index.md' );
    }

    public function test_generate_all_clears_dirty_set(): void {
        $this->touch_md( 'post', 'a.md' );
        $post = $this->make_post( [ 'post_type' => 'post' ] );
        $this->generator->on_file_generated( $this->base_dir . '/post/a.md', $post );

        $this->generator->generate_all();

        $this->assertFileExists( $this->base_dir . '/post/index.md' );
        $this->assertFileExists( $this->base_dir . '/index.md' );

        unlink( $this->base_dir . '/post/index.md' );
        unlink( $this->base_dir . '/index.md' );

        $this->generator->flush_dirty();

        $this->assertFileDoesNotExist( $this->base_dir . '/post/index.md' );
        $this->assertFileDoesNotExist( $this->base_dir . '/index.md' );
    }

    public function test_flush_dirty_noop_when_empty(): void {
        $generator = $this->make_generator();

        $generator->flush_dirty();

        $this->assertFileDoesNotExist( $this->base_dir . '/index.md' );
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
            $target = $dir . '/' . $item;
            is_dir( $target ) ? $this->remove_dir( $target ) : unlink( $target );
        }
        rmdir( $dir );
    }
}

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

    public function test_generate_term_queries_enabled_post_types(): void {
        $GLOBALS['_mock_get_posts_args'] = null;

        $this->yaml_formatter->method( 'format' )->willReturn( '' );
        $this->file_writer->method( 'write' )->willReturn( true );

        $gen = $this->make_generator( ['post_types' => ['post', 'page', 'climate_contract']] );
        $gen->generate_term( $this->make_term() );

        $this->assertSame(
            ['post', 'page', 'climate_contract'],
            $GLOBALS['_mock_get_posts_args']['post_type'] ?? null
        );
    }

    public function test_generate_term_queries_no_types_when_option_absent(): void {
        // Unified via ExportPolicy::enabled_post_types(): an absent post_types
        // option means no enabled types, consistent with every other consumer.
        // Options::get() always merges real defaults, so this only arises in
        // artificially constructed options arrays.
        $GLOBALS['_mock_get_posts_args'] = null;

        $this->yaml_formatter->method( 'format' )->willReturn( '' );
        $this->file_writer->method( 'write' )->willReturn( true );

        $gen = $this->make_generator(); // no post_types key in options
        $gen->generate_term( $this->make_term() );

        $this->assertSame(
            [],
            $GLOBALS['_mock_get_posts_args']['post_type'] ?? null
        );
    }

    // -----------------------------------------------------------------------
    // generate_term — body links
    // -----------------------------------------------------------------------

    public function test_body_links_to_md_files(): void {
        $GLOBALS['_mock_posts']     = [
            new \WP_Post( ['ID' => 1, 'post_title' => 'Post One', 'post_excerpt' => '', 'post_name' => 'post-one', 'post_type' => 'post'] ),
        ];
        $GLOBALS['_mock_permalink'] = 'https://example.com/post-one/';

        $this->yaml_formatter->method( 'format' )->willReturn( '' );
        $captured = null;
        $this->file_writer->method( 'write' )
            ->willReturnCallback( function ( string $path, string $content ) use ( &$captured ) {
                $captured = $content;
                return true;
            } );

        $gen = $this->make_generator( ['post_types' => ['post']] );
        $gen->generate_term( $this->make_term() );

        $this->assertStringContainsString(
            '- [Post One](https://example.com/wp-content/uploads/' . $this->export_subdir . '/post/post-one.md)',
            $captured
        );
        $this->assertStringNotContainsString( 'https://example.com/post-one/', $captured );
    }

    public function test_keeps_permalink_for_non_enabled_post_type(): void {
        $GLOBALS['_mock_posts']     = [
            new \WP_Post( ['ID' => 1, 'post_title' => 'Other Type Post', 'post_excerpt' => '', 'post_name' => 'other-post', 'post_type' => 'climate_contract'] ),
        ];
        $GLOBALS['_mock_permalink'] = 'https://example.com/other-post/';

        $this->yaml_formatter->method( 'format' )->willReturn( '' );
        $captured = null;
        $this->file_writer->method( 'write' )
            ->willReturnCallback( function ( string $path, string $content ) use ( &$captured ) {
                $captured = $content;
                return true;
            } );

        // post_types enabled only includes 'post' — the fixture post is of type 'climate_contract'.
        $gen = $this->make_generator( ['post_types' => ['post']] );
        $gen->generate_term( $this->make_term() );

        $this->assertStringContainsString( '- [Other Type Post](https://example.com/other-post/)', $captured );
    }

    // -----------------------------------------------------------------------
    // lifecycle actions
    // -----------------------------------------------------------------------

    public function test_generate_term_fires_generated_action(): void {
        reset_mock_hooks();
        $term = $this->make_term();

        $this->yaml_formatter->method( 'format' )->willReturn( '' );
        $this->file_writer->method( 'write' )->willReturn( true );

        $this->generator->generate_term( $term );

        $fired = get_mock_fired_actions( 'markdown_for_agents_taxonomy_file_generated' );
        $this->assertCount( 1, $fired );
        $this->assertSame( $this->generator->get_export_path( $term ), $fired[0][0] );
        $this->assertSame( $term, $fired[0][1] );
    }

    public function test_delete_term_file_fires_deleted_action(): void {
        reset_mock_hooks();
        $term = $this->make_term();
        $path = $this->generator->get_export_path( $term );
        $dir  = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        file_put_contents( $path, '# Test' );

        $this->file_writer->method( 'delete' )->willReturn( true );

        $result = $this->generator->delete_term_file( $term );

        $this->assertTrue( $result );
        $fired = get_mock_fired_actions( 'markdown_for_agents_taxonomy_file_deleted' );
        $this->assertCount( 1, $fired );
        $this->assertSame( $path, $fired[0][0] );
        $this->assertSame( $term, $fired[0][1] );
    }

    public function test_delete_term_file_does_not_fire_deleted_action_when_file_missing(): void {
        reset_mock_hooks();
        $term = $this->make_term();

        $result = $this->generator->delete_term_file( $term );

        $this->assertFalse( $result );
        $this->assertSame( [], get_mock_fired_actions( 'markdown_for_agents_taxonomy_file_deleted' ) );
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

    public function test_generate_batch_records_error_when_write_fails(): void {
        // A failed filesystem write must surface as an error, not vanish.
        $term = $this->make_term( [ 'term_id' => 7 ] );
        $GLOBALS['_mock_taxonomy_terms']['category'] = [ $term ];

        $this->yaml_formatter->method( 'format' )->willReturn( '' );
        $this->file_writer->method( 'write' )->willReturn( false );

        $result = $this->generator->generate_batch( 0, 10 );

        $this->assertSame( 0, $result['processed'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 7, $result['errors'][0]['term_id'] );
        $this->assertStringContainsString( 'write', $result['errors'][0]['message'] );
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

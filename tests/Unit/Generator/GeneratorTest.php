<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\ContentFilter;
use Tclp\WpMarkdownForAgents\Generator\Converter;
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
use Tclp\WpMarkdownForAgents\Generator\FileWriter;
use Tclp\WpMarkdownForAgents\Generator\FrontmatterBuilder;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Generator\YamlFormatter;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\Generator
 */
class GeneratorTest extends TestCase {

    private string $base_dir;

    /** @var FrontmatterBuilder&MockObject */
    private FrontmatterBuilder $frontmatter_builder;

    /** @var ContentFilter&MockObject */
    private ContentFilter $content_filter;

    /** @var Converter&MockObject */
    private Converter $converter;

    /** @var YamlFormatter&MockObject */
    private YamlFormatter $yaml_formatter;

    /** @var FileWriter&MockObject */
    private FileWriter $file_writer;

    /** @var TaxonomyArchiveGenerator&MockObject */
    private TaxonomyArchiveGenerator $taxonomy_generator;

    private Generator $generator;

    private string $export_subdir;

    protected function setUp(): void {
        $this->export_subdir = 'wp-mfa-gen-' . uniqid();
        $this->base_dir      = sys_get_temp_dir() . '/' . $this->export_subdir;
        mkdir( $this->base_dir, 0755, true );

        // Point wp_upload_dir() basedir to sys_get_temp_dir() so
        // Options::get_export_base() resolves to $this->base_dir.
        $GLOBALS['_mock_upload_dir'] = [
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'https://example.com/wp-content/uploads',
        ];

        $this->frontmatter_builder = $this->createMock( FrontmatterBuilder::class );
        $this->content_filter      = $this->createMock( ContentFilter::class );
        $this->converter           = $this->createMock( Converter::class );
        $this->yaml_formatter      = $this->createMock( YamlFormatter::class );
        $this->file_writer         = $this->createMock( FileWriter::class );

        $GLOBALS['_mock_posts']        = [];
        $GLOBALS['_mock_post_meta']    = [];
        $GLOBALS['_mock_permalink']    = 'https://example.com/test/';
        $GLOBALS['_mock_post_objects'] = [];
        $GLOBALS['_mock_wp_query']     = null;

        $GLOBALS['_mock_object_taxonomies'] = [];
        $GLOBALS['_mock_terms']             = [];
        $GLOBALS['_mock_term_link']         = [];

        $this->taxonomy_generator    = $this->createMock( TaxonomyArchiveGenerator::class );
        $GLOBALS['_mock_post_terms'] = [];

        $this->generator = $this->make_generator();
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->base_dir );
        unset( $GLOBALS['_mock_upload_dir'] );
    }

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

    private function make_post( array $props = [] ): \WP_Post {
        return new \WP_Post( array_merge( [
            'ID'          => 1,
            'post_title'  => 'Test Post',
            'post_name'   => 'test-post',
            'post_type'   => 'post',
            'post_status' => 'publish',
            'post_content' => '<p>Hello</p>',
        ], $props ) );
    }

    // -----------------------------------------------------------------------
    // generate_post()
    // -----------------------------------------------------------------------

    public function test_generate_post_calls_collaborators_in_order(): void {
        $post = $this->make_post();

        $this->frontmatter_builder->expects( $this->once() )
            ->method( 'build' )
            ->with( $post )
            ->willReturn( [ 'title' => 'Test Post' ] );

        $this->content_filter->expects( $this->once() )
            ->method( 'filter' )
            ->willReturn( '<p>Hello</p>' );

        $this->converter->expects( $this->once() )
            ->method( 'convert' )
            ->willReturn( 'Hello' );

        $this->yaml_formatter->expects( $this->once() )
            ->method( 'format' )
            ->willReturn( "---\ntitle: Test Post\n---\n" );

        $this->file_writer->expects( $this->once() )
            ->method( 'write' )
            ->willReturn( true );

        $result = $this->generator->generate_post( $post );

        $this->assertTrue( $result );
    }

    public function test_generate_post_skips_non_configured_post_type(): void {
        $post = $this->make_post( [ 'post_type' => 'product' ] );

        $this->file_writer->expects( $this->never() )->method( 'write' );

        $result = $this->generator->generate_post( $post );

        $this->assertFalse( $result );
    }

    public function test_generate_post_skips_non_published_post(): void {
        $post = $this->make_post( [ 'post_status' => 'draft' ] );

        $this->file_writer->expects( $this->never() )->method( 'write' );

        $result = $this->generator->generate_post( $post );

        $this->assertFalse( $result );
    }

    public function test_generate_post_skips_password_protected_post(): void {
        $post = $this->make_post( [ 'post_password' => 'secret' ] );

        $this->file_writer->expects( $this->never() )->method( 'write' );

        $result = $this->generator->generate_post( $post );

        $this->assertFalse( $result );
    }

    public function test_generate_post_skips_excluded_post(): void {
        $post = $this->make_post();
        $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';

        $this->file_writer->expects( $this->never() )->method( 'write' );

        $result = $this->generator->generate_post( $post );

        $this->assertFalse( $result );
    }

    public function test_generate_post_fires_action_on_success(): void {
        $post = $this->make_post();

        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( '' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )->willReturn( true );

        // do_action is a no-op stub in tests — just confirm no exception.
        $result = $this->generator->generate_post( $post );
        $this->assertTrue( $result );
    }

    public function test_generate_post_returns_false_when_write_fails(): void {
        $post = $this->make_post();

        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( '' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )->willReturn( false );

        $result = $this->generator->generate_post( $post );
        $this->assertFalse( $result );
    }

    // -----------------------------------------------------------------------
    // delete_post()
    // -----------------------------------------------------------------------

    public function test_delete_post_calls_file_writer_delete(): void {
        $post = $this->make_post();
        $GLOBALS['_mock_post_objects'][1] = $post;

        $this->file_writer->expects( $this->once() )
            ->method( 'delete' )
            ->willReturn( true );

        $result = $this->generator->delete_post( 1 );
        $this->assertTrue( $result );
    }

    public function test_delete_post_returns_false_for_unknown_post(): void {
        $GLOBALS['_mock_post_objects'] = [];

        $result = $this->generator->delete_post( 999 );
        $this->assertFalse( $result );
    }

    // -----------------------------------------------------------------------
    // get_export_path()
    // -----------------------------------------------------------------------

    public function test_get_export_path_returns_expected_structure(): void {
        $post = $this->make_post( [
            'post_type' => 'post',
            'post_name' => 'my-slug',
        ] );

        $path = $this->generator->get_export_path( $post );

        $this->assertStringContainsString( 'post', $path );
        $this->assertStringContainsString( 'my-slug.md', $path );
    }

    // -----------------------------------------------------------------------
    // on_save_post()
    // -----------------------------------------------------------------------

    public function test_on_save_post_generates_for_published_post(): void {
        $post = $this->make_post( [ 'post_status' => 'publish' ] );

        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( '' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->expects( $this->once() )->method( 'write' )->willReturn( true );

        $this->generator->on_save_post( 1, $post );
    }

    public function test_on_save_post_deletes_for_trashed_post(): void {
        $post = $this->make_post( [ 'post_status' => 'trash' ] );
        $GLOBALS['_mock_post_objects'][1] = $post;

        $this->file_writer->expects( $this->once() )->method( 'delete' )->willReturn( true );
        $this->file_writer->expects( $this->never() )->method( 'write' );

        $this->generator->on_save_post( 1, $post );
    }

    public function test_on_save_post_deletes_for_password_protected_published_post(): void {
        $post = $this->make_post( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
        $GLOBALS['_mock_post_objects'][1] = $post;

        $this->file_writer->expects( $this->once() )->method( 'delete' )->willReturn( true );
        $this->file_writer->expects( $this->never() )->method( 'write' );

        $this->generator->on_save_post( 1, $post );
    }

    public function test_on_save_post_deletes_for_excluded_published_post(): void {
        $post = $this->make_post( [ 'post_status' => 'publish' ] );
        $GLOBALS['_mock_post_objects'][1] = $post;
        $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';

        $this->file_writer->expects( $this->once() )->method( 'delete' )->willReturn( true );
        $this->file_writer->expects( $this->never() )->method( 'write' );

        $this->generator->on_save_post( 1, $post );
    }

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

    public function test_on_save_post_does_not_run_during_autosave(): void {
        define( 'DOING_AUTOSAVE', true );
        $post = $this->make_post();

        $this->file_writer->expects( $this->never() )->method( 'write' );
        $this->file_writer->expects( $this->never() )->method( 'delete' );

        $this->generator->on_save_post( 1, $post );
    }

    // -----------------------------------------------------------------------
    // generate_post_type()
    // -----------------------------------------------------------------------

    public function test_generate_post_type_returns_counts(): void {
        $post1 = $this->make_post( [ 'ID' => 1 ] );
        $post2 = $this->make_post( [ 'ID' => 2 ] );

        $GLOBALS['_mock_posts'] = [ $post1, $post2 ];

        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( '' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )->willReturn( true );

        // Override get_posts to return nothing on the second call (end of pagination).
        $call = 0;
        $GLOBALS['_mock_posts_callback'] = function() use ( $post1, $post2, &$call ) {
            return $call++ === 0 ? [ $post1, $post2 ] : [];
        };

        $results = $this->generator->generate_post_type( 'post' );

        $this->assertArrayHasKey( 'success', $results );
        $this->assertArrayHasKey( 'failed', $results );
        $this->assertArrayHasKey( 'skipped', $results );
    }

    // -----------------------------------------------------------------------
    // generate_batch()
    // -----------------------------------------------------------------------

    public function test_generate_batch_returns_zero_totals_when_no_posts(): void {
        $GLOBALS['_mock_wp_query'] = fn( array $args ): array => [ [], 0 ];

        $result = $this->generator->generate_batch( 'post', 0, 10 );

        $this->assertSame( 0, $result['total'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( [], $result['errors'] );
    }

    public function test_generate_batch_returns_processed_count(): void {
        $post1 = $this->make_post( [ 'ID' => 10, 'post_name' => 'post-10' ] );
        $post2 = $this->make_post( [ 'ID' => 11, 'post_name' => 'post-11' ] );

        $GLOBALS['_mock_wp_query']     = fn( array $args ): array => [ [ 10, 11 ], 2 ];
        $GLOBALS['_mock_post_objects'] = [ 10 => $post1, 11 => $post2 ];

        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( '' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )->willReturn( true );

        $result = $this->generator->generate_batch( 'post', 0, 10 );

        $this->assertSame( 2, $result['total'] );
        $this->assertSame( 2, $result['processed'] );
        $this->assertSame( [], $result['errors'] );
    }

    public function test_generate_batch_collects_error_and_continues(): void {
        $post1 = $this->make_post( [ 'ID' => 20, 'post_name' => 'post-20' ] );
        $post2 = $this->make_post( [ 'ID' => 21, 'post_name' => 'post-21' ] );

        $GLOBALS['_mock_wp_query']     = fn( array $args ): array => [ [ 20, 21 ], 2 ];
        $GLOBALS['_mock_post_objects'] = [ 20 => $post1, 21 => $post2 ];

        $call = 0;
        $this->frontmatter_builder->method( 'build' )
            ->willReturnCallback( function () use ( &$call ): array {
                if ( ++$call === 2 ) {
                    throw new \RuntimeException( 'build failed' );
                }
                return [];
            } );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( '' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )->willReturn( true );

        $result = $this->generator->generate_batch( 'post', 0, 10 );

        $this->assertSame( 2, $result['total'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 21, $result['errors'][0]['post_id'] );
        $this->assertSame( 'build failed', $result['errors'][0]['message'] );
    }

    public function test_generate_batch_silently_skips_ineligible_post(): void {
        // post_type 'event' is not in options['post_types'], so generate_post returns false.
        $post = $this->make_post( [ 'ID' => 30, 'post_name' => 'event-30', 'post_type' => 'event' ] );

        $GLOBALS['_mock_wp_query']     = fn( array $args ): array => [ [ 30 ], 1 ];
        $GLOBALS['_mock_post_objects'] = [ 30 => $post ];

        $this->file_writer->expects( $this->never() )->method( 'write' );

        $result = $this->generator->generate_batch( 'event', 0, 10 );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( [], $result['errors'] );
    }

    // -----------------------------------------------------------------------
    // Topics section (include_taxonomy_topics option)
    // -----------------------------------------------------------------------

    public function test_topics_section_appended_when_option_enabled(): void {
        $post = $this->make_post( ['ID' => 42] );

        $GLOBALS['_mock_object_taxonomies']['post'] = [
            'category' => (object) ['name' => 'category', 'label' => 'Categories'],
        ];
        $GLOBALS['_mock_terms'][42]['category'] = [
            new \WP_Term( ['term_id' => 1, 'slug' => 'news', 'name' => 'News', 'taxonomy' => 'category'] ),
        ];
        $GLOBALS['_mock_term_link'][1] = 'https://example.com/category/news/';

        $written = '';
        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( 'Body content.' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )
            ->willReturnCallback( function ( string $path, string $content ) use ( &$written ): bool {
                $written = $content;
                return true;
            } );

        $gen = $this->make_generator( ['include_taxonomy_topics' => true] );
        $gen->generate_post( $post );

        $this->assertStringContainsString( '## Topics', $written );
        $this->assertStringContainsString( '[News](https://example.com/category/news/)', $written );
        $this->assertStringContainsString( 'Categories', $written );
    }

    public function test_topics_section_absent_when_option_disabled(): void {
        $post = $this->make_post( ['ID' => 42] );

        $GLOBALS['_mock_object_taxonomies']['post'] = [
            'category' => (object) ['name' => 'category', 'label' => 'Categories'],
        ];
        $GLOBALS['_mock_terms'][42]['category'] = [
            new \WP_Term( ['term_id' => 1, 'slug' => 'news', 'name' => 'News', 'taxonomy' => 'category'] ),
        ];

        $written = '';
        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( 'Body content.' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )
            ->willReturnCallback( function ( string $path, string $content ) use ( &$written ): bool {
                $written = $content;
                return true;
            } );

        $gen = $this->make_generator( ['include_taxonomy_topics' => false] );
        $gen->generate_post( $post );

        $this->assertStringNotContainsString( '## Topics', $written );
    }

    public function test_topics_section_absent_when_no_terms(): void {
        $post = $this->make_post( ['ID' => 42] );

        $GLOBALS['_mock_object_taxonomies']['post'] = []; // no taxonomies

        $written = '';
        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( 'Body content.' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )
            ->willReturnCallback( function ( string $path, string $content ) use ( &$written ): bool {
                $written = $content;
                return true;
            } );

        $gen = $this->make_generator( ['include_taxonomy_topics' => true] );
        $gen->generate_post( $post );

        $this->assertStringNotContainsString( '## Topics', $written );
    }

    public function test_topics_section_absent_when_taxonomy_has_no_assigned_terms(): void {
        $post = $this->make_post( ['ID' => 42] );

        $GLOBALS['_mock_object_taxonomies']['post'] = [
            'category' => (object) ['name' => 'category', 'label' => 'Categories'],
        ];
        // Taxonomy exists but post has no terms assigned (get_the_terms returns false)
        $GLOBALS['_mock_terms'][42] = []; // no terms for this post

        $written = '';
        $this->frontmatter_builder->method( 'build' )->willReturn( [] );
        $this->content_filter->method( 'filter' )->willReturn( '' );
        $this->converter->method( 'convert' )->willReturn( 'Body content.' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\n---\n" );
        $this->file_writer->method( 'write' )
            ->willReturnCallback( function ( string $path, string $content ) use ( &$written ): bool {
                $written = $content;
                return true;
            } );

        $gen = $this->make_generator( ['include_taxonomy_topics' => true] );
        $gen->generate_post( $post );

        $this->assertStringNotContainsString( '## Topics', $written );
    }

    // -----------------------------------------------------------------------
    // get_post_markdown()
    // -----------------------------------------------------------------------

    public function test_get_post_markdown_returns_yaml_and_body(): void {
        $post = $this->make_post( ['post_type' => 'post', 'post_status' => 'publish'] );

        $this->frontmatter_builder->method( 'build' )->willReturn( ['title' => 'Test'] );
        $this->content_filter->method( 'filter' )->willReturn( '<p>Hello</p>' );
        $this->converter->method( 'convert' )->willReturn( 'Hello' );
        $this->yaml_formatter->method( 'format' )->willReturn( "---\ntitle: Test\n---\n" );

        $output = $this->generator->get_post_markdown( $post );

        $this->assertNotNull( $output );
        $this->assertStringContainsString( '---', $output );
        $this->assertStringContainsString( 'Hello', $output );
    }

    public function test_get_post_markdown_returns_null_for_ineligible_post(): void {
        $post = $this->make_post( ['post_type' => 'post', 'post_status' => 'draft'] );

        $this->frontmatter_builder->expects( $this->never() )->method( 'build' );

        $output = $this->generator->get_post_markdown( $post );

        $this->assertNull( $output );
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

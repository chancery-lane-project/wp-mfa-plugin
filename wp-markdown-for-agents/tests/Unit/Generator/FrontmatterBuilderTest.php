<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\FrontmatterBuilder;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyCollector;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\FrontmatterBuilder
 */
class FrontmatterBuilderTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_mock_terms']             = [];
        $GLOBALS['_mock_object_taxonomies'] = [];
        $GLOBALS['_mock_post_meta']         = [];
        $GLOBALS['_mock_thumbnail']         = null;
        $GLOBALS['_mock_permalink']         = 'https://example.com/my-post/';
    }

    private function make_post( array $props = [] ): \WP_Post {
        $defaults = [
            'ID'                => 42,
            'post_title'        => 'My Post Title',
            'post_excerpt'      => 'A short excerpt.',
            'post_name'         => 'my-post',
            'post_type'         => 'post',
            'post_status'       => 'publish',
            'post_date_gmt'     => '2025-03-01 10:00:00',
            'post_modified_gmt' => '2025-10-15 14:23:00',
        ];
        return new \WP_Post( array_merge( $defaults, $props ) );
    }

    private function make_builder( array $options = [] ): FrontmatterBuilder {
        $defaults = [
            'include_taxonomies' => false,
            'include_meta'       => false,
            'meta_keys'          => [],
        ];
        return new FrontmatterBuilder(
            new TaxonomyCollector(),
            array_merge( $defaults, $options )
        );
    }

    public function test_includes_core_fields(): void {
        $post   = $this->make_post();
        $result = $this->make_builder()->build( $post );

        $this->assertSame( 'My Post Title', $result['title'] );
        $this->assertSame( 42, $result['wpid'] );
        $this->assertSame( 'post', $result['type'] );
        $this->assertSame( 'publish', $result['status'] );
        $this->assertSame( 'https://example.com/my-post/', $result['permalink'] );
    }

    public function test_dates_are_iso8601(): void {
        $post   = $this->make_post();
        $result = $this->make_builder()->build( $post );

        $this->assertSame( '2025-03-01T10:00:00Z', $result['date'] );
        $this->assertSame( '2025-10-15T14:23:00Z', $result['modified'] );
    }

    public function test_excerpt_is_stripped_of_html(): void {
        $post   = $this->make_post( [ 'post_excerpt' => '<p>Clean excerpt.</p>' ] );
        $result = $this->make_builder()->build( $post );

        $this->assertSame( 'Clean excerpt.', $result['excerpt'] );
    }

    public function test_does_not_include_ssg_keys(): void {
        $post   = $this->make_post();
        $result = $this->make_builder()->build( $post );

        $this->assertArrayNotHasKey( 'layout', $result );
        $this->assertArrayNotHasKey( 'eleventyComputed', $result );
        $this->assertArrayNotHasKey( 'file_type', $result );
    }

    public function test_taxonomies_included_when_option_enabled(): void {
        $GLOBALS['_mock_object_taxonomies']['post'] = [
            'category' => (object) [ 'name' => 'category', 'label' => 'Categories' ],
        ];
        $GLOBALS['_mock_terms'][42]['category'] = [
            (object) [ 'term_id' => 1, 'name' => 'News', 'slug' => 'news' ],
        ];

        $post   = $this->make_post();
        $result = $this->make_builder( [ 'include_taxonomies' => true ] )->build( $post );

        $this->assertArrayHasKey( 'categories', $result );
        $this->assertSame( [ 'News' ], $result['categories'] );
    }

    public function test_taxonomies_not_included_when_option_disabled(): void {
        $GLOBALS['_mock_object_taxonomies']['post'] = [
            'category' => (object) [ 'name' => 'category', 'label' => 'Categories' ],
        ];
        $GLOBALS['_mock_terms'][42]['category'] = [
            (object) [ 'term_id' => 1, 'name' => 'News', 'slug' => 'news' ],
        ];

        $post   = $this->make_post();
        $result = $this->make_builder( [ 'include_taxonomies' => false ] )->build( $post );

        $this->assertArrayNotHasKey( 'categories', $result );
    }

    public function test_post_meta_included_when_option_enabled(): void {
        $GLOBALS['_mock_post_meta'][42]['my_field'] = 'custom value';

        $post   = $this->make_post();
        $result = $this->make_builder( [
            'include_meta' => true,
            'meta_keys'    => [ 'my_field' ],
        ] )->build( $post );

        $this->assertArrayHasKey( 'my_field', $result );
        $this->assertSame( 'custom value', $result['my_field'] );
    }

    public function test_post_meta_not_included_when_option_disabled(): void {
        $GLOBALS['_mock_post_meta'][42]['my_field'] = 'custom value';

        $post   = $this->make_post();
        $result = $this->make_builder( [
            'include_meta' => false,
            'meta_keys'    => [ 'my_field' ],
        ] )->build( $post );

        $this->assertArrayNotHasKey( 'my_field', $result );
    }

    public function test_featured_image_included_when_present(): void {
        $GLOBALS['_mock_thumbnail']              = 99;
        $GLOBALS['_mock_attachment_url'][99]     = 'https://example.com/wp-content/uploads/photo.jpg';

        $post   = $this->make_post();
        $result = $this->make_builder()->build( $post );

        $this->assertArrayHasKey( 'featured_image', $result );
        $this->assertSame( 'https://example.com/wp-content/uploads/photo.jpg', $result['featured_image'] );
    }

    public function test_no_featured_image_key_when_none_set(): void {
        $GLOBALS['_mock_thumbnail'] = null;

        $post   = $this->make_post();
        $result = $this->make_builder()->build( $post );

        $this->assertArrayNotHasKey( 'featured_image', $result );
    }
}

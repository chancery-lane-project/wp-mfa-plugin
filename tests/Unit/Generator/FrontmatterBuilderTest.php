<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
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
        $GLOBALS['_mock_hierarchical_types'] = [];
        $GLOBALS['_mock_post_parent']        = [];
        $GLOBALS['_mock_post_ancestors']     = [];
        $GLOBALS['_mock_posts']              = [];
        $GLOBALS['_mock_user_data']          = [];
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
            new FieldResolver(),
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
            'post_type_configs' => [
                'post' => [
                    'frontmatter_fields' => [ 'my_field' ],
                    'content_fields'     => [],
                ],
            ],
        ] )->build( $post );

        $this->assertArrayHasKey( 'my_field', $result );
        $this->assertSame( 'custom value', $result['my_field'] );
    }

    public function test_post_meta_not_included_when_not_configured(): void {
        $GLOBALS['_mock_post_meta'][42]['my_field'] = 'custom value';

        $post   = $this->make_post();
        $result = $this->make_builder( [
            'post_type_configs' => [],
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

    public function test_hierarchy_fields_included_for_hierarchical_type(): void {
        $GLOBALS['_mock_hierarchical_types']  = ['page'];
        $GLOBALS['_mock_post_parent'][10]     = 5;
        $GLOBALS['_mock_post_ancestors'][10]  = [5, 3];
        // The get_posts() mock in wordpress-mocks.php returns $GLOBALS['_mock_posts']
        // regardless of query args — set to [] to simulate no child pages found.
        $GLOBALS['_mock_posts']              = []; // no children

        $post   = $this->make_post(['ID' => 10, 'post_type' => 'page']);
        $result = $this->make_builder(['include_hierarchy' => true])->build($post);

        $this->assertSame(5, $result['parent']);
        $this->assertSame([5, 3], $result['ancestors']);
        $this->assertSame([], $result['children']);
    }

    public function test_hierarchy_fields_absent_when_option_disabled(): void {
        $post   = $this->make_post(['post_type' => 'page']);
        $result = $this->make_builder(['include_hierarchy' => false])->build($post);

        $this->assertArrayNotHasKey('parent', $result);
        $this->assertArrayNotHasKey('ancestors', $result);
        $this->assertArrayNotHasKey('children', $result);
    }

    public function test_hierarchy_fields_absent_for_non_hierarchical_type(): void {
        $GLOBALS['_mock_hierarchical_types'] = []; // 'post' is not hierarchical
        $post   = $this->make_post(['post_type' => 'post']);
        $result = $this->make_builder(['include_hierarchy' => true])->build($post);

        $this->assertArrayNotHasKey('parent', $result);
    }

    public function test_hierarchy_children_populated_when_present(): void {
        $GLOBALS['_mock_hierarchical_types'] = ['page'];
        $GLOBALS['_mock_post_parent'][20]    = 0;
        $GLOBALS['_mock_post_ancestors'][20] = [];
        $GLOBALS['_mock_posts']              = [101, 102]; // get_posts() mock returns this regardless of args

        $post   = $this->make_post(['ID' => 20, 'post_type' => 'page']);
        $result = $this->make_builder(['include_hierarchy' => true])->build($post);

        $this->assertSame([101, 102], $result['children']);
    }

    public function test_author_name_included_when_option_enabled(): void {
        $GLOBALS['_mock_user_data'][1] = new \WP_User(['ID' => 1, 'display_name' => 'Jane Smith']);

        $post   = $this->make_post(['post_author' => '1']);
        $result = $this->make_builder(['include_author' => true])->build($post);

        $this->assertSame('Jane Smith', $result['author']);
    }

    public function test_author_not_included_when_option_disabled(): void {
        $post   = $this->make_post();
        $result = $this->make_builder(['include_author' => false])->build($post);

        $this->assertArrayNotHasKey('author', $result);
    }

    public function test_author_not_included_when_user_not_found(): void {
        $GLOBALS['_mock_user_data'] = []; // no user
        $post   = $this->make_post(['post_author' => '99']);
        $result = $this->make_builder(['include_author' => true])->build($post);

        $this->assertArrayNotHasKey('author', $result);
    }

    public function test_featured_image_is_relative_when_option_enabled(): void {
        $GLOBALS['_mock_thumbnail']          = 99;
        $GLOBALS['_mock_attachment_url'][99] = 'https://example.com/wp-content/uploads/photo.jpg';

        $post   = $this->make_post();
        $result = $this->make_builder(['relative_image_paths' => true])->build($post);

        $this->assertSame('/wp-content/uploads/photo.jpg', $result['featured_image']);
    }

    public function test_featured_image_is_absolute_when_option_disabled(): void {
        $GLOBALS['_mock_thumbnail']          = 99;
        $GLOBALS['_mock_attachment_url'][99] = 'https://example.com/wp-content/uploads/photo.jpg';

        $post   = $this->make_post();
        $result = $this->make_builder(['relative_image_paths' => false])->build($post);

        $this->assertSame('https://example.com/wp-content/uploads/photo.jpg', $result['featured_image']);
    }

    private function set_multi_taxonomy_terms(): void {
        $GLOBALS['_mock_object_taxonomies']['post'] = [
            'category' => (object) [ 'name' => 'category', 'label' => 'Categories' ],
            'post_tag' => (object) [ 'name' => 'post_tag', 'label' => 'Tags' ],
            'sector'   => (object) [ 'name' => 'sector', 'label' => 'Sector' ],
        ];
        $GLOBALS['_mock_terms'][42]['category'] = [
            (object) [ 'term_id' => 1, 'name' => 'News', 'slug' => 'news' ],
            (object) [ 'term_id' => 2, 'name' => 'Climate', 'slug' => 'climate' ],
        ];
        $GLOBALS['_mock_terms'][42]['post_tag'] = [
            (object) [ 'term_id' => 3, 'name' => 'net-zero', 'slug' => 'net-zero' ],
            (object) [ 'term_id' => 4, 'name' => 'Climate', 'slug' => 'climate-tag' ],
        ];
        $GLOBALS['_mock_terms'][42]['sector'] = [
            (object) [ 'term_id' => 5, 'name' => 'Energy', 'slug' => 'energy' ],
        ];
    }

    public function test_okf_compat_off_produces_no_timestamp_or_flat_tags_changes(): void {
        $this->set_multi_taxonomy_terms();

        $post   = $this->make_post();
        $result = $this->make_builder( [ 'include_taxonomies' => true, 'okf_compat' => false ] )->build( $post );

        $this->assertArrayNotHasKey( 'timestamp', $result );
        $this->assertSame( [ 'net-zero', 'Climate' ], $result['tags'] );
    }

    public function test_okf_compat_on_adds_timestamp_mirroring_modified(): void {
        $post   = $this->make_post();
        $result = $this->make_builder( [ 'okf_compat' => true ] )->build( $post );

        $this->assertArrayHasKey( 'timestamp', $result );
        $this->assertSame( $result['modified'], $result['timestamp'] );
        $this->assertSame( '2025-10-15T14:23:00Z', $result['timestamp'] );
    }

    public function test_okf_compat_on_no_timestamp_when_modified_empty(): void {
        $post   = $this->make_post( [ 'post_modified_gmt' => '0000-00-00 00:00:00' ] );
        $result = $this->make_builder( [ 'okf_compat' => true ] )->build( $post );

        $this->assertArrayNotHasKey( 'timestamp', $result );
    }

    public function test_okf_compat_on_flat_tags_across_taxonomies_deduped(): void {
        $this->set_multi_taxonomy_terms();

        $post   = $this->make_post();
        $result = $this->make_builder( [ 'include_taxonomies' => true, 'okf_compat' => true ] )->build( $post );

        $this->assertSame( [ 'News', 'Climate', 'net-zero', 'Energy' ], $result['tags'] );
        $this->assertSame( [ 'News', 'Climate' ], $result['categories'] );
    }

    public function test_okf_compat_on_builds_flat_tags_even_when_include_taxonomies_off(): void {
        $this->set_multi_taxonomy_terms();

        $post   = $this->make_post();
        $result = $this->make_builder( [ 'include_taxonomies' => false, 'okf_compat' => true ] )->build( $post );

        $this->assertArrayHasKey( 'tags', $result );
        $this->assertSame( [ 'News', 'Climate', 'net-zero', 'Energy' ], $result['tags'] );
        $this->assertArrayNotHasKey( 'categories', $result );
    }

    public function test_flat_tags_filter_applied(): void {
        $this->set_multi_taxonomy_terms();

        $GLOBALS['_mock_apply_filters']['markdown_for_agents_flat_tags'] = static function ( array $flat, \WP_Post $post ): array {
            $flat[] = 'extra';
            return $flat;
        };

        $post   = $this->make_post();
        $result = $this->make_builder( [ 'include_taxonomies' => true, 'okf_compat' => true ] )->build( $post );

        unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_flat_tags'] );

        $this->assertContains( 'extra', $result['tags'] );
    }
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyCollector;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\TaxonomyCollector
 */
class TaxonomyCollectorTest extends TestCase {

    private TaxonomyCollector $collector;

    protected function setUp(): void {
        $this->collector = new TaxonomyCollector();

        // Reset global mocks.
        $GLOBALS['_mock_terms']              = [];
        $GLOBALS['_mock_object_taxonomies']  = [];
    }

    private function make_term( string $name, int $term_id = 1 ): object {
        return (object) [ 'term_id' => $term_id, 'name' => $name, 'slug' => sanitize_title_stub( $name ) ];
    }

    public function test_returns_empty_array_when_no_taxonomies(): void {
        $GLOBALS['_mock_object_taxonomies']['post'] = [];

        $result = $this->collector->collect( 1, 'post' );
        $this->assertSame( [], $result );
    }

    public function test_normalises_post_tag_to_tags(): void {
        $tax = (object) [ 'name' => 'post_tag', 'label' => 'Tags' ];
        $GLOBALS['_mock_object_taxonomies']['post'] = [ 'post_tag' => $tax ];
        $GLOBALS['_mock_terms'][1]['post_tag']      = [ $this->make_term( 'climate' ), $this->make_term( 'law' ) ];

        $result = $this->collector->collect( 1, 'post' );

        $this->assertArrayHasKey( 'tags', $result );
        $this->assertSame( [ 'climate', 'law' ], $result['tags'] );
    }

    public function test_normalises_category_to_categories(): void {
        $tax = (object) [ 'name' => 'category', 'label' => 'Categories' ];
        $GLOBALS['_mock_object_taxonomies']['post'] = [ 'category' => $tax ];
        $GLOBALS['_mock_terms'][1]['category']      = [ $this->make_term( 'News' ) ];

        $result = $this->collector->collect( 1, 'post' );

        $this->assertArrayHasKey( 'categories', $result );
        $this->assertSame( [ 'News' ], $result['categories'] );
    }

    public function test_skips_taxonomy_with_no_terms(): void {
        $tax = (object) [ 'name' => 'post_tag', 'label' => 'Tags' ];
        $GLOBALS['_mock_object_taxonomies']['post'] = [ 'post_tag' => $tax ];
        // No terms set in mock — get_the_terms returns false.

        $result = $this->collector->collect( 1, 'post' );
        $this->assertArrayNotHasKey( 'tags', $result );
    }

    public function test_decodes_html_entities_in_term_names(): void {
        $tax = (object) [ 'name' => 'category', 'label' => 'Categories' ];
        $GLOBALS['_mock_object_taxonomies']['post'] = [ 'category' => $tax ];
        $GLOBALS['_mock_terms'][1]['category']      = [ $this->make_term( 'Cats &amp; Dogs' ) ];

        $result = $this->collector->collect( 1, 'post' );

        $this->assertSame( [ 'Cats & Dogs' ], $result['categories'] );
    }

    public function test_custom_taxonomy_uses_taxonomy_slug_as_key(): void {
        $tax = (object) [ 'name' => 'topic', 'label' => 'Topics' ];
        $GLOBALS['_mock_object_taxonomies']['post'] = [ 'topic' => $tax ];
        $GLOBALS['_mock_terms'][1]['topic']         = [ $this->make_term( 'Contract Law' ) ];

        $result = $this->collector->collect( 1, 'post' );

        $this->assertArrayHasKey( 'topic', $result );
        $this->assertSame( [ 'Contract Law' ], $result['topic'] );
    }

    public function test_collects_multiple_taxonomies(): void {
        $GLOBALS['_mock_object_taxonomies']['post'] = [
            'category' => (object) [ 'name' => 'category', 'label' => 'Categories' ],
            'post_tag' => (object) [ 'name' => 'post_tag', 'label' => 'Tags' ],
        ];
        $GLOBALS['_mock_terms'][1]['category'] = [ $this->make_term( 'News' ) ];
        $GLOBALS['_mock_terms'][1]['post_tag']  = [ $this->make_term( 'climate' ), $this->make_term( 'legal' ) ];

        $result = $this->collector->collect( 1, 'post' );

        $this->assertSame( [ 'News' ], $result['categories'] );
        $this->assertSame( [ 'climate', 'legal' ], $result['tags'] );
    }
}

// Minimal helper for test term slugs — not a WP function stub.
function sanitize_title_stub( string $title ): string {
    return strtolower( str_replace( ' ', '-', $title ) );
}

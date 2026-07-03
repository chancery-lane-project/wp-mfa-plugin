<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\InternalUrlResolver;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\InternalUrlResolver
 */
class InternalUrlResolverTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_mock_post_objects']        = [];
		$GLOBALS['_mock_post_meta']           = [];
		$GLOBALS['_mock_url_to_postid']       = [];
		$GLOBALS['_mock_url_to_postid_calls'] = 0;
		$GLOBALS['_mock_taxonomies']          = [ 'category' => 'category' ];
		$GLOBALS['_mock_taxonomy_terms']      = [];
		$GLOBALS['_mock_term_link']           = [];
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_mock_post_objects'],
			$GLOBALS['_mock_post_meta'],
			$GLOBALS['_mock_url_to_postid'],
			$GLOBALS['_mock_url_to_postid_calls'],
			$GLOBALS['_mock_taxonomies'],
			$GLOBALS['_mock_taxonomy_terms'],
			$GLOBALS['_mock_term_link']
		);
	}

	private function make_post( array $props = [] ): \WP_Post {
		return new \WP_Post( array_merge(
			[
				'ID'            => 1,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_name'     => 'my-post',
				'post_password' => '',
			],
			$props
		) );
	}

	private function make_term( array $props = [] ): \WP_Term {
		return new \WP_Term( array_merge(
			[
				'term_id'  => 10,
				'name'     => 'Climate Law',
				'slug'     => 'climate-law',
				'taxonomy' => 'category',
			],
			$props
		) );
	}

	private function resolver( array $options = [] ): InternalUrlResolver {
		return new InternalUrlResolver( array_merge( [ 'post_types' => [ 'post', 'page' ] ], $options ) );
	}

	public function test_external_host_returns_null(): void {
		$r = $this->resolver();

		$this->assertNull( $r->resolve( 'https://elsewhere.org/other-post/' ) );
	}

	public function test_resolvable_published_post_of_enabled_type(): void {
		$post = $this->make_post();
		$GLOBALS['_mock_post_objects'][1]  = $post;
		$GLOBALS['_mock_url_to_postid']['https://example.com/my-post/'] = 1;

		$r = $this->resolver();

		$this->assertSame( 'post/my-post.md', $r->resolve( 'https://example.com/my-post/' ) );
	}

	public function test_post_of_non_enabled_type_returns_null(): void {
		$post = $this->make_post( [ 'post_type' => 'product' ] );
		$GLOBALS['_mock_post_objects'][1]  = $post;
		$GLOBALS['_mock_url_to_postid']['https://example.com/my-post/'] = 1;

		$r = $this->resolver();

		$this->assertNull( $r->resolve( 'https://example.com/my-post/' ) );
	}

	public function test_excluded_post_returns_null(): void {
		$post = $this->make_post();
		$GLOBALS['_mock_post_objects'][1] = $post;
		$GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';
		$GLOBALS['_mock_url_to_postid']['https://example.com/my-post/'] = 1;

		$r = $this->resolver();

		$this->assertNull( $r->resolve( 'https://example.com/my-post/' ) );
	}

	public function test_non_publish_post_returns_null(): void {
		$post = $this->make_post( [ 'post_status' => 'draft' ] );
		$GLOBALS['_mock_post_objects'][1] = $post;
		$GLOBALS['_mock_url_to_postid']['https://example.com/my-post/'] = 1;

		$r = $this->resolver();

		$this->assertNull( $r->resolve( 'https://example.com/my-post/' ) );
	}

	public function test_passworded_post_returns_null(): void {
		$post = $this->make_post( [ 'post_password' => 'secret' ] );
		$GLOBALS['_mock_post_objects'][1] = $post;
		$GLOBALS['_mock_url_to_postid']['https://example.com/my-post/'] = 1;

		$r = $this->resolver();

		$this->assertNull( $r->resolve( 'https://example.com/my-post/' ) );
	}

	public function test_term_url_resolves_to_taxonomy_path(): void {
		$term = $this->make_term();
		$GLOBALS['_mock_taxonomy_terms']['category'] = [ $term ];
		$GLOBALS['_mock_term_link'][10] = 'https://example.com/category/climate-law/';

		$r = $this->resolver();

		$this->assertSame( 'taxonomy/category/climate-law.md', $r->resolve( 'https://example.com/category/climate-law/' ) );
	}

	public function test_term_url_comparison_is_trailing_slash_insensitive(): void {
		$term = $this->make_term();
		$GLOBALS['_mock_taxonomy_terms']['category'] = [ $term ];
		$GLOBALS['_mock_term_link'][10] = 'https://example.com/category/climate-law/';

		$r = $this->resolver();

		$this->assertSame( 'taxonomy/category/climate-law.md', $r->resolve( 'https://example.com/category/climate-law' ) );
	}

	public function test_unknown_internal_url_returns_null(): void {
		$r = $this->resolver();

		$this->assertNull( $r->resolve( 'https://example.com/nowhere/' ) );
	}

	public function test_resolution_is_memoised_per_url(): void {
		$post = $this->make_post();
		$GLOBALS['_mock_post_objects'][1] = $post;
		$GLOBALS['_mock_url_to_postid']['https://example.com/my-post/'] = 1;

		$r = $this->resolver();
		$r->resolve( 'https://example.com/my-post/' );
		$r->resolve( 'https://example.com/my-post/' );

		$this->assertSame( 1, $GLOBALS['_mock_url_to_postid_calls'] );
	}

	public function test_term_map_is_built_once_across_multiple_lookups(): void {
		$term1 = $this->make_term();
		$term2 = $this->make_term( [ 'term_id' => 11, 'slug' => 'net-zero' ] );
		$GLOBALS['_mock_taxonomy_terms']['category'] = [ $term1, $term2 ];
		$GLOBALS['_mock_term_link'][10] = 'https://example.com/category/climate-law/';
		$GLOBALS['_mock_term_link'][11] = 'https://example.com/category/net-zero/';

		$r = $this->resolver();
		$r->resolve( 'https://example.com/category/climate-law/' );
		$r->resolve( 'https://example.com/category/net-zero/' );

		// Both terms are already available in the taxonomy map built on first lookup.
		$this->assertSame( 'taxonomy/category/climate-law.md', $r->resolve( 'https://example.com/category/climate-law/' ) );
		$this->assertSame( 'taxonomy/category/net-zero.md', $r->resolve( 'https://example.com/category/net-zero/' ) );
	}
}

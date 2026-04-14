<?php
declare(strict_types=1);
namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\ManifestGenerator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\ManifestGenerator
 */
class ManifestGeneratorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_mock_terms']             = [];
		$GLOBALS['_mock_object_taxonomies'] = [];
	}

	private function make_post( array $props = [] ): \WP_Post {
		return new \WP_Post( array_merge([
			'ID'           => 1,
			'post_title'   => 'Hello',
			'post_content' => 'Content',
			'post_type'    => 'post',
			'post_modified'=> '2025-01-01 12:00:00',
		], $props ) );
	}

	public function test_hash_changes_when_taxonomy_terms_change(): void {
		$post = $this->make_post();

		$GLOBALS['_mock_object_taxonomies']['post'] = ['category'];
		$GLOBALS['_mock_terms'][$post->ID]['category'] = [
			new \WP_Term(['term_id' => 1, 'slug' => 'news', 'name' => 'News', 'taxonomy' => 'category']),
		];
		$hash_with_news = ManifestGenerator::compute_full_hash( $post );

		$GLOBALS['_mock_terms'][$post->ID]['category'] = [
			new \WP_Term(['term_id' => 2, 'slug' => 'climate', 'name' => 'Climate', 'taxonomy' => 'category']),
		];
		$hash_with_climate = ManifestGenerator::compute_full_hash( $post );

		$this->assertNotSame( $hash_with_news, $hash_with_climate );
	}

	public function test_hash_stable_when_terms_unchanged(): void {
		$post = $this->make_post();
		$GLOBALS['_mock_object_taxonomies']['post'] = ['category'];
		$GLOBALS['_mock_terms'][$post->ID]['category'] = [
			new \WP_Term(['term_id' => 1, 'slug' => 'news', 'name' => 'News', 'taxonomy' => 'category']),
		];

		$hash_a = ManifestGenerator::compute_full_hash( $post );
		$hash_b = ManifestGenerator::compute_full_hash( $post );

		$this->assertSame( $hash_a, $hash_b );
	}

	public function test_hash_stable_with_no_taxonomies(): void {
		$post = $this->make_post();
		$GLOBALS['_mock_object_taxonomies']['post'] = [];

		$hash_a = ManifestGenerator::compute_full_hash( $post );
		$hash_b = ManifestGenerator::compute_full_hash( $post );

		$this->assertSame( $hash_a, $hash_b );
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\LinkRewriter;

class LinkRewriterTest extends TestCase {

	private function rewriter( array $map ): LinkRewriter {
		return new LinkRewriter( fn( string $url ): ?string => $map[ $url ] ?? null );
	}

	public function test_rewrites_resolvable_internal_link_to_markdown_page_url(): void {
		$r  = $this->rewriter( [ 'https://example.com/other-post/' => 'https://example.com/other-post/' ] );
		$md = 'See [the other post](https://example.com/other-post/) for details.';

		$this->assertSame(
			'See [the other post](https://example.com/other-post/?output_format=md) for details.',
			$r->rewrite( $md )
		);
	}

	public function test_uses_the_canonical_url_returned_by_the_resolver(): void {
		// e.g. a link written against a since-renamed slug.
		$r  = $this->rewriter( [ 'https://example.com/old-slug/' => 'https://example.com/new-slug/' ] );
		$md = '[renamed](https://example.com/old-slug/)';

		$this->assertSame( '[renamed](https://example.com/new-slug/?output_format=md)', $r->rewrite( $md ) );
	}

	public function test_rewrites_term_archive_link(): void {
		$r  = $this->rewriter( [ 'https://example.com/category/climate/' => 'https://example.com/category/climate/' ] );
		$md = '[Climate](https://example.com/category/climate/)';

		$this->assertSame(
			'[Climate](https://example.com/category/climate/?output_format=md)',
			$r->rewrite( $md )
		);
	}

	public function test_passes_root_relative_links_to_the_resolver(): void {
		$r  = $this->rewriter( [ '/about/' => 'https://example.com/about/' ] );
		$md = '[About](/about/)';

		$this->assertSame( '[About](https://example.com/about/?output_format=md)', $r->rewrite( $md ) );
	}

	public function test_leaves_unresolvable_and_external_links_untouched(): void {
		$r  = $this->rewriter( [] );
		$md = '[ext](https://elsewhere.org/x/) [rel](./a.md) [mail](mailto:a@b.c) [anchor](#top)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}

	public function test_does_not_rewrite_image_links(): void {
		$r  = $this->rewriter( [ 'https://example.com/img-page/' => 'https://example.com/img-page/' ] );
		$md = '![alt text](https://example.com/img-page/)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}

	public function test_preserves_fragment_and_strips_query(): void {
		$r  = $this->rewriter( [ 'https://example.com/other/' => 'https://example.com/other/' ] );
		$md = '[a](https://example.com/other/#section) [b](https://example.com/other/?utm=x)';

		$this->assertSame(
			'[a](https://example.com/other/?output_format=md#section) [b](https://example.com/other/?output_format=md)',
			$r->rewrite( $md )
		);
	}

	/**
	 * @dataProvider pagination_links
	 */
	public function test_leaves_pagination_links_untouched( string $url ): void {
		// Page 2+ of a listing has no export; stripping the query would turn
		// "Next page" into a link back to the document itself. The resolver
		// matches everything, as url_to_postid() does for `/blog/page/2/`.
		$r  = new LinkRewriter( fn( string $url ): ?string => 'https://example.com/blog/' );
		$md = '[Next page](' . $url . ')';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}

	/** @return array<string, array{0: string}> */
	public static function pagination_links(): array {
		return [
			'paged'           => [ 'https://example.com/blog/?paged=2' ],
			'page'            => [ 'https://example.com/blog/?page=2' ],
			'query loop'      => [ 'https://example.com/blog/?query-1-page=2' ],
			'comments page'   => [ 'https://example.com/blog/?cpage=2' ],
			'among other args' => [ 'https://example.com/blog/?utm_source=x&paged=3' ],
			'pretty'          => [ 'https://example.com/blog/page/2/' ],
			'pretty no slash' => [ 'https://example.com/blog/page/2' ],
			'query-only'      => [ '?query-1-page=2' ],
		];
	}

	public function test_rewrites_links_whose_query_is_not_pagination(): void {
		$r  = $this->rewriter( [ 'https://example.com/blog/' => 'https://example.com/blog/' ] );
		$md = '[a](https://example.com/blog/?pagename=x) [b](https://example.com/blog/?mypaged=2)';

		$this->assertSame(
			'[a](https://example.com/blog/?output_format=md) [b](https://example.com/blog/?output_format=md)',
			$r->rewrite( $md )
		);
	}

	public function test_rewriting_is_idempotent(): void {
		$r    = $this->rewriter( [ 'https://example.com/other/' => 'https://example.com/other/' ] );
		$once = $r->rewrite( '[a](https://example.com/other/#s)' );

		$this->assertSame( $once, $r->rewrite( $once ) );
	}

	public function test_unmatchable_urls_fail_safe_untouched(): void {
		// The ')' URL matches only up to the first ')', truncating the captured
		// URL; the truncated string resolves to null so the match is returned
		// verbatim (identity no-op). The space URL never matches at all. Either
		// way the text passes through unchanged.
		$r  = $this->rewriter( [ 'https://example.com/weird/' => 'https://example.com/weird/' ] );
		$md = '[a](https://example.com/x(1)/) [b](https://example.com/a b/)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}

	public function test_markdown_url_appends_query_argument(): void {
		$this->assertSame( 'https://example.com/a/?output_format=md', LinkRewriter::markdown_url( 'https://example.com/a/' ) );
	}

	public function test_markdown_url_joins_plain_permalinks_with_ampersand(): void {
		$this->assertSame( 'https://example.com/?p=12&output_format=md', LinkRewriter::markdown_url( 'https://example.com/?p=12' ) );
	}

	public function test_markdown_url_keeps_fragment_after_query(): void {
		$this->assertSame( 'https://example.com/a/?output_format=md#top', LinkRewriter::markdown_url( 'https://example.com/a/#top' ) );
	}
}

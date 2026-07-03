<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\LinkRewriter;

class LinkRewriterTest extends TestCase {

	private const BASE = 'https://example.com/wp-content/uploads/wp-mfa-exports';

	private function rewriter( array $map ): LinkRewriter {
		return new LinkRewriter( fn( string $url ): ?string => $map[ $url ] ?? null, self::BASE );
	}

	public function test_rewrites_resolvable_internal_link_to_absolute_md_url(): void {
		$r  = $this->rewriter( [ 'https://example.com/other-post/' => 'post/other-post.md' ] );
		$md = 'See [the other post](https://example.com/other-post/) for details.';

		$this->assertSame(
			'See [the other post](' . self::BASE . '/post/other-post.md) for details.',
			$r->rewrite( $md )
		);
	}

	public function test_rewrites_term_archive_link(): void {
		$r  = $this->rewriter( [ 'https://example.com/category/climate/' => 'taxonomy/category/climate.md' ] );
		$md = '[Climate](https://example.com/category/climate/)';

		$this->assertSame(
			'[Climate](' . self::BASE . '/taxonomy/category/climate.md)',
			$r->rewrite( $md )
		);
	}

	public function test_leaves_unresolvable_and_external_links_untouched(): void {
		$r  = $this->rewriter( [] );
		$md = '[ext](https://elsewhere.org/x/) [rel](./a.md) [mail](mailto:a@b.c) [anchor](#top)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}

	public function test_does_not_rewrite_image_links(): void {
		$r  = $this->rewriter( [ 'https://example.com/img-page/' => 'post/img-page.md' ] );
		$md = '![alt text](https://example.com/img-page/)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}

	public function test_preserves_fragment_and_strips_query(): void {
		$r  = $this->rewriter( [ 'https://example.com/other/' => 'post/other.md' ] );
		$md = '[a](https://example.com/other/#section) [b](https://example.com/other/?utm=x)';

		$this->assertSame(
			'[a](' . self::BASE . '/post/other.md#section) [b](' . self::BASE . '/post/other.md)',
			$r->rewrite( $md )
		);
	}

	public function test_unmatchable_urls_fail_safe_untouched(): void {
		// URLs containing ')' or spaces don't match the link regex — they must
		// pass through unchanged rather than being mangled.
		$r  = $this->rewriter( [ 'https://example.com/weird/' => 'post/weird.md' ] );
		$md = '[a](https://example.com/x(1)/) [b](https://example.com/a b/)';

		$this->assertSame( $md, $r->rewrite( $md ) );
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Negotiate;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Negotiate\Negotiator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Negotiate\Negotiator
 */
class NegotiatorTest extends TestCase {

    private string $tmp_dir;

    /** @var Generator&MockObject */
    private Generator $generator;

    protected function setUp(): void {
        $this->tmp_dir = sys_get_temp_dir() . '/wp-mfa-neg-' . uniqid();
        mkdir( $this->tmp_dir, 0755, true );

        $this->generator = $this->createMock( Generator::class );

        $GLOBALS['_mock_is_singular']    = false;
        $GLOBALS['_mock_queried_object'] = null;
        $_SERVER['HTTP_ACCEPT']          = '';
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->tmp_dir );
        unset( $_SERVER['HTTP_ACCEPT'] );
    }

    private function make_negotiator( array $options = [] ): Negotiator {
        return new Negotiator(
            array_merge( [ 'post_types' => [ 'post', 'page' ], 'export_dir' => 'wp-mfa-exports' ], $options ),
            $this->generator
        );
    }

    private function make_post(): \WP_Post {
        return new \WP_Post( [
            'ID'        => 1,
            'post_type' => 'post',
            'post_name' => 'test-post',
        ] );
    }

    // -----------------------------------------------------------------------
    // maybe_serve_markdown — guard conditions
    // -----------------------------------------------------------------------

    public function test_does_nothing_when_not_singular(): void {
        $GLOBALS['_mock_is_singular'] = false;
        $_SERVER['HTTP_ACCEPT']       = 'text/markdown';

        $this->generator->expects( $this->never() )->method( 'get_export_path' );

        $neg = $this->make_negotiator();
        $neg->maybe_serve_markdown();
    }

    public function test_does_nothing_when_accept_header_missing_markdown(): void {
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $this->make_post();
        $_SERVER['HTTP_ACCEPT']          = 'text/html,application/xhtml+xml';

        $this->generator->expects( $this->never() )->method( 'get_export_path' );

        $neg = $this->make_negotiator();
        $neg->maybe_serve_markdown();
    }

    public function test_does_nothing_when_queried_object_is_not_wp_post(): void {
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = (object) [ 'term_id' => 1 ]; // taxonomy term, not WP_Post
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        $this->generator->expects( $this->never() )->method( 'get_export_path' );

        $neg = $this->make_negotiator();
        $neg->maybe_serve_markdown();
    }

    public function test_does_nothing_when_md_file_does_not_exist(): void {
        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        $this->generator->method( 'get_export_path' )
            ->willReturn( '/nonexistent/path/post.md' );

        // Should return before serving — no exception means success.
        $neg = $this->make_negotiator();
        $neg->maybe_serve_markdown();
        $this->addToAssertionCount( 1 );
    }

    // -----------------------------------------------------------------------
    // output_link_tag — guard conditions
    // -----------------------------------------------------------------------

    public function test_link_tag_not_output_when_not_singular(): void {
        $GLOBALS['_mock_is_singular'] = false;

        $this->generator->expects( $this->never() )->method( 'get_export_path' );

        ob_start();
        $this->make_negotiator()->output_link_tag();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_link_tag_not_output_when_md_file_missing(): void {
        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;

        $this->generator->method( 'get_export_path' )
            ->willReturn( '/nonexistent/path/post.md' );

        ob_start();
        $this->make_negotiator()->output_link_tag();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_link_tag_output_when_md_file_exists(): void {
        $md_file = $this->tmp_dir . '/test-post.md';
        file_put_contents( $md_file, '# Test' );

        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $GLOBALS['_mock_permalink']      = 'https://example.com/test-post/';

        $this->generator->method( 'get_export_path' )->willReturn( $md_file );

        ob_start();
        $this->make_negotiator()->output_link_tag();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'rel="alternate"', $output );
        $this->assertStringContainsString( 'type="text/markdown"', $output );
        $this->assertStringContainsString( 'https://example.com/test-post/', $output );
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

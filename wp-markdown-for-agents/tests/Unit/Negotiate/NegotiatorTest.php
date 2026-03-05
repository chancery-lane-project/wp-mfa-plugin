<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Negotiate;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
use Tclp\WpMarkdownForAgents\Negotiate\Negotiator;
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;

/**
 * @covers \Tclp\WpMarkdownForAgents\Negotiate\Negotiator
 */
class NegotiatorTest extends TestCase {

    private string $tmp_dir;

    /** @var Generator&MockObject */
    private Generator $generator;

    /** @var AccessLogger&MockObject */
    private AccessLogger $logger;

    protected function setUp(): void {
        $this->tmp_dir = sys_get_temp_dir() . '/wp-mfa-neg-' . uniqid();
        mkdir( $this->tmp_dir, 0755, true );

        $this->generator = $this->createMock( Generator::class );
        $this->logger    = $this->createMock( AccessLogger::class );

        $GLOBALS['_mock_is_singular']    = false;
        $GLOBALS['_mock_queried_object'] = null;
        $_SERVER['HTTP_ACCEPT']          = '';
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->tmp_dir );
        unset( $_SERVER['HTTP_ACCEPT'] );
        unset( $_SERVER['HTTP_USER_AGENT'] );
    }

    private function make_negotiator( array $options = [] ): Negotiator {
        $merged = array_merge( [
            'post_types'       => [ 'post', 'page' ],
            'export_dir'       => 'wp-mfa-exports',
            'ua_force_enabled' => false,
            'ua_agent_strings' => [],
        ], $options );
        return new Negotiator( $merged, $this->generator, new AgentDetector( $merged ), $this->logger );
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
    // maybe_serve_markdown — UA detection
    // -----------------------------------------------------------------------

    public function test_calls_get_export_path_when_ua_matches_known_agent(): void {
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $this->make_post();
        $_SERVER['HTTP_ACCEPT']          = 'text/html';
        $_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

        // File does not exist → exits early after get_export_path, before readfile/exit.
        $this->generator->expects( $this->once() )
            ->method( 'get_export_path' )
            ->willReturn( '/nonexistent/path/post.md' );

        $neg = $this->make_negotiator( [
            'ua_force_enabled' => true,
            'ua_agent_strings' => [ 'GPTBot' ],
        ] );
        $neg->maybe_serve_markdown();
    }

    public function test_does_nothing_when_ua_force_disabled_even_if_ua_matches(): void {
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $this->make_post();
        $_SERVER['HTTP_ACCEPT']          = 'text/html';
        $_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

        $this->generator->expects( $this->never() )->method( 'get_export_path' );

        $neg = $this->make_negotiator( [
            'ua_force_enabled' => false,
            'ua_agent_strings' => [ 'GPTBot' ],
        ] );
        $neg->maybe_serve_markdown();
    }

    public function test_does_nothing_when_ua_unknown_and_no_accept_header(): void {
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $this->make_post();
        $_SERVER['HTTP_ACCEPT']          = 'text/html';
        $_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 Chrome/120';

        $this->generator->expects( $this->never() )->method( 'get_export_path' );

        $neg = $this->make_negotiator( [
            'ua_force_enabled' => true,
            'ua_agent_strings' => [ 'GPTBot' ],
        ] );
        $neg->maybe_serve_markdown();
    }

    // -----------------------------------------------------------------------
    // maybe_serve_markdown — access logging
    // -----------------------------------------------------------------------

    public function test_log_access_not_called_when_file_missing(): void {
        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        $this->generator->method( 'get_export_path' )
            ->willReturn( '/nonexistent/path/post.md' );

        $this->logger->expects( $this->never() )->method( 'log_access' );

        $neg = $this->make_negotiator();
        $neg->maybe_serve_markdown();
    }

    public function test_log_access_not_called_when_no_match(): void {
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $this->make_post();
        $_SERVER['HTTP_ACCEPT']          = 'text/html';
        $_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 Chrome/120';

        $this->logger->expects( $this->never() )->method( 'log_access' );

        $neg = $this->make_negotiator( [
            'ua_force_enabled' => true,
            'ua_agent_strings' => [ 'GPTBot' ],
        ] );
        $neg->maybe_serve_markdown();
    }

    public function test_log_access_not_called_when_not_singular(): void {
        $GLOBALS['_mock_is_singular'] = false;
        $_SERVER['HTTP_ACCEPT']       = 'text/markdown';

        $this->logger->expects( $this->never() )->method( 'log_access' );

        $neg = $this->make_negotiator();
        $neg->maybe_serve_markdown();
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

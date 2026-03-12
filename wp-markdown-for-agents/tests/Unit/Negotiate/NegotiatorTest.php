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
        // Place tmp_dir inside the default export_dir base so is_safe_filepath
        // can validate paths in tests that exercise the full serve path.
        $export_base   = sys_get_temp_dir() . '/wp-mfa-exports';
        $this->tmp_dir = $export_base . '/' . uniqid( 'wp-mfa-neg-', true );
        mkdir( $this->tmp_dir, 0755, true );

        $this->generator = $this->createMock( Generator::class );
        $this->logger    = $this->createMock( AccessLogger::class );

        $GLOBALS['_mock_is_singular']    = false;
        $GLOBALS['_mock_queried_object'] = null;
        $GLOBALS['_mock_sent_headers']   = [];
        $_SERVER['HTTP_ACCEPT']          = '';
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->tmp_dir );
        unset( $_SERVER['HTTP_ACCEPT'] );
        unset( $_SERVER['HTTP_USER_AGENT'] );
        unset( $_GET['output_format'] );    // ADD this line
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
    // maybe_serve_markdown — query parameter negotiation (B4)
    // -----------------------------------------------------------------------

    public function test_serves_markdown_via_output_format_md_query_param(): void {
        $md_file = $this->tmp_dir . '/test-post.md';
        file_put_contents( $md_file, '# Test' );

        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/html';
        $_GET['output_format']           = 'md';

        $this->generator->method( 'get_export_path' )->willReturn( $md_file );
        $this->logger->expects( $this->once() )
            ->method( 'log_access' )
            ->with( 1, 'query-param' );

        $neg = $this->make_negotiator();
        try {
            $neg->maybe_serve_markdown();
        } catch ( \Exception $e ) {}
    }

    public function test_serves_markdown_via_output_format_markdown_query_param(): void {
        $md_file = $this->tmp_dir . '/test-post.md';
        file_put_contents( $md_file, '# Test' );

        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/html';
        $_GET['output_format']           = 'markdown';

        $this->generator->method( 'get_export_path' )->willReturn( $md_file );
        $this->logger->expects( $this->once() )
            ->method( 'log_access' )
            ->with( 1, 'query-param' );

        $neg = $this->make_negotiator();
        try {
            $neg->maybe_serve_markdown();
        } catch ( \Exception $e ) {}
    }

    public function test_does_nothing_when_output_format_query_param_is_invalid(): void {
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $this->make_post();
        $_SERVER['HTTP_ACCEPT']          = 'text/html';
        $_GET['output_format']           = 'html';

        $this->generator->expects( $this->never() )->method( 'get_export_path' );
        $this->make_negotiator()->maybe_serve_markdown();
    }

    // -----------------------------------------------------------------------
    // output_link_tag — href includes ?output_format=md (B3)
    // -----------------------------------------------------------------------

    public function test_link_tag_href_includes_output_format_query_param(): void {
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

        $this->assertStringContainsString( 'output_format=md', $output );
        $this->assertStringContainsString( 'https://example.com/test-post/', $output );
    }

    // -----------------------------------------------------------------------
    // maybe_serve_markdown — Vary: Accept scoping (G4)
    // -----------------------------------------------------------------------

    public function test_log_access_label_is_query_param_when_served_via_query_param(): void {
        // Indirectly verifies that the query-param path does not send Vary: Accept
        // (the access label distinguishes query-param from accept-header).
        $md_file = $this->tmp_dir . '/test-post.md';
        file_put_contents( $md_file, '# Test' );

        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/html';
        $_GET['output_format']           = 'md';

        $this->generator->method( 'get_export_path' )->willReturn( $md_file );
        $this->logger->expects( $this->once() )
            ->method( 'log_access' )
            ->with( 1, 'query-param' ); // NOT 'accept-header' — Vary: Accept must not be sent

        $neg = $this->make_negotiator();
        try {
            $neg->maybe_serve_markdown();
        } catch ( \Exception $e ) {}
    }

    // -----------------------------------------------------------------------
    // maybe_serve_markdown — Content-Signal header filter (G3)
    // -----------------------------------------------------------------------

    public function test_content_signal_filter_receives_correct_default_value(): void {
        $md_file = $this->tmp_dir . '/test-post.md';
        file_put_contents( $md_file, '# Test' );

        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        $this->generator->method( 'get_export_path' )->willReturn( $md_file );
        $this->logger->method( 'log_access' );

        $filter_received = null;
        $GLOBALS['_mock_apply_filters']['wp_mfa_content_signal'] = static function ( string $val ) use ( &$filter_received ): string {
            $filter_received = $val;
            return $val;
        };

        $neg = $this->make_negotiator();
        try {
            $neg->maybe_serve_markdown();
        } catch ( \Exception $e ) {}

        $this->assertSame( 'ai-input=yes, search=yes', $filter_received );
        $this->assertContains( 'Content-Signal: ai-input=yes, search=yes', $GLOBALS['_mock_sent_headers'] );
        $this->assertContains( 'X-Markdown-Source: wp-markdown-for-agents', $GLOBALS['_mock_sent_headers'] );
        unset( $GLOBALS['_mock_apply_filters']['wp_mfa_content_signal'] );
    }

    public function test_code_path_completes_when_content_signal_filter_returns_empty_string(): void {
        $md_file = $this->tmp_dir . '/test-post.md';
        file_put_contents( $md_file, '# Test' );

        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        $this->generator->method( 'get_export_path' )->willReturn( $md_file );

        $GLOBALS['_mock_apply_filters']['wp_mfa_content_signal'] = static fn( string $val ): string => '';

        // The method must proceed all the way to log_access (no fatal early-return
        // when the filter suppresses the Content-Signal header).
        $this->logger->expects( $this->once() )->method( 'log_access' );

        $neg = $this->make_negotiator();
        try {
            $neg->maybe_serve_markdown();
        } catch ( \Exception $e ) {}

        unset( $GLOBALS['_mock_apply_filters']['wp_mfa_content_signal'] );
    }

    // -----------------------------------------------------------------------
    // maybe_serve_markdown — per-post kill switch (G6)
    // -----------------------------------------------------------------------

    public function test_does_nothing_when_serve_enabled_filter_returns_false(): void {
        $post = $this->make_post();
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        // Filter fires on hot path — after Accept check, after WP_Post check.
        $GLOBALS['_mock_apply_filters']['wp_mfa_serve_enabled'] = static fn( bool $val, \WP_Post $p ): bool => false;

        $this->generator->expects( $this->never() )->method( 'get_export_path' );
        $this->logger->expects( $this->never() )->method( 'log_access' );

        $this->make_negotiator()->maybe_serve_markdown();

        unset( $GLOBALS['_mock_apply_filters']['wp_mfa_serve_enabled'] );
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

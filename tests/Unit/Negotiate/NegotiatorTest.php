<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Negotiate;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
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

    /** @var TaxonomyArchiveGenerator&MockObject */
    private TaxonomyArchiveGenerator $taxonomy_generator;

    /** @var AccessLogger&MockObject */
    private AccessLogger $logger;

    protected function setUp(): void {
        // Place tmp_dir inside the default export_dir base so is_safe_filepath
        // can validate paths in tests that exercise the full serve path.
        $export_base   = sys_get_temp_dir() . '/wp-mfa-exports';
        $this->tmp_dir = $export_base . '/' . uniqid( 'wp-mfa-neg-', true );
        mkdir( $this->tmp_dir, 0755, true );

        $this->generator          = $this->createMock( Generator::class );
        $this->taxonomy_generator = $this->createMock( TaxonomyArchiveGenerator::class );
        $this->logger             = $this->createMock( AccessLogger::class );

        $GLOBALS['_mock_is_singular'] = false;
        $GLOBALS['_mock_is_tax']      = false;
        $GLOBALS['_mock_queried_object'] = null;
        $GLOBALS['_mock_sent_headers']   = [];
        $_SERVER['HTTP_ACCEPT']          = '';
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->tmp_dir );
        unset( $_SERVER['HTTP_ACCEPT'] );
        unset( $_SERVER['HTTP_USER_AGENT'] );
        unset( $_GET['output_format'] );
        unset( $GLOBALS['_mock_is_tax'] );
    }

    private function make_negotiator( array $options = [] ): Negotiator {
        $merged = array_merge( [
            'post_types'       => [ 'post', 'page' ],
            'export_dir'       => 'wp-mfa-exports',
            'ua_force_enabled' => false,
            'ua_agent_strings' => [],
        ], $options );
        return new Negotiator(
            $merged,
            $this->generator,
            $this->taxonomy_generator,
            new AgentDetector( $merged ),
            $this->logger
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
    // is_eligible_singular — filterable post type allowlist (G7)
    // -----------------------------------------------------------------------

    public function test_serves_post_type_added_to_allowlist_via_filter(): void {
        // 'event' is not in options['post_types'], but the filter adds it.
        // With the updated is_singular mock, the test verifies the full hot path runs.
        $GLOBALS['_mock_apply_filters']['wp_mfa_serve_post_types'] = static fn( array $types ): array =>
            array_merge( $types, [ 'event' ] );

        $post = new \WP_Post( [ 'ID' => 2, 'post_type' => 'event', 'post_name' => 'my-event' ] );
        $GLOBALS['_mock_is_singular']    = true;  // Simulates WP confirming this is a singular page.
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        // File missing → early return after get_export_path. Confirms eligible check passed.
        $this->generator->expects( $this->once() )
            ->method( 'get_export_path' )
            ->willReturn( '/nonexistent/event.md' );

        $this->make_negotiator()->maybe_serve_markdown();

        unset( $GLOBALS['_mock_apply_filters']['wp_mfa_serve_post_types'] );
    }

    public function test_does_not_serve_post_type_removed_from_allowlist_via_filter(): void {
        // Negotiator is configured with only 'post'. The filter removes it,
        // leaving an empty array. is_singular([]) returns false (Task 0 fix).
        $GLOBALS['_mock_apply_filters']['wp_mfa_serve_post_types'] = static fn( array $types ): array =>
            array_values( array_filter( $types, static fn( string $t ): bool => $t !== 'post' ) );

        $post = $this->make_post(); // post_type = 'post'
        $GLOBALS['_mock_is_singular']    = true;
        $GLOBALS['_mock_queried_object'] = $post;
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        // is_singular([]) returns false → eligible check fails → get_export_path never called.
        $this->generator->expects( $this->never() )->method( 'get_export_path' );

        $this->make_negotiator( [ 'post_types' => [ 'post' ] ] )->maybe_serve_markdown();

        unset( $GLOBALS['_mock_apply_filters']['wp_mfa_serve_post_types'] );
    }

    // -----------------------------------------------------------------------
    // maybe_serve_markdown — taxonomy archive branch
    // -----------------------------------------------------------------------

    public function test_does_nothing_for_taxonomy_when_no_markdown_signal(): void {
        $GLOBALS['_mock_is_singular'] = false;
        $GLOBALS['_mock_is_tax']      = true;
        $_SERVER['HTTP_ACCEPT']       = 'text/html';

        $this->taxonomy_generator->expects( $this->never() )->method( 'get_export_path' );

        $this->make_negotiator()->maybe_serve_markdown();
    }

    public function test_taxonomy_branch_does_nothing_when_serve_taxonomies_filter_returns_false(): void {
        $GLOBALS['_mock_is_singular'] = false;
        $GLOBALS['_mock_is_tax']      = true;
        $_SERVER['HTTP_ACCEPT']       = 'text/markdown';

        $GLOBALS['_mock_apply_filters']['wp_mfa_serve_taxonomies'] = fn( bool $v ): bool => false;
        $this->taxonomy_generator->expects( $this->never() )->method( 'get_export_path' );

        $this->make_negotiator()->maybe_serve_markdown();

        unset( $GLOBALS['_mock_apply_filters']['wp_mfa_serve_taxonomies'] );
    }

    public function test_taxonomy_branch_does_nothing_when_queried_object_is_not_wp_term(): void {
        $GLOBALS['_mock_is_singular']    = false;
        $GLOBALS['_mock_is_tax']         = true;
        $GLOBALS['_mock_queried_object'] = (object) ['ID' => 1]; // WP_Post, not WP_Term
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        $this->taxonomy_generator->expects( $this->never() )->method( 'get_export_path' );

        $this->make_negotiator()->maybe_serve_markdown();
    }

    public function test_taxonomy_branch_does_nothing_when_md_file_missing(): void {
        $GLOBALS['_mock_is_singular']    = false;
        $GLOBALS['_mock_is_tax']         = true;
        $GLOBALS['_mock_queried_object'] = new \WP_Term( ['term_id' => 1, 'taxonomy' => 'category', 'slug' => 'news'] );
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        $this->taxonomy_generator->method( 'get_export_path' )->willReturn( '/nonexistent/news.md' );

        $this->make_negotiator()->maybe_serve_markdown();
        $this->addToAssertionCount( 1 );
    }

    public function test_taxonomy_branch_serves_file_when_exists(): void {
        $md_file = $this->tmp_dir . '/news.md';
        file_put_contents( $md_file, '# News' );

        $GLOBALS['_mock_is_singular']    = false;
        $GLOBALS['_mock_is_tax']         = true;
        $GLOBALS['_mock_queried_object'] = new \WP_Term( ['term_id' => 1, 'taxonomy' => 'category', 'slug' => 'news'] );
        $_SERVER['HTTP_ACCEPT']          = 'text/markdown';

        $this->taxonomy_generator->method( 'get_export_path' )->willReturn( $md_file );

        $neg = $this->make_negotiator();
        try {
            $neg->maybe_serve_markdown();
        } catch ( \RuntimeException $e ) {
            // readfile() throws in tests — expected
        }

        $this->assertContains( 'Content-Type: text/markdown; charset=utf-8', $GLOBALS['_mock_sent_headers'] );
        $this->assertSame( $md_file, $GLOBALS['_mock_readfile_path'] );
    }

    // -----------------------------------------------------------------------
    // output_link_tag — taxonomy branch
    // -----------------------------------------------------------------------

    public function test_taxonomy_link_tag_not_output_when_no_md_file(): void {
        $GLOBALS['_mock_is_singular']    = false;
        $GLOBALS['_mock_is_tax']         = true;
        $GLOBALS['_mock_queried_object'] = new \WP_Term( ['term_id' => 1, 'taxonomy' => 'category', 'slug' => 'news'] );

        $this->taxonomy_generator->method( 'get_export_path' )->willReturn( '/nonexistent/news.md' );

        ob_start();
        $this->make_negotiator()->output_link_tag();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    public function test_taxonomy_link_tag_output_when_md_file_exists(): void {
        $md_file = $this->tmp_dir . '/news.md';
        file_put_contents( $md_file, '# News' );

        $GLOBALS['_mock_is_singular']    = false;
        $GLOBALS['_mock_is_tax']         = true;
        $GLOBALS['_mock_queried_object'] = new \WP_Term( ['term_id' => 1, 'taxonomy' => 'category', 'slug' => 'news'] );

        $this->taxonomy_generator->method( 'get_export_path' )->willReturn( $md_file );

        ob_start();
        $this->make_negotiator()->output_link_tag();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'rel="alternate"', $output );
        $this->assertStringContainsString( 'type="text/markdown"', $output );
        $this->assertStringContainsString( 'output_format=md', $output );
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

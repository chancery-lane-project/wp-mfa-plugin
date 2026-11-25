<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Negotiate;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
use Tclp\WpMarkdownForAgents\Negotiate\EarlyServer;
use Tclp\WpMarkdownForAgents\Negotiate\MarkdownResponder;
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * @covers \Tclp\WpMarkdownForAgents\Negotiate\EarlyServer
 * @covers \Tclp\WpMarkdownForAgents\Negotiate\MarkdownResponder
 */
class EarlyServerTest extends TestCase {

    private string $base;

    /** @var AccessLogger&MockObject */
    private AccessLogger $logger;

    protected function setUp(): void {
        $this->base   = sys_get_temp_dir() . '/' . uniqid( 'wp-mfa-early-', true );
        $this->logger = $this->createMock( AccessLogger::class );
        mkdir( $this->base, 0755, true );

        $GLOBALS['_mock_sent_headers'] = [];
        $GLOBALS['_mock_ob_buffers']   = [];
        $GLOBALS['_mock_options'][ EarlyServer::GUARD_OPTION ] = [ 'hooked' => [], 'checked' => 1 ];
        unset( $GLOBALS['_mock_readfile_path'], $GLOBALS['wp_filter'] );
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->base );
        unset( $GLOBALS['_mock_options'][ EarlyServer::GUARD_OPTION ], $GLOBALS['wp_filter'], $GLOBALS['_mock_readfile_path'] );
    }

    // -----------------------------------------------------------------------
    // resolve()
    // -----------------------------------------------------------------------

    public function test_resolves_post_by_last_segment_when_permalink_matches(): void {
        $file = $this->export( 'post/a-post.md', 'https://example.com/2026/10/a-post/', 'post', 42 );

        $match = $this->server()->resolve( '/2026/10/a-post/' );

        $this->assertSame( realpath( $file ), $match['file'] );
        $this->assertSame( 42, $match['object_id'] );
        $this->assertSame( StatsRepository::OBJECT_POST, $match['object_type'] );
        $this->assertSame( 'https://example.com/2026/10/a-post/', $match['permalink'] );
    }

    public function test_matches_without_trailing_slash(): void {
        $this->export( 'post/a-post.md', 'https://example.com/2026/10/a-post/', 'post', 42 );

        $this->assertNotNull( $this->server()->resolve( '/2026/10/a-post' ) );
    }

    public function test_does_not_serve_a_file_whose_permalink_is_another_page(): void {
        // A date archive whose last segment is also a post's slug.
        $this->export( 'post/2006.md', 'https://example.com/2006/11/2006/', 'post', 7 );

        $this->assertNull( $this->server()->resolve( '/2006/' ) );
    }

    public function test_resolves_hierarchical_page_by_full_path(): void {
        $this->export( 'page/about/team.md', 'https://example.com/about/team/', 'page', 9 );

        $match = $this->server()->resolve( '/about/team/' );

        $this->assertSame( 9, $match['object_id'] );
    }

    public function test_resolves_taxonomy_archive_from_its_directory(): void {
        $this->export( 'taxonomy/clause-type/green-leases.md', 'https://example.com/clause-type/green-leases/', 'taxonomy_archive', 0, 15 );

        $match = $this->server()->resolve( '/clause-type/green-leases/' );

        $this->assertSame( 15, $match['object_id'] );
        $this->assertSame( StatsRepository::OBJECT_TERM, $match['object_type'] );
    }

    public function test_ignores_post_types_that_are_not_enabled(): void {
        $this->export( 'product/a-thing.md', 'https://example.com/product/a-thing/', 'product', 3 );

        $this->assertNull( $this->server()->resolve( '/product/a-thing/' ) );
    }

    public function test_strips_the_home_path_for_nested_candidates(): void {
        $this->export( 'page/about/team.md', 'https://example.com/blog/about/team/', 'page', 9 );

        $this->assertNotNull( $this->server( [], '/blog' )->resolve( '/blog/about/team/' ) );
    }

    public function test_path_traversal_stays_inside_the_export_directory(): void {
        // A file one level above the export directory whose permalink matches
        // the traversal path, so only the realpath confinement can refuse it.
        // post/../../outside.md resolves to that file.
        // realpath() needs every directory on the way to exist.
        mkdir( $this->base . '/post', 0755, true );
        $outside = dirname( $this->base ) . '/outside.md';
        file_put_contents( $outside, "---\nwpid: 1\npermalink: \"https://example.com/../../outside\"\n---\n" );

        try {
            $this->assertNull( $this->server()->resolve( '/../../outside' ) );
            $this->assertNull( $this->server()->resolve( '/%2e%2e/%2e%2e/outside' ) );
        } finally {
            unlink( $outside );
        }
    }

    public function test_ignores_a_file_without_front_matter(): void {
        @mkdir( $this->base . '/post', 0755, true );
        file_put_contents( $this->base . '/post/plain.md', "# Plain\n" );

        $this->assertNull( $this->server()->resolve( '/2026/10/plain/' ) );
    }

    public function test_root_path_is_never_resolved(): void {
        $this->assertNull( $this->server()->resolve( '/' ) );
    }

    // -----------------------------------------------------------------------
    // maybe_serve()
    // -----------------------------------------------------------------------

    public function test_serves_with_shared_headers_and_logs_the_access(): void {
        $file = $this->export( 'post/a-post.md', 'https://example.com/2026/10/a-post/', 'post', 42 );

        $this->logger->expects( $this->once() )
            ->method( 'log_access' )
            ->with( 42, 'curl', 'query-param', StatsRepository::OBJECT_POST );

        $this->serve( '/2026/10/a-post/', 'GET', '', 'curl/8.0', 'md' );

        $this->assertSame( realpath( $file ), $GLOBALS['_mock_readfile_path'] );
        $this->assertContains( 'Content-Type: text/markdown; charset=utf-8', $GLOBALS['_mock_sent_headers'] );
        $this->assertContains( 'X-Markdown-Source: ' . EarlyServer::SOURCE, $GLOBALS['_mock_sent_headers'] );
        $this->assertContains( 'Link: <https://example.com/2026/10/a-post/>; rel="canonical"', $GLOBALS['_mock_sent_headers'] );
        $this->assertContains( 'Cache-Control: private, no-store, max-age=0', $GLOBALS['_mock_sent_headers'] );
    }

    public function test_head_request_is_served_but_not_counted(): void {
        $this->export( 'post/a-post.md', 'https://example.com/2026/10/a-post/', 'post', 42 );

        $this->logger->expects( $this->never() )->method( 'log_access' );

        $this->serve( '/2026/10/a-post/', 'HEAD', 'text/markdown', 'curl/8.0', '' );

        $this->assertArrayHasKey( '_mock_readfile_path', $GLOBALS );
    }

    public function test_stands_aside_until_the_guard_has_been_checked(): void {
        $this->export( 'post/a-post.md', 'https://example.com/2026/10/a-post/', 'post', 42 );
        unset( $GLOBALS['_mock_options'][ EarlyServer::GUARD_OPTION ] );

        $this->assertFalse( $this->server()->maybe_serve( '/2026/10/a-post/', 'GET', '', '', 'md' ) );
        $this->assertSame( [], $GLOBALS['_mock_sent_headers'] );
    }

    public function test_stands_aside_while_a_guarded_filter_is_hooked(): void {
        $this->export( 'post/a-post.md', 'https://example.com/2026/10/a-post/', 'post', 42 );
        $GLOBALS['_mock_options'][ EarlyServer::GUARD_OPTION ] = [ 'hooked' => [ 'markdown_for_agents_serve_enabled' ], 'checked' => 1 ];

        $this->assertFalse( $this->server()->maybe_serve( '/2026/10/a-post/', 'GET', '', '', 'md' ) );
    }

    public function test_stands_aside_when_the_request_does_not_ask_for_markdown(): void {
        $this->export( 'post/a-post.md', 'https://example.com/2026/10/a-post/', 'post', 42 );

        $this->assertFalse( $this->server()->maybe_serve( '/2026/10/a-post/', 'GET', 'text/html', 'Mozilla/5.0', '' ) );
    }

    public function test_stands_aside_when_no_export_matches(): void {
        $this->assertFalse( $this->server()->maybe_serve( '/2026/10/missing/', 'GET', 'text/markdown', '', '' ) );
    }

    // -----------------------------------------------------------------------
    // Guard
    // -----------------------------------------------------------------------

    public function test_record_guard_lists_filters_that_gained_callbacks(): void {
        $this->hook( 'markdown_for_agents_cache_headers', 1 );
        $before = EarlyServer::callback_counts();

        $this->hook( 'markdown_for_agents_cache_headers', 2 );
        $this->hook( 'get_canonical_url', 1 );
        EarlyServer::record_guard( $before );

        $this->assertSame(
            [ 'markdown_for_agents_cache_headers', 'get_canonical_url' ],
            get_option( EarlyServer::GUARD_OPTION )['hooked']
        );
        $this->assertFalse( EarlyServer::guard_is_clear() );
    }

    public function test_must_use_plugin_callbacks_do_not_block(): void {
        // Already present at muplugins_loaded, so loaded on early requests too.
        $this->hook( 'markdown_for_agents_cache_headers', 1 );
        unset( $GLOBALS['_mock_options'][ EarlyServer::GUARD_OPTION ] );

        EarlyServer::record_guard( EarlyServer::callback_counts() );

        $this->assertTrue( EarlyServer::guard_is_clear() );
    }

    public function test_record_guard_leaves_an_unchanged_guard_alone(): void {
        $GLOBALS['_mock_options'][ EarlyServer::GUARD_OPTION ] = [ 'hooked' => [], 'checked' => 123 ];

        EarlyServer::record_guard( EarlyServer::callback_counts() );

        $this->assertSame( 123, get_option( EarlyServer::GUARD_OPTION )['checked'] );
    }

    // -----------------------------------------------------------------------
    // MarkdownResponder::access_method()
    // -----------------------------------------------------------------------

    public function test_access_method_precedence(): void {
        $detector = new AgentDetector( [ 'ua_force_enabled' => true, 'ua_agent_strings' => [ 'GPTBot' ] ] );

        $this->assertSame( 'query-param', MarkdownResponder::access_method( $detector, 'text/markdown', 'GPTBot/1.2', 'md' ) );
        $this->assertSame( 'query-param', MarkdownResponder::access_method( $detector, '', '', 'markdown' ) );
        $this->assertSame( 'accept-header', MarkdownResponder::access_method( $detector, 'text/markdown', 'GPTBot/1.2', '' ) );
        $this->assertSame( 'ua', MarkdownResponder::access_method( $detector, 'text/html', 'GPTBot/1.2', '' ) );
        $this->assertNull( MarkdownResponder::access_method( $detector, 'text/html', 'Mozilla/5.0', 'html' ) );
    }

    public function test_user_agent_trigger_respects_ua_force_enabled(): void {
        $detector = new AgentDetector( [ 'ua_force_enabled' => false, 'ua_agent_strings' => [ 'GPTBot' ] ] );

        $this->assertNull( MarkdownResponder::access_method( $detector, '', 'GPTBot/1.2', '' ) );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $options */
    private function server( array $options = [], string $home_path = '' ): EarlyServer {
        $merged   = array_merge( [ 'post_types' => [ 'post', 'page' ], 'ua_force_enabled' => false, 'ua_agent_strings' => [] ], $options );
        $detector = new AgentDetector( $merged );

        return new EarlyServer( $merged, $detector, new MarkdownResponder( $detector, $this->logger ), $this->base, $home_path );
    }

    private function serve( string $path, string $method, string $accept, string $ua, string $format ): void {
        try {
            $this->server()->maybe_serve( $path, $method, $accept, $ua, $format );
            $this->fail( 'Expected the file to be sent.' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'readfile_mock:', $e->getMessage() );
        }
    }

    private function export( string $relative, string $permalink, string $type, int $wpid, int $term_id = 0 ): string {
        $file = $this->base . '/' . $relative;
        @mkdir( dirname( $file ), 0755, true );
        $id_line = 'taxonomy_archive' === $type ? "term_id: {$term_id}" : "wpid: {$wpid}";
        file_put_contents( $file, "---\ntitle: Test\ntype: {$type}\n{$id_line}\npermalink: \"{$permalink}\"\n---\n\n# Test\n" );

        return $file;
    }

    private function hook( string $hook, int $count ): void {
        $GLOBALS['wp_filter'][ $hook ] = (object) [ 'callbacks' => [ 10 => array_fill( 0, $count, [ 'function' => 'strlen', 'accepted_args' => 1 ] ) ] ];
    }

    private function remove_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
            $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        }
        rmdir( $dir );
    }
}

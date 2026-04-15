<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Admin\Admin;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::handle_generate_batch_ajax
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::enqueue_scripts
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::handle_preview_post_ajax
 */
class AdminAjaxTest extends TestCase {

    /** @var Generator&MockObject */
    private Generator $generator;

    /** @var TaxonomyArchiveGenerator&MockObject */
    private TaxonomyArchiveGenerator $taxonomy_generator;

    private Admin $admin;

    protected function setUp(): void {
        $this->generator          = $this->createMock( Generator::class );
        $this->taxonomy_generator = $this->createMock( TaxonomyArchiveGenerator::class );
        $this->admin              = new Admin( Options::get_defaults(), $this->generator, $this->taxonomy_generator );

        // Reset globals before each test.
        unset(
            $GLOBALS['_mock_json_response'],
            $GLOBALS['_mock_enqueued_scripts'],
            $GLOBALS['_mock_localized_scripts']
        );
        $GLOBALS['_mock_verify_nonce']     = 1;
        $GLOBALS['_mock_current_user_can'] = true;
        $GLOBALS['_mock_post_objects']     = [];
        $GLOBALS['_mock_current_screen']   = null;
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
        unset( $GLOBALS['_mock_verify_nonce'] );
        $GLOBALS['_mock_current_user_can'] = true;
    }

    // -----------------------------------------------------------------------
    // handle_generate_batch_ajax()
    // -----------------------------------------------------------------------

    public function test_valid_request_returns_batch_result(): void {
        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
            'offset'    => '0',
            'limit'     => '10',
        ];

        $this->generator->method( 'generate_batch' )
            ->willReturn( [ 'total' => 5, 'processed' => 5, 'errors' => [] ] );

        $this->admin->handle_generate_batch_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertTrue( $response['success'] );
        $this->assertSame( 5, $response['data']['total'] );
        $this->assertSame( 5, $response['data']['processed'] );
        $this->assertSame( [], $response['data']['errors'] );
    }

    public function test_invalid_nonce_triggers_wp_die(): void {
        $GLOBALS['_mock_verify_nonce'] = false;
        $_POST['nonce']                = 'bad-nonce';

        $this->expectException( \RuntimeException::class );

        $this->admin->handle_generate_batch_ajax();
    }

    public function test_non_admin_user_receives_json_error_403(): void {
        $GLOBALS['_mock_current_user_can'] = false;
        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
        ];

        $this->admin->handle_generate_batch_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertSame( 403, $response['status'] );
    }

    public function test_missing_post_type_returns_400(): void {
        $_POST = [
            'nonce'  => 'test',
            'offset' => '0',
            'limit'  => '10',
        ];

        $this->admin->handle_generate_batch_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertSame( 400, $response['status'] );
    }

    public function test_post_type_is_sanitised(): void {
        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'bad type!@#',
            'offset'    => '0',
            'limit'     => '10',
        ];

        $captured_post_type = null;
        $this->generator->method( 'generate_batch' )
            ->willReturnCallback(
                function ( string $pt ) use ( &$captured_post_type ): array {
                    $captured_post_type = $pt;
                    return [ 'total' => 0, 'processed' => 0, 'errors' => [] ];
                }
            );

        $this->admin->handle_generate_batch_ajax();

        // sanitize_key strips spaces and special characters.
        $this->assertSame( 'badtype', $captured_post_type );
    }

    public function test_limit_is_capped_at_50(): void {
        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
            'offset'    => '0',
            'limit'     => '200',
        ];

        $captured_limit = null;
        $this->generator->method( 'generate_batch' )
            ->willReturnCallback(
                function ( string $pt, int $offset, int $limit ) use ( &$captured_limit ): array {
                    $captured_limit = $limit;
                    return [ 'total' => 0, 'processed' => 0, 'errors' => [] ];
                }
            );

        $this->admin->handle_generate_batch_ajax();

        $this->assertSame( 50, $captured_limit );
    }

    public function test_handle_generate_taxonomy_batch_ajax_returns_batch_result(): void {
        $this->taxonomy_generator->expects( $this->once() )
            ->method( 'generate_batch' )
            ->with( 2, 10 )
            ->willReturn( [ 'total' => 50, 'processed' => 10, 'errors' => [] ] );

        $_POST['offset'] = '2';
        $_POST['limit']  = '10';

        $this->admin->handle_generate_taxonomy_batch_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertTrue( $response['success'] );
        $this->assertSame( 50, $response['data']['total'] );
        $this->assertSame( 10, $response['data']['processed'] );
    }

    // -----------------------------------------------------------------------
    // handle_preview_post_ajax()
    // -----------------------------------------------------------------------

    public function test_preview_post_returns_markdown_for_valid_post(): void {
        $post = new \WP_Post( ['ID' => 42, 'post_type' => 'post', 'post_status' => 'publish'] );
        $GLOBALS['_mock_post_objects'][42] = $post;
        $_POST['post_id'] = '42';
        $_POST['nonce']   = 'test_nonce';

        $admin = $this->make_admin_with_preview_support( "---\ntitle: Hello\n---\n\nBody." );
        $admin->handle_preview_post_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertTrue( $response['success'] );
        $this->assertStringContainsString( '---', $response['data']['markdown'] );
    }

    public function test_preview_post_returns_error_for_ineligible_post(): void {
        $post = new \WP_Post( ['ID' => 99, 'post_type' => 'post', 'post_status' => 'draft'] );
        $GLOBALS['_mock_post_objects'][99] = $post;
        $_POST['post_id'] = '99';
        $_POST['nonce']   = 'test_nonce';

        $admin = $this->make_admin_with_preview_support( null );
        $admin->handle_preview_post_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertSame( 422, $response['status'] );
    }

    public function test_preview_post_returns_error_403_for_non_admin(): void {
        $GLOBALS['_mock_current_user_can'] = false;
        $_POST['post_id']                  = '42';

        $admin = $this->make_admin_with_preview_support( null );
        $admin->handle_preview_post_ajax();

        $response = $GLOBALS['_mock_json_response'];
        $this->assertFalse( $response['success'] );
        $this->assertSame( 403, $response['status'] );
    }

    /**
     * Helper: create an Admin whose Generator mock returns $markdown from get_post_markdown().
     *
     * @param string|null $markdown Return value for Generator::get_post_markdown().
     */
    private function make_admin_with_preview_support( ?string $markdown ): Admin {
        $generator = $this->createMock( Generator::class );
        $generator->method( 'get_post_markdown' )->willReturn( $markdown );
        return new Admin( Options::get_defaults(), $generator, $this->taxonomy_generator );
    }

    // -----------------------------------------------------------------------
    // enqueue_scripts()
    // -----------------------------------------------------------------------

    public function test_enqueue_scripts_enqueues_on_settings_page(): void {
        $GLOBALS['_mock_enqueued_scripts']  = [];
        $GLOBALS['_mock_localized_scripts'] = [];

        $this->admin->enqueue_scripts( 'settings_page_markdown-for-agents' );

        $this->assertArrayHasKey( 'mfa-bulk-generate', $GLOBALS['_mock_enqueued_scripts'] );
        $this->assertStringContainsString( 'bulk-generate.js', $GLOBALS['_mock_enqueued_scripts']['mfa-bulk-generate'] );

        $localised = $GLOBALS['_mock_localized_scripts']['mfa-bulk-generate'] ?? null;
        $this->assertNotNull( $localised );
        $this->assertSame( 'markdownForAgentsBulkGenerate', $localised['object'] );
        $this->assertArrayHasKey( 'nonce', $localised['data'] );
        $this->assertArrayHasKey( 'ajaxurl', $localised['data'] );
    }

    public function test_enqueue_scripts_skips_other_pages(): void {
        $GLOBALS['_mock_enqueued_scripts'] = [];

        $this->admin->enqueue_scripts( 'options-general.php' );

        $this->assertArrayNotHasKey( 'mfa-bulk-generate', $GLOBALS['_mock_enqueued_scripts'] );
    }

    public function test_enqueue_scripts_enqueues_preview_on_post_editor_for_enabled_post_type(): void {
        $screen            = new \WP_Screen();
        $screen->base      = 'post';
        $screen->post_type = 'post';

        $GLOBALS['_mock_current_screen']   = $screen;
        $GLOBALS['_mock_enqueued_scripts'] = [];

        $this->admin->enqueue_scripts( 'post.php' );

        $this->assertArrayHasKey( 'mfa-preview', $GLOBALS['_mock_enqueued_scripts'] );
        $this->assertStringContainsString( 'preview.js', $GLOBALS['_mock_enqueued_scripts']['mfa-preview'] );
    }

    public function test_enqueue_scripts_does_not_enqueue_preview_when_screen_is_null(): void {
        $GLOBALS['_mock_current_screen']   = null;
        $GLOBALS['_mock_enqueued_scripts'] = [];

        $this->admin->enqueue_scripts( 'post.php' );

        $this->assertArrayNotHasKey( 'mfa-preview', $GLOBALS['_mock_enqueued_scripts'] );
    }
}

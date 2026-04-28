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
        $GLOBALS['_mock_transients']       = [];
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

    public function test_final_batch_clears_post_type_from_pending_regen(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'post', 'page' ], 0 );

        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
            'offset'    => '0',
            'limit'     => '10',
        ];

        $this->generator->method( 'generate_batch' )
            ->willReturn( [ 'total' => 5, 'processed' => 5, 'errors' => [] ] );

        $this->admin->handle_generate_batch_ajax();

        $this->assertSame( [ 'page' ], get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_final_batch_for_last_pending_type_deletes_transient(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'post' ], 0 );

        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
            'offset'    => '0',
            'limit'     => '10',
        ];

        $this->generator->method( 'generate_batch' )
            ->willReturn( [ 'total' => 3, 'processed' => 3, 'errors' => [] ] );

        $this->admin->handle_generate_batch_ajax();

        $this->assertFalse( get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_non_final_batch_does_not_clear_pending_regen(): void {
        set_transient( 'markdown_for_agents_needs_regen', [ 'post' ], 0 );

        $_POST = [
            'nonce'     => 'test',
            'post_type' => 'post',
            'offset'    => '0',
            'limit'     => '10',
        ];

        // total exceeds offset+limit — more batches to come.
        $this->generator->method( 'generate_batch' )
            ->willReturn( [ 'total' => 50, 'processed' => 10, 'errors' => [] ] );

        $this->admin->handle_generate_batch_ajax();

        $this->assertSame( [ 'post' ], get_transient( 'markdown_for_agents_needs_regen' ) );
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
        $this->assertSame( 'mfaBulkGenerate', $localised['object'] );
        $this->assertArrayHasKey( 'nonce', $localised['data'] );
        $this->assertArrayHasKey( 'ajaxurl', $localised['data'] );
    }

    public function test_enqueue_scripts_skips_other_pages(): void {
        $GLOBALS['_mock_enqueued_scripts'] = [];

        $this->admin->enqueue_scripts( 'options-general.php' );

        $this->assertArrayNotHasKey( 'mfa-bulk-generate', $GLOBALS['_mock_enqueued_scripts'] );
    }
}

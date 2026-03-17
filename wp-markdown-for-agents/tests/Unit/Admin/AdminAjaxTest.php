<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Admin\Admin;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::handle_generate_batch_ajax
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::enqueue_scripts
 */
class AdminAjaxTest extends TestCase {

    /** @var Generator&MockObject */
    private Generator $generator;

    private Admin $admin;

    protected function setUp(): void {
        $this->generator = $this->createMock( Generator::class );
        $this->admin     = new Admin( Options::get_defaults(), $this->generator );

        // Reset globals before each test.
        unset(
            $GLOBALS['_mock_json_response'],
            $GLOBALS['_mock_enqueued_scripts'],
            $GLOBALS['_mock_localized_scripts']
        );
        $GLOBALS['_mock_verify_nonce']     = 1;
        $GLOBALS['_mock_current_user_can'] = true;
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
        unset( $GLOBALS['_mock_verify_nonce'] );
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

    // -----------------------------------------------------------------------
    // enqueue_scripts()
    // -----------------------------------------------------------------------

    public function test_enqueue_scripts_enqueues_on_settings_page(): void {
        $GLOBALS['_mock_enqueued_scripts']  = [];
        $GLOBALS['_mock_localized_scripts'] = [];

        $this->admin->enqueue_scripts( 'settings_page_wp-markdown-for-agents' );

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

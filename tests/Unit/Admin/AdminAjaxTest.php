<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Admin\Admin;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\StageFactory;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::handle_start_generation_job_ajax
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::handle_job_status_ajax
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::enqueue_scripts
 * @covers \Tclp\WpMarkdownForAgents\Admin\Admin::handle_preview_post_ajax
 */
class AdminAjaxTest extends TestCase {

    /** @var Generator&MockObject */
    private Generator $generator;

    /** @var TaxonomyArchiveGenerator&MockObject */
    private TaxonomyArchiveGenerator $taxonomy_generator;

    private Admin $admin;

    private FrozenClock $clock;

    private GenerationJob $job;

    /** @var StageFactory&MockObject */
    private StageFactory $factory;

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
        $GLOBALS['_mock_transients']       = [];
        $GLOBALS['_mock_options']          = [];
        reset_mock_scheduled_events();
        $_POST = [];
    }

    protected function tearDown(): void {
        $_POST = [];
        unset( $GLOBALS['_mock_verify_nonce'] );
        $GLOBALS['_mock_current_user_can'] = true;
    }

    /** Build an Admin wired to a real GenerationJob and a mocked StageFactory. */
    private function admin_with_job( ?StageFactory $factory = null ): Admin {
        $this->clock   = new FrozenClock( 1_000_000 );
        $this->job     = new GenerationJob( $this->clock );
        $this->factory = $factory ?? $this->createMock( StageFactory::class );

        return new Admin(
            Options::get_defaults(),
            $this->generator,
            $this->taxonomy_generator,
            null,
            null,
            $this->job,
            $this->factory
        );
    }

    // -----------------------------------------------------------------------
    // handle_start_generation_job_ajax()
    // -----------------------------------------------------------------------

    public function test_start_job_requires_capability(): void {
        $GLOBALS['_mock_current_user_can'] = false;
        $_POST                             = [ 'nonce' => 'test', 'scope' => 'all' ];

        $this->admin_with_job()->handle_start_generation_job_ajax();

        $this->assertFalse( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 403, $GLOBALS['_mock_json_response']['status'] );
    }

    public function test_start_job_rejects_an_unknown_scope(): void {
        $_POST   = [ 'nonce' => 'test', 'scope' => 'nonsense' ];
        $factory = $this->createMock( StageFactory::class );
        $factory->method( 'build_stage_list' )->willReturn( [] );

        $this->admin_with_job( $factory )->handle_start_generation_job_ajax();

        $this->assertFalse( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 400, $GLOBALS['_mock_json_response']['status'] );
    }

    public function test_start_job_writes_a_running_record_and_returns_it(): void {
        $_POST   = [ 'nonce' => 'test', 'scope' => 'taxonomy' ];
        $factory = $this->createMock( StageFactory::class );
        $factory->expects( $this->once() )
            ->method( 'build_stage_list' )
            ->with( 'taxonomy' )
            ->willReturn( [ [ 'type' => 'taxonomy', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );

        $this->admin_with_job( $factory )->handle_start_generation_job_ajax();

        $this->assertTrue( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 'running', $GLOBALS['_mock_json_response']['data']['status'] );
        $this->assertSame( 'running', $this->job->get()['status'] );
    }

    public function test_starting_a_second_job_returns_409_with_the_live_record(): void {
        $_POST   = [ 'nonce' => 'test', 'scope' => 'taxonomy' ];
        $factory = $this->createMock( StageFactory::class );
        $factory->method( 'build_stage_list' )
            ->willReturn( [ [ 'type' => 'taxonomy', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );

        $admin = $this->admin_with_job( $factory );
        $admin->handle_start_generation_job_ajax();
        $admin->handle_start_generation_job_ajax();

        $this->assertFalse( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 409, $GLOBALS['_mock_json_response']['status'] );
        $this->assertSame( 'running', $GLOBALS['_mock_json_response']['data']['job']['status'] );
    }

    public function test_start_job_returns_500_when_the_queue_is_not_wired_up(): void {
        $_POST = [ 'nonce' => 'test', 'scope' => 'all' ];

        $this->admin->handle_start_generation_job_ajax();

        $this->assertFalse( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 500, $GLOBALS['_mock_json_response']['status'] );
    }

    // -----------------------------------------------------------------------
    // handle_job_status_ajax()
    // -----------------------------------------------------------------------

    public function test_job_status_returns_the_record_without_the_lock_token(): void {
        $_POST   = [ 'nonce' => 'test', 'scope' => 'taxonomy' ];
        $factory = $this->createMock( StageFactory::class );
        $factory->method( 'build_stage_list' )
            ->willReturn( [ [ 'type' => 'taxonomy', 'total' => null, 'processed' => 0, 'skipped' => 0, 'error_count' => 0, 'state' => 'pending' ] ] );

        $admin = $this->admin_with_job( $factory );
        $admin->handle_start_generation_job_ajax();
        $admin->handle_job_status_ajax();

        $data = $GLOBALS['_mock_json_response']['data'];

        $this->assertTrue( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 'running', $data['status'] );
        $this->assertArrayNotHasKey( 'lock_token', $data );
    }

    public function test_job_status_requires_capability(): void {
        $GLOBALS['_mock_current_user_can'] = false;
        $_POST                             = [ 'nonce' => 'test' ];

        $this->admin_with_job()->handle_job_status_ajax();

        $this->assertSame( 403, $GLOBALS['_mock_json_response']['status'] );
    }

    public function test_job_status_returns_500_when_the_queue_is_not_wired_up(): void {
        $_POST = [ 'nonce' => 'test' ];

        $this->admin->handle_job_status_ajax();

        $this->assertFalse( $GLOBALS['_mock_json_response']['success'] );
        $this->assertSame( 500, $GLOBALS['_mock_json_response']['status'] );
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

    public function test_enqueue_localises_the_new_nonce_action(): void {
        $GLOBALS['_mock_nonces'] = [];

        $this->admin_with_job()->enqueue_scripts( 'settings_page_markdown-for-agents' );

        $localised = $GLOBALS['_mock_localized_scripts']['mfa-bulk-generate']['data'];

        $this->assertSame( 'test_nonce_mfa_generation_job', $localised['nonce'] );
        $this->assertArrayHasKey( 'ajaxurl', $localised );
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

    // -----------------------------------------------------------------------
    // add_action_links()
    // -----------------------------------------------------------------------

    public function test_action_links_prepends_settings_link(): void {
        $links = $this->admin->add_action_links( [ '<a href="#">Deactivate</a>' ] );

        $this->assertCount( 2, $links );
        // Settings comes first, before Deactivate.
        $this->assertStringContainsString( 'options-general.php?page=markdown-for-agents', $links[0] );
        $this->assertStringContainsString( 'Settings', $links[0] );
        $this->assertStringContainsString( 'Deactivate', $links[1] );
    }
}

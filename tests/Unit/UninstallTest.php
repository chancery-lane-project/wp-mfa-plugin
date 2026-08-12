<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * uninstall.php has no plugin autoloader available (by design — it is
 * required only conditionally, for the bundle-deletion path), so it cannot
 * be namespaced or covered by @covers. Requiring it directly is the only way
 * to exercise it; delete_files_on_uninstall is left unset in every test here
 * so the autoloader-requiring branch is never reached.
 */
class UninstallTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_mock_options']         = [];
        $GLOBALS['_mock_option_autoload'] = [];
        $GLOBALS['_mock_transients']      = [];
        $GLOBALS['wpdb']                  = new \wpdb();
        reset_mock_scheduled_events();

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }
    }

    public function test_uninstall_clears_job_queue_state_and_cron_events(): void {
        update_option( 'markdown_for_agents_options', [] );
        update_option( 'markdown_for_agents_job', [ 'status' => 'running' ] );
        update_option( 'markdown_for_agents_job_tick_lock', [ 'token' => 't' ] );
        wp_schedule_single_event( time() + 1, 'markdown_for_agents_process_batch' );
        wp_schedule_event( time() + 3600, 'hourly', 'markdown_for_agents_job_watchdog' );

        require dirname( __DIR__, 2 ) . '/uninstall.php';

        $this->assertFalse( get_option( 'markdown_for_agents_job' ) );
        $this->assertFalse( get_option( 'markdown_for_agents_job_tick_lock' ) );
        $this->assertFalse( wp_next_scheduled( 'markdown_for_agents_process_batch' ) );
        $this->assertFalse( wp_next_scheduled( 'markdown_for_agents_job_watchdog' ) );
    }
}

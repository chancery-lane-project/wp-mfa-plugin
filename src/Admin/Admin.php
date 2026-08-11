<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Admin;

use Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker;
use Tclp\WpMarkdownForAgents\Discovery\ArdCatalog;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\StageFactory;

/**
 * Admin coordinator — wires SettingsPage and MetaBox and handles POST actions.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Admin
 */
class Admin {

	private SettingsPage $settings_page;
	private MetaBox $meta_box;
	private NeedsRegenTracker $needs_regen;

	/**
	 * @since  1.0.0
	 * @param  array<string, mixed>  $options          Current plugin options.
	 * @param  Generator             $generator        Generator instance; also used to write manifests before a bundle rebuild.
	 * @param  TaxonomyArchiveGenerator $taxonomy_generator Taxonomy archive generator.
	 * @param  BundleGenerator|null  $bundle_generator Retained for constructor-signature compatibility; the bundle
	 *                                                 zip/manifest rebuild now runs as BundleStage inside the job queue.
	 * @param  ArdCatalog|null       $ard_catalog      Optional ARD catalog builder for the settings page discovery panel.
	 * @param  GenerationJob|null    $generation_job   Optional job repository; absent means the AJAX job endpoints 500.
	 * @param  StageFactory|null     $stage_factory    Optional stage-list builder; absent means the AJAX job endpoints 500.
	 */
	public function __construct(
		private readonly array $options,
		private readonly Generator $generator,
		private readonly TaxonomyArchiveGenerator $taxonomy_generator,
		private readonly ?BundleGenerator $bundle_generator = null,
		?ArdCatalog $ard_catalog = null,
		private readonly ?GenerationJob $generation_job = null,
		private readonly ?StageFactory $stage_factory = null,
	) {
		$this->settings_page = new SettingsPage( $options, $ard_catalog );
		$this->meta_box      = new MetaBox( $options, $generator );
		$this->needs_regen   = new NeedsRegenTracker();
	}

	/**
	 * Register the settings page.
	 *
	 * Hooked to `admin_menu`.
	 *
	 * @since  1.0.0
	 */
	public function add_settings_page(): void {
		$this->settings_page->add_page();
	}

	/**
	 * Register settings, sections, and fields.
	 *
	 * Hooked to `admin_init`.
	 *
	 * @since  1.0.0
	 */
	public function register_settings(): void {
		$this->settings_page->register();
	}

	/**
	 * Register per-post meta boxes.
	 *
	 * Hooked to `add_meta_boxes`.
	 *
	 * @since  1.0.0
	 */
	public function add_meta_boxes(): void {
		$this->meta_box->register();
	}

	/**
	 * Delegate save_post to MetaBox::save().
	 *
	 * Hooked to `save_post` at priority 5.
	 *
	 * @since  1.3.0
	 * @param  int $post_id The post being saved.
	 */
	public function handle_meta_box_save( int $post_id ): void {
		$this->meta_box->save( $post_id );
	}

	/**
	 * Handle the bulk-generate POST action.
	 *
	 * Hooked to `admin_post_markdown_for_agents_generate`.
	 *
	 * @since  1.0.0
	 */
	public function handle_generate_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'markdown-for-agents-and-statistics' ) );
		}

		check_admin_referer( 'markdown_for_agents_generate' );

		$post_type = sanitize_key( (string) ( $_POST['post_type'] ?? '' ) );

		$results = $this->generator->generate_post_type( $post_type );

		set_transient(
			'markdown_for_agents_admin_notice',
			array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: success count, 2: skipped count, 3: failed count */
					__( 'Generated %1$d files. Skipped: %2$d. Failed: %3$d.', 'markdown-for-agents-and-statistics' ),
					$results['success'],
					$results['skipped'],
					$results['failed']
				),
			),
			60
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=markdown-for-agents' ) );
		exit;
	}

	/**
	 * Handle the single-post regenerate POST action.
	 *
	 * Hooked to `admin_post_markdown_for_agents_regenerate_post`.
	 *
	 * @since  1.0.0
	 */
	public function handle_regenerate_post_action(): void {
		check_admin_referer( 'markdown_for_agents_regenerate' );

		$post_id = absint( wp_unslash( $_REQUEST['post_id'] ?? 0 ) );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'markdown-for-agents-and-statistics' ) );
		}

		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$ok = $this->generator->generate_post( $post );
			set_transient(
				'markdown_for_agents_admin_notice',
				array(
					'type'    => $ok ? 'success' : 'error',
					'message' => $ok
						? __( 'Markdown file regenerated.', 'markdown-for-agents-and-statistics' )
						: __( 'Failed to regenerate Markdown file.', 'markdown-for-agents-and-statistics' ),
				),
				60
			);
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Start a bulk generation job.
	 *
	 * Hooked to `wp_ajax_mfa_start_generation_job`. Returns immediately — all
	 * work happens in the WP-Cron tick chain, so closing the tab is harmless.
	 *
	 * @since  1.7.0
	 */
	public function handle_start_generation_job_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorised' ), 403 );
			return;
		}

		check_ajax_referer( 'mfa_generation_job', 'nonce' );

		if ( null === $this->generation_job || null === $this->stage_factory ) {
			wp_send_json_error( array( 'message' => 'Generation queue unavailable.' ), 500 );
			return;
		}

		$scope  = sanitize_text_field( wp_unslash( (string) ( $_POST['scope'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stages = $this->stage_factory->build_stage_list( $scope );

		if ( empty( $stages ) ) {
			wp_send_json_error( array( 'message' => 'Nothing to generate for that scope.' ), 400 );
			return;
		}

		$result = $this->generation_job->start( $stages );

		if ( ! $result['ok'] ) {
			// 409: a job is already live. The UI switches to that job's progress
			// rather than showing an error.
			wp_send_json_error(
				array(
					'message' => $result['message'],
					'job'     => $this->public_job_record(),
				),
				409
			);
			return;
		}

		wp_send_json_success( $this->public_job_record() );
	}

	/**
	 * Return the current job record for polling.
	 *
	 * Hooked to `wp_ajax_mfa_job_status`. Read-only.
	 *
	 * @since  1.7.0
	 */
	public function handle_job_status_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorised' ), 403 );
			return;
		}

		check_ajax_referer( 'mfa_generation_job', 'nonce' );

		if ( null === $this->generation_job ) {
			wp_send_json_error( array( 'message' => 'Generation queue unavailable.' ), 500 );
			return;
		}

		wp_send_json_success( $this->public_job_record() );
	}

	/**
	 * The job record minus its internal lock token.
	 *
	 * @since  1.7.0
	 * @return array<string, mixed>
	 */
	private function public_job_record(): array {
		$record = null !== $this->generation_job ? $this->generation_job->get() : array();

		unset( $record['lock_token'] );

		return $record;
	}

	/**
	 * Handle the AJAX preview-post request.
	 *
	 * Returns the generated Markdown as JSON without writing to disk.
	 *
	 * Hooked to `wp_ajax_mfa_preview_post`.
	 *
	 * @since  1.2.0
	 */
	public function handle_preview_post_ajax(): void {
		$post_id = absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below.

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorised' ), 403 );
			return;
		}

		check_ajax_referer( 'mfa_preview_post_' . $post_id, 'nonce' );

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			wp_send_json_error( array( 'message' => 'Post not found.' ), 404 );
			return;
		}

		$markdown = $this->generator->get_post_markdown( $post );

		if ( null === $markdown ) {
			wp_send_json_error( array( 'message' => 'Post is not eligible for export (check post type and status).' ), 422 );
			return;
		}

		wp_send_json_success( array( 'markdown' => $markdown ) );
	}

	/**
	 * Enqueue admin JS on the plugin settings page and post editor screens.
	 *
	 * Hooked to `admin_enqueue_scripts`.
	 *
	 * @since  1.1.0
	 * @param  string $hook The current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'settings_page_markdown-for-agents' === $hook ) {
			wp_enqueue_script(
				'mfa-bulk-generate',
				MARKDOWN_FOR_AGENTS_PLUGIN_URL . 'assets/js/bulk-generate.js',
				array(),
				MARKDOWN_FOR_AGENTS_VERSION,
				true
			);

			wp_localize_script(
				'mfa-bulk-generate',
				'markdownForAgentsBulkGenerate',
				array(
					'nonce'   => wp_create_nonce( 'mfa_generation_job' ),
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				)
			);
		}

		// Enqueue preview JS on post editor screens for enabled post types.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if (
			$screen instanceof \WP_Screen &&
			'post' === $screen->base &&
			in_array( $screen->post_type, (array) ( $this->options['post_types'] ?? array() ), true )
		) {
			wp_enqueue_script(
				'mfa-preview',
				MARKDOWN_FOR_AGENTS_PLUGIN_URL . 'assets/js/preview.js',
				array(),
				MARKDOWN_FOR_AGENTS_VERSION,
				true
			);
		}
	}

	/**
	 * Display transient-based admin notices.
	 *
	 * Hooked to `admin_notices`.
	 *
	 * @since  1.0.0
	 */
	public function display_admin_notices(): void {
		$this->display_action_notice();
		$this->display_regen_notice();
	}

	/**
	 * Render the transient-backed action notice (set by POST handlers).
	 *
	 * @since  1.0.0
	 */
	private function display_action_notice(): void {
		$notice = get_transient( 'markdown_for_agents_admin_notice' );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'markdown_for_agents_admin_notice' );

		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true )
			? $notice['type'] : 'info';

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			wp_kses_post( (string) ( $notice['message'] ?? '' ) )
		);
	}

	/**
	 * Render the "settings changed — regenerate" warning when the regen
	 * transient is set. Dismissible via core's `is-dismissible` JS, which
	 * only hides for the session; the notice returns on the next page load
	 * until a full bulk regeneration of every flagged post type completes.
	 *
	 * @since  1.2.0
	 */
	private function display_regen_notice(): void {
		$pending = get_transient( NeedsRegenTracker::TRANSIENT );

		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=markdown-for-agents#generate-markdown-files' );

		printf(
			'<div class="notice notice-warning is-dismissible" data-mfa-notice="needs_regen"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Markdown for Agents: settings changed since the last bulk run. Existing Markdown files may be out of date until you regenerate.', 'markdown-for-agents-and-statistics' ),
			esc_url( $settings_url ),
			esc_html__( 'Regenerate now', 'markdown-for-agents-and-statistics' )
		);
	}

	/**
	 * Add a "Settings" link to this plugin's action links on the Plugins screen
	 * (shown as "Settings | Deactivate"). Hooked to the plugin-specific
	 * `plugin_action_links_{basename}` filter.
	 *
	 * @since  1.6.0
	 * @param  string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=markdown-for-agents' ) ),
			esc_html__( 'Settings', 'markdown-for-agents-and-statistics' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
}

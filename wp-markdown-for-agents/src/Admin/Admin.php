<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Admin;

use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Admin coordinator — wires SettingsPage and MetaBox and handles POST actions.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Admin
 */
class Admin {

	private SettingsPage $settings_page;
	private MetaBox $meta_box;

	/**
	 * @since  1.0.0
	 * @param  array<string, mixed> $options   Current plugin options.
	 * @param  Generator            $generator Generator instance.
	 */
	public function __construct(
		private readonly array $options,
		private readonly Generator $generator
	) {
		$this->settings_page = new SettingsPage( $options, $generator );
		$this->meta_box      = new MetaBox( $options, $generator );
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
	 * Handle the bulk-generate POST action.
	 *
	 * Hooked to `admin_post_wp_mfa_generate`.
	 *
	 * @since  1.0.0
	 */
	public function handle_generate_action(): void {
		$post_type = sanitize_key( (string) ( $_POST['post_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-markdown-for-agents' ) );
		}

		check_admin_referer( 'wp_mfa_generate_' . $post_type );

		$results = $this->generator->generate_post_type( $post_type );

		set_transient(
			'wp_mfa_admin_notice',
			array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: success count, 2: failed count */
					__( 'Generated %1$d files. Failed: %2$d.', 'wp-markdown-for-agents' ),
					$results['success'],
					$results['failed']
				),
			),
			60
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=wp-markdown-for-agents' ) );
		exit;
	}

	/**
	 * Handle the single-post regenerate POST action.
	 *
	 * Hooked to `admin_post_wp_mfa_regenerate_post`.
	 *
	 * @since  1.0.0
	 */
	public function handle_regenerate_post_action(): void {
		$post_id = (int) ( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-markdown-for-agents' ) );
		}

		check_admin_referer( 'wp_mfa_regenerate_' . $post_id );

		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$ok = $this->generator->generate_post( $post );
			set_transient(
				'wp_mfa_admin_notice',
				array(
					'type'    => $ok ? 'success' : 'error',
					'message' => $ok
						? __( 'Markdown file regenerated.', 'wp-markdown-for-agents' )
						: __( 'Failed to regenerate Markdown file.', 'wp-markdown-for-agents' ),
				),
				60
			);
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Handle the AJAX batch-generate request.
	 *
	 * Processes one paginated slice (offset + limit) for a post type and
	 * returns JSON with total found, processed count, and any per-post errors.
	 *
	 * Hooked to `wp_ajax_mfa_generate_batch`.
	 *
	 * @since  1.1.0
	 */
	public function handle_generate_batch_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorised' ), 403 );
			return;
		}

		check_ajax_referer( 'mfa_generate_batch', 'nonce' );

		$post_type = sanitize_key( (string) ( $_POST['post_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$offset    = absint( $_POST['offset'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$limit     = min( absint( $_POST['limit'] ?? 10 ), 50 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $post_type ) {
			wp_send_json_error( array( 'message' => 'post_type is required.' ), 400 );
			return;
		}

		$result = $this->generator->generate_batch( $post_type, $offset, $limit );

		wp_send_json_success( $result );
		return;
	}

	/**
	 * Enqueue the bulk-generate JS on the plugin settings page.
	 *
	 * Hooked to `admin_enqueue_scripts`.
	 *
	 * @since  1.1.0
	 * @param  string $hook The current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'settings_page_wp-markdown-for-agents' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'mfa-bulk-generate',
			WP_MFA_PLUGIN_URL . 'assets/js/bulk-generate.js',
			array(),
			WP_MFA_VERSION,
			true
		);

		wp_localize_script(
			'mfa-bulk-generate',
			'mfaBulkGenerate',
			array(
				'nonce'   => wp_create_nonce( 'mfa_generate_batch' ),
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Display transient-based admin notices.
	 *
	 * Hooked to `admin_notices`.
	 *
	 * @since  1.0.0
	 */
	public function display_admin_notices(): void {
		$notice = get_transient( 'wp_mfa_admin_notice' );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'wp_mfa_admin_notice' );

		$type    = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true )
			? $notice['type'] : 'info';
		$message = wp_kses_post( (string) ( $notice['message'] ?? '' ) );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			$message
		);
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $data ): string {
		return $data;
	}
}

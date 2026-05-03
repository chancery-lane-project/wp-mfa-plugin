<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Admin;

use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Per-post meta box showing .md file status and a regenerate button.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Admin
 */
class MetaBox {

	/**
	 * @since  1.0.0
	 * @param  array<string, mixed> $options   Plugin options.
	 * @param  Generator            $generator Generator instance.
	 */
	public function __construct(
		private readonly array $options,
		private readonly Generator $generator
	) {}

	/**
	 * Register the meta box for all enabled post types.
	 *
	 * Hooked to `add_meta_boxes`.
	 *
	 * @since  1.0.0
	 */
	public function register(): void {
		foreach ( (array) ( $this->options['post_types'] ?? array() ) as $post_type ) {
			add_meta_box(
				'markdown_for_agents_status',
				__( 'Markdown for Agents', 'markdown-for-agents-and-statistics' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @since  1.0.0
	 * @param  \WP_Post $post The current post.
	 */
	public function render( \WP_Post $post ): void {
		$filepath  = $this->generator->get_export_path( $post );
		$exists    = file_exists( $filepath );
		$excluded  = (bool) get_post_meta( $post->ID, '_markdown_for_agents_excluded', true );

		$regen_url     = wp_nonce_url(
			admin_url( 'admin-post.php?action=markdown_for_agents_regenerate_post&post_id=' . $post->ID ),
			'markdown_for_agents_regenerate'
		);
		$preview_nonce = wp_create_nonce( 'mfa_preview_post_' . $post->ID );
		?>
		<?php wp_nonce_field( 'markdown_for_agents_exclude', 'markdown_for_agents_exclude_nonce' ); ?>
		<p>
			<label>
				<input type="checkbox" name="markdown_for_agents_excluded" value="1"
					   <?php checked( $excluded, true ); ?>>
				<?php esc_html_e( 'Exclude from Markdown output', 'markdown-for-agents-and-statistics' ); ?>
			</label>
		</p>
		<p>
			<?php if ( $exists ) : ?>
				<strong><?php esc_html_e( 'Markdown file:', 'markdown-for-agents-and-statistics' ); ?></strong>
				<?php esc_html_e( 'Generated', 'markdown-for-agents-and-statistics' ); ?><br>
				<small><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) filemtime( $filepath ) ) ); ?></small>
			<?php else : ?>
				<?php esc_html_e( 'No Markdown file generated yet.', 'markdown-for-agents-and-statistics' ); ?>
			<?php endif; ?>
		</p>
		<p>
			<?php if ( $excluded ) : ?>
				<span class="button button-secondary button-small" aria-disabled="true" tabindex="-1" style="opacity:0.5;cursor:default;">
					<?php esc_html_e( 'Regenerate', 'markdown-for-agents-and-statistics' ); ?>
				</span>
			<?php else : ?>
				<a href="<?php echo esc_url( $regen_url ); ?>" class="button button-secondary button-small">
					<?php esc_html_e( 'Regenerate', 'markdown-for-agents-and-statistics' ); ?>
				</a>
			<?php endif; ?>
			<button type="button" class="button button-secondary button-small mfa-preview-btn"
					data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
					data-nonce="<?php echo esc_attr( $preview_nonce ); ?>"
					data-ajaxurl="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
					<?php disabled( $excluded, true ); ?>>
				<?php esc_html_e( 'Preview Markdown', 'markdown-for-agents-and-statistics' ); ?>
			</button>
		</p>
		<details class="mfa-preview-output" hidden>
			<summary><?php esc_html_e( 'Markdown preview', 'markdown-for-agents-and-statistics' ); ?></summary>
			<pre class="mfa-preview-content" style="max-height:300px;overflow:auto;font-size:11px;white-space:pre-wrap;"></pre>
		</details>
		<?php
	}

	/**
	 * Save the exclusion checkbox value from the metabox form.
	 *
	 * Hooked to `save_post` at priority 5 — runs before Generator::on_save_post
	 * at priority 10 so the exclusion meta is readable on the same save.
	 *
	 * @since  1.3.0
	 * @param  int $post_id The post being saved.
	 */
	public function save( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['markdown_for_agents_exclude_nonce'] ?? '' ) ),
			'markdown_for_agents_exclude'
		) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$excluded = sanitize_key( wp_unslash( $_POST['markdown_for_agents_excluded'] ?? '' ) ) === '1';

		if ( $excluded ) {
			update_post_meta( $post_id, '_markdown_for_agents_excluded', '1' );
			$this->generator->delete_post( $post_id );
		} else {
			delete_post_meta( $post_id, '_markdown_for_agents_excluded' );
		}
	}
}

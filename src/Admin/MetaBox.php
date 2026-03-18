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
				'wp_mfa_status',
				__( 'Markdown for Agents', 'wp-markdown-for-agents' ),
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
		$filepath = $this->generator->get_export_path( $post );
		$exists   = file_exists( $filepath );
		?>
		<p>
			<?php if ( $exists ) : ?>
				<strong><?php esc_html_e( 'Markdown file:', 'wp-markdown-for-agents' ); ?></strong>
				<?php esc_html_e( 'Generated', 'wp-markdown-for-agents' ); ?><br>
				<small><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) filemtime( $filepath ) ) ); ?></small>
			<?php else : ?>
				<?php esc_html_e( 'No Markdown file generated yet.', 'wp-markdown-for-agents' ); ?>
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp_mfa_regenerate_post">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
			<?php wp_nonce_field( 'wp_mfa_regenerate_' . $post->ID ); ?>
			<button type="submit" class="button button-secondary button-small">
				<?php esc_html_e( 'Regenerate', 'wp-markdown-for-agents' ); ?>
			</button>
		</form>
		<?php
	}
}

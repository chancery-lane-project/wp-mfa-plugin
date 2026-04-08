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
				__( 'Markdown for Agents', 'markdown-for-agents' ),
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
		$regen_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=markdown_for_agents_regenerate_post&post_id=' . $post->ID ),
			'markdown_for_agents_regenerate_' . $post->ID
		);
		?>
		<p>
			<?php if ( $exists ) : ?>
				<strong><?php esc_html_e( 'Markdown file:', 'markdown-for-agents' ); ?></strong>
				<?php esc_html_e( 'Generated', 'markdown-for-agents' ); ?><br>
				<small><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) filemtime( $filepath ) ) ); ?></small>
			<?php else : ?>
				<?php esc_html_e( 'No Markdown file generated yet.', 'markdown-for-agents' ); ?>
			<?php endif; ?>
		</p>
		<a href="<?php echo esc_url( $regen_url ); ?>" class="button button-secondary button-small">
			<?php esc_html_e( 'Regenerate', 'markdown-for-agents' ); ?>
		</a>
		<?php
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Admin;

use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * Registers and renders the plugin settings page.
 *
 * Uses the WordPress Settings API exclusively — no custom form handling.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Admin
 */
class SettingsPage {

	/**
	 * Settings group name.
	 *
	 * @since  1.0.0
	 */
	private const SETTINGS_GROUP = 'wp_mfa_settings_group';

	/**
	 * Settings page slug.
	 *
	 * @since  1.0.0
	 */
	private const PAGE_SLUG = 'wp-markdown-for-agents';

	/**
	 * @since  1.0.0
	 * @param  array<string, mixed> $options   Current plugin options.
	 * @param  Generator            $generator Generator instance for bulk generate actions.
	 */
	public function __construct(
		private array $options,
		private readonly Generator $generator
	) {}

	/**
	 * Register the settings page under Settings menu.
	 *
	 * @since  1.0.0
	 */
	public function add_page(): void {
		add_options_page(
			__( 'WP Markdown for Agents', 'wp-markdown-for-agents' ),
			__( 'Markdown for Agents', 'wp-markdown-for-agents' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields via the Settings API.
	 *
	 * @since  1.0.0
	 */
	public function register(): void {
		register_setting(
			self::SETTINGS_GROUP,
			Options::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
			)
		);

		add_settings_section(
			'wp_mfa_general',
			__( 'General', 'wp-markdown-for-agents' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field( 'wp_mfa_enabled', __( 'Enable plugin', 'wp-markdown-for-agents' ), array( $this, 'field_enabled' ), self::PAGE_SLUG, 'wp_mfa_general' );
		add_settings_field( 'wp_mfa_post_types', __( 'Post types', 'wp-markdown-for-agents' ), array( $this, 'field_post_types' ), self::PAGE_SLUG, 'wp_mfa_general' );
		add_settings_field( 'wp_mfa_export_dir', __( 'Export directory', 'wp-markdown-for-agents' ), array( $this, 'field_export_dir' ), self::PAGE_SLUG, 'wp_mfa_general' );
		add_settings_field( 'wp_mfa_auto_generate', __( 'Auto-generate on save', 'wp-markdown-for-agents' ), array( $this, 'field_auto_generate' ), self::PAGE_SLUG, 'wp_mfa_general' );
		add_settings_field( 'wp_mfa_include_taxonomies', __( 'Include taxonomies', 'wp-markdown-for-agents' ), array( $this, 'field_include_taxonomies' ), self::PAGE_SLUG, 'wp_mfa_general' );

		// Per-post-type field configuration sections.
		$enabled_types = (array) ( $this->options['post_types'] ?? array() );
		foreach ( $enabled_types as $type_slug ) {
			$type_obj    = get_post_type_object( $type_slug );
			$type_label  = $type_obj ? $type_obj->label : $type_slug;
			$section_id  = 'wp_mfa_type_' . $type_slug;

			add_settings_section(
				$section_id,
				/* translators: %s: post type label */
				sprintf( __( 'Field Configuration: %s', 'wp-markdown-for-agents' ), $type_label ),
				'__return_false',
				self::PAGE_SLUG
			);

			add_settings_field(
				'wp_mfa_frontmatter_fields_' . $type_slug,
				__( 'Frontmatter fields', 'wp-markdown-for-agents' ),
				function () use ( $type_slug ): void {
					$this->field_type_frontmatter_fields( $type_slug );
				},
				self::PAGE_SLUG,
				$section_id
			);

			add_settings_field(
				'wp_mfa_content_fields_' . $type_slug,
				__( 'Content fields', 'wp-markdown-for-agents' ),
				function () use ( $type_slug ): void {
					$this->field_type_content_fields( $type_slug );
				},
				self::PAGE_SLUG,
				$section_id
			);
		}

		add_settings_section(
			'wp_mfa_ua_detection',
			__( 'Agent Detection', 'wp-markdown-for-agents' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field( 'wp_mfa_ua_force_enabled', __( 'Enable UA detection', 'wp-markdown-for-agents' ), array( $this, 'field_ua_force_enabled' ), self::PAGE_SLUG, 'wp_mfa_ua_detection' );
		add_settings_field( 'wp_mfa_ua_agent_strings', __( 'Agent user-agent strings', 'wp-markdown-for-agents' ), array( $this, 'field_ua_agent_strings' ), self::PAGE_SLUG, 'wp_mfa_ua_detection' );
	}

	/**
	 * Sanitise incoming options before saving.
	 *
	 * @since  1.0.0
	 * @param  mixed $input Raw form input.
	 * @return array<string, mixed>
	 */
	public function sanitize_options( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return Options::get_defaults();
		}

		$defaults = Options::get_defaults();
		$clean    = array();

		$clean['enabled']            = ! empty( $input['enabled'] );
		$clean['auto_generate']      = ! empty( $input['auto_generate'] );
		$clean['include_taxonomies'] = ! empty( $input['include_taxonomies'] );
		$clean['frontmatter_format'] = 'yaml';

		// Export dir: validate it's a simple directory name, no path traversal.
		$export_dir = sanitize_file_name( (string) ( $input['export_dir'] ?? $defaults['export_dir'] ) );
		// Strip any remaining double-dot sequences that could form traversal after sanitisation.
		$export_dir          = trim( str_replace( '..', '', $export_dir ), '-' );
		$clean['export_dir'] = $export_dir ?: $defaults['export_dir'];

		// Post types: validate each is a registered public post type.
		$public_types        = array_keys( get_post_types( array( 'public' => true ) ) );
		$submitted_types     = (array) ( $input['post_types'] ?? array() );
		$clean['post_types'] = array_values( array_intersect( $submitted_types, $public_types ) );

		// Per-post-type field configs: sanitise field names (allow dots for ACF groups).
		$type_configs = array();
		foreach ( $clean['post_types'] as $type_slug ) {
			$raw_frontmatter = (string) ( $input['post_type_configs'][ $type_slug ]['frontmatter_fields'] ?? '' );
			$raw_content     = (string) ( $input['post_type_configs'][ $type_slug ]['content_fields'] ?? '' );

			$frontmatter_fields = $this->sanitize_field_list( $raw_frontmatter );
			$content_fields     = $this->sanitize_field_list( $raw_content );

			if ( ! empty( $frontmatter_fields ) || ! empty( $content_fields ) ) {
				$type_configs[ $type_slug ] = array(
					'frontmatter_fields' => $frontmatter_fields,
					'content_fields'     => $content_fields,
				);
			}
		}
		$clean['post_type_configs'] = $type_configs;

		$clean['delete_files_on_uninstall'] = ! empty( $input['delete_files_on_uninstall'] );

		$clean['ua_force_enabled'] = ! empty( $input['ua_force_enabled'] );

		// UA agent strings: one per line, trim whitespace, drop empty lines.
		$ua_raw                    = (string) ( $input['ua_agent_strings'] ?? '' );
		$ua_lines                  = array_filter( array_map( 'trim', explode( "\n", $ua_raw ) ) );
		$clean['ua_agent_strings'] = array_values( $ua_lines );

		return $clean;
	}

	/**
	 * Render the settings page.
	 *
	 * @since  1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Markdown for Agents', 'wp-markdown-for-agents' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
			<?php $this->render_generate_buttons(); ?>
		</div>
		<?php
	}

	/**
	 * Render "Generate all" buttons for each enabled post type.
	 *
	 * @since  1.0.0
	 */
	private function render_generate_buttons(): void {
		$post_types = (array) ( $this->options['post_types'] ?? array() );
		if ( empty( $post_types ) ) {
			return;
		}
		?>
		<hr>
		<h2><?php esc_html_e( 'Generate Markdown files', 'wp-markdown-for-agents' ); ?></h2>
		<p><?php esc_html_e( 'Regenerate all Markdown files for a post type. This may take a while on large sites.', 'wp-markdown-for-agents' ); ?></p>
		<?php foreach ( $post_types as $post_type ) : ?>
			<p>
				<button type="button" class="button button-secondary" data-post-type="<?php echo esc_attr( $post_type ); ?>">
					<?php
					/* translators: %s: post type slug */
					printf( esc_html__( 'Generate all: %s', 'wp-markdown-for-agents' ), esc_html( $post_type ) );
					?>
				</button>
			</p>
		<?php endforeach; ?>
		<?php
	}

	// -----------------------------------------------------------------------
	// Field renderers
	// -----------------------------------------------------------------------

	/** @since 1.0.0 */
	public function field_enabled(): void {
		$checked = checked( ! empty( $this->options['enabled'] ), true, false );
		echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[enabled]" value="1" ' . $checked . '>';
	}

	/** @since 1.0.0 */
	public function field_post_types(): void {
		$enabled = (array) ( $this->options['post_types'] ?? array() );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$checked = checked( in_array( $type->name, $enabled, true ), true, false );
			echo '<label><input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[post_types][]" value="' . esc_attr( $type->name ) . '" ' . $checked . '> ' . esc_html( $type->label ) . '</label><br>';
		}
	}

	/** @since 1.0.0 */
	public function field_export_dir(): void {
		$val = esc_attr( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) );
		echo '<input type="text" name="' . esc_attr( Options::OPTION_KEY ) . '[export_dir]" value="' . $val . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Subdirectory within wp-content/uploads/ to store exported .md files.', 'wp-markdown-for-agents' ) . '</p>';
	}

	/** @since 1.0.0 */
	public function field_auto_generate(): void {
		$checked = checked( ! empty( $this->options['auto_generate'] ), true, false );
		echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[auto_generate]" value="1" ' . $checked . '>';
		echo '<p class="description">' . esc_html__( 'Automatically regenerate the .md file when a post is saved.', 'wp-markdown-for-agents' ) . '</p>';
	}

	/** @since 1.0.0 */
	public function field_include_taxonomies(): void {
		$checked = checked( ! empty( $this->options['include_taxonomies'] ), true, false );
		echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[include_taxonomies]" value="1" ' . $checked . '>';
	}

	/**
	 * Render the frontmatter fields textarea for a post type.
	 *
	 * @since  1.1.0
	 * @param  string $type_slug Post type slug.
	 */
	public function field_type_frontmatter_fields( string $type_slug ): void {
		$configs = (array) ( $this->options['post_type_configs'] ?? array() );
		$fields  = (array) ( $configs[ $type_slug ]['frontmatter_fields'] ?? array() );
		$val     = esc_textarea( implode( "\n", $fields ) );
		$name    = esc_attr( Options::OPTION_KEY ) . '[post_type_configs][' . esc_attr( $type_slug ) . '][frontmatter_fields]';

		echo '<textarea name="' . $name . '" rows="4" class="large-text">' . $val . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Meta or ACF fields to include in YAML frontmatter. One per line. Use dot notation for ACF groups (e.g. group_name.field_name).', 'wp-markdown-for-agents' ) . '</p>';
	}

	/**
	 * Render the content fields textarea for a post type.
	 *
	 * @since  1.1.0
	 * @param  string $type_slug Post type slug.
	 */
	public function field_type_content_fields( string $type_slug ): void {
		$configs = (array) ( $this->options['post_type_configs'] ?? array() );
		$fields  = (array) ( $configs[ $type_slug ]['content_fields'] ?? array() );
		$val     = esc_textarea( implode( "\n", $fields ) );
		$name    = esc_attr( Options::OPTION_KEY ) . '[post_type_configs][' . esc_attr( $type_slug ) . '][content_fields]';

		echo '<textarea name="' . $name . '" rows="4" class="large-text">' . $val . '</textarea>';
		echo '<p class="description">' . esc_html__( 'ACF or meta fields to use as the body content. When set, post_content is automatically excluded. One per line. Use dot notation for ACF groups.', 'wp-markdown-for-agents' ) . '</p>';
	}

	/**
	 * Sanitise a newline-separated list of field names.
	 *
	 * Allows alphanumeric characters, underscores, hyphens, and dots
	 * (dots are required for ACF group dot notation like group.field).
	 *
	 * @since  1.1.0
	 * @param  string $raw Raw textarea input.
	 * @return string[] Sanitised field names.
	 */
	private function sanitize_field_list( string $raw ): array {
		$lines = explode( "\n", $raw );
		$clean = array();

		foreach ( $lines as $line ) {
			$field = trim( $line );
			// Allow only safe characters: a-z, 0-9, underscore, hyphen, dot.
			$field = preg_replace( '/[^a-zA-Z0-9_.\-]/', '', $field );
			if ( '' !== $field ) {
				$clean[] = $field;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/** @since 1.1.0 */
	public function field_ua_force_enabled(): void {
		$checked = checked( ! empty( $this->options['ua_force_enabled'] ), true, false );
		echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[ua_force_enabled]" value="1" ' . $checked . '>';
		echo '<p class="description">' . esc_html__( 'Serve Markdown to known LLM agent crawlers based on User-Agent string.', 'wp-markdown-for-agents' ) . '</p>';
	}

	/** @since 1.1.0 */
	public function field_ua_agent_strings(): void {
		$val = esc_textarea( implode( "\n", (array) ( $this->options['ua_agent_strings'] ?? array() ) ) );
		echo '<textarea name="' . esc_attr( Options::OPTION_KEY ) . '[ua_agent_strings]" rows="8" class="large-text">' . $val . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One User-Agent substring per line. Matching is case-insensitive. Edit to add or remove agents.', 'wp-markdown-for-agents' ) . '</p>';
	}
}

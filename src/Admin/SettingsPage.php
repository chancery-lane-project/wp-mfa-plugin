<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Admin;

use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Discovery\ArdCatalog;
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
	private const SETTINGS_GROUP = 'markdown_for_agents_settings_group';

	/**
	 * Settings page slug.
	 *
	 * @since  1.0.0
	 */
	private const PAGE_SLUG = 'markdown-for-agents';

	/**
	 * Settings tabs: slug => label. Each tab renders its own set of sections so
	 * the page stays short (notably the per-post-type Field Configuration).
	 *
	 * @since  1.6.0
	 * @var    array<string, string>
	 */
	private const TABS = array(
		'general' => 'General',
		'fields'  => 'Field Configuration',
		'agents'  => 'Agent Detection',
	);

	/**
	 * The Settings API "page" identifier for a tab's sections/fields.
	 *
	 * @since  1.6.0
	 * @param  string $tab Tab slug.
	 * @return string
	 */
	private function tab_page( string $tab ): string {
		return self::PAGE_SLUG . '-' . $tab;
	}

	/**
	 * Resolve the active tab from the request, defaulting to the first tab.
	 *
	 * @since  1.6.0
	 * @return string
	 */
	private function active_tab(): string {
		// Read-only view switch; no state change, so no nonce required.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, self::TABS ) ? $tab : 'general';
	}

	/**
	 * @since  1.0.0
	 * @param  array<string, mixed> $options     Current plugin options.
	 * @param  ArdCatalog|null      $ard_catalog Optional ARD catalog builder for the discovery panel.
	 */
	public function __construct(
		private array $options,
		private readonly ?ArdCatalog $ard_catalog = null
	) {}

	/**
	 * Register the settings page under Settings menu.
	 *
	 * @since  1.0.0
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Markdown for Agents and Statistics', 'markdown-for-agents-and-statistics' ),
			__( 'Markdown for Agents', 'markdown-for-agents-and-statistics' ),
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

		// --- General tab -------------------------------------------------
		$general = $this->tab_page( 'general' );

		add_settings_section(
			'markdown_for_agents_general',
			__( 'General', 'markdown-for-agents-and-statistics' ),
			'__return_false',
			$general
		);

		add_settings_field( 'markdown_for_agents_post_types', __( 'Post types', 'markdown-for-agents-and-statistics' ), array( $this, 'field_post_types' ), $general, 'markdown_for_agents_general' );
		add_settings_field( 'markdown_for_agents_export_dir', __( 'Export directory', 'markdown-for-agents-and-statistics' ), array( $this, 'field_export_dir' ), $general, 'markdown_for_agents_general' );
		add_settings_field( 'markdown_for_agents_auto_generate', __( 'Auto-generate on save', 'markdown-for-agents-and-statistics' ), array( $this, 'field_auto_generate' ), $general, 'markdown_for_agents_general' );
		add_settings_field( 'markdown_for_agents_include_taxonomies', __( 'Include taxonomies', 'markdown-for-agents-and-statistics' ), array( $this, 'field_include_taxonomies' ), $general, 'markdown_for_agents_general' );
		add_settings_field( 'markdown_for_agents_include_hierarchy', __( 'Include hierarchy', 'markdown-for-agents-and-statistics' ), array( $this, 'field_include_hierarchy' ), $general, 'markdown_for_agents_general' );
		add_settings_field( 'markdown_for_agents_include_author', __( 'Include author', 'markdown-for-agents-and-statistics' ), array( $this, 'field_include_author' ), $general, 'markdown_for_agents_general' );
		add_settings_field( 'markdown_for_agents_relative_image_paths', __( 'Relative image paths', 'markdown-for-agents-and-statistics' ), array( $this, 'field_relative_image_paths' ), $general, 'markdown_for_agents_general' );
		add_settings_field( 'markdown_for_agents_include_taxonomy_topics', __( 'Topics section', 'markdown-for-agents-and-statistics' ), array( $this, 'field_include_taxonomy_topics' ), $general, 'markdown_for_agents_general' );

		add_settings_section(
			'markdown_for_agents_discovery',
			__( 'Agent discovery (OKF / ARD)', 'markdown-for-agents-and-statistics' ),
			array( $this, 'section_discovery_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field( 'markdown_for_agents_bundle_enabled', __( 'Build downloadable bundle (.zip + manifest)', 'markdown-for-agents-and-statistics' ), array( $this, 'field_bundle_enabled' ), self::PAGE_SLUG, 'markdown_for_agents_discovery' );

		// The Field Configuration tab is rendered manually (see render_fields_tab)
		// so each post type can collapse into its own accordion.

		// --- Agent Detection tab ----------------------------------------
		$agents = $this->tab_page( 'agents' );

		add_settings_section(
			'markdown_for_agents_ua_detection',
			__( 'Agent Detection', 'markdown-for-agents-and-statistics' ),
			'__return_false',
			$agents
		);

		add_settings_field( 'markdown_for_agents_ua_force_enabled', __( 'Enable UA detection', 'markdown-for-agents-and-statistics' ), array( $this, 'field_ua_force_enabled' ), $agents, 'markdown_for_agents_ua_detection' );
		add_settings_field( 'markdown_for_agents_ua_agent_strings', __( 'Agent user-agent strings', 'markdown-for-agents-and-statistics' ), array( $this, 'field_ua_agent_strings' ), $agents, 'markdown_for_agents_ua_detection' );
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

		// Start from the currently stored options and overlay only the fields on
		// the submitted tab. With tabbed forms, other tabs' fields aren't in this
		// POST at all, so a full rebuild would silently wipe them.
		$clean  = Options::get();
		$active = isset( $input['_active_tab'] ) ? sanitize_key( $input['_active_tab'] ) : '';

		if ( array_key_exists( $active, self::TABS ) ) {
			switch ( $active ) {
				case 'general':
					$this->sanitize_general_fields( $input, $clean );
					break;
				case 'fields':
					$this->sanitize_field_configs( $input, $clean );
					break;
				case 'agents':
					$this->sanitize_agent_fields( $input, $clean );
					break;
			}
		} else {
			// No tab context (programmatic save or a legacy full-form submit):
			// process every field. Order matters — general resolves post_types,
			// which field configs depend on.
			$this->sanitize_general_fields( $input, $clean );
			$this->sanitize_field_configs( $input, $clean );
			$this->sanitize_agent_fields( $input, $clean );
		}

		$this->maybe_flag_regeneration( $this->options, $clean );

		return $clean;
	}

	/**
	 * Sanitise the General tab fields, overlaying them onto $clean.
	 *
	 * @since  1.6.0
	 * @param  array<string, mixed> $input Raw form input.
	 * @param  array<string, mixed> $clean Options being built (mutated by reference).
	 */
	private function sanitize_general_fields( array $input, array &$clean ): void {
		$defaults = Options::get_defaults();

		$clean['auto_generate']           = ! empty( $input['auto_generate'] );
		$clean['include_taxonomies']      = ! empty( $input['include_taxonomies'] );
		$clean['include_hierarchy']       = ! empty( $input['include_hierarchy'] );
		$clean['include_author']          = ! empty( $input['include_author'] );
		$clean['relative_image_paths']    = ! empty( $input['relative_image_paths'] );
		$clean['include_taxonomy_topics'] = ! empty( $input['include_taxonomy_topics'] );
		$clean['bundle_enabled']          = ! empty( $input['bundle_enabled'] );

		// Export dir: validate it's a simple directory name, no path traversal.
		$export_dir          = sanitize_file_name( (string) ( $input['export_dir'] ?? $defaults['export_dir'] ) );
		$export_dir          = trim( str_replace( '..', '', $export_dir ), '-' );
		$clean['export_dir'] = $export_dir ? $export_dir : $defaults['export_dir'];

		// Post types: validate each is a registered public post type.
		$public_types        = array_keys( get_post_types( array( 'public' => true ) ) );
		$submitted_types     = (array) ( $input['post_types'] ?? array() );
		$clean['post_types'] = array_values( array_intersect( $submitted_types, $public_types ) );

		// Drop field configs for post types that are no longer enabled; keep the
		// rest untouched (they're edited on the Field Configuration tab).
		$clean['post_type_configs'] = array_intersect_key(
			(array) ( $clean['post_type_configs'] ?? array() ),
			array_flip( $clean['post_types'] )
		);
	}

	/**
	 * Sanitise the Field Configuration tab (per-post-type frontmatter/content
	 * field lists), rebuilding the configs for every enabled post type.
	 *
	 * @since  1.6.0
	 * @param  array<string, mixed> $input Raw form input.
	 * @param  array<string, mixed> $clean Options being built (mutated by reference).
	 */
	private function sanitize_field_configs( array $input, array &$clean ): void {
		$type_configs = array();

		foreach ( (array) ( $clean['post_types'] ?? array() ) as $type_slug ) {
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
	}

	/**
	 * Sanitise the Agent Detection tab fields, overlaying them onto $clean.
	 *
	 * @since  1.6.0
	 * @param  array<string, mixed> $input Raw form input.
	 * @param  array<string, mixed> $clean Options being built (mutated by reference).
	 */
	private function sanitize_agent_fields( array $input, array &$clean ): void {
		$clean['ua_force_enabled'] = ! empty( $input['ua_force_enabled'] );

		// UA agent strings: one per line, trim whitespace, drop empty lines.
		// Guard against the WordPress double-sanitize quirk where the callback receives
		// its own array output on a second pass, which would cast to the string 'Array'.
		$ua_input = $input['ua_agent_strings'] ?? '';
		$ua_raw   = is_array( $ua_input ) ? implode( "\n", $ua_input ) : (string) $ua_input;
		$ua_lines                  = array_filter( array_map( 'trim', explode( "\n", $ua_raw ) ) );
		$clean['ua_agent_strings'] = array_values( $ua_lines );
	}

	/**
	 * Set the regeneration-needed transient when output-affecting settings change.
	 *
	 * Compares old vs new options on the keys that influence generated file
	 * contents or location. When any differ, stores the snapshot of currently
	 * enabled post types as the pending set; the AJAX bulk-generate handler
	 * removes types from this set as they complete and clears the transient
	 * once the set is empty.
	 *
	 * @since  1.2.0
	 * @param  array<string, mixed> $old Existing saved options.
	 * @param  array<string, mixed> $incoming Sanitised incoming options.
	 */
	private function maybe_flag_regeneration( array $old, array $incoming ): void {
		$old_pt = (array) ( $old['post_types'] ?? array() );
		$new_pt = (array) ( $incoming['post_types'] ?? array() );
		sort( $old_pt );
		sort( $new_pt );

		$changed =
			( $old['export_dir'] ?? null ) !== ( $incoming['export_dir'] ?? null )
			|| ! empty( $old['include_taxonomies'] ) !== ! empty( $incoming['include_taxonomies'] )
			|| ! empty( $old['bundle_enabled'] ) !== ! empty( $incoming['bundle_enabled'] )
			|| $old_pt !== $new_pt
			|| wp_json_encode( $old['post_type_configs'] ?? array() ) !== wp_json_encode( $incoming['post_type_configs'] ?? array() );

		if ( ! $changed ) {
			return;
		}

		$pending = array_values( (array) ( $incoming['post_types'] ?? array() ) );

		if ( empty( $pending ) ) {
			delete_transient( 'markdown_for_agents_needs_regen' );
			return;
		}

		set_transient( 'markdown_for_agents_needs_regen', $pending, 0 );
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
		$active = $this->active_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Markdown for Agents and Statistics', 'markdown-for-agents-and-statistics' ); ?></h1>

			<p class="description" style="max-width:60em;">
				<?php esc_html_e( 'Converts your published content to Markdown and serves it to AI agents that request it via HTTP content negotiation (Accept: text/markdown), cutting the tokens and server load needed to consume your pages. Use the tabs below to choose what is exported and how.', 'markdown-for-agents-and-statistics' ); ?>
			</p>

			<nav class="nav-tab-wrapper">
				<?php foreach ( self::TABS as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'options-general.php' ) ) ); ?>"
						class="nav-tab <?php echo $active === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				// Tell sanitize_options() which tab is being saved so it only
				// re-evaluates that tab's fields and preserves the others.
				printf( '<input type="hidden" name="%s[_active_tab]" value="%s">', esc_attr( Options::OPTION_KEY ), esc_attr( $active ) );

				if ( 'fields' === $active ) {
					$this->render_fields_tab();
				} else {
					do_settings_sections( $this->tab_page( $active ) );
				}

				submit_button();
				?>
			</form>
			<?php $this->render_generate_buttons(); ?>
		</div>
		<?php
	}

	/**
	 * Render the Field Configuration tab: a section per enabled post type, all
	 * expanded (moving them onto their own tab is what keeps the page manageable).
	 *
	 * @since  1.6.0
	 */
	private function render_fields_tab(): void {
		$post_types = (array) ( $this->options['post_types'] ?? array() );

		if ( empty( $post_types ) ) {
			echo '<p>' . esc_html__( 'Enable one or more post types on the General tab to configure their fields.', 'markdown-for-agents-and-statistics' ) . '</p>';
			return;
		}

		foreach ( $post_types as $type_slug ) {
			$type_obj   = get_post_type_object( $type_slug );
			$type_label = $type_obj ? $type_obj->label : $type_slug;
			?>
			<h2 class="title">
				<?php
				/* translators: %s: post type label */
				printf( esc_html__( 'Field Configuration: %s', 'markdown-for-agents-and-statistics' ), esc_html( $type_label ) );
				?>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Frontmatter fields', 'markdown-for-agents-and-statistics' ); ?></th>
					<td><?php $this->field_type_frontmatter_fields( $type_slug ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Content fields', 'markdown-for-agents-and-statistics' ); ?></th>
					<td><?php $this->field_type_content_fields( $type_slug ); ?></td>
				</tr>
			</table>
			<?php
		}
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
		<h2 id="generate-markdown-files"><?php esc_html_e( 'Generate Markdown files', 'markdown-for-agents-and-statistics' ); ?></h2>
		<p><?php esc_html_e( 'Regenerate everything — every enabled post type, then all taxonomy archives. This may take a while on large sites.', 'markdown-for-agents-and-statistics' ); ?></p>
		<p>
			<button type="button" class="button button-primary" data-generate-all="1">
				<?php esc_html_e( 'Generate everything', 'markdown-for-agents-and-statistics' ); ?>
			</button>
		</p>
		<p><?php esc_html_e( 'Or regenerate a single post type:', 'markdown-for-agents-and-statistics' ); ?></p>
		<?php foreach ( $post_types as $post_type ) : ?>
			<p>
				<button type="button" class="button button-secondary" data-post-type="<?php echo esc_attr( $post_type ); ?>">
					<?php
					/* translators: %s: post type slug */
					printf( esc_html__( 'Generate all: %s', 'markdown-for-agents-and-statistics' ), esc_html( $post_type ) );
					?>
				</button>
			</p>
		<?php endforeach; ?>
		<hr>
		<h2><?php esc_html_e( 'Taxonomy Archives', 'markdown-for-agents-and-statistics' ); ?></h2>
		<p><?php esc_html_e( 'Generate Markdown archive files for all public taxonomy terms.', 'markdown-for-agents-and-statistics' ); ?></p>
		<p>
			<button type="button" class="button button-secondary" data-action="mfa_generate_taxonomy_batch">
				<?php esc_html_e( 'Generate All Taxonomy Archives', 'markdown-for-agents-and-statistics' ); ?>
			</button>
		</p>
		<?php
	}

	// -----------------------------------------------------------------------
	// Field renderers
	// -----------------------------------------------------------------------

	/** @since 1.0.0 */
	public function field_post_types(): void {
		$enabled = (array) ( $this->options['post_types'] ?? array() );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			echo '<label><input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[post_types][]" value="' . esc_attr( $type->name ) . '" ' . checked( in_array( $type->name, $enabled, true ), true, false ) . '> ' . esc_html( $type->label ) . '</label><br>';
		}
	}

	/** @since 1.0.0 */
	public function field_export_dir(): void {
		echo '<input type="text" name="' . esc_attr( Options::OPTION_KEY ) . '[export_dir]" value="' . esc_attr( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) ) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Subdirectory within wp-content/uploads/ to store exported .md files.', 'markdown-for-agents-and-statistics' ) . '</p>';
	}

	/** @since 1.0.0 */
	public function field_auto_generate(): void {
		echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[auto_generate]" value="1" ' . checked( ! empty( $this->options['auto_generate'] ), true, false ) . '>';
		echo '<p class="description">' . esc_html__( 'Automatically regenerate the .md file when a post is saved.', 'markdown-for-agents-and-statistics' ) . '</p>';
	}

	/** @since 1.0.0 */
	public function field_include_taxonomies(): void {
		echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[include_taxonomies]" value="1" ' . checked( ! empty( $this->options['include_taxonomies'] ), true, false ) . '>';
	}

	/**
	 * Render the include-hierarchy checkbox field.
	 *
	 * @since  1.2.0
	 */
	public function field_include_hierarchy(): void {
		$checked = ! empty( $this->options['include_hierarchy'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[include_hierarchy]"
					value="1" <?php checked( $checked, true ); ?>>
			<?php esc_html_e( 'Add parent, ancestors, and children IDs to frontmatter for hierarchical post types (pages, etc.).', 'markdown-for-agents-and-statistics' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the include-author checkbox field.
	 *
	 * @since  1.2.0
	 */
	public function field_include_author(): void {
		$checked = ! empty( $this->options['include_author'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[include_author]"
					value="1" <?php checked( $checked, true ); ?>>
			<?php esc_html_e( "Add the post author's display name to frontmatter.", 'markdown-for-agents-and-statistics' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the relative-image-paths checkbox field.
	 *
	 * @since  1.2.0
	 */
	public function field_relative_image_paths(): void {
		$checked = ! empty( $this->options['relative_image_paths'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[relative_image_paths]"
					value="1" <?php checked( $checked, true ); ?>>
			<?php esc_html_e( 'Use root-relative paths for featured images (e.g. /wp-content/uploads/…). Helps exports survive domain changes.', 'markdown-for-agents-and-statistics' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the topics-section checkbox field.
	 *
	 * @since  1.2.0
	 */
	public function field_include_taxonomy_topics(): void {
		$checked = ! empty( $this->options['include_taxonomy_topics'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[include_taxonomy_topics]"
					value="1" <?php checked( $checked, true ); ?>>
			<?php esc_html_e( 'Append a "## Topics" section with linked taxonomy terms to the Markdown body.', 'markdown-for-agents-and-statistics' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the introductory text for the "Agent discovery" section.
	 *
	 * Describes the single bundle toggle: exported Markdown is always
	 * OKF-compliant; enabling the toggle additionally packages the export
	 * tree into a downloadable archive and publishes an ARD discovery
	 * catalog for it.
	 *
	 * @since  1.6.0
	 */
	public function section_discovery_intro(): void {
		echo '<p>' . esc_html__( 'Exported Markdown files are always OKF-compliant (flat tags, timestamp, and internal links pointing at the Markdown file versions). Enable the toggle below for a downloadable bundle of the whole export tree, complete with a manifest and an ARD discovery catalog.', 'markdown-for-agents-and-statistics' ) . '</p>';
	}

	/**
	 * Render the bundle-build checkbox field.
	 *
	 * When checked, also renders the ARD catalog JSON panel and deployment
	 * instructions directly below (previously a separate `ard_enabled`
	 * checkbox) — the bundle, its manifest, and the catalog are now one unit.
	 *
	 * @since  1.6.0
	 */
	public function field_bundle_enabled(): void {
		$checked = ! empty( $this->options['bundle_enabled'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options::OPTION_KEY ); ?>[bundle_enabled]"
					value="1" <?php checked( $checked, true ); ?>>
			<?php esc_html_e( 'Build the export tree into a downloadable .zip bundle with a manifest.json and relative internal links, and publish an ARD discovery catalog for it below.', 'markdown-for-agents-and-statistics' ); ?>
		</label>
		<?php
		if ( $checked ) {
			$this->render_ard_panel();
		}
	}

	/**
	 * Render the ARD catalog JSON panel and deployment instructions.
	 *
	 * @since  1.6.0
	 */
	private function render_ard_panel(): void {
		if ( null === $this->ard_catalog ) {
			?>
			<p class="description"><?php esc_html_e( 'The ARD catalog could not be built. Regenerate the bundle and reload this page.', 'markdown-for-agents-and-statistics' ); ?></p>
			<?php
			return;
		}
		?>
		<p>
			<textarea readonly rows="18" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $this->ard_catalog->to_json() ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'To publish this catalog, create /.well-known/ai-catalog.json at your web root containing the JSON above — either paste it in directly or symlink to a file you manage yourself.', 'markdown-for-agents-and-statistics' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'The content above is deliberately stable across bundle rebuilds, so you only need to copy it once.', 'markdown-for-agents-and-statistics' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'The plugin never serves this path itself: no routes or rewrite rules are registered for /.well-known/.', 'markdown-for-agents-and-statistics' ); ?>
		</p>
		<?php
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

		echo '<textarea name="' . esc_attr( Options::OPTION_KEY ) . '[post_type_configs][' . esc_attr( $type_slug ) . '][frontmatter_fields]" rows="4" class="large-text">' . esc_textarea( implode( "\n", $fields ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Meta or ACF fields to include in YAML frontmatter. One per line. Use dot notation for ACF groups (e.g. group_name.field_name).', 'markdown-for-agents-and-statistics' ) . '</p>';
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

		echo '<textarea name="' . esc_attr( Options::OPTION_KEY ) . '[post_type_configs][' . esc_attr( $type_slug ) . '][content_fields]" rows="4" class="large-text">' . esc_textarea( implode( "\n", $fields ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'ACF or meta fields to use as the body content. When set, post_content is automatically excluded. One per line. Use dot notation for ACF groups.', 'markdown-for-agents-and-statistics' ) . '</p>';
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
		echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[ua_force_enabled]" value="1" ' . checked( ! empty( $this->options['ua_force_enabled'] ), true, false ) . '>';
		echo '<p class="description">' . esc_html__( 'Serve Markdown to known LLM agent crawlers based on User-Agent string.', 'markdown-for-agents-and-statistics' ) . '</p>';
	}

	/** @since 1.1.0 */
	public function field_ua_agent_strings(): void {
		echo '<textarea name="' . esc_attr( Options::OPTION_KEY ) . '[ua_agent_strings]" rows="8" class="large-text">' . esc_textarea( implode( "\n", (array) ( $this->options['ua_agent_strings'] ?? array() ) ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One User-Agent substring per line. Matching is case-insensitive. Edit to add or remove agents.', 'markdown-for-agents-and-statistics' ) . '</p>';
	}
}

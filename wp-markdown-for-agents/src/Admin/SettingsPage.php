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
            [ $this, 'render_page' ]
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
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_options' ],
            ]
        );

        add_settings_section(
            'wp_mfa_general',
            __( 'General', 'wp-markdown-for-agents' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field( 'wp_mfa_enabled', __( 'Enable plugin', 'wp-markdown-for-agents' ), [ $this, 'field_enabled' ], self::PAGE_SLUG, 'wp_mfa_general' );
        add_settings_field( 'wp_mfa_post_types', __( 'Post types', 'wp-markdown-for-agents' ), [ $this, 'field_post_types' ], self::PAGE_SLUG, 'wp_mfa_general' );
        add_settings_field( 'wp_mfa_export_dir', __( 'Export directory', 'wp-markdown-for-agents' ), [ $this, 'field_export_dir' ], self::PAGE_SLUG, 'wp_mfa_general' );
        add_settings_field( 'wp_mfa_auto_generate', __( 'Auto-generate on save', 'wp-markdown-for-agents' ), [ $this, 'field_auto_generate' ], self::PAGE_SLUG, 'wp_mfa_general' );
        add_settings_field( 'wp_mfa_include_taxonomies', __( 'Include taxonomies', 'wp-markdown-for-agents' ), [ $this, 'field_include_taxonomies' ], self::PAGE_SLUG, 'wp_mfa_general' );
        add_settings_field( 'wp_mfa_include_meta', __( 'Include post meta', 'wp-markdown-for-agents' ), [ $this, 'field_include_meta' ], self::PAGE_SLUG, 'wp_mfa_general' );

        add_settings_section(
            'wp_mfa_ua_detection',
            __( 'Agent Detection', 'wp-markdown-for-agents' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field( 'wp_mfa_ua_force_enabled', __( 'Enable UA detection', 'wp-markdown-for-agents' ), [ $this, 'field_ua_force_enabled' ], self::PAGE_SLUG, 'wp_mfa_ua_detection' );
        add_settings_field( 'wp_mfa_ua_agent_strings', __( 'Agent user-agent strings', 'wp-markdown-for-agents' ), [ $this, 'field_ua_agent_strings' ], self::PAGE_SLUG, 'wp_mfa_ua_detection' );
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
        $clean    = [];

        $clean['enabled']            = ! empty( $input['enabled'] );
        $clean['auto_generate']      = ! empty( $input['auto_generate'] );
        $clean['include_taxonomies'] = ! empty( $input['include_taxonomies'] );
        $clean['include_meta']       = ! empty( $input['include_meta'] );
        $clean['frontmatter_format'] = 'yaml';

        // Export dir: validate it's a simple directory name, no path traversal.
        $export_dir = sanitize_file_name( (string) ( $input['export_dir'] ?? $defaults['export_dir'] ) );
        // Strip any remaining double-dot sequences that could form traversal after sanitisation.
        $export_dir = trim( str_replace( '..', '', $export_dir ), '-' );
        $clean['export_dir'] = $export_dir ?: $defaults['export_dir'];

        // Post types: validate each is a registered public post type.
        $public_types       = array_keys( get_post_types( [ 'public' => true ] ) );
        $submitted_types    = (array) ( $input['post_types'] ?? [] );
        $clean['post_types'] = array_values( array_intersect( $submitted_types, $public_types ) );

        // Meta keys: one per line, sanitise each.
        $meta_raw        = (string) ( $input['meta_keys'] ?? '' );
        $meta_lines      = array_filter( array_map( 'sanitize_key', explode( "\n", $meta_raw ) ) );
        $clean['meta_keys'] = array_values( $meta_lines );

        $clean['delete_files_on_uninstall'] = ! empty( $input['delete_files_on_uninstall'] );

        $clean['ua_force_enabled'] = ! empty( $input['ua_force_enabled'] );

        // UA agent strings: one per line, trim whitespace, drop empty lines.
        $ua_raw              = (string) ( $input['ua_agent_strings'] ?? '' );
        $ua_lines            = array_filter( array_map( 'trim', explode( "\n", $ua_raw ) ) );
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
        $post_types = (array) ( $this->options['post_types'] ?? [] );
        if ( empty( $post_types ) ) {
            return;
        }
        ?>
        <hr>
        <h2><?php esc_html_e( 'Generate Markdown files', 'wp-markdown-for-agents' ); ?></h2>
        <p><?php esc_html_e( 'Regenerate all Markdown files for a post type. This may take a while on large sites.', 'wp-markdown-for-agents' ); ?></p>
        <?php foreach ( $post_types as $post_type ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wp_mfa_generate">
                <input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
                <?php wp_nonce_field( 'wp_mfa_generate_' . $post_type ); ?>
                <p>
                    <button type="submit" class="button button-secondary">
                        <?php
                        /* translators: %s: post type slug */
                        printf( esc_html__( 'Generate all: %s', 'wp-markdown-for-agents' ), esc_html( $post_type ) );
                        ?>
                    </button>
                </p>
            </form>
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
        $enabled = (array) ( $this->options['post_types'] ?? [] );
        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
            $checked = checked( in_array( $type->name, $enabled, true ), true, false );
            echo '<label><input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[post_types][]" value="' . esc_attr( $type->name ) . '" ' . $checked . '> ' . esc_html( $type->label ) . '</label><br>';
        }
    }

    /** @since 1.0.0 */
    public function field_export_dir(): void {
        $val = esc_attr( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) );
        echo '<input type="text" name="' . esc_attr( Options::OPTION_KEY ) . '[export_dir]" value="' . $val . '" class="regular-text">';
        echo '<p class="description">' . esc_html__( 'Subdirectory within wp-content/ to store exported .md files.', 'wp-markdown-for-agents' ) . '</p>';
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

    /** @since 1.0.0 */
    public function field_include_meta(): void {
        $checked  = checked( ! empty( $this->options['include_meta'] ), true, false );
        $meta_val = esc_textarea( implode( "\n", (array) ( $this->options['meta_keys'] ?? [] ) ) );
        echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[include_meta]" value="1" ' . $checked . '>';
        echo '<p class="description">' . esc_html__( 'Include post meta in frontmatter.', 'wp-markdown-for-agents' ) . '</p>';
        echo '<textarea name="' . esc_attr( Options::OPTION_KEY ) . '[meta_keys]" rows="4" class="large-text">' . $meta_val . '</textarea>';
        echo '<p class="description">' . esc_html__( 'One meta key per line.', 'wp-markdown-for-agents' ) . '</p>';
    }

    /** @since 1.1.0 */
    public function field_ua_force_enabled(): void {
        $checked = checked( ! empty( $this->options['ua_force_enabled'] ), true, false );
        echo '<input type="checkbox" name="' . esc_attr( Options::OPTION_KEY ) . '[ua_force_enabled]" value="1" ' . $checked . '>';
        echo '<p class="description">' . esc_html__( 'Serve Markdown to known LLM agent crawlers based on User-Agent string.', 'wp-markdown-for-agents' ) . '</p>';
    }

    /** @since 1.1.0 */
    public function field_ua_agent_strings(): void {
        $val = esc_textarea( implode( "\n", (array) ( $this->options['ua_agent_strings'] ?? [] ) ) );
        echo '<textarea name="' . esc_attr( Options::OPTION_KEY ) . '[ua_agent_strings]" rows="8" class="large-text">' . $val . '</textarea>';
        echo '<p class="description">' . esc_html__( 'One User-Agent substring per line. Matching is case-insensitive. Edit to add or remove agents.', 'wp-markdown-for-agents' ) . '</p>';
    }
}

// WordPress helper stub — defined here to avoid redefining if WP is loaded.
if ( ! function_exists( 'checked' ) ) {
    function checked( mixed $helper, mixed $current, bool $echo = true ): string {
        $result = $helper === $current ? ' checked="checked"' : '';
        if ( $echo ) {
            echo $result;
        }
        return $result;
    }
}

if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

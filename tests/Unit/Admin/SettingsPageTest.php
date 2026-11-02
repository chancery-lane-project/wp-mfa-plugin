<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Admin\SettingsPage;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Discovery\ArdCatalog;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Admin\SettingsPage
 */
class SettingsPageTest extends TestCase {

    /** @var Generator&MockObject */
    private Generator $generator;

    protected function setUp(): void {
        $GLOBALS['_mock_registered_settings'] = [];
        $GLOBALS['_mock_settings_sections']   = [];
        $GLOBALS['_mock_settings_fields']     = [];
        $GLOBALS['_mock_transients']          = [];
        $GLOBALS['_mock_options']             = [];
        $this->generator = $this->createMock( Generator::class );
    }

    private function make_page( array $options = [], ?ArdCatalog $ard_catalog = null, ?Generator $generator = null ): SettingsPage {
        return new SettingsPage(
            array_merge( Options::get_defaults(), $options ),
            $ard_catalog,
            $generator
        );
    }

    private function make_ard_catalog(): ArdCatalog {
        $bundle_generator = $this->createMock( BundleGenerator::class );
        $bundle_generator->method( 'bundle_url' )->willReturn( 'https://example.test/wp-content/uploads/wp-mfa-exports.zip' );

        return new ArdCatalog( $bundle_generator );
    }

    public function test_register_registers_option_key(): void {
        $this->make_page()->register();
        $registered = $GLOBALS['_mock_registered_settings']['markdown_for_agents_settings_group'] ?? [];
        $this->assertContains( Options::OPTION_KEY, $registered );
    }

    public function test_register_adds_settings_section(): void {
        $this->make_page()->register();
        $sections = $GLOBALS['_mock_settings_sections']['markdown-for-agents-general'] ?? [];
        $this->assertContains( 'markdown_for_agents_general', $sections );
    }

    public function test_register_adds_all_fields(): void {
        $this->make_page()->register();
        $fields = $GLOBALS['_mock_settings_fields']['markdown-for-agents-general'] ?? [];
        foreach ( [ 'markdown_for_agents_post_types', 'markdown_for_agents_export_dir',
                    'markdown_for_agents_auto_generate', 'markdown_for_agents_include_taxonomies' ] as $field ) {
            $this->assertContains( $field, $fields );
        }
    }

    public function test_sanitize_strips_unknown_keys(): void {
        $input  = [ 'unknown_key' => 'evil', 'export_dir' => 'my-exports' ];
        $result = $this->make_page()->sanitize_options( $input );
        $this->assertArrayNotHasKey( 'unknown_key', $result );
    }

    public function test_sanitize_blocks_path_traversal_in_export_dir(): void {
        $result = $this->make_page()->sanitize_options( [ 'export_dir' => '../../../etc/passwd' ] );
        $this->assertStringNotContainsString( '..', $result['export_dir'] );
        $this->assertStringNotContainsString( '/', $result['export_dir'] );
    }

    public function test_sanitize_returns_defaults_for_non_array_input(): void {
        $result = $this->make_page()->sanitize_options( 'garbage' );
        $this->assertSame( Options::get_defaults(), $result );
    }

    public function test_sanitize_post_type_configs_field_lists(): void {
        $result = $this->make_page()->sanitize_options( [
            'post_types'        => [ 'post' ],
            'post_type_configs' => [
                'post' => [
                    'frontmatter_fields' => "my_field\ngroup.subfield\n",
                    'content_fields'     => "group.content_body\n",
                ],
            ],
        ] );
        $this->assertSame( [ 'my_field', 'group.subfield' ], $result['post_type_configs']['post']['frontmatter_fields'] );
        $this->assertSame( [ 'group.content_body' ], $result['post_type_configs']['post']['content_fields'] );
    }

    public function test_register_adds_ua_detection_section(): void {
        $this->make_page()->register();
        $sections = $GLOBALS['_mock_settings_sections']['markdown-for-agents-agents'] ?? [];
        $this->assertContains( 'markdown_for_agents_ua_detection', $sections );
    }

    public function test_register_adds_ua_detection_fields(): void {
        $this->make_page()->register();
        $fields = $GLOBALS['_mock_settings_fields']['markdown-for-agents-agents'] ?? [];
        $this->assertContains( 'markdown_for_agents_ua_force_enabled', $fields );
        $this->assertContains( 'markdown_for_agents_ua_agent_strings', $fields );
    }

    public function test_sanitize_ua_force_enabled_cast_to_bool(): void {
        $result = $this->make_page()->sanitize_options( [ 'ua_force_enabled' => '1' ] );
        $this->assertTrue( $result['ua_force_enabled'] );

        $result = $this->make_page()->sanitize_options( [] );
        $this->assertFalse( $result['ua_force_enabled'] );
    }

    public function test_sanitize_ua_agent_strings_parses_textarea_lines(): void {
        $result = $this->make_page()->sanitize_options( [
            'ua_agent_strings' => "GPTBot\nClaudeBot\n\nPerplexityBot\n",
        ] );
        $this->assertSame( [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ], $result['ua_agent_strings'] );
    }

    public function test_sanitize_ua_agent_strings_trims_whitespace(): void {
        $result = $this->make_page()->sanitize_options( [
            'ua_agent_strings' => "  GPTBot  \n  ClaudeBot  \n",
        ] );
        $this->assertSame( [ 'GPTBot', 'ClaudeBot' ], $result['ua_agent_strings'] );
    }

    public function test_sanitize_ua_agent_strings_drops_empty_lines(): void {
        $result = $this->make_page()->sanitize_options( [
            'ua_agent_strings' => "\n\nGPTBot\n\n",
        ] );
        $this->assertSame( [ 'GPTBot' ], $result['ua_agent_strings'] );
    }

    // -----------------------------------------------------------------------
    // Tabbed save: merging must not wipe fields on other tabs.
    // -----------------------------------------------------------------------

    /** Seed the stored option so sanitize_options() has something to merge over. */
    private function seed_stored( array $stored ): void {
        $GLOBALS['_mock_options'][ Options::OPTION_KEY ] = array_merge( Options::get_defaults(), $stored );
    }

    public function test_sanitize_general_tab_preserves_fields_and_agents(): void {
        $this->seed_stored( [
            'post_types'        => [ 'post' ],
            'post_type_configs' => [ 'post' => [ 'frontmatter_fields' => [ 'my_field' ], 'content_fields' => [] ] ],
            'ua_agent_strings'  => [ 'CustomBot' ],
            'ua_force_enabled'  => false,
        ] );

        $result = $this->make_page()->sanitize_options( [
            '_active_tab'   => 'general',
            'post_types'    => [ 'post' ],
            'export_dir'    => 'new-dir',
            'auto_generate' => '1',
        ] );

        // The submitted general fields are applied…
        $this->assertSame( 'new-dir', $result['export_dir'] );
        $this->assertTrue( $result['auto_generate'] );
        // …while the other tabs' settings survive.
        $this->assertSame( [ 'my_field' ], $result['post_type_configs']['post']['frontmatter_fields'] );
        $this->assertSame( [ 'CustomBot' ], $result['ua_agent_strings'] );
        $this->assertFalse( $result['ua_force_enabled'] );
    }

    public function test_sanitize_fields_tab_preserves_general_and_agents(): void {
        $this->seed_stored( [
            'post_types'       => [ 'post' ],
            'export_dir'       => 'custom-dir',
            'ua_agent_strings' => [ 'CustomBot' ],
        ] );

        $result = $this->make_page()->sanitize_options( [
            '_active_tab'       => 'fields',
            'post_type_configs' => [ 'post' => [ 'frontmatter_fields' => "new_field\n", 'content_fields' => '' ] ],
        ] );

        $this->assertSame( [ 'new_field' ], $result['post_type_configs']['post']['frontmatter_fields'] );
        $this->assertSame( 'custom-dir', $result['export_dir'] );
        $this->assertSame( [ 'CustomBot' ], $result['ua_agent_strings'] );
        $this->assertSame( [ 'post' ], $result['post_types'] );
    }

    public function test_sanitize_agents_tab_preserves_general_and_fields(): void {
        $this->seed_stored( [
            'post_types'        => [ 'post' ],
            'export_dir'        => 'custom-dir',
            'post_type_configs' => [ 'post' => [ 'frontmatter_fields' => [ 'my_field' ], 'content_fields' => [] ] ],
        ] );

        $result = $this->make_page()->sanitize_options( [
            '_active_tab'      => 'agents',
            'ua_force_enabled' => '1',
            'ua_agent_strings' => "GPTBot\nClaudeBot\n",
        ] );

        $this->assertTrue( $result['ua_force_enabled'] );
        $this->assertSame( [ 'GPTBot', 'ClaudeBot' ], $result['ua_agent_strings'] );
        $this->assertSame( 'custom-dir', $result['export_dir'] );
        $this->assertSame( [ 'my_field' ], $result['post_type_configs']['post']['frontmatter_fields'] );
    }

    public function test_sanitize_general_tab_prunes_configs_for_disabled_types(): void {
        $this->seed_stored( [
            'post_types'        => [ 'post', 'page' ],
            'post_type_configs' => [
                'post' => [ 'frontmatter_fields' => [ 'a' ], 'content_fields' => [] ],
                'page' => [ 'frontmatter_fields' => [ 'b' ], 'content_fields' => [] ],
            ],
        ] );

        $result = $this->make_page()->sanitize_options( [
            '_active_tab' => 'general',
            'post_types'  => [ 'post' ], // 'page' disabled
        ] );

        $this->assertArrayHasKey( 'post', $result['post_type_configs'] );
        $this->assertArrayNotHasKey( 'page', $result['post_type_configs'] );
    }

    public function test_sanitize_flags_regen_when_post_type_configs_change(): void {
        $page = $this->make_page( [
            'post_types'        => [ 'post' ],
            'post_type_configs' => [
                'post' => [ 'frontmatter_fields' => [ 'old_field' ], 'content_fields' => [] ],
            ],
        ] );

        $page->sanitize_options( [
            'post_types'        => [ 'post' ],
            'post_type_configs' => [
                'post' => [ 'frontmatter_fields' => "new_field\n", 'content_fields' => '' ],
            ],
        ] );

        $this->assertSame( [ 'post' ], get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_sanitize_does_not_flag_regen_when_unrelated_settings_change(): void {
        $page = $this->make_page( [
            'post_types'         => [ 'post' ],
            'post_type_configs'  => [
                'post' => [ 'frontmatter_fields' => [ 'my_field' ], 'content_fields' => [] ],
            ],
            'include_taxonomies' => true,
            'export_dir'         => 'wp-mfa-exports',
            'ua_force_enabled'   => false,
        ] );

        $page->sanitize_options( [
            'post_types'         => [ 'post' ],
            'post_type_configs'  => [
                'post' => [ 'frontmatter_fields' => "my_field\n", 'content_fields' => '' ],
            ],
            'include_taxonomies' => '1',
            'export_dir'         => 'wp-mfa-exports',
            'ua_force_enabled'   => '1',
        ] );

        $this->assertFalse( get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_register_adds_discovery_section(): void {
        $this->make_page()->register();
        $sections = $GLOBALS['_mock_settings_sections']['markdown-for-agents-general'] ?? [];
        $this->assertContains( 'markdown_for_agents_discovery', $sections );
    }

    public function test_register_adds_discovery_fields(): void {
        $this->make_page()->register();
        $fields = $GLOBALS['_mock_settings_fields']['markdown-for-agents-general'] ?? [];
        $this->assertContains( 'markdown_for_agents_bundle_enabled', $fields );
        $this->assertNotContains( 'markdown_for_agents_okf_compat', $fields );
        $this->assertNotContains( 'markdown_for_agents_ard_enabled', $fields );
    }

    public function test_sanitize_flags_regen_when_bundle_enabled_changes(): void {
        $old = array_merge( Options::get_defaults(), array( 'post_types' => array( 'post' ), 'bundle_enabled' => false ) );
        $new = array( 'post_types' => array( 'post' ), 'bundle_enabled' => '1' );

        $page = $this->make_page( $old );
        $page->sanitize_options( $new );

        $this->assertSame( array( 'post' ), get_transient( 'markdown_for_agents_needs_regen' ) );
    }

    public function test_render_page_includes_generate_everything_button(): void {
        $page = $this->make_page( [ 'post_types' => [ 'post', 'page' ] ] );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'data-mfa-scope="all"', $output );
        $this->assertStringContainsString( 'Generate everything', $output );
        // Per-type and taxonomy buttons remain alongside it.
        $this->assertStringContainsString( 'data-mfa-scope="post_type:post"', $output );
        $this->assertStringContainsString( 'data-mfa-scope="taxonomy"', $output );
    }

    public function test_field_bundle_enabled_renders_ard_panel_when_bundle_enabled(): void {
        $options = [ 'bundle_enabled' => true ];
        $page    = $this->make_page( $options, $this->make_ard_catalog( $options ) );

        ob_start();
        $page->field_bundle_enabled();
        $output = ob_get_clean();

        $this->assertStringContainsString( '<textarea', $output );
        $this->assertStringContainsString( 'specVersion', $output );
        $this->assertStringContainsString( 'ai-catalog.json', $output );
    }

    public function test_field_bundle_enabled_shows_ard_fallback_when_catalog_null(): void {
        $options = [ 'bundle_enabled' => true ];
        $page    = $this->make_page( $options, null );

        ob_start();
        $page->field_bundle_enabled();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( '<textarea', $output );
    }

    public function test_field_bundle_enabled_no_ard_panel_when_bundle_disabled(): void {
        $options = [ 'bundle_enabled' => false ];
        $page    = $this->make_page( $options, $this->make_ard_catalog( $options ) );

        ob_start();
        $page->field_bundle_enabled();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( '<textarea', $output );
    }

    // -----------------------------------------------------------------------
    // Agent homepage
    // -----------------------------------------------------------------------

    public function test_register_adds_agent_homepage_field_to_discovery_section(): void {
        $this->make_page()->register();
        $this->assertContains( 'markdown_for_agents_agent_homepage', $GLOBALS['_mock_settings_fields']['markdown-for-agents-general'] ?? [] );
    }

    public function test_sanitize_keeps_existing_page_and_rejects_others(): void {
        $GLOBALS['_mock_post_objects'] = [
            42 => new \WP_Post( [ 'ID' => 42, 'post_type' => 'page', 'post_status' => 'draft' ] ),
            7  => new \WP_Post( [ 'ID' => 7, 'post_type' => 'post' ] ),
        ];

        $page = $this->make_page();
        $base = [ '_active_tab' => 'general', 'post_types' => [ 'post', 'page' ] ];

        $this->assertSame( 42, $page->sanitize_options( $base + [ 'agent_homepage_id' => '42' ] )['agent_homepage_id'] );
        $this->assertSame( 0, $page->sanitize_options( $base + [ 'agent_homepage_id' => '7' ] )['agent_homepage_id'] );
        $this->assertSame( 0, $page->sanitize_options( $base + [ 'agent_homepage_id' => '999' ] )['agent_homepage_id'] );
        $this->assertSame( 0, $page->sanitize_options( $base + [ 'agent_homepage_id' => [ 'x' ] ] )['agent_homepage_id'] );
        $this->assertSame( 0, $page->sanitize_options( $base )['agent_homepage_id'] );

        $GLOBALS['_mock_post_objects'] = [];
    }

    public function test_agent_homepage_change_alone_does_not_flag_regeneration(): void {
        $GLOBALS['_mock_post_objects'] = [ 42 => new \WP_Post( [ 'ID' => 42, 'post_type' => 'page' ] ) ];

        $this->make_page()->sanitize_options( [ '_active_tab' => 'general', 'post_types' => [ 'post', 'page' ], 'include_taxonomies' => '1', 'agent_homepage_id' => 42 ] );

        $this->assertArrayNotHasKey( \Tclp\WpMarkdownForAgents\Core\NeedsRegenTracker::TRANSIENT, $GLOBALS['_mock_transients'] );
        $GLOBALS['_mock_post_objects'] = [];
    }

    public function test_field_agent_homepage_renders_picker_with_saved_value(): void {
        ob_start();
        $this->make_page( [ 'agent_homepage_id' => 42 ] )->field_agent_homepage();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'name="' . Options::OPTION_KEY . '[agent_homepage_id]"', $html );
        $this->assertSame( 42, $GLOBALS['_mock_dropdown_pages_args']['selected'] );
        $this->assertSame( '0', $GLOBALS['_mock_dropdown_pages_args']['option_none_value'] );
    }

    public function test_field_agent_homepage_warns_when_file_missing(): void {
        $GLOBALS['_mock_post_objects'] = [ 42 => new \WP_Post( [ 'ID' => 42, 'post_type' => 'page' ] ) ];
        $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/for-agents.md' );

        ob_start();
        $this->make_page( [ 'agent_homepage_id' => 42 ], null, $this->generator )->field_agent_homepage();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'not been generated', $html );
        $GLOBALS['_mock_post_objects'] = [];
    }

    public function test_field_agent_homepage_notes_filter_override(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_agent_homepage'] = static fn( int $id ): int => 0;

        ob_start();
        $this->make_page( [ 'agent_homepage_id' => 42 ] )->field_agent_homepage();
        $html = ob_get_clean();

        unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_agent_homepage'] );
        $this->assertStringContainsString( 'filter overrides this choice', $html );
        $this->assertStringNotContainsString( 'dashicons-warning', $html );
    }
}

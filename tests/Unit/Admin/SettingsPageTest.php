<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Admin\SettingsPage;
use Tclp\WpMarkdownForAgents\Core\Options;
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
        $this->generator = $this->createMock( Generator::class );
    }

    private function make_page( array $options = [] ): SettingsPage {
        return new SettingsPage(
            array_merge( Options::get_defaults(), $options ),
            $this->generator
        );
    }

    public function test_register_registers_option_key(): void {
        $this->make_page()->register();
        $registered = $GLOBALS['_mock_registered_settings']['markdown_for_agents_settings_group'] ?? [];
        $this->assertContains( Options::OPTION_KEY, $registered );
    }

    public function test_register_adds_settings_section(): void {
        $this->make_page()->register();
        $sections = $GLOBALS['_mock_settings_sections']['markdown-for-agents'] ?? [];
        $this->assertContains( 'markdown_for_agents_general', $sections );
    }

    public function test_register_adds_all_fields(): void {
        $this->make_page()->register();
        $fields = $GLOBALS['_mock_settings_fields']['markdown-for-agents'] ?? [];
        foreach ( [ 'markdown_for_agents_enabled', 'markdown_for_agents_post_types', 'markdown_for_agents_export_dir',
                    'markdown_for_agents_auto_generate', 'markdown_for_agents_include_taxonomies' ] as $field ) {
            $this->assertContains( $field, $fields );
        }
    }

    public function test_sanitize_strips_unknown_keys(): void {
        $input  = [ 'enabled' => '1', 'unknown_key' => 'evil', 'export_dir' => 'my-exports' ];
        $result = $this->make_page()->sanitize_options( $input );
        $this->assertArrayNotHasKey( 'unknown_key', $result );
    }

    public function test_sanitize_casts_enabled_to_bool(): void {
        $result = $this->make_page()->sanitize_options( [ 'enabled' => '1' ] );
        $this->assertTrue( $result['enabled'] );

        $result = $this->make_page()->sanitize_options( [] );
        $this->assertFalse( $result['enabled'] );
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
        $sections = $GLOBALS['_mock_settings_sections']['markdown-for-agents'] ?? [];
        $this->assertContains( 'markdown_for_agents_ua_detection', $sections );
    }

    public function test_register_adds_ua_detection_fields(): void {
        $this->make_page()->register();
        $fields = $GLOBALS['_mock_settings_fields']['markdown-for-agents'] ?? [];
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
}

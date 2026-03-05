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
        $registered = $GLOBALS['_mock_registered_settings']['wp_mfa_settings_group'] ?? [];
        $this->assertContains( Options::OPTION_KEY, $registered );
    }

    public function test_register_adds_settings_section(): void {
        $this->make_page()->register();
        $sections = $GLOBALS['_mock_settings_sections']['wp-markdown-for-agents'] ?? [];
        $this->assertContains( 'wp_mfa_general', $sections );
    }

    public function test_register_adds_all_fields(): void {
        $this->make_page()->register();
        $fields = $GLOBALS['_mock_settings_fields']['wp-markdown-for-agents'] ?? [];
        foreach ( [ 'wp_mfa_enabled', 'wp_mfa_post_types', 'wp_mfa_export_dir',
                    'wp_mfa_auto_generate', 'wp_mfa_include_taxonomies', 'wp_mfa_include_meta' ] as $field ) {
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

    public function test_sanitize_meta_keys_one_per_line(): void {
        $result = $this->make_page()->sanitize_options( [
            'include_meta' => '1',
            'meta_keys'    => "my_field\nanother_field\n",
        ] );
        $this->assertSame( [ 'my_field', 'another_field' ], $result['meta_keys'] );
    }
}

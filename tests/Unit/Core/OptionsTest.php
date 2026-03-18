<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Options;

/**
 * @covers \Tclp\WpMarkdownForAgents\Core\Options
 */
class OptionsTest extends TestCase {

    protected function setUp(): void {
        reset_mock_options();
    }

    public function test_defaults_contain_required_keys(): void {
        $defaults = Options::get_defaults();

        foreach ( [ 'enabled', 'post_types', 'export_dir', 'auto_generate',
                    'include_taxonomies', 'post_type_configs' ] as $key ) {
            $this->assertArrayHasKey( $key, $defaults );
        }
    }

    public function test_defaults_enabled_is_true(): void {
        $this->assertTrue( Options::get_defaults()['enabled'] );
    }

    public function test_defaults_post_types_includes_post_and_page(): void {
        $this->assertContains( 'post', Options::get_defaults()['post_types'] );
        $this->assertContains( 'page', Options::get_defaults()['post_types'] );
    }

    public function test_get_returns_defaults_when_no_option_saved(): void {
        $options = Options::get();
        $this->assertSame( Options::get_defaults(), $options );
    }

    public function test_get_merges_saved_values_over_defaults(): void {
        update_option( Options::OPTION_KEY, [ 'enabled' => false, 'export_dir' => 'custom-dir' ] );

        $options = Options::get();

        $this->assertFalse( $options['enabled'] );
        $this->assertSame( 'custom-dir', $options['export_dir'] );
        // Defaults still present for unset keys.
        $this->assertContains( 'post', $options['post_types'] );
    }

    public function test_get_handles_non_array_option_gracefully(): void {
        update_option( Options::OPTION_KEY, 'invalid' );
        $options = Options::get();
        $this->assertSame( Options::get_defaults(), $options );
    }

    public function test_defaults_contain_ua_detection_keys(): void {
        $defaults = Options::get_defaults();
        $this->assertArrayHasKey( 'ua_force_enabled', $defaults );
        $this->assertArrayHasKey( 'ua_agent_strings', $defaults );
    }

    public function test_defaults_ua_force_enabled_is_true(): void {
        $this->assertTrue( Options::get_defaults()['ua_force_enabled'] );
    }

    public function test_defaults_ua_agent_strings_is_non_empty_array(): void {
        $strings = Options::get_defaults()['ua_agent_strings'];
        $this->assertIsArray( $strings );
        $this->assertNotEmpty( $strings );
    }

    public function test_defaults_ua_agent_strings_contains_known_agents(): void {
        $strings = Options::get_defaults()['ua_agent_strings'];
        $this->assertContains( 'GPTBot', $strings );
        $this->assertContains( 'ClaudeBot', $strings );
        $this->assertContains( 'PerplexityBot', $strings );
    }
}

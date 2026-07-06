<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Discovery\ArdCatalog;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Discovery\ArdCatalog
 */
class ArdCatalogTest extends TestCase {

    protected function setUp(): void {
        reset_mock_options();

        $GLOBALS['_mock_bloginfo']   = [ 'name' => 'The Chancery Lane Project' ];
        $GLOBALS['_mock_upload_dir'] = [
            'basedir' => sys_get_temp_dir() . '/wp-mfa-ard-uploads',
            'baseurl' => 'https://example.com/wp-content/uploads',
        ];

        unset( $GLOBALS['_mock_apply_filters']['markdown_for_agents_ai_catalog'] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_mock_bloginfo'], $GLOBALS['_mock_upload_dir'], $GLOBALS['_mock_apply_filters']['markdown_for_agents_ai_catalog'] );
    }

    private function make_catalog( array $options = [] ): ArdCatalog {
        $options = array_merge( [ 'export_dir' => 'wp-mfa-exports' ], $options );

        return new ArdCatalog( $options, new BundleGenerator( $options ) );
    }

    public function test_build_returns_spec_version_and_host(): void {
        $catalog = $this->make_catalog()->build();

        $this->assertSame( '1.0', $catalog['specVersion'] );
        $this->assertSame( 'The Chancery Lane Project', $catalog['host']['displayName'] );
        $this->assertSame( 'example.com', $catalog['host']['identifier'] );
    }

    public function test_build_returns_single_entry_with_urn_and_matching_media_types(): void {
        $catalog = $this->make_catalog()->build();

        $this->assertCount( 1, $catalog['entries'] );

        $entry = $catalog['entries'][0];

        $this->assertSame( 'urn:air:example.com:knowledge:markdown-bundle', $entry['identifier'] );
        $this->assertSame( 'The Chancery Lane Project Markdown knowledge bundle', $entry['displayName'] );
        $this->assertSame( 'application/okf-bundle+gzip', $entry['type'] );
        $this->assertSame( $entry['type'], $entry['mediaType'] );
    }

    public function test_build_entry_url_matches_bundle_generator(): void {
        $options = [ 'export_dir' => 'wp-mfa-exports' ];
        $bundle_generator = new BundleGenerator( $options );
        $catalog = new ArdCatalog( $options, $bundle_generator );

        $this->assertSame( $bundle_generator->bundle_url(), $catalog->build()['entries'][0]['url'] );
    }

    public function test_build_omits_updated_at_and_version(): void {
        $catalog = $this->make_catalog()->build();

        $this->assertArrayNotHasKey( 'updatedAt', $catalog );
        $this->assertArrayNotHasKey( 'version', $catalog );
        $this->assertArrayNotHasKey( 'updatedAt', $catalog['entries'][0] );
        $this->assertArrayNotHasKey( 'version', $catalog['entries'][0] );
    }

    public function test_build_is_filterable(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_ai_catalog'] = function ( array $catalog ): array {
            $catalog['entries'][] = [ 'identifier' => 'urn:air:example.com:knowledge:extra' ];
            return $catalog;
        };

        $catalog = $this->make_catalog()->build();

        $this->assertCount( 2, $catalog['entries'] );
        $this->assertSame( 'urn:air:example.com:knowledge:extra', $catalog['entries'][1]['identifier'] );
    }

    public function test_to_json_round_trips_and_is_pretty_printed_with_unescaped_slashes(): void {
        $json = $this->make_catalog()->to_json();

        $decoded = json_decode( $json, true );

        $this->assertIsArray( $decoded );
        $this->assertSame( '1.0', $decoded['specVersion'] );
        $this->assertStringContainsString( "\n", $json );
        $this->assertStringContainsString( 'https://', $json );
        $this->assertStringNotContainsString( 'https:\/\/', $json );
    }
}

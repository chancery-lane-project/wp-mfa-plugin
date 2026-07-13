<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Discovery;

use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;

/**
 * Builds the ARD (Agent Readiness Directory) `ai-catalog.json` document.
 *
 * The plugin never writes or serves this file — Decision 1 of the bundle/ARD
 * plan explicitly rejects any `.well-known` routing or file-writing to avoid
 * colliding with other plugins or server configuration. The settings page
 * displays `to_json()` for the site owner to copy into a manually deployed
 * `/.well-known/ai-catalog.json`.
 *
 * @since  1.6.0
 * @package Tclp\WpMarkdownForAgents\Discovery
 */
class ArdCatalog {

	/**
	 * @since  1.6.0
	 * @param  array<string, mixed> $options         Plugin options.
	 * @param  BundleGenerator      $bundle_generator Provides the bundle URL for the catalog entry.
	 */
	public function __construct( private readonly array $options, private readonly BundleGenerator $bundle_generator ) {}

	/**
	 * Build the ARD catalog document.
	 *
	 * Deliberately omits the ARD-optional `updatedAt` and `version` fields
	 * (Decision 2): because deployment is a manual copy into `.well-known`,
	 * the pasted file must not go stale as the bundle is rebuilt behind it.
	 *
	 * @since  1.6.0
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$catalog = array(
			'specVersion' => '1.0',
			'host'        => array(
				'displayName' => get_bloginfo( 'name' ),
				'identifier'  => $host,
			),
			'entries'     => array(
				array(
					'identifier'  => 'urn:air:' . $host . ':knowledge:markdown-bundle',
					'displayName' => get_bloginfo( 'name' ) . ' Markdown knowledge bundle',
					'description' => 'OKF-structured Markdown export of site content, packaged as a downloadable bundle.',
					// Same unregistered value in both fields: ard-spec#27 field-name
					// workaround (Decision 6) — consumers reading either field find it.
					'type'        => 'application/okf-bundle+zip',
					'mediaType'   => 'application/okf-bundle+zip',
					'url'         => $this->bundle_generator->bundle_url(),
				),
			),
		);

		/**
		 * Filters the complete ARD ai-catalog document before display.
		 *
		 * Receives the full document (specVersion, host, entries) built by
		 * `ArdCatalog::build()`, keyed exactly as it will be JSON-encoded.
		 * Use this to add further entries (e.g. an MCP server) or amend the
		 * host block.
		 *
		 * @since 1.6.0
		 * @param array<string, mixed> $catalog The ARD catalog document.
		 */
		return apply_filters( 'markdown_for_agents_ai_catalog', $catalog );
	}

	/**
	 * Render the catalog as pretty-printed JSON with unescaped slashes.
	 *
	 * @since  1.6.0
	 * @return string
	 */
	public function to_json(): string {
		return (string) wp_json_encode( $this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

/**
 * Centralised option defaults and access.
 *
 * All plugin options are stored under the single key `markdown_for_agents_options` for
 * clean uninstall. This class provides the canonical defaults and a
 * convenience getter that always returns a fully-merged array.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class Options {

	/**
	 * WordPress options key.
	 *
	 * @since  1.0.0
	 */
	public const OPTION_KEY = 'markdown_for_agents_options';

	/**
	 * Return the default option values.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'enabled'                   => true,
			'post_types'                => array( 'post', 'page' ),
			'export_dir'                => 'wp-mfa-exports',
			'auto_generate'             => false,
			'include_taxonomies'        => true,
			'include_hierarchy'         => false,
			'post_type_configs'         => array(),
			'frontmatter_format'        => 'yaml',
			'delete_files_on_uninstall' => false,
			'ua_force_enabled'          => true,
			'ua_agent_strings'          => array(
				'GPTBot',
				'ChatGPT-User',
				'ClaudeBot',
				'Claude-Web',
				'anthropic-ai',
				'PerplexityBot',
				'Google-Extended',
				'Amazonbot',
				'cohere-ai',
				'meta-externalagent',
				'Bytespider',
				'CCBot',
				'Applebot-Extended',
			),
		);
	}

	/**
	 * Retrieve the saved options, merged with defaults for any missing keys.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( static::get_defaults(), $saved );
	}

	/**
	 * Return the absolute filesystem path to the export base directory.
	 *
	 * Files are stored under the WordPress uploads directory:
	 * `wp-content/uploads/{export_dir}/`
	 *
	 * @since  1.2.0
	 * @param  array<string, mixed>|null $options Resolved options array, or null to fetch.
	 * @return string Absolute path without trailing slash.
	 */
	public static function get_export_base( ?array $options = null ): string {
		$options    = $options ?? static::get();
		$upload_dir = wp_upload_dir();
		$base       = $upload_dir['basedir'];

		return rtrim( $base . '/' . sanitize_file_name( (string) ( $options['export_dir'] ?? 'wp-mfa-exports' ) ), '/\\' );
	}
}

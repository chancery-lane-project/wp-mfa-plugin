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
			'post_types'                => array( 'post', 'page' ),
			'export_dir'                => 'wp-mfa-exports',
			'auto_generate'             => false,
			'include_taxonomies'        => true,
			'include_hierarchy'         => false,
			'include_author'            => false,
			'relative_image_paths'      => false,
			'include_taxonomy_topics'   => false,
			'bundle_enabled'            => false,
			'post_type_configs'         => array(),
			'delete_files_on_uninstall' => false,
			'ua_force_enabled'          => true,

			/*
			 * Known AI agent User-Agent substrings, matched case-insensitively.
			 *
			 * Provenance is deliberate and every entry traces to one of three sources,
			 * marked below. The Cloudflare Radar bot directory is the authority: it
			 * verifies operators rather than accepting community reports, and its
			 * AI_ASSISTANT / AI_SEARCH / AI_CRAWLER categories map onto the
			 * on-demand / search / training split in AgentDetector.
			 *
			 * Entries are specific rather than broad prefixes: the matched substring is
			 * what gets stored as the stats label, so a broader string (e.g. 'Claude-')
			 * would relabel a bot mid-history and split its series. For the same reason,
			 * never remove an entry — historical rows keep the old label and would fall
			 * out of the category map.
			 */
			'ua_agent_strings'          => array(

				/*
				 * Baseline: shipped since 1.1.0. Retained regardless of Radar listing —
				 * removing one would orphan its recorded statistics.
				 */
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

				/*
				 * Baseline gap-fix: classified by AgentDetector since 1.5.0 but never
				 * present here, so the categories could not be populated from a match.
				 */
				'OAI-SearchBot',
				'Claude-User',
				'Perplexity-User',

				/*
				 * Cloudflare Radar AI_ASSISTANT — fetched in response to a human prompt.
				 */
				'meta-externalfetcher/',
				'MistralAI-User',
				'Google-Agent',
				'DuckAssistBot',
				'Devin',
				'TwinAgent',
				'ApifyWebsiteContentCrawler',
				'ChathiveCrawler',
				'CledaraBot',
				'EasyScan',
				'HarkBot',
				'HIFIBot',
				'QATechBot',
				'Instapaper',
				'Nava/',
				'Retool/',

				/*
				 * Cloudflare Radar AI_SEARCH — indexing to answer queries with citations.
				 */
				'Claude-SearchBot',
				'Bravebot',
				'Amzn-SearchBot',
				'Cloudflare-AI-Search',
				'Anomura',
				'Element451Bot',
				'KernelSearchBot',
				'ShapBot/',
				'alphalens-bot',

				/*
				 * Cloudflare Radar AI_CRAWLER — corpus collection and background crawling.
				 */
				'KimiBot',
				'PetalBot',
				'GoogleOther',
				'CloudVertexBot',
				'ICC-Crawler/',
				'Cotoyogi/',
				'atlassian-bot',
				'LinerBot',
				'magpie-crawler',
				'bigsur.ai',
				'QualifiedBot',
				'Awario',
				'amazon-kendra-',
				'Anchor Browser',
				'BorderxBot',
				'CitibotSiteCrawler',
				'CloudflareBrowserRenderingCrawler',
				'netEstate NE Crawler',
				'FishBot',
				'make.com',
				'NavuBot',
				'Novellum',
				'AdpResearchBot/',
				'SelectikaScraper',
				'SemrushBot-OCOB',
				'SemrushBot-SWA/',
				'WARDBot',
				'ygs-scraper-bot',
				'w4mwnpbXf3MFAbxOkJRw',
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

	/**
	 * Return the public URL to the export base directory.
	 *
	 * Mirrors get_export_base() but uses the uploads URL rather than the filesystem path.
	 *
	 * @since  1.5.0
	 * @param  array<string, mixed>|null $options Resolved options array, or null to fetch.
	 * @return string URL without trailing slash.
	 */
	public static function get_export_base_url( ?array $options = null ): string {
		$options    = $options ?? static::get();
		$upload_dir = wp_upload_dir();
		$base       = $upload_dir['baseurl'];

		return rtrim( $base . '/' . sanitize_file_name( (string) ( $options['export_dir'] ?? 'wp-mfa-exports' ) ), '/' );
	}
}

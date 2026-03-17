<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

use Tclp\WpMarkdownForAgents\Admin\Admin;
use Tclp\WpMarkdownForAgents\CLI\Commands;
use Tclp\WpMarkdownForAgents\Generator\ContentFilter;
use Tclp\WpMarkdownForAgents\Generator\Converter;
use Tclp\WpMarkdownForAgents\Generator\FileWriter;
use Tclp\WpMarkdownForAgents\Generator\FrontmatterBuilder;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\LlmsTxtGenerator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyCollector;
use Tclp\WpMarkdownForAgents\Generator\YamlFormatter;
use Tclp\WpMarkdownForAgents\Negotiate\AgentDetector;
use Tclp\WpMarkdownForAgents\Negotiate\Negotiator;
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;
use Tclp\WpMarkdownForAgents\Stats\StatsPage;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * Main plugin orchestrator.
 *
 * Instantiates all classes, wires hooks via the Loader, and delegates to run().
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class Plugin {

	private Loader $loader;

	/**
	 * @since  1.0.0
	 * @param  string $version Plugin version string.
	 */
	public function __construct( private readonly string $version ) {
		$this->loader = new Loader();
		$this->define_hooks();
	}

	/**
	 * Register all hooks.
	 *
	 * @since  1.0.0
	 */
	private function define_hooks(): void {
		$options = Options::get();

		// i18n.
		add_action(
			'plugins_loaded',
			function (): void {
				load_plugin_textdomain(
					'wp-markdown-for-agents',
					false,
					dirname( plugin_basename( WP_MFA_PLUGIN_DIR . 'wp-markdown-for-agents.php' ) ) . '/languages/'
				);
			}
		);

		$this->define_generator( $options );

		if ( empty( $options['enabled'] ) ) {
			return;
		}

		$this->define_negotiate_hooks( $options );
		$this->define_admin_hooks( $options );
		$this->define_cli_commands( $options );
	}

	/**
	 * Build the Generator and wire save_post if auto-generate is enabled.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $options
	 */
	private function define_generator( array $options ): void {
		$export_base       = Options::get_export_base( $options );
		$this->file_writer = new FileWriter( $export_base );

		$generator = new Generator(
			$options,
			new FrontmatterBuilder( new TaxonomyCollector(), $options ),
			new ContentFilter(),
			new Converter(),
			new YamlFormatter(),
			$this->file_writer
		);

		// Store on object so other methods can access it.
		$this->generator = $generator;

		if ( ! empty( $options['auto_generate'] ) ) {
			$this->loader->add_action( 'save_post', $generator, 'on_save_post', 10, 2 );
		}
	}

	/**
	 * Wire content negotiation hooks (skip on AJAX and REST).
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $options
	 */
	private function define_negotiate_hooks( array $options ): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		global $wpdb;
		$agent_detector = new AgentDetector( $options );
		$stats_repo     = new StatsRepository( $wpdb );
		$access_logger  = new AccessLogger( $stats_repo );
		$negotiator     = new Negotiator( $options, $this->generator, $agent_detector, $access_logger );
		$this->loader->add_action( 'template_redirect', $negotiator, 'maybe_serve_markdown', 1 );
		$this->loader->add_action( 'wp_head', $negotiator, 'output_link_tag', 1 );
	}

	/**
	 * Wire admin hooks.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $options
	 */
	private function define_admin_hooks( array $options ): void {
		if ( ! is_admin() ) {
			return;
		}

		$admin = new Admin( $options, $this->generator );
		$this->loader->add_action( 'admin_menu', $admin, 'add_settings_page' );
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'add_meta_boxes', $admin, 'add_meta_boxes' );
		$this->loader->add_action( 'admin_post_wp_mfa_generate', $admin, 'handle_generate_action' );
		$this->loader->add_action( 'admin_post_wp_mfa_regenerate_post', $admin, 'handle_regenerate_post_action' );
		$this->loader->add_action( 'admin_notices', $admin, 'display_admin_notices' );

		global $wpdb;
		$stats_page = new StatsPage( new StatsRepository( $wpdb ) );
		$this->loader->add_action( 'admin_menu', $stats_page, 'add_page' );
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $options
	 */
	private function define_cli_commands( array $options ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command(
			'markdown-agents',
			new Commands( $options, $this->generator, new LlmsTxtGenerator( $options ), $this->file_writer )
		);
	}

	/**
	 * Execute all registered hooks.
	 *
	 * @since  1.0.0
	 */
	public function run(): void {
		$this->loader->run();
	}

	/** @var Generator */
	private Generator $generator;

	/** @var FileWriter */
	private FileWriter $file_writer;
}

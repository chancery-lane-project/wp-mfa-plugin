<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Admin\Admin;
use Tclp\WpMarkdownForAgents\CLI\Commands;
use Tclp\WpMarkdownForAgents\Generator\ContentFilter;
use Tclp\WpMarkdownForAgents\Generator\Converter;
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
use Tclp\WpMarkdownForAgents\Generator\FileWriter;
use Tclp\WpMarkdownForAgents\Generator\FrontmatterBuilder;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\LlmsTxtGenerator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
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

		$this->define_generator( $options );

		// DB migration — must run unconditionally regardless of 'enabled' state.
		add_action(
			'plugins_loaded',
			static function (): void {
				global $wpdb;
				Migrator::maybe_migrate( $wpdb );
			}
		);

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

		$field_resolver = new FieldResolver();

		$taxonomy_generator = new TaxonomyArchiveGenerator(
			$options,
			new YamlFormatter(),
			$this->file_writer
		);

		// Store for use by other methods.
		$this->taxonomy_generator = $taxonomy_generator;

		$generator = new Generator(
			$options,
			new FrontmatterBuilder( $field_resolver, new TaxonomyCollector(), $options ),
			new ContentFilter(),
			new Converter(),
			new YamlFormatter(),
			$this->file_writer,
			$field_resolver,
			$taxonomy_generator,
		);

		// Store on object so other methods can access it.
		$this->generator = $generator;

		$this->loader->add_action( 'delete_term', $taxonomy_generator, 'on_delete_term', 10, 4 );

		if ( ! empty( $options['auto_generate'] ) ) {
			$this->loader->add_action( 'save_post', $generator, 'on_save_post', 10, 2 );
			$this->loader->add_action( 'before_delete_post', $generator, 'cache_post_terms', 10, 1 );
			$this->loader->add_action( 'after_delete_post',  $generator, 'regenerate_term_archives_after_delete', 10, 2 );
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
		$negotiator     = new Negotiator( $options, $this->generator, $this->taxonomy_generator, $agent_detector, $access_logger );
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

		$admin = new Admin( $options, $this->generator, $this->taxonomy_generator );
		$this->loader->add_action( 'admin_menu', $admin, 'add_settings_page' );
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'add_meta_boxes', $admin, 'add_meta_boxes' );
		$this->loader->add_action( 'admin_post_markdown_for_agents_generate', $admin, 'handle_generate_action' );
		$this->loader->add_action( 'admin_post_markdown_for_agents_regenerate_post', $admin, 'handle_regenerate_post_action' );
		$this->loader->add_action( 'admin_notices', $admin, 'display_admin_notices' );
		$this->loader->add_action( 'wp_ajax_mfa_generate_batch', $admin, 'handle_generate_batch_ajax' );
		$this->loader->add_action( 'wp_ajax_mfa_generate_taxonomy_batch', $admin, 'handle_generate_taxonomy_batch_ajax' );
		$this->loader->add_action( 'wp_ajax_mfa_preview_post', $admin, 'handle_preview_post_ajax' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );

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

		global $wpdb;

		\WP_CLI::add_command(
			'markdown-agents',
			new Commands( $options, $this->generator, new LlmsTxtGenerator( $options ), $this->file_writer, $this->taxonomy_generator, new StatsRepository( $wpdb ) )
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

	private Generator $generator;
	private TaxonomyArchiveGenerator $taxonomy_generator;
	private FileWriter $file_writer;
}

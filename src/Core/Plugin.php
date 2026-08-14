<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Admin\Admin;
use Tclp\WpMarkdownForAgents\CLI\Commands;
use Tclp\WpMarkdownForAgents\Discovery\ArdCatalog;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\ContentFilter;
use Tclp\WpMarkdownForAgents\Generator\Converter;
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;
use Tclp\WpMarkdownForAgents\Generator\FileWriter;
use Tclp\WpMarkdownForAgents\Generator\FrontmatterBuilder;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\IndexGenerator;
use Tclp\WpMarkdownForAgents\Generator\InternalUrlResolver;
use Tclp\WpMarkdownForAgents\Generator\LinkRewriter;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyCollector;
use Tclp\WpMarkdownForAgents\Generator\YamlFormatter;
use Tclp\WpMarkdownForAgents\Jobs\Clock;
use Tclp\WpMarkdownForAgents\Jobs\GenerationJob;
use Tclp\WpMarkdownForAgents\Jobs\JobRunner;
use Tclp\WpMarkdownForAgents\Jobs\StageFactory;
use Tclp\WpMarkdownForAgents\Jobs\SystemClock;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;
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
	 */
	public function __construct() {
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

		// DB migration — runs on every load.
		add_action(
			'plugins_loaded',
			static function (): void {
				global $wpdb;
				Migrator::maybe_migrate( $wpdb );
			}
		);

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

		// Built once, outside the closure, so URL memoisation and the term-link map persist across every link resolved this request.
		$url_resolver  = new InternalUrlResolver( $options );
		$link_rewriter = new LinkRewriter(
			fn( string $url ): ?string => $url_resolver->resolve( $url ),
			Options::get_export_base_url( $options )
		);

		$generator = new Generator(
			$options,
			new FrontmatterBuilder( $field_resolver, new TaxonomyCollector(), $options ),
			new ContentFilter(),
			new Converter(),
			new YamlFormatter(),
			$this->file_writer,
			$field_resolver,
			$taxonomy_generator,
			$link_rewriter,
		);

		// Store on object so other methods can access it.
		$this->generator = $generator;

		$this->loader->add_action( 'delete_term', $taxonomy_generator, 'on_delete_term', 10, 4 );

		// Registered unconditionally so MD files are deleted whenever a post is
		// moved to a non-public status or permanently deleted, even if auto_generate is disabled.
		$this->loader->add_action( 'transition_post_status', $generator, 'on_transition_post_status', 10, 3 );
		$this->loader->add_action( 'before_delete_post', $generator, 'on_before_delete_post', 10, 1 );
		// Priority 10 runs after handle_meta_box_save (priority 5) so exclusion meta is already saved.
		$this->loader->add_action( 'save_post', $generator, 'on_save_post_cleanup', 10, 2 );

		if ( ! empty( $options['auto_generate'] ) ) {
			$this->loader->add_action( 'save_post', $generator, 'on_save_post', 10, 2 );
			$this->loader->add_action( 'before_delete_post', $generator, 'cache_post_terms', 10, 1 );
			$this->loader->add_action( 'after_delete_post',  $generator, 'regenerate_term_archives_after_delete', 10, 2 );
		}

		$index_generator = new IndexGenerator( $options, $this->file_writer );
		$this->index_generator = $index_generator;

		$this->loader->add_action( 'markdown_for_agents_file_generated', $index_generator, 'on_file_generated', 10, 2 );
		$this->loader->add_action( 'markdown_for_agents_file_deleted', $index_generator, 'on_file_deleted', 10, 2 );
		$this->loader->add_action( 'markdown_for_agents_taxonomy_file_generated', $index_generator, 'on_taxonomy_file_generated', 10, 2 );
		$this->loader->add_action( 'markdown_for_agents_taxonomy_file_deleted', $index_generator, 'on_taxonomy_file_deleted', 10, 2 );
		$this->loader->add_action( 'shutdown', $index_generator, 'flush_dirty' );

		$bundle_generator       = new BundleGenerator( $options );
		$this->bundle_generator = $bundle_generator;

		$this->loader->add_action( 'markdown_for_agents_file_generated', $bundle_generator, 'mark_stale_and_schedule' );
		$this->loader->add_action( 'markdown_for_agents_file_deleted', $bundle_generator, 'mark_stale_and_schedule' );
		$this->loader->add_action( 'markdown_for_agents_taxonomy_file_generated', $bundle_generator, 'mark_stale_and_schedule' );
		$this->loader->add_action( 'markdown_for_agents_taxonomy_file_deleted', $bundle_generator, 'mark_stale_and_schedule' );
		add_action(
			'markdown_for_agents_rebuild_bundle',
			static function () use ( $generator, $bundle_generator ): void {
				// The result is deliberately discarded: nobody watches a cron tick.
				// Failures surface through is_stale() and the CLI status output.
				$generator->rebuild_bundle( $bundle_generator );
			}
		);

		global $wpdb;

		$clock          = new SystemClock();
		$generation_job = new GenerationJob( $clock );
		$stage_factory  = new StageFactory( $wpdb, $options, $generator, $taxonomy_generator, $bundle_generator );
		$job_runner     = new JobRunner(
			$generation_job,
			new TickMutex( $clock ),
			$stage_factory,
			new NeedsRegenTracker(),
			$clock,
			$bundle_generator
		);

		$this->generation_job = $generation_job;
		$this->stage_factory  = $stage_factory;
		$this->clock          = $clock;

		// The cron chain. Registered unconditionally: a job started in wp-admin
		// is processed by whatever request happens to spawn cron next.
		$this->loader->add_action( JobRunner::TICK_HOOK, $job_runner, 'run_tick' );
		$this->loader->add_action( JobRunner::WATCHDOG_HOOK, $job_runner, 'watchdog' );

		// Hourly backstop for a chain that lost its event. Registered here
		// rather than on activation so existing installs pick it up on update.
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( JobRunner::WATCHDOG_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', JobRunner::WATCHDOG_HOOK );
				}
			}
		);

		$this->job_runner = $job_runner;
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
		// Priority 2 runs directly after maybe_serve_markdown, which exits when
		// it serves Markdown — so these headers only ever reach HTML responses.
		$this->loader->add_action( 'template_redirect', $negotiator, 'output_html_headers', 2 );
		$this->loader->add_action( 'wp_head', $negotiator, 'output_link_tag', 1 );
	}

	/**
	 * Wire admin hooks.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $options
	 */
	private function define_admin_hooks( array $options ): void {
		$ard_catalog = new ArdCatalog( $this->bundle_generator );
		$admin       = new Admin( $options, $this->generator, $this->taxonomy_generator, $ard_catalog, $this->generation_job, $this->stage_factory, $this->clock );

		// Registered unconditionally — exclusion meta must be saved regardless of
		// is_admin() or auto_generate setting. Priority 5 runs before
		// Generator::on_save_post at priority 10.
		$this->loader->add_action( 'save_post', $admin, 'handle_meta_box_save', 5, 1 );

		if ( ! is_admin() ) {
			return;
		}

		$this->loader->add_action( 'admin_menu', $admin, 'add_settings_page' );
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'add_meta_boxes', $admin, 'add_meta_boxes' );
		$this->loader->add_action( 'admin_post_markdown_for_agents_generate', $admin, 'handle_generate_action' );
		$this->loader->add_action( 'admin_post_markdown_for_agents_regenerate_post', $admin, 'handle_regenerate_post_action' );
		$this->loader->add_action( 'admin_notices', $admin, 'display_admin_notices' );
		$this->loader->add_action( 'wp_ajax_mfa_start_generation_job', $admin, 'handle_start_generation_job_ajax' );
		$this->loader->add_action( 'wp_ajax_mfa_job_status', $admin, 'handle_job_status_ajax' );
		// Same-process fallback when a running job has no pending cron event.
		$this->loader->add_action( 'admin_init', $this->job_runner, 'maybe_nudge' );
		$this->loader->add_action( 'wp_ajax_mfa_preview_post', $admin, 'handle_preview_post_ajax' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
		$this->loader->add_filter( 'plugin_action_links_' . MARKDOWN_FOR_AGENTS_PLUGIN_BASENAME, $admin, 'add_action_links' );

		global $wpdb;
		$stats_page = new StatsPage( new StatsRepository( $wpdb ), new AgentDetector( $options ) );
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
			new Commands( $options, $this->generator, $this->file_writer, $this->taxonomy_generator, new StatsRepository( $wpdb ), $this->index_generator, $this->bundle_generator )
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
	private IndexGenerator $index_generator;
	private BundleGenerator $bundle_generator;
	private GenerationJob $generation_job;
	private StageFactory $stage_factory;
	private JobRunner $job_runner;
	private Clock $clock;
}

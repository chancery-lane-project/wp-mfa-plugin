<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\CLI;

use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\LlmsTxtGenerator;

/**
 * WP-CLI commands for WP Markdown for Agents.
 *
 * Register under the `markdown-agents` parent:
 *
 *   WP_CLI::add_command( 'markdown-agents', new Commands( $options, $generator ) );
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\CLI
 */
class Commands {

    /**
     * @since  1.0.0
     * @param  array<string, mixed>   $options   Plugin options.
     * @param  Generator              $generator Generator instance.
     * @param  LlmsTxtGenerator|null  $llms_txt  Optional llms.txt generator.
     */
    public function __construct(
        private readonly array $options,
        private readonly Generator $generator,
        private readonly ?LlmsTxtGenerator $llms_txt = null
    ) {}

    /**
     * Generate Markdown export files.
     *
     * ## OPTIONS
     *
     * [--post-type=<type>]
     * : Generate all posts of this type.
     *
     * [--post-id=<id>]
     * : Generate a single post.
     *
     * [--dry-run]
     * : Report what would be generated without writing files.
     *
     * [--force]
     * : Regenerate even if the .md file is newer than the post.
     *
     * [--with-llmstxt]
     * : Also regenerate llms.txt after export.
     *
     * ## EXAMPLES
     *
     *   wp markdown-agents generate --post-type=post
     *   wp markdown-agents generate --post-id=42
     *
     * @since  1.0.0
     * @param  array<int, string>    $args       Positional args.
     * @param  array<string, string> $assoc_args Named args.
     */
    public function generate( array $args, array $assoc_args ): void {
        $dry_run   = isset( $assoc_args['dry-run'] );
        $post_id   = isset( $assoc_args['post-id'] ) ? (int) $assoc_args['post-id'] : null;
        $post_type = $assoc_args['post-type'] ?? null;

        if ( null !== $post_id ) {
            $this->generate_single( $post_id, $dry_run );
            return;
        }

        $types = null !== $post_type
            ? [ $post_type ]
            : (array) ( $this->options['post_types'] ?? [] );

        foreach ( $types as $type ) {
            $this->generate_type( $type, $dry_run );
        }

        if ( isset( $assoc_args['with-llmstxt'] ) && $this->llms_txt ) {
            $export_base = WP_CONTENT_DIR . '/' . sanitize_file_name( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) );
            $result      = $this->llms_txt->generate( $export_base );
            if ( $result ) {
                \WP_CLI::success( 'llms.txt generated.' );
            } else {
                \WP_CLI::warning( 'llms.txt generation failed.' );
            }
        }
    }

    /**
     * Show generation status for each enabled post type.
     *
     * ## EXAMPLES
     *
     *   wp markdown-agents status
     *
     * @since  1.0.0
     * @param  array<int, string>    $args
     * @param  array<string, string> $assoc_args
     */
    public function status( array $args, array $assoc_args ): void {
        $post_types  = (array) ( $this->options['post_types'] ?? [] );
        $export_base = WP_CONTENT_DIR . '/' . sanitize_file_name( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) );

        $rows = [];

        foreach ( $post_types as $type ) {
            $total = (int) wp_count_posts( $type )->publish; // phpcs:ignore

            // Count .md files in the export directory.
            $type_dir  = $export_base . '/' . $type;
            $generated = is_dir( $type_dir )
                ? count( glob( $type_dir . '/*.md' ) ?: [] )
                : 0;

            $rows[] = [
                'post_type' => $type,
                'published' => $total,
                'generated' => $generated,
                'missing'   => max( 0, $total - $generated ),
            ];
        }

        \WP_CLI\Utils\format_items( 'table', $rows, [ 'post_type', 'published', 'generated', 'missing' ] );
    }

    /**
     * Delete Markdown export files.
     *
     * ## OPTIONS
     *
     * [--post-type=<type>]
     * : Delete all .md files for this post type.
     *
     * [--post-id=<id>]
     * : Delete the .md file for a single post.
     *
     * [--all]
     * : Delete all generated files across all post types.
     *
     * [--yes]
     * : Skip confirmation when using --all.
     *
     * ## EXAMPLES
     *
     *   wp markdown-agents delete --post-type=post
     *   wp markdown-agents delete --all --yes
     *
     * @since  1.0.0
     * @param  array<int, string>    $args
     * @param  array<string, string> $assoc_args
     */
    public function delete( array $args, array $assoc_args ): void {
        if ( isset( $assoc_args['all'] ) ) {
            \WP_CLI::confirm( 'Delete all generated Markdown files?', $assoc_args );

            $types = (array) ( $this->options['post_types'] ?? [] );
            foreach ( $types as $type ) {
                $this->delete_type( $type );
            }
            \WP_CLI::success( 'All Markdown files deleted.' );
            return;
        }

        if ( isset( $assoc_args['post-id'] ) ) {
            $post_id = (int) $assoc_args['post-id'];
            $ok      = $this->generator->delete_post( $post_id );
            $ok
                ? \WP_CLI::success( "Deleted file for post {$post_id}." )
                : \WP_CLI::error( "Could not delete file for post {$post_id}." );
            return;
        }

        if ( isset( $assoc_args['post-type'] ) ) {
            $this->delete_type( $assoc_args['post-type'] );
            \WP_CLI::success( 'Done.' );
            return;
        }

        \WP_CLI::error( 'Specify --post-type, --post-id, or --all.' );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * @since  1.0.0
     */
    private function generate_single( int $post_id, bool $dry_run ): void {
        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post ) {
            \WP_CLI::error( "Post {$post_id} not found." );
            return;
        }

        if ( $dry_run ) {
            \WP_CLI::log( "[dry-run] Would generate: {$post->post_name} ({$post->post_type})" );
            return;
        }

        $ok = $this->generator->generate_post( $post );
        $ok
            ? \WP_CLI::success( "Generated: {$post->post_name}" )
            : \WP_CLI::warning( "Failed: {$post->post_name}" );
    }

    /**
     * @since  1.0.0
     */
    private function generate_type( string $post_type, bool $dry_run ): void {
        if ( $dry_run ) {
            \WP_CLI::log( "[dry-run] Would generate all published posts of type: {$post_type}" );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( "Generating {$post_type}", 0 );
        $done     = 0;

        $results = $this->generator->generate_post_type(
            $post_type,
            function () use ( $progress, &$done ): void {
                ++$done;
                $progress->tick();
            }
        );

        $progress->finish();

        \WP_CLI::success( sprintf(
            '%s: %d generated, %d failed.',
            $post_type,
            $results['success'],
            $results['failed']
        ) );
    }

    /**
     * @since  1.0.0
     */
    private function delete_type( string $post_type ): void {
        $export_base = WP_CONTENT_DIR . '/' . sanitize_file_name( (string) ( $this->options['export_dir'] ?? 'wp-mfa-exports' ) );
        $type_dir    = $export_base . '/' . $post_type;

        if ( ! is_dir( $type_dir ) ) {
            \WP_CLI::log( "No export directory for {$post_type}." );
            return;
        }

        $files = glob( $type_dir . '/*.md' ) ?: [];
        foreach ( $files as $file ) {
            unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        }

        \WP_CLI::log( sprintf( 'Deleted %d files for %s.', count( $files ), $post_type ) );
    }
}

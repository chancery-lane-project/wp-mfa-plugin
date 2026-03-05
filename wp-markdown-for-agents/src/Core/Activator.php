<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Core;

/**
 * Handles plugin activation tasks.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Core
 */
class Activator {

    /**
     * Run on plugin activation.
     *
     * Creates the export directory, writes a protective .htaccess, and sets
     * default options (does not overwrite existing saved options).
     *
     * @since  1.0.0
     */
    public static function activate(): void {
        $options    = Options::get();
        $export_dir = trailingslashit( WP_CONTENT_DIR ) . sanitize_file_name( $options['export_dir'] );

        if ( wp_mkdir_p( $export_dir ) ) {
            $htaccess = $export_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                    $htaccess,
                    "# Deny direct access to generated Markdown files.\nDeny from all\n"
                );
            }
        }

        add_option( Options::OPTION_KEY, Options::get_defaults() );
    }
}

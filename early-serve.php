<?php
/**
 * Early serving entry point. Not loaded by the plugin itself.
 *
 * Required by the must-use loader in mu-plugins/markdown-for-agents-early.php
 * when a site owner has copied it into wp-content/mu-plugins/. See
 * docs/early-serving.md.
 *
 * @package Tclp\WpMarkdownForAgents
 * @since   1.9.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Stand aside unless the plugin itself is active on this site.
if ( ! in_array( basename( __DIR__ ) . '/markdown-for-agents.php', (array) get_option( 'active_plugins', array() ), true ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

add_action( 'muplugins_loaded', array( \Tclp\WpMarkdownForAgents\Negotiate\EarlyServer::class, 'bootstrap' ), PHP_INT_MAX );

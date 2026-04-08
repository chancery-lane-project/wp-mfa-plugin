<?php

/**
 * Plugin Name:       Markdown for Agents and Statistics
 * Plugin URI:        https://github.com/tclp/markdown-for-agents
 * Description:       Serve pre-generated Markdown files to AI agents via HTTP content negotiation, with access statistics.
 * Version:           1.2.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            The Chancery Lane Project
 * Author URI:        https://chancerylane.uk
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       markdown-for-agents
 * Domain Path:       /languages
 *
 * @package Tclp\WpMarkdownForAgents
 */

declare(strict_types=1);

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MARKDOWN_FOR_AGENTS_VERSION', '1.2.0' );
define( 'MARKDOWN_FOR_AGENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARKDOWN_FOR_AGENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MARKDOWN_FOR_AGENTS_PLUGIN_DIR . 'vendor/autoload.php';

use Tclp\WpMarkdownForAgents\Core\Activator;
use Tclp\WpMarkdownForAgents\Core\Deactivator;
use Tclp\WpMarkdownForAgents\Core\Plugin;

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

$plugin = new Plugin( MARKDOWN_FOR_AGENTS_VERSION );
$plugin->run();

<?php
/**
 * Plugin Name: Markdown for Agents – early serving
 * Description: Lets Markdown for Agents and Statistics answer Markdown requests before other plugins and the theme load. Delete this file to switch it off.
 *
 * Copy this file into wp-content/mu-plugins/. It only loads code from the
 * Markdown for Agents and Statistics plugin, and does nothing if that plugin
 * is missing or inactive. If the plugin's folder is not named
 * markdown-for-agents-and-statistics, change the path below.
 *
 * See docs/early-serving.md in the plugin for what it skips and how to check
 * it is working.
 */

$markdown_for_agents_early = WP_PLUGIN_DIR . '/markdown-for-agents-and-statistics/early-serve.php';

if ( is_readable( $markdown_for_agents_early ) ) {
	require_once $markdown_for_agents_early;
}

unset( $markdown_for_agents_early );

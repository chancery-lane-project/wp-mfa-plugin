<?php
/**
 * Plugin Name: Markdown for Agents – early serving
 * Description: Lets Markdown for Agents and Statistics answer Markdown requests before other plugins and the theme load. Delete this file to switch it off.
 *
 * Copy this file into wp-content/mu-plugins/. It only loads code from the
 * Markdown for Agents and Statistics plugin, and does nothing if that plugin
 * is missing or inactive. The plugin is found among the active plugins, so
 * its folder can have any name.
 *
 * See docs/early-serving.md in the plugin for what it skips and how to check
 * it is working.
 */

foreach ( (array) get_option( 'active_plugins', array() ) as $markdown_for_agents_plugin ) {
	$markdown_for_agents_plugin = (string) $markdown_for_agents_plugin;

	if ( 'markdown-for-agents.php' === basename( $markdown_for_agents_plugin ) && '.' !== dirname( $markdown_for_agents_plugin ) ) {
		$markdown_for_agents_early = WP_PLUGIN_DIR . '/' . dirname( $markdown_for_agents_plugin ) . '/early-serve.php';

		// Another plugin's main file can share the name, so keep looking
		// until one of them has the entry point.
		if ( is_readable( $markdown_for_agents_early ) ) {
			require_once $markdown_for_agents_early;
			break;
		}
	}
}

unset( $markdown_for_agents_plugin, $markdown_for_agents_early );

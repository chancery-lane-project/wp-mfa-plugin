<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Homepage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;

/**
 * Resolves the page chosen as the site's Markdown homepage for agents.
 *
 * The agent homepage is an ordinary published page (for example
 * `/for-agents/`) picked in settings. Its pre-generated `.md` file is served
 * for Markdown requests to the front page, and it is listed first in the
 * root `index.md`.
 *
 * Stateless: every method takes the resolved options array it needs.
 *
 * @since  1.8.0
 * @package Tclp\WpMarkdownForAgents\Homepage
 */
final class AgentHomepage {

	/**
	 * Return the configured agent homepage ID, or 0 when none is set.
	 *
	 * @since  1.8.0
	 * @param  array<string, mixed> $options Plugin options.
	 * @return int
	 */
	public static function id( array $options ): int {
		$id = (int) ( $options['agent_homepage_id'] ?? 0 );

		/**
		 * Filter the post ID used as the Markdown homepage for agents.
		 *
		 * Lets managed sites set or override the settings choice in code.
		 * Return 0 to turn the agent homepage off.
		 *
		 * @since 1.8.0
		 * @param int $post_id The page picked in settings, or 0.
		 */
		return max( 0, (int) apply_filters( 'markdown_for_agents_agent_homepage', $id ) );
	}

	/**
	 * Return the agent homepage when it is set and eligible for export.
	 *
	 * @since  1.8.0
	 * @param  array<string, mixed> $options Plugin options.
	 * @return \WP_Post|null
	 */
	public static function post( array $options ): ?\WP_Post {
		$id = self::id( $options );
		if ( $id < 1 ) {
			return null;
		}

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! ExportPolicy::is_eligible( $post, $options ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * List the reasons the configured agent homepage cannot be served.
	 *
	 * Empty when no homepage is set or when it can be served. Used for the
	 * warnings beside the settings picker.
	 *
	 * @since  1.8.0
	 * @param  array<string, mixed> $options  Plugin options.
	 * @param  string|null          $filepath The page's export path, if known, to check the file exists.
	 * @return string[] Translated, unescaped messages.
	 */
	public static function problems( array $options, ?string $filepath = null ): array {
		$id = self::id( $options );
		if ( $id < 1 ) {
			return array();
		}

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return array( __( 'The chosen page no longer exists.', 'markdown-for-agents-and-statistics' ) );
		}

		$problems = array();

		if ( 'publish' !== $post->post_status ) {
			$problems[] = __( 'The page is not published.', 'markdown-for-agents-and-statistics' );
		}

		if ( '' !== $post->post_password ) {
			$problems[] = __( 'The page is password-protected.', 'markdown-for-agents-and-statistics' );
		}

		if ( get_post_meta( $post->ID, '_markdown_for_agents_excluded', true ) ) {
			$problems[] = __( 'The page is excluded from Markdown export in its editor sidebar.', 'markdown-for-agents-and-statistics' );
		}

		if ( ! in_array( $post->post_type, ExportPolicy::enabled_post_types( $options ), true ) ) {
			$problems[] = __( 'Its post type is not enabled for export under Post types.', 'markdown-for-agents-and-statistics' );
		}

		if ( array() === $problems && null !== $filepath && ! file_exists( $filepath ) ) {
			$problems[] = __( 'Its Markdown file has not been generated yet. Update the page, or regenerate it from its editor sidebar.', 'markdown-for-agents-and-statistics' );
		}

		return $problems;
	}
}

<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Negotiate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Stats\AccessLogger;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;

/**
 * Serves Markdown before other plugins, the theme and the main query load.
 *
 * Opt-in. Nothing here runs unless the site owner copies the loader in
 * mu-plugins/markdown-for-agents-early.php into wp-content/mu-plugins/. The
 * loader requires early-serve.php, which hooks bootstrap() to
 * muplugins_loaded: after every must-use plugin, before regular plugins.
 *
 * A Markdown request it can answer is answered there, with the same trigger
 * rules, statistics and headers as Negotiator (all through
 * MarkdownResponder), so the request never loads the rest of WordPress. A
 * request it cannot answer carries on and Negotiator handles it as usual.
 *
 * Without the main query it finds the export file from the URL path, and
 * serves it only if the permalink in the file's own front matter is the path
 * asked for. Filters added by regular plugins or the theme have not loaded
 * yet, so it switches itself off while any of GUARDED_FILTERS has a callback
 * from them; see record_guard().
 *
 * @since  1.9.0
 * @package Tclp\WpMarkdownForAgents\Negotiate
 */
class EarlyServer {

	/**
	 * X-Markdown-Source value for responses served early.
	 */
	public const SOURCE = 'markdown-for-agents-early';

	/**
	 * Autoloaded option holding the guard: array{hooked: string[], checked: int}.
	 * Absent until a full request has checked the filters; early serving stays
	 * off until then.
	 */
	public const GUARD_OPTION = 'markdown_for_agents_early_guard';

	/**
	 * Filters that change what is served or how. When a regular plugin or the
	 * theme hooks one, the early route cannot honour it, so it stands aside.
	 *
	 * @var string[]
	 */
	public const GUARDED_FILTERS = array(
		'markdown_for_agents_serve_enabled',
		'markdown_for_agents_serve_taxonomies',
		'markdown_for_agents_canonical_url',
		'markdown_for_agents_content_signal',
		'markdown_for_agents_cache_headers',
		'markdown_for_agents_export_path',
		'markdown_for_agents_post_relative_path',
		'get_canonical_url',
	);

	/**
	 * Most bytes of a file read while looking for the end of its front matter.
	 */
	private const FRONT_MATTER_MAX_BYTES = 16384;

	/**
	 * @since  1.9.0
	 * @param  array<string, mixed> $options     Plugin options.
	 * @param  AgentDetector        $detector    Detector built from the options.
	 * @param  MarkdownResponder    $responder   Shared response sender and logger.
	 * @param  string               $export_base Absolute path of the export directory.
	 * @param  string               $home_path   Path of the home URL, e.g. '' or '/blog'.
	 */
	public function __construct(
		private readonly array $options,
		private readonly AgentDetector $detector,
		private readonly MarkdownResponder $responder,
		private readonly string $export_base,
		private readonly string $home_path = ''
	) {}

	/**
	 * Entry point, hooked to muplugins_loaded by early-serve.php.
	 *
	 * Every front-end GET or HEAD snapshots the guarded filters, so a request
	 * that goes on to load fully can tell which callbacks came from regular
	 * plugins or the theme. Then, if the request asks for Markdown, it tries
	 * to serve the file and exit.
	 *
	 * @since  1.9.0
	 */
	public static function bootstrap(): void {
		// Front-end GET and HEAD only. The guard is recorded on the same
		// requests: WP-CLI with --skip-plugins, or a theme that adds a filter
		// only outside wp-admin, would otherwise record a clear guard that is
		// wrong for the front end.
		if ( is_multisite() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		// Public content-negotiation parameter on anonymous frontend requests — nonces are not applicable here.
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), PHP_URL_PATH );
		if ( str_starts_with( $path, '/wp-' ) || str_contains( $path, '.php' ) ) {
			return;
		}

		$before = self::callback_counts();
		add_action(
			'wp_loaded',
			static function () use ( $before ): void {
				self::record_guard( $before );
			},
			PHP_INT_MAX
		);

		// The home page has no export of its own to find.
		if ( '' === $path || '/' === $path ) {
			return;
		}

		global $wpdb;
		$options   = Options::get();
		$detector  = new AgentDetector( $options );
		$home_path = rtrim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
		$server    = new self(
			$options,
			$detector,
			new MarkdownResponder( $detector, new AccessLogger( new StatsRepository( $wpdb ) ) ),
			Options::get_export_base( $options ),
			$home_path
		);

		$server->maybe_serve(
			$path,
			$method,
			sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			sanitize_key( wp_unslash( $_GET['output_format'] ?? '' ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Serve the request's Markdown if it asks for it and a matching export
	 * exists. Exits after sending; returns false when it did not serve.
	 *
	 * @since  1.9.0
	 * @param  string $path      Request path, e.g. '/2026/10/a-post/'.
	 * @param  string $method    'GET' or 'HEAD'.
	 * @param  string $accept    Sanitised Accept header.
	 * @param  string $ua        Sanitised User-Agent header.
	 * @param  string $format_qp Sanitised output_format query value.
	 * @return bool False when the request is left to WordPress.
	 */
	public function maybe_serve( string $path, string $method, string $accept, string $ua, string $format_qp ): bool {
		if ( ! self::guard_is_clear() ) {
			return false;
		}

		$access_method = MarkdownResponder::access_method( $this->detector, $accept, $ua, $format_qp );
		if ( null === $access_method ) {
			return false;
		}

		$match = $this->resolve( $path );
		if ( null === $match ) {
			return false;
		}

		$this->responder->log_access( $match['object_id'], $match['object_type'], $ua, $method, $access_method );
		$this->responder->send( $match['file'], $access_method, $match['permalink'], self::SOURCE );

		return true;
	}

	/**
	 * Find the export file for a request path.
	 *
	 * Candidates follow ExportPolicy's default layout: for each enabled post
	 * type, {type}/{last segment}.md and, for nested paths,
	 * {type}/{full path}.md; then, for each taxonomy directory present,
	 * taxonomy/{taxonomy}/{last segment}.md. They are tried in that order and
	 * the taxonomy directories are only listed if no post matches, because
	 * every file-system call counts on hosts with slow storage. A candidate is
	 * accepted only if it is a file inside the export directory whose front
	 * matter permalink has the same path as the request, ignoring a trailing
	 * slash.
	 *
	 * @since  1.9.0
	 * @param  string $path Request path.
	 * @return array{file: string, permalink: string, object_id: int, object_type: string}|null
	 */
	public function resolve( string $path ): ?array {
		$path = rawurldecode( $path );
		if ( '' !== $this->home_path && str_starts_with( $path, $this->home_path . '/' ) ) {
			$relative = substr( $path, strlen( $this->home_path ) );
		} else {
			$relative = $path;
		}

		$segments = array_values( array_filter( explode( '/', trim( $relative, '/' ) ), 'strlen' ) );
		if ( ! $segments ) {
			return null;
		}

		$base = realpath( $this->export_base );
		if ( false === $base ) {
			return null;
		}

		$want = rtrim( $path, '/' );
		foreach ( $this->candidates( $base, $segments ) as $candidate ) {
			$file = realpath( $base . '/' . $candidate );
			if ( false === $file || ! str_starts_with( $file, $base . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			$front = self::front_matter( $file );
			if ( null === $front || ! isset( $front['permalink'] ) ) {
				continue;
			}

			if ( rtrim( rawurldecode( (string) wp_parse_url( $front['permalink'], PHP_URL_PATH ) ), '/' ) !== $want ) {
				continue;
			}

			$is_term   = 'taxonomy_archive' === ( $front['type'] ?? '' );
			$object_id = (int) ( $is_term ? ( $front['term_id'] ?? 0 ) : ( $front['wpid'] ?? 0 ) );

			return array(
				'file'        => $file,
				'permalink'   => esc_url_raw( $front['permalink'] ),
				'object_id'   => $object_id,
				'object_type' => $is_term ? StatsRepository::OBJECT_TERM : StatsRepository::OBJECT_POST,
			);
		}

		return null;
	}

	/**
	 * Candidate export paths, relative to the export directory, in the order
	 * resolve() tries them. Generated lazily so the taxonomy directories are
	 * listed only when every post candidate has failed.
	 *
	 * @since  1.9.0
	 * @param  string   $base     Real path of the export directory.
	 * @param  string[] $segments Request path segments.
	 * @return \Generator<int, string>
	 */
	private function candidates( string $base, array $segments ): \Generator {
		$last   = sanitize_file_name( (string) end( $segments ) );
		$nested = implode( '/', array_map( 'sanitize_file_name', $segments ) );
		$seen   = array();

		foreach ( (array) ( $this->options['post_types'] ?? array() ) as $type ) {
			$type  = sanitize_file_name( (string) $type );
			$paths = count( $segments ) > 1 ? array( $type . '/' . $last . '.md', $type . '/' . $nested . '.md' ) : array( $type . '/' . $last . '.md' );
			foreach ( $paths as $candidate ) {
				if ( ! isset( $seen[ $candidate ] ) ) {
					$seen[ $candidate ] = true;
					yield $candidate;
				}
			}
		}

		$taxonomies = is_dir( $base . '/taxonomy' ) ? scandir( $base . '/taxonomy' ) : false;
		foreach ( false === $taxonomies ? array() : $taxonomies as $taxonomy ) {
			if ( '.' !== $taxonomy[0] ) {
				yield 'taxonomy/' . $taxonomy . '/' . $last . '.md';
			}
		}
	}

	/**
	 * Read the scalar fields of a file's YAML front matter.
	 *
	 * Only top-level `key: value` lines are read, which is all resolve() needs.
	 * The file is read a line at a time and closed at the end of the front
	 * matter, so the body is never read.
	 *
	 * @since  1.9.0
	 * @param  string $file Absolute path.
	 * @return array<string, string>|null Null when the file has no complete front matter.
	 */
	private static function front_matter( string $file ): ?array {
		// A directory or unreadable file is simply not a match, so the warning is silenced.
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return null;
		}

		$fields = null;
		$read   = 0;
		// On a directory fopen() succeeds and the first read fails with a warning.
		$line = @fgets( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $line && '---' === rtrim( $line, "\r\n" ) ) {
			$found = array();
			while ( false !== ( $line = fgets( $handle ) ) && $read < self::FRONT_MATTER_MAX_BYTES ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$read += strlen( $line );
				$line  = rtrim( $line, "\r\n" );
				if ( '---' === $line ) {
					$fields = $found;
					break;
				}
				if ( preg_match( '/^([a-z_]+):[ \t]*(.*?)[ \t]*$/', $line, $m ) && '' !== $m[2] ) {
					$value          = $m[2];
					$quoted         = strlen( $value ) > 1 && '"' === $value[0] && '"' === substr( $value, -1 );
					$found[ $m[1] ] = $quoted ? stripcslashes( substr( $value, 1, -1 ) ) : $value;
				}
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $fields;
	}

	/**
	 * Whether a full request has checked the guarded filters and found none
	 * hooked by regular plugins or the theme.
	 *
	 * @since  1.9.0
	 */
	public static function guard_is_clear(): bool {
		$guard = get_option( self::GUARD_OPTION );

		return is_array( $guard ) && isset( $guard['hooked'] ) && array() === $guard['hooked'];
	}

	/**
	 * Count the callbacks on each guarded filter.
	 *
	 * @since  1.9.0
	 * @return array<string, int>
	 */
	public static function callback_counts(): array {
		global $wp_filter;

		$counts = array();
		foreach ( self::GUARDED_FILTERS as $hook ) {
			$count = 0;
			if ( isset( $wp_filter[ $hook ] ) && is_object( $wp_filter[ $hook ] ) ) {
				foreach ( (array) $wp_filter[ $hook ]->callbacks as $callbacks ) {
					$count += count( (array) $callbacks );
				}
			}
			$counts[ $hook ] = $count;
		}

		return $counts;
	}

	/**
	 * Record which guarded filters gained callbacks after must-use plugins.
	 *
	 * Runs at wp_loaded on front-end requests that load fully, once regular
	 * plugins, the theme and init have run. Callbacks added by
	 * must-use plugins were already counted in $before, so they do not block
	 * early serving: they are loaded on early requests too. Writes the option
	 * only when the list changes.
	 *
	 * A callback added after wp_loaded, for example inside a template_redirect
	 * callback, is not seen. The documentation says so.
	 *
	 * @since  1.9.0
	 * @param  array<string, int> $before Counts taken at muplugins_loaded.
	 */
	public static function record_guard( array $before ): void {
		$hooked = array();
		foreach ( self::callback_counts() as $hook => $count ) {
			if ( $count > ( $before[ $hook ] ?? 0 ) ) {
				$hooked[] = $hook;
			}
		}

		$current = get_option( self::GUARD_OPTION );
		if ( is_array( $current ) && ( $current['hooked'] ?? null ) === $hooked ) {
			return;
		}

		update_option(
			self::GUARD_OPTION,
			array(
				'hooked'  => $hooked,
				'checked' => time(),
			),
			true
		);
	}
}

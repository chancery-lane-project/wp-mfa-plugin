<?php

declare(strict_types=1);

/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Only stubs needed for the classes under test are defined here.
 * Add stubs as needed when writing new tests.
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}

if (!defined('WP_MFA_PLUGIN_URL')) {
    define('WP_MFA_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-markdown-for-agents/');
}

if (!defined('WP_MFA_VERSION')) {
    define('WP_MFA_VERSION', '1.0.0-test');
}

// ---------------------------------------------------------------------------
// Hook tracking
// ---------------------------------------------------------------------------

/** @var array<string, list<array{component: object, callback: string, priority: int}>> */
$GLOBALS['_mock_actions'] = [];
/** @var array<string, list<array{component: object, callback: string, priority: int}>> */
$GLOBALS['_mock_filters'] = [];
/** @var array<string, mixed> */
$GLOBALS['_mock_options'] = [];

$GLOBALS['_mock_json_response']      = null;
$GLOBALS['_mock_enqueued_scripts']   = [];
$GLOBALS['_mock_localized_scripts']  = [];
$GLOBALS['_mock_wp_query']           = null;

function reset_mock_hooks(): void {
    $GLOBALS['_mock_actions'] = [];
    $GLOBALS['_mock_filters'] = [];
}

function reset_mock_options(): void {
    $GLOBALS['_mock_options'] = [];
}

/** @return array<string, mixed> */
function get_mock_actions(): array {
    return $GLOBALS['_mock_actions'];
}

/** @return array<string, mixed> */
function get_mock_filters(): array {
    return $GLOBALS['_mock_filters'];
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable|array $callback, int $priority = 10, int $accepted_args = 1): bool {
        $GLOBALS['_mock_actions'][$hook][] = compact('callback', 'priority', 'accepted_args');
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable|array $callback, int $priority = 10, int $accepted_args = 1): bool {
        $GLOBALS['_mock_filters'][$hook][] = compact('callback', 'priority', 'accepted_args');
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        // Per-test override: $GLOBALS['_mock_apply_filters']['hook'] = fn($val, ...$args) => $modified
        if ( isset( $GLOBALS['_mock_apply_filters'][ $hook ] ) ) {
            $cb = $GLOBALS['_mock_apply_filters'][ $hook ];
            return $cb( $value, ...$args );
        }
        // Fallback: transparent passthrough for all unregistered hooks.
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void {
        // No-op in tests.
    }
}

// ---------------------------------------------------------------------------
// Options API
// ---------------------------------------------------------------------------

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed {
        return $GLOBALS['_mock_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value): bool {
        $GLOBALS['_mock_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $option, mixed $value): bool {
        if (!isset($GLOBALS['_mock_options'][$option])) {
            $GLOBALS['_mock_options'][$option] = $value;
            return true;
        }
        return false;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool {
        unset($GLOBALS['_mock_options'][$option]);
        return true;
    }
}

// ---------------------------------------------------------------------------
// Post / taxonomy mocks (configurable via $GLOBALS)
// ---------------------------------------------------------------------------

/** @var array<int, array<string, mixed>> */
$GLOBALS['_mock_terms']     = [];
$GLOBALS['_mock_permalink'] = 'https://example.com/test-post/';
$GLOBALS['_mock_post_meta'] = [];
$GLOBALS['_mock_thumbnail'] = null;

if (!function_exists('get_the_terms')) {
    function get_the_terms(int $post_id, string $taxonomy): array|false|\WP_Error {
        return $GLOBALS['_mock_terms'][$post_id][$taxonomy] ?? false;
    }
}

if (!function_exists('get_object_taxonomies')) {
    /** @return array<string, object>|string[] */
    function get_object_taxonomies(string $post_type, string $output = 'names'): array {
        return $GLOBALS['_mock_object_taxonomies'][$post_type] ?? [];
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(int|\WP_Post $post): string|false {
        return $GLOBALS['_mock_permalink'];
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.com' . $path;
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return $GLOBALS['_mock_bloginfo'][ $show ] ?? '';
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
        return $GLOBALS['_mock_post_meta'][$post_id][$key] ?? ($single ? '' : []);
    }
}

if (!function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id(int|\WP_Post $post): int|false {
        return $GLOBALS['_mock_thumbnail'] ?? false;
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id): string|false {
        return $GLOBALS['_mock_attachment_url'][$attachment_id] ?? false;
    }
}

// ---------------------------------------------------------------------------
// Filesystem stubs
// ---------------------------------------------------------------------------

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $dir): bool {
        return $GLOBALS['_mock_mkdir_result'] ?? true;
    }
}

if (!function_exists('wp_upload_dir')) {
    /** @return array<string, string> */
    function wp_upload_dir(): array {
        return $GLOBALS['_mock_upload_dir'] ?? [
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'https://example.com/wp-content/uploads',
        ];
    }
}

// ---------------------------------------------------------------------------
// is_singular / queried object stubs
// ---------------------------------------------------------------------------

$GLOBALS['_mock_is_singular'] = false;

if (!function_exists('is_singular')) {
    function is_singular( string|array $post_types = '' ): bool {
        // An empty allowlist means no types are eligible — match real WP behaviour.
        if ( is_array( $post_types ) && empty( $post_types ) ) {
            return false;
        }
        return $GLOBALS['_mock_is_singular'];
    }
}

if (!function_exists('get_queried_object')) {
    function get_queried_object(): mixed {
        return $GLOBALS['_mock_queried_object'] ?? null;
    }
}

// ---------------------------------------------------------------------------
// Misc WordPress helpers
// ---------------------------------------------------------------------------

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $string, bool $remove_breaks = false): string {
        return strip_tags($string);
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string {
        return preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?? $filename;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return strtolower(preg_replace('/[^a-z0-9_-]/', '', $key) ?? $key);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return trim(strip_tags($str));
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $string): string {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('wp_list_pluck')) {
    function wp_list_pluck(array $list, string $field): array {
        return array_column($list, $field);
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string {
        return $url;
    }
}

if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int|\WP_Post $post): int|false {
        return false;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value): int|bool {
        $GLOBALS['_mock_post_meta'][$post_id][$meta_key] = $meta_value;
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $meta_key): bool {
        unset($GLOBALS['_mock_post_meta'][$post_id][$meta_key]);
        return true;
    }
}

if (!function_exists('get_posts')) {
    /** @return \WP_Post[] */
    function get_posts(array $args = []): array {
        return $GLOBALS['_mock_posts'] ?? [];
    }
}

if (!function_exists('get_post')) {
    function get_post(int|\WP_Post|null $post = null): \WP_Post|null {
        if ($post instanceof \WP_Post) {
            return $post;
        }
        return $GLOBALS['_mock_post_objects'][(int) $post] ?? null;
    }
}

// ---------------------------------------------------------------------------
// WP_Post stub
// ---------------------------------------------------------------------------

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_name = '';
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_date = '2025-01-01 12:00:00';
        public string $post_date_gmt = '2025-01-01 12:00:00';
        public string $post_modified = '2025-06-01 12:00:00';
        public string $post_modified_gmt = '2025-06-01 12:00:00';
        public string $post_author = '1';

        public function __construct(array $props = []) {
            foreach ($props as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// WP_Query stub
// ---------------------------------------------------------------------------

if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts       = [];
        public int   $found_posts = 0;

        /**
         * Constructor reads $GLOBALS['_mock_wp_query'] callable.
         * Callable signature: (array $args): array{0: int[], 1: int}
         * Returns [post_id_array, found_posts_count].
         */
        public function __construct(array $args) {
            global $_mock_wp_query;
            if (isset($_mock_wp_query) && is_callable($_mock_wp_query)) {
                [$this->posts, $this->found_posts] = ($_mock_wp_query)($args);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// WP_Error stub
// ---------------------------------------------------------------------------

if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(
            public readonly string $code = '',
            public readonly string $message = ''
        ) {}
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool {
        return $thing instanceof WP_Error;
    }
}

// ---------------------------------------------------------------------------
// Admin / Settings API stubs
// ---------------------------------------------------------------------------

$GLOBALS['_mock_registered_settings']  = [];
$GLOBALS['_mock_settings_sections']    = [];
$GLOBALS['_mock_settings_fields']      = [];
$GLOBALS['_mock_meta_boxes']           = [];
$GLOBALS['_mock_current_user_can']     = true;
$GLOBALS['_mock_transients']           = [];

if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return $GLOBALS['_mock_is_admin'] ?? false;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool {
        return false;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return $GLOBALS['_mock_current_user_can'] ?? true;
    }
}

if (!function_exists('__return_false')) {
    function __return_false(): bool { return false; }
}

if (!function_exists('__return_true')) {
    function __return_true(): bool { return true; }
}

if (!function_exists('register_setting')) {
    function register_setting(string $option_group, string $option_name, array $args = []): void {
        $GLOBALS['_mock_registered_settings'][$option_group][] = $option_name;
    }
}

if (!function_exists('add_settings_section')) {
    function add_settings_section(string $id, string $title, callable $callback, string $page): void {
        $GLOBALS['_mock_settings_sections'][$page][] = $id;
    }
}

if (!function_exists('add_settings_field')) {
    function add_settings_field(string $id, string $title, callable $callback, string $page, string $section = 'default', array $args = []): void {
        $GLOBALS['_mock_settings_fields'][$page][] = $id;
    }
}

if (!function_exists('add_options_page')) {
    function add_options_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback): string {
        return 'settings_page_' . $menu_slug;
    }
}

if (!function_exists('add_meta_box')) {
    function add_meta_box(string $id, string $title, callable $callback, string|array|null $screen = null, string $context = 'advanced', string $priority = 'default', ?array $callback_args = null): void {
        $GLOBALS['_mock_meta_boxes'][] = compact('id', 'title', 'screen', 'context');
    }
}

if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array {
        return $GLOBALS['_mock_post_types'] ?? ['post' => 'post', 'page' => 'page'];
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): ?object {
        $objects = $GLOBALS['_mock_post_type_objects'] ?? [
            'post' => (object) ['name' => 'post', 'label' => 'Posts'],
            'page' => (object) ['name' => 'page', 'label' => 'Pages'],
        ];
        return $objects[$post_type] ?? null;
    }
}

if (!function_exists('settings_fields')) {
    function settings_fields(string $option_group): void {}
}

if (!function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void {}
}

if (!function_exists('submit_button')) {
    function submit_button(?string $text = null): void {}
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true): string {
        return '<input type="hidden" name="' . $name . '" value="test_nonce">';
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action = '-1'): int|false {
        return $GLOBALS['_mock_verify_nonce'] ?? 1;
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action = '-1', string $query_arg = '_wpnonce'): int|false {
        return 1;
    }
}

if (!function_exists('check_ajax_referer')) {
    /**
     * Verifies AJAX nonce. Calls wp_die(-1) if nonce is invalid (and $die is true).
     * Tests control validity via $GLOBALS['_mock_verify_nonce'] (truthy = valid, falsy = invalid).
     */
    function check_ajax_referer(string $action = '-1', string $query_arg = 'nonce', bool $die = true): int|false {
        $valid = $GLOBALS['_mock_verify_nonce'] ?? 1;
        if (!$valid) {
            if ($die) {
                wp_die(-1);
            }
            return false;
        }
        return 1;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string {
        return 'test_nonce_' . $action;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool {
        $GLOBALS['_mock_redirect'] = $location;
        return true;
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string|int $message = '', string $title = '', array|int $args = []): void {
        throw new \RuntimeException('wp_die called: ' . $message);
    }
}

// Note: unlike real WordPress, these stubs do NOT call wp_die() after sending.
// Handlers must explicitly return after calling these, or the test will continue executing.
if (!function_exists('wp_send_json_success')) {
    /**
     * Captures response in $GLOBALS['_mock_json_response'].
     * Shape: ['success' => true, 'data' => mixed]
     */
    function wp_send_json_success(mixed $data = null, int $status_code = 200): void {
        $GLOBALS['_mock_json_response'] = [
            'success' => true,
            'data'    => $data,
            'status'  => $status_code,
        ];
    }
}

if (!function_exists('wp_send_json_error')) {
    /**
     * Captures response in $GLOBALS['_mock_json_response'].
     * Shape: ['success' => false, 'data' => mixed, 'status' => int]
     */
    function wp_send_json_error(mixed $data = null, int $status_code = 0): void {
        $GLOBALS['_mock_json_response'] = [
            'success' => false,
            'data'    => $data,
            'status'  => $status_code,
        ];
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool {
        $GLOBALS['_mock_transients'][$transient] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed {
        return $GLOBALS['_mock_transients'][$transient] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool {
        unset($GLOBALS['_mock_transients'][$transient]);
        return true;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void {
        echo $text;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string {
        return basename(dirname($file)) . '/' . basename($file);
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return trailingslashit(dirname($file));
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string {
        return 'https://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, bool $deprecated = false, string $plugin_rel_path = ''): bool {
        return true;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void {}
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void {}
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void {}
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        return $GLOBALS['_mock_mkdir_result'] ?? true;
    }
}

// ---------------------------------------------------------------------------
// HTTP / output stubs for Negotiator
// ---------------------------------------------------------------------------

$GLOBALS['_mock_headers_sent'] = false;
$GLOBALS['_mock_sent_headers'] = [];
$GLOBALS['_mock_readfile_path'] = null;

if (!function_exists('headers_sent')) {
    function headers_sent(): bool {
        return $GLOBALS['_mock_headers_sent'] ?? false;
    }
}

// ---------------------------------------------------------------------------
// wpdb mock
// ---------------------------------------------------------------------------

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $charset = 'utf8mb4';

        /** @var list<array{query: string, args: list<mixed>}> */
        public array $queries = [];
        /** @var mixed */
        public $last_result = [];
        /** @var mixed */
        public $mock_get_results = [];
        /** @var mixed */
        public $mock_get_var = null;

        public function prepare(string $query, mixed ...$args): string {
            $this->queries[] = ['query' => $query, 'args' => $args];
            $prepared = $query;
            $offset   = 0;
            foreach ($args as $arg) {
                $pos = strpos($prepared, '%', $offset);
                if (false === $pos) {
                    break;
                }
                $replacement = is_string($arg) ? "'" . $arg . "'" : (string) $arg;
                $prepared    = substr($prepared, 0, $pos) . $replacement . substr($prepared, $pos + 2);
                $offset      = $pos + strlen($replacement);
            }
            return $prepared;
        }

        public function query(string $query): int|bool {
            $this->queries[] = ['query' => $query, 'args' => []];
            return true;
        }

        public function get_results(string|null $query = null, string $output = 'OBJECT'): array {
            $this->queries[] = ['query' => $query, 'args' => []];
            return $this->mock_get_results;
        }

        public function get_var(string|null $query = null): mixed {
            $this->queries[] = ['query' => $query, 'args' => []];
            return $this->mock_get_var;
        }

        public function get_charset_collate(): string {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }
}

// ---------------------------------------------------------------------------
// Admin menu stubs
// ---------------------------------------------------------------------------

$GLOBALS['_mock_menu_pages']       = [];
$GLOBALS['_mock_dbdelta_queries']  = [];
$GLOBALS['_mock_post_titles']      = [];

if (!function_exists('add_menu_page')) {
    function add_menu_page(string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null, string $icon_url = '', ?int $position = null): string {
        $GLOBALS['_mock_menu_pages'][$menu_slug] = compact('page_title', 'menu_title', 'capability', 'icon_url', 'position');
        return 'toplevel_page_' . $menu_slug;
    }
}

// ---------------------------------------------------------------------------
// dbDelta stub
// ---------------------------------------------------------------------------

if (!function_exists('dbDelta')) {
    function dbDelta(string|array $queries = '', bool $execute = true): array {
        $GLOBALS['_mock_dbdelta_queries'] = is_array($queries) ? $queries : [$queries];
        return [];
    }
}

// ---------------------------------------------------------------------------
// absint stub
// ---------------------------------------------------------------------------

if (!function_exists('absint')) {
    function absint(mixed $maybeint): int {
        return abs((int) $maybeint);
    }
}

// ---------------------------------------------------------------------------
// get_the_title stub
// ---------------------------------------------------------------------------

if (!function_exists('get_the_title')) {
    function get_the_title(int|\WP_Post $post = 0): string {
        $id = $post instanceof \WP_Post ? $post->ID : $post;
        return $GLOBALS['_mock_post_titles'][$id] ?? 'Post ' . $id;
    }
}

// ---------------------------------------------------------------------------
// Form helper stubs for SettingsPage
// ---------------------------------------------------------------------------

if (!function_exists('checked')) {
    function checked(mixed $helper, mixed $current, bool $echo = true): string {
        $result = $helper === $current ? ' checked="checked"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

// ---------------------------------------------------------------------------
// HTML sanitisation stubs for Admin
// ---------------------------------------------------------------------------

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $data): string {
        return $data;
    }
}

// ---------------------------------------------------------------------------
// Form helper stubs for StatsPage
// ---------------------------------------------------------------------------

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $echo = true): string {
        $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg( string|array $key, mixed $value = null, string $url = '' ): string {
        $pairs = is_array( $key ) ? $key : [ $key => $value ];
        $query = http_build_query( $pairs );
        if ( '' === $url ) {
            return '?' . $query;
        }
        $sep = str_contains( $url, '?' ) ? '&' : '?';
        return $url . $sep . $query;
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string {
        return number_format($number, $decimals);
    }
}

if (!function_exists('remove_query_arg')) {
    function remove_query_arg(string|array $key, string $query = ''): string {
        return $query;
    }
}

// ---------------------------------------------------------------------------
// Script enqueue stubs for Admin::enqueue_scripts()
// ---------------------------------------------------------------------------

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = false): void {
        $GLOBALS['_mock_enqueued_scripts'][$handle] = $src;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $object_name, array $l10n): bool {
        $GLOBALS['_mock_localized_scripts'][$handle] = [
            'object' => $object_name,
            'data'   => $l10n,
        ];
        return true;
    }
}

// ---------------------------------------------------------------------------
// WP_Term stub
// ---------------------------------------------------------------------------

if (!class_exists('WP_Term')) {
    class WP_Term {
        public int    $term_id     = 0;
        public string $name        = '';
        public string $slug        = '';
        public string $taxonomy    = '';
        public string $description = '';
        public int    $count       = 0;

        public function __construct(array $props = []) {
            foreach ($props as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Taxonomy function stubs
// ---------------------------------------------------------------------------

$GLOBALS['_mock_is_tax']         = false;
$GLOBALS['_mock_taxonomies']     = ['category' => 'category', 'post_tag' => 'post_tag'];
$GLOBALS['_mock_post_terms']     = [];
$GLOBALS['_mock_taxonomy_terms'] = [];
$GLOBALS['_mock_term_link']      = [];

if (!function_exists('is_tax')) {
    function is_tax(string $taxonomy = '', int|string|array $term = ''): bool {
        return $GLOBALS['_mock_is_tax'] ?? false;
    }
}

if (!function_exists('is_category')) {
    function is_category(int|string|array $category = ''): bool {
        return $GLOBALS['_mock_is_tax'] ?? false;
    }
}

if (!function_exists('is_tag')) {
    function is_tag(int|string|array $tag = ''): bool {
        return $GLOBALS['_mock_is_tax'] ?? false;
    }
}

if (!function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names'): array {
        return $GLOBALS['_mock_taxonomies'] ?? ['category' => 'category', 'post_tag' => 'post_tag'];
    }
}

if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms(int $post_id, string $taxonomy, array $args = []): array|\WP_Error {
        return $GLOBALS['_mock_post_terms'][$post_id][$taxonomy] ?? [];
    }
}

if (!function_exists('get_terms')) {
    function get_terms(array|string $args = []): array|\WP_Error {
        $taxonomy = is_array($args) ? ($args['taxonomy'] ?? '') : $args;
        return $GLOBALS['_mock_taxonomy_terms'][$taxonomy] ?? [];
    }
}

if (!function_exists('get_term_link')) {
    function get_term_link(\WP_Term|int|string $term, string $taxonomy = ''): string|\WP_Error {
        if ($term instanceof \WP_Term) {
            return $GLOBALS['_mock_term_link'][$term->term_id]
                ?? 'https://example.com/' . $term->taxonomy . '/' . $term->slug . '/';
        }
        return 'https://example.com/term/' . (int) $term . '/';
    }
}

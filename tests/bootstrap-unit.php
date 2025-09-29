<?php
/**
 * Minimal WordPress shims and configuration for unit testing.
 *
 * @package SparxstarUserEnvironmentCheck\Tests
 */

declare(strict_types=1);

/**
 * Attempt to include the Composer autoloader when available.
 */
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
}

/**
 * Registered WordPress-style actions captured during unit tests.
 *
 * @var array<string, array<int, array{callback:mixed, priority:int, accepted_args:int}>>
 */
$GLOBALS['spx_registered_actions'] = array();

/**
 * Registered REST routes captured during unit tests.
 *
 * @var array<int, array{namespace:string, route:string, args:array}>
 */
$GLOBALS['spx_registered_routes'] = array();

/**
 * Headers emitted by header() calls during unit tests.
 *
 * @var array<int, array{header:string, replace:bool}>
 */
$GLOBALS['spx_sent_headers'] = array();

/**
 * Flag indicating whether tests should simulate the WordPress admin area.
 *
 * @var bool
 */
$GLOBALS['spx_is_admin'] = false;

/**
 * Records the last text domain loaded by load_plugin_textdomain().
 *
 * @var array<int, array{text_domain:string, deprecated:bool|string, path:string|false}>
 */
$GLOBALS['spx_loaded_textdomains'] = array();

/**
 * Styles registered through wp_register_style().
 *
 * @var array<string, array{src:string, deps:array<int, string>, ver:string}>
 */
$GLOBALS['spx_registered_styles'] = array();

/**
 * Styles enqueued for output during unit tests.
 *
 * @var array<int, string>
 */
$GLOBALS['spx_enqueued_styles'] = array();

/**
 * Scripts registered through wp_register_script().
 *
 * @var array<string, array{src:string, deps:array<int, string>, ver:string, in_footer:bool}>
 */
$GLOBALS['spx_registered_scripts'] = array();

/**
 * Scripts enqueued for output during unit tests.
 *
 * @var array<int, string>
 */
$GLOBALS['spx_enqueued_scripts'] = array();

/**
 * Localized script payloads captured during unit tests.
 *
 * @var array<string, array{name:string, data:array<mixed>}>
 */
$GLOBALS['spx_localized_scripts'] = array();

/**
 * Database inserts captured by the wpdb shim.
 *
 * @var array<int, array{table:string, data:array<string, mixed>}>
 */
$GLOBALS['spx_db_inserts'] = array();

/**
 * Database queries executed by the wpdb shim.
 *
 * @var array<int, string>
 */
$GLOBALS['spx_db_queries'] = array();

if (!defined('ABSPATH')) {
    /**
     * Placeholder for the WordPress ABSPATH constant required by plugin files.
     */
    define('ABSPATH', '/tmp/');
}

if (!defined('WP_DEBUG')) {
    /**
     * Enables WordPress debug mode for unit testing.
     */
    define('WP_DEBUG', true);
}

if (!defined('WP_DEBUG_LOG')) {
    /**
     * Enables logging while running unit tests.
     */
    define('WP_DEBUG_LOG', true);
}

if (!defined('SPX_ENV_CHECK_PLUGIN_PATH')) {
    /**
     * Absolute path to the plugin root for resolving assets in unit tests.
     */
    define('SPX_ENV_CHECK_PLUGIN_PATH', dirname(__DIR__) . '/');
}

if (!defined('SPX_ENV_CHECK_PLUGIN_FILE')) {
    /**
     * Absolute path to the main plugin file for unit testing hooks.
     */
    define('SPX_ENV_CHECK_PLUGIN_FILE', SPX_ENV_CHECK_PLUGIN_PATH . 'sparxstar-user-environment-check.php');
}

if (!defined('SPX_ENV_CHECK_VERSION')) {
    /**
     * Default plugin version used for cache-busting in unit tests.
     */
    define('SPX_ENV_CHECK_VERSION', '0.0.0-test');
}

if (!defined('SPX_ENV_CHECK_TEXT_DOMAIN')) {
    /**
     * Text domain identifier used during unit tests.
     */
    define('SPX_ENV_CHECK_TEXT_DOMAIN', 'sparxstar-user-environment-check');
}

if (!defined('SPX_ENV_CHECK_DB_TABLE_NAME')) {
    /**
     * Database table name placeholder used by unit tests.
     */
    define('SPX_ENV_CHECK_DB_TABLE_NAME', 'sparxstar_env_snapshots');
}

if (!function_exists('add_action')) {
    /**
     * Mock implementation of add_action() that records hook registrations.
     *
     * @param string   $hook          Hook name being registered.
     * @param callable $callback      Callback to execute.
     * @param int      $priority      Hook priority.
     * @param int      $accepted_args Number of accepted arguments.
     */
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $GLOBALS['spx_registered_actions'][$hook][] = array(
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        );
    }
}

if (!function_exists('add_filter')) {
    /**
     * Stub implementation mirroring add_action() semantics for filters.
     *
     * @param string   $hook          Filter name being registered.
     * @param callable $callback      Callback to execute.
     * @param int      $priority      Hook priority.
     * @param int      $accepted_args Number of accepted arguments.
     */
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        add_action($hook, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('register_rest_route')) {
    /**
     * Mock implementation of register_rest_route() that records REST routes.
     *
     * @param string $namespace REST namespace.
     * @param string $route     Route path.
     * @param array  $args      Route arguments.
     */
    function register_rest_route(string $namespace, string $route, array $args): void
    {
        $GLOBALS['spx_registered_routes'][] = array(
            'namespace' => $namespace,
            'route' => $route,
            'args' => $args,
        );
    }
}

if (!function_exists('load_plugin_textdomain')) {
    /**
     * Records load_plugin_textdomain() invocations for verification.
     *
     * @param string     $domain     Text domain identifier.
     * @param bool       $deprecated Deprecated argument retained for parity.
     * @param string|false $path     Relative path to the language files.
     */
    function load_plugin_textdomain(string $domain, bool $deprecated = false, $path = false): bool
    {
        $GLOBALS['spx_loaded_textdomains'][] = array(
            'text_domain' => $domain,
            'deprecated' => $deprecated,
            'path' => $path,
        );
        return true;
    }
}

if (!function_exists('plugin_basename')) {
    /**
     * Simplified plugin_basename() for unit testing.
     *
     * @param string $file Plugin file path.
     */
    function plugin_basename(string $file): string
    {
        return basename($file);
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * Minimal sanitize_text_field() equivalent for unit testing.
     *
     * @param string $value Raw value to sanitize.
     */
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('esc_html')) {
    /**
     * Minimal esc_html() equivalent backed by htmlspecialchars().
     *
     * @param string $value Raw value to escape.
     */
    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('is_admin')) {
    /**
     * Indicates whether the simulated environment is in the admin area.
     */
    function is_admin(): bool
    {
        return (bool) $GLOBALS['spx_is_admin'];
    }
}

if (!function_exists('header')) {
    /**
     * Records header() calls for later assertions.
     *
     * @param string $header Header string.
     * @param bool   $replace Whether to replace existing header.
     */
    function header(string $header, bool $replace = true): void
    {
        $GLOBALS['spx_sent_headers'][] = array(
            'header' => $header,
            'replace' => $replace,
        );
    }
}

if (!function_exists('rest_url')) {
    /**
     * Generates a deterministic REST URL for unit tests.
     *
     * @param string $path REST path fragment.
     */
    function rest_url(string $path = ''): string
    {
        return 'https://example.test/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    /**
     * Deterministic nonce generator for unit tests.
     *
     * @param string $action Action name.
     */
    function wp_create_nonce(string $action): string
    {
        return hash('sha256', $action);
    }
}

if (!function_exists('esc_html__')) {
    /**
     * Simplified translation helper returning escaped text.
     *
     * @param string $text Text to translate.
     * @param string $domain Translation domain.
     */
    function esc_html__(string $text, string $domain): string
    {
        return esc_html($text . '|' . $domain);
    }
}

if (!function_exists('esc_url_raw')) {
    /**
     * Identity implementation for esc_url_raw().
     *
     * @param string $url URL to sanitize.
     */
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (!function_exists('trailingslashit')) {
    /**
     * Ensures a path ends with a trailing slash.
     *
     * @param string $value Path to normalize.
     */
    function trailingslashit(string $value): string
    {
        return rtrim($value, "/\\") . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    /**
     * Resolves a pseudo plugin directory URL for assets.
     *
     * @param string $file Plugin file path.
     */
    function plugin_dir_url(string $file): string
    {
        return 'https://example.test/wp-content/plugins/sparxstar-user-environment-check/';
    }
}

if (!function_exists('wp_register_style')) {
    /**
     * Stubbed wp_register_style() for unit testing.
     *
     * @param string $handle Handle name.
     * @param string $src    Stylesheet source.
     * @param array  $deps   Dependencies.
     * @param string $ver    Version string.
     */
    function wp_register_style(string $handle, string $src, array $deps = array(), string $ver = ''): void
    {
        $GLOBALS['spx_registered_styles'][$handle] = compact('src', 'deps', 'ver');
    }
}

if (!function_exists('wp_enqueue_style')) {
    /**
     * Records enqueued styles during unit tests.
     *
     * @param string $handle Handle to enqueue.
     */
    function wp_enqueue_style(string $handle): void
    {
        $GLOBALS['spx_enqueued_styles'][] = $handle;
    }
}

if (!function_exists('wp_register_script')) {
    /**
     * Stubbed wp_register_script() for unit testing.
     *
     * @param string $handle Script handle.
     * @param string $src    Script source URL.
     * @param array  $deps   Script dependencies.
     * @param string $ver    Version string.
     * @param bool   $in_footer Whether to load in footer.
     */
    function wp_register_script(string $handle, string $src, array $deps = array(), string $ver = '', bool $in_footer = false): void
    {
        $GLOBALS['spx_registered_scripts'][$handle] = compact('src', 'deps', 'ver', 'in_footer');
    }
}

if (!function_exists('wp_enqueue_script')) {
    /**
     * Records enqueued scripts during unit tests.
     *
     * @param string $handle Handle to enqueue.
     */
    function wp_enqueue_script(string $handle): void
    {
        $GLOBALS['spx_enqueued_scripts'][] = $handle;
    }
}

if (!function_exists('wp_localize_script')) {
    /**
     * Stores localization data registered for a script.
     *
     * @param string $handle Script handle.
     * @param string $name   Object name exposed to JavaScript.
     * @param array  $data   Data passed to the script.
     */
    function wp_localize_script(string $handle, string $name, array $data): void
    {
        $GLOBALS['spx_localized_scripts'][$handle] = compact('name', 'data');
    }
}

if (!function_exists('get_current_user_id')) {
    /**
     * Returns a deterministic user identifier for unit testing.
     */
    function get_current_user_id(): int
    {
        return 1;
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * Thin wrapper around json_encode mirroring WordPress' helper.
     *
     * @param mixed $data Data to encode.
     */
    function wp_json_encode($data): string
    {
        return (string) json_encode($data, JSON_THROW_ON_ERROR);
    }
}

if (!function_exists('current_time')) {
    /**
     * Returns an ISO8601 timestamp for deterministic testing.
     *
     * @param string $type  Requested format (ignored).
     * @param bool   $gmt   Whether GMT was requested.
     */
    function current_time(string $type, bool $gmt = false): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Minimal WP_REST_Request stub for unit testing.
     */
    class WP_REST_Request
    {
        /**
         * Stored route parameters.
         *
         * @var array<string, mixed>
         */
        private array $params = array();

        /**
         * Retrieve parameters previously assigned via set_param().
         *
         * @return array<string, mixed>
         */
        public function get_json_params(): array
        {
            return $this->params;
        }

        /**
         * Assign a parameter for retrieval.
         *
         * @param string $key   Parameter name.
         * @param mixed  $value Parameter value.
         */
        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * Minimal WP_REST_Response stub for unit testing.
     */
    class WP_REST_Response
    {
        /**
         * Response payload.
         *
         * @var array<mixed>
         */
        public array $data;

        /**
         * HTTP status code for the response.
         */
        public int $status;

        /**
         * Instantiate a response representation.
         *
         * @param array<mixed> $data   Response body.
         * @param int          $status HTTP status code.
         */
        public function __construct(array $data, int $status)
        {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

if (!isset($GLOBALS['wpdb'])) {
    /**
     * Minimal wpdb stand-in used by the REST API class during unit tests.
     */
    $GLOBALS['wpdb'] = new class {
        /**
         * Database table prefix.
         */
        public string $base_prefix = 'wp_';

        /**
         * Returns a prepared SQL string.
         *
         * @param string $query Query template.
         * @param mixed  ...$args Values to substitute.
         */
        public function prepare(string $query, ...$args): string
        {
            return vsprintf($query, $args);
        }

        /**
         * Mock insert implementation that records inserted rows.
         *
         * @param string $table Table name.
         * @param array  $data  Data to insert.
         */
        public function insert(string $table, array $data): void
        {
            $GLOBALS['spx_db_inserts'][] = compact('table', 'data');
        }

        /**
         * Mock retrieval that always returns null to simulate an empty table.
         *
         * @param string $query SQL query.
         */
        public function get_row(string $query)
        {
            $GLOBALS['spx_db_queries'][] = $query;
            return null;
        }
    };
}

<?php
/**
 * PHPUnit bootstrap for unit tests without a full WordPress stack.
 *
 * This loader wires up Composer's autoloader, defines the plugin constants
 * that production code expects, and registers lightweight shims for the small
 * subset of WordPress functions referenced by the isolated unit tests.
 *
 * @package Starisian\SparxstarUEC\Tests
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(
        /**
         * Minimal PSR-4 loader for the plugin namespace when Composer is unavailable.
         *
         * @param string $class Fully-qualified class name.
         * @return void
         */
        static function (string $class): void {
            $prefix = 'Starisian\\SparxstarUEC\\';
            if (str_starts_with($class, $prefix) === false) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($path)) {
                require $path;
            }
        }
    );
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('SPX_ENV_CHECK_PLUGIN_FILE')) {
    define('SPX_ENV_CHECK_PLUGIN_FILE', dirname(__DIR__) . '/sparxstar-user-environment-check.php');
}

if (!defined('SPX_ENV_CHECK_PLUGIN_PATH')) {
    define('SPX_ENV_CHECK_PLUGIN_PATH', dirname(__DIR__) . '/');
}

if (!defined('SPX_ENV_CHECK_VERSION')) {
    define('SPX_ENV_CHECK_VERSION', 'test');
}

if (!defined('SPX_ENV_CHECK_TEXT_DOMAIN')) {
    define('SPX_ENV_CHECK_TEXT_DOMAIN', 'sparxstar-user-environment-check');
}

if (!defined('SPX_ENV_CHECK_DB_TABLE_NAME')) {
    define('SPX_ENV_CHECK_DB_TABLE_NAME', 'sparxstar_env_snapshots');
}

/**
 * In-memory cache store used by the WordPress cache shims.
 *
 * @var array<string, array<string, mixed>>
 */
$GLOBALS['wp_cache_store'] = $GLOBALS['wp_cache_store'] ?? [];

/**
 * Registry used by the action shim to capture hooks.
 *
 * @var array<string, array<int, array<string, mixed>>>
 */
$GLOBALS['registered_actions'] = $GLOBALS['registered_actions'] ?? [];

if (!function_exists('plugin_dir_path')) {
    /**
     * Shimmed version of plugin_dir_path for the unit test environment.
     *
     * @param string $file Absolute path to a file inside the plugin.
     * @return string Normalised directory path ending with a slash.
     */
    function plugin_dir_path(string $file): string
    {
        return rtrim(dirname($file), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('plugin_basename')) {
    /**
     * Shimmed version of plugin_basename mirroring WordPress behaviour.
     *
     * @param string $file Absolute path to the plugin file.
     * @return string Plugin basename composed of directory and file name.
     */
    function plugin_basename(string $file): string
    {
        return ltrim(str_replace(dirname($file) . DIRECTORY_SEPARATOR, '', $file), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('load_plugin_textdomain')) {
    /**
     * Stubbed translation loader that simply records the request.
     *
     * @param string      $domain Text domain to load.
     * @param bool        $deprecated Unused legacy parameter.
     * @param string|bool $plugin_rel_path Optional relative path.
     * @return bool       Always true in the test environment.
     */
    function load_plugin_textdomain(string $domain, bool $deprecated = false, $plugin_rel_path = false): bool
    {
        $GLOBALS['loaded_textdomains'][] = [$domain, $plugin_rel_path];
        return true;
    }
}

if (!function_exists('is_admin')) {
    /**
     * Indicates whether the current request targets the WordPress admin area.
     * Always returns false in unit tests so that admin hooks are skipped.
     *
     * @return bool False to keep the environment minimal.
     */
    function is_admin(): bool
    {
        return false;
    }
}

if (!function_exists('esc_html')) {
    /**
     * Basic HTML-escaping helper mirroring WordPress' esc_html.
     *
     * @param string $text Text to escape.
     * @return string Escaped text safe for output.
     */
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    /**
     * Translation helper that proxies to the esc_html shim for tests.
     *
     * @param string $text   Text to translate.
     * @param string $domain Optional text domain.
     * @return string        Escaped translation string.
     */
    function esc_html__(string $text, string $domain = ''): string
    {
        unset($domain);
        return esc_html($text);
    }
}

if (!function_exists('esc_html_e')) {
    /**
     * Echoing translation helper mirroring WordPress' esc_html_e.
     *
     * @param string $text   Text to echo.
     * @param string $domain Optional text domain.
     * @return void
     */
    function esc_html_e(string $text, string $domain = ''): void
    {
        echo esc_html__($text, $domain);
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * Minimal sanitiser matching the WordPress helper used by production code.
     *
     * @param string $value Raw input value.
     * @return string Trimmed and stripped version suitable for tests.
     */
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('get_current_user_id')) {
    /**
     * Shim for get_current_user_id returning zero in the isolated environment.
     *
     * @return int Simulated user identifier.
     */
    function get_current_user_id(): int
    {
        return 0;
    }
}

if (!function_exists('wp_cache_get')) {
    /**
     * Minimal stand-in for wp_cache_get using the in-memory store.
     *
     * @param string $key   Cache key to retrieve.
     * @param string $group Cache group namespace.
     * @return mixed        Stored value or false when no cache entry exists.
     */
    function wp_cache_get(string $key, string $group = ''): mixed
    {
        return $GLOBALS['wp_cache_store'][$group][$key] ?? false;
    }
}

if (!function_exists('wp_cache_set')) {
    /**
     * Minimal stand-in for wp_cache_set using the in-memory store.
     *
     * @param string $key     Cache key to store.
     * @param mixed  $value   Arbitrary value to persist.
     * @param string $group   Cache group namespace.
     * @param int    $timeout Ignored timeout parameter kept for signature parity.
     * @return bool           Whether the value was stored.
     */
    function wp_cache_set(string $key, mixed $value, string $group = '', int $timeout = 0): bool
    {
        if (!isset($GLOBALS['wp_cache_store'][$group])) {
            $GLOBALS['wp_cache_store'][$group] = [];
        }

        $GLOBALS['wp_cache_store'][$group][$key] = $value;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    /**
     * Minimal stand-in for wp_cache_delete using the in-memory store.
     *
     * @param string $key   Cache key to delete.
     * @param string $group Cache group namespace.
     * @return bool         Whether the key existed prior to deletion.
     */
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        if (!isset($GLOBALS['wp_cache_store'][$group][$key])) {
            return false;
        }

        unset($GLOBALS['wp_cache_store'][$group][$key]);
        return true;
    }
}

if (!function_exists('add_action')) {
    /**
     * Test shim for add_action that records callbacks without executing them.
     *
     * @param string   $hook     Name of the hook being registered.
     * @param callable $callback Callback to run when the hook fires.
     * @param int      $priority Execution priority.
     * @param int      $args     Number of accepted arguments.
     * @return bool              Always true for testing.
     */
    function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        $GLOBALS['registered_actions'][$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'args' => $args,
        ];
        return true;
    }
}

if (!function_exists('register_activation_hook')) {
    /**
     * Shim for register_activation_hook retained for parity with the plugin bootstrap.
     *
     * @param string   $file     Main plugin file path.
     * @param callable $callback Activation callback.
     * @return void
     */
    function register_activation_hook(string $file, callable $callback): void
    {
        $GLOBALS['registered_activation_hook'] = ['file' => $file, 'callback' => $callback];
    }
}

if (!function_exists('register_deactivation_hook')) {
    /**
     * Shim for register_deactivation_hook retained for parity with the plugin bootstrap.
     *
     * @param string   $file     Main plugin file path.
     * @param callable $callback Deactivation callback.
     * @return void
     */
    function register_deactivation_hook(string $file, callable $callback): void
    {
        $GLOBALS['registered_deactivation_hook'] = ['file' => $file, 'callback' => $callback];
    }
}

if (!class_exists('wpdb')) {
    /**
     * Lightweight stand-in for WordPress' wpdb class.
     */
    class wpdb
    {
        /**
         * Simulated base prefix used when composing table names.
         *
         * @var string
         */
        public string $base_prefix = 'wp_';

        /**
         * Logged queries executed via the insert helper.
         *
         * @var array<int, array{table: string, data: array<string, mixed>}>
         */
        public array $queries = [];

        /**
         * Mimic WordPress' insert helper by capturing the provided arguments.
         *
         * @param string               $table Table name being written to.
         * @param array<string, mixed> $data  Data payload to store.
         * @return bool                        True on success.
         */
        public function insert(string $table, array $data): bool
        {
            $this->queries[] = ['table' => $table, 'data' => $data];
            return true;
        }
    }
}

$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new wpdb();

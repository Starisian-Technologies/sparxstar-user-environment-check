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
    define('SPX_ENV_CHECK_DB_TABLE_NAME', 'sparxstar_uec_snapshots');
}

/**
 * In-memory option store keyed by blog ID.
 *
 * @var array<int, array<string, mixed>>
 */
$GLOBALS['wp_options'] = $GLOBALS['wp_options'] ?? [];

/**
 * In-memory transient store used by WordPress transient shims.
 *
 * @var array<string, mixed>
 */
$GLOBALS['wp_transients'] = $GLOBALS['wp_transients'] ?? [];

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

/**
 * Registry of scheduled hooks captured by cron shims.
 *
 * @var array<string, array<int, array<string, mixed>>>
 */
$GLOBALS['scheduled_hooks'] = $GLOBALS['scheduled_hooks'] ?? [];

/**
 * Collection of SQL statements passed to dbDelta for assertions.
 *
 * @var array<int, string>
 */
$GLOBALS['dbDelta_queries'] = $GLOBALS['dbDelta_queries'] ?? [];

/**
 * Track blog IDs switched to during multisite tests.
 *
 * @var array<int, int>
 */
$GLOBALS['switched_blogs'] = $GLOBALS['switched_blogs'] ?? [];

/**
 * Track the current blog context for multisite stubs.
 *
 * @var int
 */
$GLOBALS['current_blog_id'] = $GLOBALS['current_blog_id'] ?? 1;

/**
 * Registry used by the register_rest_route shim to capture routes for assertions.
 *
 * @var array<int, array<string, mixed>>
 */
$GLOBALS['spx_registered_routes'] = $GLOBALS['spx_registered_routes'] ?? [];

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

if (!function_exists('apply_filters')) {
    /**
     * Basic filter shim that returns the provided value unchanged.
     *
     * @param string $hook_name Hook name.
     * @param mixed  $value     Value to filter.
     * @return mixed Unaltered value to keep tests predictable.
     */
    function apply_filters(string $hook_name, mixed $value): mixed
    {
        unset($hook_name);
        return $value;
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

if (!function_exists('is_super_admin')) {
    /**
     * Stubbed super admin check used for multisite lifecycle handlers.
     *
     * @return bool True by default to allow network operations in tests.
     */
    function is_super_admin(): bool
    {
        return true;
    }
}

if (!function_exists('is_multisite')) {
    /**
     * Indicates whether the multisite flag is set for the test run.
     *
     * @return bool Controlled via the global __is_multisite flag.
     */
    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['__is_multisite'] ?? false);
    }
}

if (!function_exists('get_sites')) {
    /**
     * Return the synthetic site list used in multisite tests.
     *
     * @param array<string, mixed> $args Optional query arguments.
     * @return array<int, object> Sites with blog_id properties.
     */
    function get_sites(array $args = []): array
    {
        unset($args);
        return $GLOBALS['__sites'] ?? [];
    }
}

if (!function_exists('switch_to_blog')) {
    /**
     * Switch the global blog context for multisite simulations.
     *
     * @param int $blog_id Target site ID.
     * @return void
     */
    function switch_to_blog(int $blog_id): void
    {
        $GLOBALS['switched_blogs'][] = $blog_id;
        $GLOBALS['current_blog_id'] = $blog_id;
        $GLOBALS['wpdb']->prefix = $blog_id === 1 ? $GLOBALS['wpdb']->base_prefix : $GLOBALS['wpdb']->base_prefix . $blog_id . '_';
    }
}

if (!function_exists('restore_current_blog')) {
    /**
     * Restore the blog context back to the primary site.
     *
     * @return void
     */
    function restore_current_blog(): void
    {
        $GLOBALS['current_blog_id'] = 1;
        $GLOBALS['wpdb']->prefix    = $GLOBALS['wpdb']->base_prefix;
    }
}

if (!class_exists('WP_Site')) {
    /**
     * Minimal stand-in for WordPress' WP_Site object.
     */
    class WP_Site
    {
        /**
         * Unique identifier for the site.
         *
         * @var int
         */
        public int $blog_id;

        /**
         * @param array<string, int> $properties Site properties.
         */
        public function __construct(array $properties)
        {
            $this->blog_id = (int) ($properties['blog_id'] ?? 0);
        }
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

if (!function_exists('current_user_can')) {
    /**
     * Capability check shim for uninstall guard logic.
     *
     * @param string $capability Requested capability.
     * @return bool True by default for deterministic tests.
     */
    function current_user_can(string $capability): bool
    {
        unset($capability);
        return true;
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

if (!function_exists('wp_json_encode')) {
    /**
     * JSON encoder shim mirroring WordPress' helper.
     *
     * @param mixed $data Data to encode.
     * @return string JSON representation.
     */
    function wp_json_encode(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
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

if (!function_exists('wp_cache_flush')) {
    /**
     * Flush the entire cache store for the current test run.
     *
     * @return bool True on completion.
     */
    function wp_cache_flush(): bool
    {
        $GLOBALS['wp_cache_store'] = [];
        return true;
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

if (!function_exists('register_uninstall_hook')) {
    /**
     * Shim for register_uninstall_hook retained for parity with the plugin bootstrap.
     *
     * @param string   $file     Main plugin file path.
     * @param callable $callback Uninstall callback.
     * @return void
     */
    function register_uninstall_hook(string $file, callable $callback): void
    {
        $GLOBALS['registered_uninstall_hook'] = ['file' => $file, 'callback' => $callback];
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
         * Active prefix reflecting the current blog context.
         *
         * @var string
         */
        public string $prefix = 'wp_';

        /**
         * Logged queries executed via the insert helper.
         *
         * @var array<int, array{table: string, data: array<string, mixed>}>
         */
        public array $queries = [];

        /**
         * Placeholder for the most recent insert identifier.
         *
         * @var int
         */
        public int $insert_id = 0;

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
            $this->insert_id++;
            return true;
        }

        /**
         * Mimic WordPress' update helper by capturing the provided arguments.
         *
         * @param string               $table Table name.
         * @param array<string, mixed> $data  Data payload to update.
         * @param array<string, mixed> $where Where clause.
         * @return bool                        True on success.
         */
        public function update(string $table, array $data, array $where): bool
        {
            $this->queries[] = ['table' => $table, 'data' => $data, 'where' => $where];
            return true;
        }

        /**
         * Mimic WordPress' query helper by recording the SQL string.
         *
         * @param string $query SQL string.
         * @return bool         True after logging.
         */
        public function query(string $query): bool
        {
            $this->queries[] = ['query' => $query];
            return true;
        }

        /**
         * Return a consistent charset collate string.
         *
         * @return string Charset statement.
         */
        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        /**
         * Basic prepare helper replacing placeholders with provided values.
         *
         * @param string $query SQL with placeholders.
         * @param mixed  ...$args Values to interpolate.
         * @return string Prepared SQL.
         */
        public function prepare(string $query, ...$args): string
        {
            foreach ($args as $arg) {
                $safe = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
                $query = preg_replace('/%[sd]/', $safe, $query, 1) ?? $query;
            }
            return $query;
        }

        /**
         * Stubbed getter for a single scalar result.
         *
         * @param string $query SQL string.
         * @return int|null     Null for absent rows.
         */
        public function get_var(string $query): ?int
        {
            $this->queries[] = ['query' => $query];
            return null;
        }

        /**
         * Stubbed getter for a row result.
         *
         * @param string     $query  SQL string.
         * @param string|int $output Output type (unused).
         * @return array<string, mixed>|null Null to indicate no results.
         */
        public function get_row(string $query, string|int $output = ARRAY_A): ?array
        {
            unset($output);
            $this->queries[] = ['query' => $query];
            return null;
        }
    }
}

$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new wpdb();

if (!function_exists('wp_next_scheduled')) {
    /**
     * Inspect the scheduled hooks registry for the next matching occurrence.
     *
     * @param string $hook Hook name.
     * @param array  $args Arguments for the event.
     * @return int|null Timestamp when scheduled or null if missing.
     */
    function wp_next_scheduled(string $hook, array $args = []): ?int
    {
        $blog_id = $GLOBALS['current_blog_id'] ?? 1;
        $hash    = md5($blog_id . '|' . $hook . serialize($args));
        return $GLOBALS['scheduled_hooks'][$hash]['timestamp'] ?? null;
    }
}

if (!function_exists('wp_schedule_event')) {
    /**
     * Record a scheduled hook in the in-memory registry.
     *
     * @param int    $timestamp When to run.
     * @param string $recurrence Recurrence key.
     * @param string $hook Hook name.
     * @param array  $args Arguments to pass.
     * @return bool  True after recording.
     */
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        $blog_id = $GLOBALS['current_blog_id'] ?? 1;
        $hash    = md5($blog_id . '|' . $hook . serialize($args));
        $GLOBALS['scheduled_hooks'][$hash] = [
            'blog_id' => $blog_id,
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'hook' => $hook,
            'args' => $args,
        ];
        return true;
    }
}

if (!function_exists('wp_unschedule_event')) {
    /**
     * Remove a scheduled hook instance from the registry.
     *
     * @param int    $timestamp Timestamp for the event.
     * @param string $hook Hook name.
     * @param array  $args Arguments used when scheduling.
     * @return bool  True when removed.
     */
    function wp_unschedule_event(int $timestamp, string $hook, array $args = []): bool
    {
        $blog_id = $GLOBALS['current_blog_id'] ?? 1;
        $hash    = md5($blog_id . '|' . $hook . serialize($args));
        unset($GLOBALS['scheduled_hooks'][$hash]);
        unset($timestamp);
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    /**
     * Remove all scheduled occurrences of a hook.
     *
     * @param string $hook Hook name.
     * @return bool  True when cleared.
     */
    function wp_clear_scheduled_hook(string $hook): bool
    {
        foreach (array_keys($GLOBALS['scheduled_hooks']) as $hash) {
            if (isset($GLOBALS['scheduled_hooks'][$hash]['hook']) && $GLOBALS['scheduled_hooks'][$hash]['hook'] === $hook) {
                unset($GLOBALS['scheduled_hooks'][$hash]);
            }
        }
        return true;
    }
}

if (!function_exists('as_next_scheduled_action')) {
    /**
     * Stub for Action Scheduler check used by the scheduler helper.
     *
     * @param string $hook Hook name.
     * @param array  $args Arguments.
     * @return bool  False to force scheduling.
     */
    function as_next_scheduled_action(string $hook, array $args = []): bool
    {
        unset($hook, $args);
        return false;
    }
}

if (!function_exists('as_schedule_recurring_action')) {
    /**
     * Stub for Action Scheduler scheduling used by the scheduler helper.
     *
     * @param int    $timestamp When to run.
     * @param int    $interval Interval in seconds.
     * @param string $hook Hook name.
     * @param array  $args Arguments.
     * @return bool  True after recording.
     */
    function as_schedule_recurring_action(int $timestamp, int $interval, string $hook, array $args = []): bool
    {
        $blog_id = $GLOBALS['current_blog_id'] ?? 1;
        $hash    = md5($blog_id . '|' . $hook . serialize($args));
        $GLOBALS['scheduled_hooks'][$hash] = [
            'blog_id' => $blog_id,
            'timestamp' => $timestamp,
            'interval' => $interval,
            'hook' => $hook,
            'args' => $args,
        ];
        return true;
    }
}

if (!function_exists('as_unschedule_all_actions')) {
    /**
     * Stub for clearing Action Scheduler events.
     *
     * @param string $hook Hook name.
     * @param array  $args Arguments.
     * @return void
     */
    function as_unschedule_all_actions(string $hook, array $args = []): void
    {
        foreach (array_keys($GLOBALS['scheduled_hooks']) as $hash) {
            if (isset($GLOBALS['scheduled_hooks'][$hash]['hook']) && $GLOBALS['scheduled_hooks'][$hash]['hook'] === $hook) {
                unset($GLOBALS['scheduled_hooks'][$hash]);
            }
        }
        unset($args);
    }
}

if (!function_exists('dbDelta')) {
    /**
     * Capture dbDelta SQL statements for assertions.
     *
     * @param string $sql SQL statement.
     * @return array<int, mixed> Empty array placeholder.
     */
    function dbDelta(string $sql): array
    {
        $GLOBALS['dbDelta_queries'][] = $sql;
        return [];
    }
}

if (!function_exists('get_option')) {
    /**
     * Retrieve an option value from the in-memory store.
     *
     * @param string $name Option name.
     * @param mixed  $default Default value.
     * @return mixed Stored value or default.
     */
    function get_option(string $name, mixed $default = false): mixed
    {
        $blog_id = $GLOBALS['current_blog_id'] ?? 1;
        return $GLOBALS['wp_options'][$blog_id][$name] ?? $default;
    }
}

if (!function_exists('add_option')) {
    /**
     * Add an option to the in-memory store if it does not exist.
     *
     * @param string $name Option name.
     * @param mixed  $value Option value.
     * @return bool True when added or already exists.
     */
    function add_option(string $name, mixed $value): bool
    {
        $blog_id = $GLOBALS['current_blog_id'] ?? 1;
        if (isset($GLOBALS['wp_options'][$blog_id][$name])) {
            return true;
        }
        $GLOBALS['wp_options'][$blog_id][$name] = $value;
        return true;
    }
}

if (!function_exists('update_option')) {
    /**
     * Update an option in the in-memory store.
     *
     * @param string $name Option name.
     * @param mixed  $value Option value.
     * @return bool True after storing.
     */
    function update_option(string $name, mixed $value): bool
    {
        $blog_id                              = $GLOBALS['current_blog_id'] ?? 1;
        $GLOBALS['wp_options'][$blog_id][$name] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    /**
     * Delete an option from the in-memory store.
     *
     * @param string $name Option name.
     * @return bool True when removed.
     */
    function delete_option(string $name): bool
    {
        $blog_id = $GLOBALS['current_blog_id'] ?? 1;
        unset($GLOBALS['wp_options'][$blog_id][$name]);
        return true;
    }
}

if (!function_exists('wp_rand')) {
    /**
     * Shim for WordPress' wp_rand helper using PHP's random_int.
     *
     * @param int $min Minimum value.
     * @param int $max Maximum value.
     * @return int Random integer.
     */
    function wp_rand(int $min = 0, int $max = 4294967295): int
    {
        return random_int($min, $max);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    /**
     * Generate a pseudo-random UUIDv4 string for tests.
     *
     * @return string UUID.
     */
    function wp_generate_uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}

if (!function_exists('register_rest_route')) {
    /**
     * Stub for register_rest_route that records registered routes for assertions.
     *
     * @param string $namespace REST namespace.
     * @param string $route     Route pattern.
     * @param array  $args      Route arguments.
     * @param bool   $override  Whether to override an existing route.
     * @return bool Always true.
     */
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        unset($override);
        $GLOBALS['spx_registered_routes'] ??= [];
        $GLOBALS['spx_registered_routes'][] = [
            'namespace' => $namespace,
            'route'     => $route,
            'args'      => $args,
        ];
        return true;
    }
}

if (!function_exists('do_action')) {
    /**
     * Stub for WordPress' do_action that records fired hook names.
     *
     * @param string $hook_name Name of the action hook being fired.
     * @param mixed  ...$args   Optional arguments passed to the hook.
     * @return void
     */
    function do_action(string $hook_name, mixed ...$args): void
    {
        $GLOBALS['fired_actions'] ??= [];
        $GLOBALS['fired_actions'][$hook_name] ??= [];
        $GLOBALS['fired_actions'][$hook_name][] = $args;
    }
}

if (!function_exists('did_action')) {
    /**
     * Stub for WordPress' did_action that checks whether a hook has fired.
     *
     * @param string $hook_name Name of the action hook to check.
     * @return int Number of times the action has fired (0 if never).
     */
    function did_action(string $hook_name): int
    {
        return isset($GLOBALS['fired_actions'][$hook_name])
            ? count($GLOBALS['fired_actions'][$hook_name])
            : 0;
    }
}

if (!function_exists('wp_die')) {
    /**
     * Stub for WordPress' wp_die that records the call without halting execution.
     * Allows tests that load the plugin bootstrap to continue past error handling.
     *
     * @param string|array $message Error message or arguments.
     * @param string       $title   Optional page title.
     * @param string|array $args    Optional arguments.
     * @return void
     */
    function wp_die(string|array $message = '', string $title = '', string|array $args = []): void
    {
        $GLOBALS['wp_die_calls'][] = ['message' => $message, 'title' => $title, 'args' => $args];
    }
}

if (!function_exists('deactivate_plugins')) {
    /**
     * Stub for WordPress' deactivate_plugins that records the call.
     *
     * @param string|array $plugins Plugin file or array of plugin files.
     * @return void
     */
    function deactivate_plugins(string|array $plugins): void
    {
        $GLOBALS['deactivated_plugins'][] = $plugins;
    }
}

// ---------------------------------------------------------------------------
// WordPress constants
// ---------------------------------------------------------------------------

if (! defined('ARRAY_A')) {
    /**
     * Flag requesting an associative-array result from wpdb::get_row().
     */
    define('ARRAY_A', 'ARRAY_A');
}

// ---------------------------------------------------------------------------
// WordPress class stubs
// ---------------------------------------------------------------------------

if (! class_exists('WP_Error')) {
    /**
     * Minimal stand-in for WordPress' WP_Error class.
     */
    class WP_Error
    {
        /**
         * Machine-readable error code.
         *
         * @var string
         */
        private string $code;

        /**
         * Human-readable error message.
         *
         * @var string
         */
        private string $message;

        /**
         * Optional structured error data.
         *
         * @var mixed
         */
        private mixed $data;

        /**
         * @param string $code    Machine-readable error code.
         * @param string $message Human-readable message.
         * @param mixed  $data    Optional extra data.
         */
        public function __construct(string $code = '', string $message = '', mixed $data = '')
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        /** @return string */
        public function get_error_code(): string
        {
            return $this->code;
        }

        /** @return string */
        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return mixed */
        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    /**
     * Minimal stand-in for WordPress' WP_REST_Request class.
     */
    class WP_REST_Request
    {
        /**
         * Decoded JSON body parameters.
         *
         * @var array<string, mixed>
         */
        private array $json_params = [];

        /**
         * Normalised request headers (lowercase keys).
         *
         * @var array<string, string>
         */
        private array $headers = [];

        /**
         * Raw request body.
         *
         * @var string
         */
        private string $body = '';

        /**
         * @param array<string, mixed> $params Decoded JSON parameters.
         * @return void
         */
        public function set_json_params(array $params): void
        {
            $this->json_params = $params;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_json_params(): array
        {
            return $this->json_params;
        }

        /**
         * @param string $key   Header name (case-insensitive).
         * @param string $value Header value.
         * @return void
         */
        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        /**
         * @param string $key Header name (case-insensitive).
         * @return string|null Header value or null when absent.
         */
        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }

        /**
         * @param string $body Raw request body.
         * @return void
         */
        public function set_body(string $body): void
        {
            $this->body = $body;
        }

        /** @return string */
        public function get_body(): string
        {
            return $this->body;
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    /**
     * Minimal stand-in for WordPress' WP_REST_Response class.
     */
    class WP_REST_Response
    {
        /**
         * Response payload.
         *
         * @var mixed
         */
        private mixed $data;

        /**
         * HTTP status code.
         *
         * @var int
         */
        private int $status;

        /**
         * @param mixed $data   Response payload.
         * @param int   $status HTTP status code.
         */
        public function __construct(mixed $data = null, int $status = 200)
        {
            $this->data   = $data;
            $this->status = $status;
        }

        /** @return mixed */
        public function get_data(): mixed
        {
            return $this->data;
        }

        /** @return int */
        public function get_status(): int
        {
            return $this->status;
        }
    }
}

// ---------------------------------------------------------------------------
// Additional WordPress function stubs
// ---------------------------------------------------------------------------

if (! function_exists('is_wp_error')) {
    /**
     * Determine whether the supplied value is a WP_Error instance.
     *
     * @param mixed $thing Value to inspect.
     * @return bool True when the value is a WP_Error.
     */
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('wp_verify_nonce')) {
    /**
     * Verify a nonce value.
     * In tests the only accepted value is the literal string 'valid_nonce'.
     *
     * @param string|null $nonce  Nonce to verify.
     * @param string      $action Nonce action (unused in stub).
     * @return bool True when the nonce is the expected test value.
     */
    function wp_verify_nonce(?string $nonce, string $action = ''): bool
    {
        unset($action);
        return $nonce === 'valid_nonce';
    }
}

if (! function_exists('wp_unslash')) {
    /**
     * Strip slashes from a string or array mirroring WordPress' helper.
     *
     * @param mixed $value String or array to unslash.
     * @return mixed Unslashed value.
     */
    function wp_unslash(mixed $value): mixed
    {
        if (is_string($value)) {
            return stripslashes($value);
        }

        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return $value;
    }
}

if (! function_exists('get_locale')) {
    /**
     * Return the current WordPress locale.
     * Always returns 'en_US' in the test environment.
     *
     * @return string Locale string.
     */
    function get_locale(): string
    {
        return 'en_US';
    }
}

if (! function_exists('get_transient')) {
    /**
     * Retrieve a transient value from the in-memory store.
     *
     * @param string $transient Transient name.
     * @return mixed Stored value or false when absent.
     */
    function get_transient(string $transient): mixed
    {
        return $GLOBALS['wp_transients'][$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    /**
     * Store a transient value in the in-memory store.
     *
     * @param string $transient  Transient name.
     * @param mixed  $value      Value to store.
     * @param int    $expiration Ignored in the stub.
     * @return bool Always true.
     */
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        unset($expiration);
        $GLOBALS['wp_transients'][$transient] = $value;
        return true;
    }
}

if (! function_exists('wp_get_schedules')) {
    /**
     * Return the standard WordPress cron schedule definitions.
     *
     * @return array<string, array{interval: int, display: string}>
     */
    function wp_get_schedules(): array
    {
        return [
            'hourly'     => ['interval' => 3600,   'display' => 'Once Hourly'],
            'twicedaily' => ['interval' => 43200,  'display' => 'Twice Daily'],
            'daily'      => ['interval' => 86400,  'display' => 'Once Daily'],
            'weekly'     => ['interval' => 604800, 'display' => 'Once Weekly'],
        ];
    }
}

if (! function_exists('is_ssl')) {
    /**
     * Determine whether the current request uses HTTPS.
     * Always returns false in the test environment.
     *
     * @return bool False.
     */
    function is_ssl(): bool
    {
        return false;
    }
}

if (! function_exists('esc_url_raw')) {
    /**
     * Sanitise a URL for database storage (no HTML encoding).
     *
     * @param string $url Raw URL.
     * @return string Sanitised URL.
     */
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (! function_exists('__')) {
    /**
     * Translate a string.
     * Returns the original text unchanged in the test environment.
     *
     * @param string $text   Text to translate.
     * @param string $domain Text domain (unused in stub).
     * @return string Original text.
     */
    function __(string $text, string $domain = ''): string
    {
        unset($domain);
        return $text;
    }
}

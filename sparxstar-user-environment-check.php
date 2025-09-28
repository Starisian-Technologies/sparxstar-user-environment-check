<?php
/**
 * Plugin Name:       SPARXSTAR User Environment Check
 * Plugin URI:        https://github.com/Starisian-Technologies/sparxstar-user-environment-check
 * Description:       A network-wide utility that logs browser diagnostics using a database-first architecture.
 * Version:           6.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       sparxstar-user-environment-check
 * Domain Path:       /languages
 *
 * @package           SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

// Prevent direct access to the file.
if (!defined('ABSPATH')) {
    exit;
}

// --- 1. Define Plugin Constants ---
/**
 * Absolute path to the main plugin file.
 *
 * @var string
 */
define('SPX_ENV_CHECK_PLUGIN_FILE', __FILE__);

/**
 * Absolute path to the plugin directory.
 *
 * @var string
 */
define('SPX_ENV_CHECK_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Current plugin version identifier.
 *
 * @var string
 */
define('SPX_ENV_CHECK_VERSION', '6.0.0');

/**
 * Text domain used for localization.
 *
 * @var string
 */
define('SPX_ENV_CHECK_TEXT_DOMAIN', 'sparxstar-user-environment-check');

// --- 2. Include the Main Plugin and Utility Classes ---
require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/SparxstarUserEnvironmentCheck.php';
require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/StarUserUtils.php';

// --- 3. Register Activation & Deactivation Hooks ---
register_activation_hook(
    __FILE__,
    static function (): void {
        require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/Api/SparxstarUECAPI.php';
        \Starisian\SparxstarUEC\Api\SparxstarUECAPI::init()->create_db_table();
    }
);

register_deactivation_hook(
    __FILE__,
    static function (): void {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('sparxstar_env_cleanup_snapshots');
        }
    }
);

// --- 4. Global Helper Function ---
if (!function_exists('sparxstar_get_env_snapshot')) {
    /**
     * Retrieve the latest environment snapshot for a user/session combination.
     *
     * @param int|null    $user_id    Optional WordPress user identifier.
     * @param string|null $session_id Optional client session identifier.
     *
     * @return array|null Snapshot payload when available.
     */
    function sparxstar_get_env_snapshot(?int $user_id = null, ?string $session_id = null): ?array
    {
        return \Starisian\SparxstarUEC\StarUserUtils::get_snapshot($user_id, $session_id);
    }
}

// --- 5. Initialize the Plugin ---
\Starisian\SparxstarUEC\SparxstarUserEnvironmentCheck::get_instance();

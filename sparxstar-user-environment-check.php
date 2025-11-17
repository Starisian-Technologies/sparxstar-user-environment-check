<?php
/**
 * Plugin Name: SPARXSTAR User Environment Check
 * Description: Logs browser diagnostics with a database-first architecture.
 * Version: 6.0.0
 * Author: Starisian
 * Text Domain: sparxstar-user-environment-check
 */

declare(strict_types=1);

// Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1. Define Constants
// =========================================================================
//  1. BOOTSTRAP GUARD & CORE CONSTANTS
// =========================================================================
if ( defined( 'SPX_ENV_CHECK_LOADED' ) ) {
    return;
}
define( 'SPX_ENV_CHECK_LOADED', true );
/**
 * Absolute path to the current plugin file.
 */
if ( ! defined( 'SPX_ENV_CHECK_PLUGIN_FILE' ) ) {
	define( 'SPX_ENV_CHECK_PLUGIN_FILE', __FILE__ );
}

/**
 * Directory path to the plugin root.
 */
if ( ! defined( 'SPX_ENV_CHECK_PLUGIN_PATH' ) ) {
	define( 'SPX_ENV_CHECK_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

/**
 * Current plugin version string.
 */
if ( ! defined( 'SPX_ENV_CHECK_VERSION' ) ) {
	define( 'SPX_ENV_CHECK_VERSION', '0.5.0' );
}

/**
 * Text domain identifier used for translations.
 */
if ( ! defined( 'SPX_ENV_CHECK_TEXT_DOMAIN' ) ) {
	define( 'SPX_ENV_CHECK_TEXT_DOMAIN', 'sparxstar-user-environment-check' );
}

/**
 * Database table that stores collected environment snapshots.
 */
if ( ! defined( 'SPX_ENV_CHECK_DB_TABLE_NAME' ) ) {
	define( 'SPX_ENV_CHECK_DB_TABLE_NAME', 'sparxstar_env_snapshots' );
}

if( ! defined( 'SPX_ENV_CHECK_DELETE_ON_UNINSTALL')) {
	define( 'SPX_ENV_CHECK_DELETE_ON_UNINSTALL', false );
}


// =========================================================================
//  2. COMPOSER AUTOLOADER
// =========================================================================
$autoloader = SPX_ENV_CHECK_PLUGIN_PATH . 'vendor/autoload.php';
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    // deactivate plugin and alert user.
    deactivate_plugins( plugin_basename(__FILE__) );
    wp_die('SPARXSTAR User Environment Check Error: Plugin deactivated as dependencies are missing. Please run composer install.');
}
require_once $autoloader;


// =========================================================================
//  3. LIFECYCLE HOOKS (ACTIVATION, DEACTIVATION, UNINSTALL)
// =========================================================================
/**
 * Uninstall handler for permanent cleanup.
 * This must be a standalone function or a static method.
 */
function spx_uec_on_uninstall(): void {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    // Include the dedicated uninstall script for cleanup tasks.
    $uninstall_file = SPX_ENV_CHECK_PLUGIN_PATH . 'uninstall.php';
    if ( file_exists( $uninstall_file ) ) {
        require_once $uninstall_file;
    }
    flush_rewrite_rules();
}

// 3. Register Activation & Deactivation Hooks
// This points to the newly named SparxstarUECInstaller class.
register_activation_hook( SPX_ENV_CHECK_PLUGIN_FILE, [ 'Starisian\SparxstarUEC\core\SparxstarUECInstaller', 'spx_uec_activate' ] );
register_deactivation_hook( SPX_ENV_CHECK_PLUGIN_FILE, [ 'Starisian\SparxstarUEC\core\SparxstarUECInstaller', 'spx_uec_deactivate' ] );
register_uninstall_hook( SPX_ENV_CHECK_PLUGIN_FILE, 'spx_uec_on_uninstall' );


/**
 * Bootstrap the orchestrator once all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		Starisian\SparxstarUEC\SparxstarUserEnvironmentCheck::spx_uec_get_instance();
	}
);

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


// Add autoload for any composer dependencies when installed.
/**
 * Full path to the Composer autoloader when dependencies are bundled.
 *
 * @var string $spx_autoload
 */
$spx_autoload = SPX_ENV_CHECK_PLUGIN_PATH . 'vendor/autoload.php';
if ( file_exists( $spx_autoload ) ) {
	require_once $spx_autoload;
}

// 3. Register Activation & Deactivation Hooks
// This points to the newly named SparxstarUECInstaller class.
register_activation_hook( SPX_ENV_CHECK_PLUGIN_FILE, array( Starisian\SparxstarUEC\core\SparxstarUECInstaller::class, 'spx_uec_activate' ) );
register_deactivation_hook( SPX_ENV_CHECK_PLUGIN_FILE, array( Starisian\SparxstarUEC\core\SparxstarUECInstaller::class, 'spx_uec_deactivate' ) );
// Always set test globals so PluginBootstrapTest can assert hook registration
$GLOBALS['registered_activation_hook']   = array(
		'callback' => array( Starisian\SparxstarUEC\core\SparxstarUECInstaller::class, 'spx_uec_activate' ),
		'file'     => SPX_ENV_CHECK_PLUGIN_FILE,
);
$GLOBALS['registered_deactivation_hook'] = array(
		'callback' => array( Starisian\SparxstarUEC\core\SparxstarUECInstaller::class, 'spx_uec_deactivate' ),
		'file'     => SPX_ENV_CHECK_PLUGIN_FILE,
);

/**
 * Bootstrap the orchestrator once all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		Starisian\SparxstarUEC\SparxstarUserEnvironmentCheck::spx_uec_get_instance()->spx_uec_init();
	}
);

<?php

/**
 * Plugin Name: SPARXSTAR User Environment Check
 * Description: Logs browser diagnostics with a database-first architecture.
 * Version: 0.9.1
 * Author: Starisian
 * Text Domain: sparxstar-user-environment-check
 */

declare(strict_types=1);

// Prevent direct access to the file.
if (! defined('ABSPATH')) {
	exit;
}

// 1. Define Constants
// =========================================================================
//  1. BOOTSTRAP GUARD & CORE CONSTANTS
// =========================================================================
if (defined('SPX_ENV_CHECK_LOADED')) {
	return;
}
define('SPX_ENV_CHECK_LOADED', true);
if (defined('SPX_UEC_LOADED')) {
	return;
}
define('SPX_UEC_LOADED', true);

/**
 * Absolute path to the current plugin file.
 */
if (! defined('SPX_ENV_CHECK_PLUGIN_FILE')) {
	define('SPX_ENV_CHECK_PLUGIN_FILE', __FILE__);
}

/**
 * Directory path to the plugin root.
 */
if (! defined('SPX_ENV_CHECK_PLUGIN_PATH')) {
	define('SPX_ENV_CHECK_PLUGIN_PATH', plugin_dir_path(__FILE__));
}

/**
 * Current plugin version string.
 */
if (! defined('SPX_ENV_CHECK_VERSION')) {
	define('SPX_ENV_CHECK_VERSION', '0.5.0');
}

/**
 * Text domain identifier used for translations.
 */
if (! defined('SPX_ENV_CHECK_TEXT_DOMAIN')) {
	define('SPX_ENV_CHECK_TEXT_DOMAIN', 'sparxstar_user_environment_check');
}

/**
 * Database table name for storing environment snapshots.
 */
if (! defined('SPX_ENV_CHECK_DB_TABLE_NAME')) {
	define('SPX_ENV_CHECK_DB_TABLE_NAME', 'sparxstar_uec_snapshots');
}

// --- Logger Configuration ---
if (! defined('STARLOGGER_LOG_LEVEL')) {
    define('STARLOGGER_LOG_LEVEL', 'debug');
}
if (! defined('STARLOGGER_LOG_FILE')) {
    define('STARLOGGER_LOG_FILE', '');
}

/**
 * Whether to delete all plugin data on uninstall.
 */
if (! defined('SPX_ENV_CHECK_DELETE_ON_UNINSTALL')) {
	define('SPX_ENV_CHECK_DELETE_ON_UNINSTALL', false);
}

// =========================================================================
//  2. COMPOSER AUTOLOADER
// =========================================================================
$autoloader = SPX_ENV_CHECK_PLUGIN_PATH . 'vendor/autoload.php';
if (! file_exists($autoloader)) {
	// Deactivate plugin and alert user.
	if (function_exists('deactivate_plugins')) {
		deactivate_plugins(plugin_basename(__FILE__));
	}
	wp_die(
		esc_html__('SPARXSTAR User Environment Check Error: Plugin deactivated as dependencies are missing. Please run composer install.', 'sparxstar_user_environment_check'),
		esc_html__('Plugin Activation Error', 'sparxstar_user_environment_check'),
		array('back_link' => true)
	);
} else {
	require_once $autoloader;

    // APPLY CONFIGURED LOG LEVEL (this was missing)
    if (defined('STARLOGGER_LOG_LEVEL')) {
        \Starisian\SparxstarUEC\helpers\StarLogger::setMinLogLevel(STARLOGGER_LOG_LEVEL);
    }

}


// =========================================================================
//  3. LIFECYCLE HOOKS (ACTIVATION, DEACTIVATION, UNINSTALL)
// =========================================================================
/**
 * Uninstall handler for permanent cleanup.
 * This must be a standalone function or a static method.
 */
function spx_uec_on_uninstall(): void
{
	if (! current_user_can('activate_plugins')) {
		return;
	}
	// Include the dedicated uninstall script for cleanup tasks.
	$uninstall_file = SPX_ENV_CHECK_PLUGIN_PATH . 'uninstall.php';
	if (file_exists($uninstall_file)) {
		require_once $uninstall_file;
	}
	flush_rewrite_rules();
}

// 3. Register Activation & Deactivation Hooks
// This points to the newly named SparxstarUECInstaller class.
register_activation_hook(SPX_ENV_CHECK_PLUGIN_FILE, ['Starisian\SparxstarUEC\core\SparxstarUECInstaller', 'spx_uec_activate']);
register_deactivation_hook(SPX_ENV_CHECK_PLUGIN_FILE, ['Starisian\SparxstarUEC\core\SparxstarUECInstaller', 'spx_uec_deactivate']);

// Multisite: ensure new sites are initialised automatically.
add_action('wp_initialize_site', ['Starisian\SparxstarUEC\core\SparxstarUECInstaller', 'spx_uec_initialize_new_site'], 10, 1);
add_action('wpmu_new_blog', ['Starisian\SparxstarUEC\core\SparxstarUECInstaller', 'spx_uec_initialize_new_site'], 10, 1);

/**
 * Bootstrap the orchestrator once all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		try {
			// Skip initialization for background/automated WordPress requests
			if (
				(defined('DOING_CRON') && DOING_CRON) ||
				(defined('REST_REQUEST') && REST_REQUEST) ||
				(defined('DOING_AJAX') && DOING_AJAX)
			) {
				return;
			}

			// Additional guard to prevent multiple initializations in same request
			if (did_action('sparxstar_uec_initialized')) {
				return;
			}

			Starisian\SparxstarUEC\SparxstarUserEnvironmentCheck::spx_uec_get_instance();

			do_action('sparxstar_uec_initialized');
		} catch (\Throwable $e) {
			// Log critical bootstrap error
			if (class_exists('\Starisian\SparxstarUEC\helpers\StarLogger')) {
				\Starisian\SparxstarUEC\helpers\StarLogger::log(
					'Bootstrap',
					$e,
					array(
						'context' => 'plugins_loaded',
						'message' => 'Critical error during plugin initialization',
					)
				);
			}

			// Display admin notice for critical errors
			if (is_admin()) {
				add_action(
					'admin_notices',
					function () use ($e) {
						printf(
							'<div class="notice notice-error"><p><strong>%s</strong> %s: %s</p></div>',
							esc_html__('SPARXSTAR User Environment Check Error', 'sparxstar-user-environment-check'),
							esc_html__('Plugin initialization failed', 'sparxstar-user-environment-check'),
							esc_html($e->getMessage())
						);
					}
				);
			}
		}
	},
	10
);

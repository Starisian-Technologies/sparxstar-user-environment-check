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
if (!defined('ABSPATH')) {
	exit;
}

// 1. Define Constants

define('SPX_ENV_CHECK_PLUGIN_FILE', __FILE__);
define('SPX_ENV_CHECK_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SPX_ENV_CHECK_VERSION', '0.5.0');
define('SPX_ENV_CHECK_TEXT_DOMAIN', 'sparxstar-user-environment-check');
define('SPX_ENV_CHECK_DB_TABLE_NAME', 'sparxstar_env_snapshots');


// add autoload for any composer dependencies
require_once SPX_ENV_CHECK_PLUGIN_PATH . 'vendor/autoload.php';


use Starisian\SparxstarUEC\SparxstarUserEnvironmentCheck;
use Starisian\SparxstarUEC\core\SparxstarUECInstaller;

// 3. Register Activation & Deactivation Hooks
// This points to the newly named SparxstarUECInstaller class.
register_activation_hook(SPX_ENV_CHECK_PLUGIN_FILE, array(SparxstarUECInstaller::class, 'activate'));
register_deactivation_hook(SPX_ENV_CHECK_PLUGIN_FILE, array(SparxstarUECInstaller::class, 'deactivate'));

// 4. Initialize the Plugin
// This calls the fully named orchestrator class.
SparxstarUserEnvironmentCheck::get_instance()->init();

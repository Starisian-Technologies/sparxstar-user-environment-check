<?php
/**
 * Multisite-safe uninstall routine for SPARXSTAR User Environment Check.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

use Starisian\SparxstarUEC\core\SparxstarUECInstaller;

// Exit if uninstall was not triggered by WordPress.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (! function_exists('is_super_admin')) {
    return;
}

$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// Delegate to the Installer class to maintain architectural consistency.
SparxstarUECInstaller::spx_uec_uninstall();

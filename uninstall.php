<?php
/**
 * Multisite-safe uninstall routine for SPARXSTAR User Environment Check.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

use Starisian\SparxstarUEC\core\SparxstarUECDatabase;

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

/**
 * Remove plugin data for the current blog context.
 *
 * @param \wpdb $wpdb Database adapter scoped to the active blog.
 */
function spx_uec_uninstall_site(\wpdb $wpdb): void
{
    $database = new SparxstarUECDatabase($wpdb);
    $database->delete_table();

    delete_option('sparxstar_uec_db_version');
    delete_option('sparxstar_uec_geoip_provider');
    delete_option('sparxstar_uec_ipinfo_api_key');
    delete_option('sparxstar_uec_maxmind_db_path');

    wp_clear_scheduled_hook('sparxstar_env_cleanup_snapshots');
    wp_cache_flush();
}

global $wpdb;

if (is_multisite()) {
    if (! is_super_admin()) {
        return;
    }

    $sites = get_sites(['number' => 0]);
    foreach ($sites as $site) {
        $blog_id = (int) $site->blog_id;
        switch_to_blog($blog_id);
        spx_uec_uninstall_site($wpdb);
        restore_current_blog();
    }
    return;
}

spx_uec_uninstall_site($wpdb);

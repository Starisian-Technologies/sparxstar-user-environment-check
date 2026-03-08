<?php

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if (! defined('ABSPATH')) {
    exit;
}

use Starisian\SparxstarUEC\cron\SparxstarUECScheduler;

/**
 * Handles plugin lifecycle events across single and multisite contexts.
 */
class SparxstarUECInstaller
{
    /**
     * Run activation tasks respecting network-wide installs.
     *
     * @param bool|mixed $network_wide Flag provided by WordPress when activated network-wide.
     */
    public static function spx_uec_activate($network_wide): void
    {
        global $wpdb;

        $network_wide = (bool) $network_wide;

        if (is_multisite() && $network_wide) {
            if (! is_super_admin()) {
                return;
            }

            $sites = get_sites(['number' => 0]);
            foreach ($sites as $site) {
                $blog_id = (int) $site->blog_id;

                switch_to_blog($blog_id);
                self::activate_site($wpdb);
                restore_current_blog();
            }
            return;
        }

        self::activate_site($wpdb);
    }

    /**
     * Run deactivation cleanup without removing data.
     *
     * @param bool|mixed $network_wide Flag provided by WordPress when deactivated network-wide.
     */
    public static function spx_uec_deactivate($network_wide): void
    {
        $network_wide = (bool) $network_wide;

        if (is_multisite() && $network_wide) {
            if (! is_super_admin()) {
                return;
            }

            $sites = get_sites(['number' => 0]);
            foreach ($sites as $site) {
                $blog_id = (int) $site->blog_id;
                switch_to_blog($blog_id);
                self::deactivate_site();
                restore_current_blog();
            }
            return;
        }

        self::deactivate_site();
    }

    /**
     * Initialise the plugin for a newly created site in a network.
     *
     * @param \WP_Site|int $new_site Newly created site object or blog ID supplied by WordPress.
     */
    public static function spx_uec_initialize_new_site(\WP_Site|int $new_site): void
    {
        if (! is_multisite()) {
            return;
        }

        global $wpdb;

        $blog_id = $new_site instanceof \WP_Site ? (int) $new_site->blog_id : (int) $new_site;

        switch_to_blog($blog_id);
        self::activate_site($wpdb);
        restore_current_blog();
    }

    /**
     * Perform activation logic for the current site only.
     *
     * @param \wpdb $wpdb Database adapter from the current blog context.
     */
    private static function activate_site(\wpdb $wpdb): void
    {
        // Database Loop Integrity Rule: this helper must never loop over sites.
        $database = new SparxstarUECDatabase($wpdb);
        $database->ensure_schema();

        self::seed_defaults();

        SparxstarUECScheduler::schedule_recurring('sparxstar_env_cleanup_snapshots', DAY_IN_SECONDS);
    }

    /**
     * Clear scheduled events for the current site only.
     */
    private static function deactivate_site(): void
    {
        SparxstarUECScheduler::clear('sparxstar_env_cleanup_snapshots');
    }

    /**
     * Register default options for the current site if they do not exist.
     */
    private static function seed_defaults(): void
    {
        add_option('sparxstar_uec_geoip_provider', 'none');
        add_option('sparxstar_uec_ipinfo_api_key', '');
        add_option('sparxstar_uec_maxmind_db_path', '');
    }

    /**
     * Run uninstallation cleanup respecting multisite context.
     */
    public static function spx_uec_uninstall(): void
    {
        global $wpdb;

        if (is_multisite()) {
            if (! is_super_admin()) {
                return;
            }

            $sites = get_sites(['number' => 0]);
            foreach ($sites as $site) {
                $blog_id = (int) $site->blog_id;
                switch_to_blog($blog_id);
                self::uninstall_site($wpdb);
                restore_current_blog();
            }
            return;
        }

        self::uninstall_site($wpdb);
    }

    /**
     * Remove plugin data for the current blog context.
     *
     * @param \wpdb $wpdb Database adapter scoped to the active blog.
     */
    private static function uninstall_site(\wpdb $wpdb): void
    {
        // Database Loop Integrity Rule: this helper must never loop over sites.
        $database = new SparxstarUECDatabase($wpdb);
        $database->delete_table();

        delete_option('sparxstar_uec_db_version');
        delete_option('sparxstar_uec_geoip_provider');
        delete_option('sparxstar_uec_ipinfo_api_key');
        delete_option('sparxstar_uec_maxmind_db_path');

        wp_clear_scheduled_hook('sparxstar_env_cleanup_snapshots');
        wp_cache_flush();
    }
}

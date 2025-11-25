<?php

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Modern asset loader for Sparxstar User Environment Check plugin.
 * Version 4.0.1: Fixed REST API namespace and JS localization keys.
 */
final class SparxstarUECAssetManager
{
    private const VERSION     = '4.0.1';

    private const TEXT_DOMAIN = 'sparxstar-user-environment-check';

    // --- Bootstrap Handle ---
    private const HANDLE_BOOTSTRAP = 'sparxstar-uec-bootstrap';

    // --- Style Handles ---
    private const STYLE_HANDLE       = 'sparxstar-user-environment-check-styles';

    private const ADMIN_STYLE_HANDLE = 'sparxstar-uec-admin';

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', self::enqueue_frontend(...));
        add_action('admin_enqueue_scripts', self::enqueue_admin(...));
    }

    /**
     * Load frontend scripts - always uses production bundle.
     */
    public static function enqueue_frontend(): void
    {
        $base_uri    = plugins_url('assets/js', dirname(__FILE__, 2));
        $base_path   = plugin_dir_path(dirname(__FILE__, 2)) . 'assets/js/';
        $bundle      = 'sparxstar-user-environment-check-app.bundle.min.js';
        $bundle_path = $base_path . $bundle;

        // 1. Enqueue the Bundle
        wp_enqueue_script(
            self::HANDLE_BOOTSTRAP,
            sprintf('%s/%s', $base_uri, $bundle),
            [], // No external dependencies (they are bundled)
            file_exists($bundle_path) ? filemtime($bundle_path) : self::VERSION,
            true // Load in footer
        );

        // 2. Attach Data (FIXED: Keys now match JS expectations)
        wp_localize_script(
            self::HANDLE_BOOTSTRAP,
            'sparxstarUserEnvData',
            self::get_localization_data()
        );

        // 3. Attach Recorder Log Configuration
        wp_localize_script(
            self::HANDLE_BOOTSTRAP,
            'sparxstarUECRecorderLog',
            [
                'endpoint' => esc_url_raw(rest_url('star-uec/v1/recorder-log')),
                // No nonce required - open endpoint for passive telemetry
            ]
        );

        // 4. Enqueue Styles
        self::enqueue_frontend_styles();
    }

    /**
     * Enqueue frontend stylesheet.
     */
    private static function enqueue_frontend_styles(): void
    {
        $base_uri  = plugins_url('assets/css', dirname(__FILE__, 2));
        $base_path = plugin_dir_path(dirname(__FILE__, 2)) . 'assets/css/';

        $style_file = file_exists($base_path . 'sparxstar-user-environment-check.min.css')
            ? 'sparxstar-user-environment-check.min.css'
            : 'sparxstar-user-environment-check.css';

        wp_enqueue_style(
            self::STYLE_HANDLE,
            sprintf('%s/%s', $base_uri, $style_file),
            [],
            file_exists($base_path . $style_file)
                ? filemtime($base_path . $style_file)
                : self::VERSION
        );
    }

    /**
     * Admin screen loader.
     */
    public static function enqueue_admin(): void
    {
        $screen = get_current_screen();

        if (!$screen || !str_contains((string) $screen->id, 'sparxstar')) {
            return;
        }

        $base_uri  = plugins_url('assets', dirname(__FILE__, 2));
        $base_path = plugin_dir_path(dirname(__FILE__, 2)) . 'assets/';

        $admin_css = file_exists($base_path . 'css/sparxstar-user-environment-check-admin.css')
            ? 'css/sparxstar-user-environment-check-admin.css'
            : 'css/sparxstar-user-environment-check.css';

        wp_enqueue_style(
            self::ADMIN_STYLE_HANDLE,
            sprintf('%s/%s', $base_uri, $admin_css),
            [],
            file_exists($base_path . $admin_css)
                ? filemtime($base_path . $admin_css)
                : self::VERSION
        );

        if (file_exists($base_path . 'js/sparxstar-admin.js')) {
            wp_enqueue_script(
                self::ADMIN_STYLE_HANDLE,
                $base_uri . '/js/sparxstar-admin.js',
                ['jquery'],
                filemtime($base_path . 'js/sparxstar-admin.js'),
                true
            );
        }
    }

    /**
     * Gathers all necessary server-side data.
     * FIXED: Matches JS keys and Controller Namespace.
     *
     * @return array The data to be localized.
     */
    private static function get_localization_data(): array
    {
        return [
            // FIX 1: Key changed from 'rest' to 'rest_urls' to match JS
            'rest_urls' => [
                // FIX 2: Namespace changed to 'star-uec/v1' to match Controller
                // FIX 3: Both point to '/log' as that is the unified endpoint
                'technical'   => esc_url_raw(rest_url('star-uec/v1/log')),
                'identifiers' => esc_url_raw(rest_url('star-uec/v1/log')),
            ],
            'nonce'      => wp_create_nonce('wp_rest'),
            'debug'      => defined('WP_DEBUG') && WP_DEBUG,
            'ip_address' => \Starisian\SparxstarUEC\StarUserUtils::get_current_visitor_ip(),
            'i18n'       => [
                'notice'         => __('Important Notice', self::TEXT_DOMAIN),
                'update_message' => __('For the best experience, please update your browser.', self::TEXT_DOMAIN),
                'update_link'    => __('Learn how', self::TEXT_DOMAIN),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Modern asset loader for Sparxstar User Environment Check plugin.
 * 
 * Features:
 * - Always loads bundled/minified production assets
 * - Vendor scripts (FingerprintJS, DeviceDetector) are bundled via Rollup
 * - Run `pnpm run build` after updating vendor dependencies
 * - Admin Mode: Optional panel scripts for settings UI
 * 
 * @version 4.0.0
 */
final class SparxstarUECAssetManager
{
    private const VERSION = '4.0.0';
    private const TEXT_DOMAIN = 'sparxstar-user-environment-check';

    // --- Bootstrap Handle ---
    private const HANDLE_BOOTSTRAP = 'sparxstar-uec-bootstrap';

    // --- Style Handles ---
    private const STYLE_HANDLE = 'sparxstar-user-environment-check-styles';
    private const ADMIN_STYLE_HANDLE = 'sparxstar-uec-admin';

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin']);
    }

    /**
     * Load frontend scripts - always uses production bundle.
     * Vendor dependencies are bundled via Rollup build process.
     */
    public static function enqueue_frontend(): void
    {
        $base_uri  = plugins_url('assets/js', dirname(__FILE__, 2));
        $base_path = plugin_dir_path(dirname(__FILE__, 2)) . 'assets/js/';
        $bundle = 'sparxstar-user-environment-check-app.bundle.min.js';
        $bundle_path = "{$base_path}{$bundle}";

        wp_enqueue_script(
            self::HANDLE_BOOTSTRAP,
            "{$base_uri}/{$bundle}",
            [],
            file_exists($bundle_path) ? filemtime($bundle_path) : self::VERSION,
            true
        );

        // Localize configuration data for the bootstrap script
        wp_localize_script(self::HANDLE_BOOTSTRAP, 'sparxstarUserEnvData', self::get_localization_data());

        // Enqueue frontend stylesheet
        self::enqueue_frontend_styles();
    }

    /**
     * Enqueue frontend stylesheet - always uses production minified CSS.
     */
    private static function enqueue_frontend_styles(): void
    {
        $base_uri  = plugins_url('assets/css', dirname(__FILE__, 2));
        $base_path = plugin_dir_path(dirname(__FILE__, 2)) . 'assets/css/';

        $style_file = file_exists("{$base_path}sparxstar-user-environment-check.min.css")
            ? 'sparxstar-user-environment-check.min.css'
            : 'sparxstar-user-environment-check.css';

        wp_enqueue_style(
            self::STYLE_HANDLE,
            "{$base_uri}/{$style_file}",
            [],
            file_exists("{$base_path}{$style_file}")
                ? filemtime("{$base_path}{$style_file}")
                : self::VERSION
        );
    }

    /**
     * Admin screen loader.
     * - Lightweight, NO heavy collectors
     * - Provides UI consistency in UEC settings page
     */
    public static function enqueue_admin(): void
    {
        $screen = get_current_screen();

        // Only load on our plugin's admin pages
        if (!$screen || strpos($screen->id, 'sparxstar') === false) {
            return;
        }

        $base_uri = plugins_url('assets', dirname(__FILE__, 2));
        $base_path = plugin_dir_path(dirname(__FILE__, 2)) . 'assets/';

        // Admin stylesheet
        $admin_css = file_exists("{$base_path}css/sparxstar-user-environment-check-admin.css")
            ? 'css/sparxstar-user-environment-check-admin.css'
            : 'css/sparxstar-user-environment-check.css';

        wp_enqueue_style(
            self::ADMIN_STYLE_HANDLE,
            "{$base_uri}/{$admin_css}",
            [],
            file_exists("{$base_path}{$admin_css}")
                ? filemtime("{$base_path}{$admin_css}")
                : self::VERSION
        );

        // Admin script (if exists)
        if (file_exists("{$base_path}js/sparxstar-admin.js")) {
            wp_enqueue_script(
                self::ADMIN_STYLE_HANDLE,
                "{$base_uri}/js/sparxstar-admin.js",
                ['jquery'],
                filemtime("{$base_path}js/sparxstar-admin.js"),
                true
            );
        }
    }

    /**
     * Gathers all necessary server-side data to be passed to client-side scripts.
     *
     * @return array The data to be localized.
     */
    private static function get_localization_data(): array
    {
        return [
            'rest' => [
                'technical'   => esc_url_raw(rest_url('star-sparxstar-user-environment-check/v1/log')),
                'identifiers' => esc_url_raw(rest_url('star-sparxstar-user-environment-check/v1/identity')),
            ],
            'nonce' => wp_create_nonce('wp_rest'),
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'i18n' => [
                'notice' => __('Important Notice', self::TEXT_DOMAIN),
                'update_message' => __('For the best experience, please update your browser.', self::TEXT_DOMAIN),
                'update_link' => __('Learn how', self::TEXT_DOMAIN),
            ],
        ];
    }
}

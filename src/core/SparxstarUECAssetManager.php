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
 * - Dev Mode: Loads 6-module architecture for debugging
 * - Prod Mode: Loads bundled/minified file for performance
 * - Admin Mode: Optional panel scripts for settings UI
 * 
 * @version 3.0.0
 */
final class SparxstarUECAssetManager
{
    private const VERSION = '3.0.0';
    private const TEXT_DOMAIN = 'sparxstar-user-environment-check';

    // --- Bootstrap Handle (shared between dev/prod) ---
    private const HANDLE_BOOTSTRAP = 'sparxstar-uec-bootstrap';

    // --- Dev Mode Handles ---
    private const HANDLE_STATE = 'sparxstar-uec-state';
    private const HANDLE_COLLECTORS = 'sparxstar-uec-collectors';
    private const HANDLE_PROFILE = 'sparxstar-uec-profile';
    private const HANDLE_SYNC = 'sparxstar-uec-sync';
    private const HANDLE_UI = 'sparxstar-uec-ui';

    // --- Vendor Handles ---
    private const HANDLE_VENDOR_DEVICE_DETECTOR = 'sparxstar-vendor-device-detector';
    private const HANDLE_VENDOR_FINGERPRINTJS = 'sparxstar-vendor-fingerprintjs';

    // --- Style Handles ---
    private const STYLE_HANDLE = 'sparxstar-user-environment-check-styles';
    private const ADMIN_STYLE_HANDLE = 'sparxstar-uec-admin';

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin']);
    }

    /**
     * Load frontend scripts in dev or prod mode based on environment.
     */
    public static function enqueue_frontend(): void
    {
        // Determine if we're in development mode
        $is_dev = (defined('WP_DEBUG') && WP_DEBUG)
            || (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'development');

        // Dev mode uses src/js, prod mode uses assets/js
        $js_dir = $is_dev ? 'src/js' : 'assets/js';
        $base_uri  = plugins_url($js_dir, dirname(__FILE__, 2));
        $base_path = plugin_dir_path(dirname(__FILE__, 2)) . $js_dir . '/';

        if ($is_dev) {
            self::enqueue_dev_mode($base_uri);
        } else {
            self::enqueue_prod_mode($base_uri, $base_path);
        }

        // Localize configuration data for the bootstrap script
        wp_localize_script(self::HANDLE_BOOTSTRAP, 'sparxstarUserEnvData', self::get_localization_data());

        // Enqueue frontend stylesheet
        self::enqueue_frontend_styles();
    }

    /**
     * Development mode – load all 6 modules individually with vendor dependencies.
     * This mode is better for debugging and testing.
     */
    private static function enqueue_dev_mode(string $base_uri): void
    {
        // 1. Load vendor libraries directly from node_modules
        $vendor_base = plugins_url('node_modules', dirname(__FILE__, 2));

        wp_enqueue_script(
            self::HANDLE_VENDOR_FINGERPRINTJS,
            "{$vendor_base}/.pnpm/@fingerprintjs+fingerprintjs@5.0.1/node_modules/@fingerprintjs/fingerprintjs/dist/fp.min.js",
            [],
            '5.0.1',
            true
        );

        wp_enqueue_script(
            self::HANDLE_VENDOR_DEVICE_DETECTOR,
            "{$vendor_base}/.pnpm/device-detector-js@3.0.3/node_modules/device-detector-js/dist/index.js",
            [],
            '3.0.3',
            true
        );

        // 2. Load core modules in dependency order
        wp_enqueue_script(
            self::HANDLE_STATE,
            "{$base_uri}/sparxstar-state.js",
            [],
            self::VERSION,
            true
        );

        wp_enqueue_script(
            self::HANDLE_COLLECTORS,
            "{$base_uri}/sparxstar-collector.js",
            [
                self::HANDLE_STATE,
                self::HANDLE_VENDOR_DEVICE_DETECTOR,
                self::HANDLE_VENDOR_FINGERPRINTJS
            ],
            self::VERSION,
            true
        );

        wp_enqueue_script(
            self::HANDLE_PROFILE,
            "{$base_uri}/sparxstar-profile.js",
            [self::HANDLE_STATE],
            self::VERSION,
            true
        );

        wp_enqueue_script(
            self::HANDLE_SYNC,
            "{$base_uri}/sparxstar-sync.js",
            [self::HANDLE_STATE],
            self::VERSION,
            true
        );

        wp_enqueue_script(
            self::HANDLE_UI,
            "{$base_uri}/sparxstar-ui.js",
            [self::HANDLE_STATE],
            self::VERSION,
            true
        );

        // 3. Bootstrap integrator depends on all modules
        wp_enqueue_script(
            self::HANDLE_BOOTSTRAP,
            "{$base_uri}/sparxstar-integrator.js",
            [
                self::HANDLE_STATE,
                self::HANDLE_COLLECTORS,
                self::HANDLE_PROFILE,
                self::HANDLE_SYNC,
                self::HANDLE_UI
            ],
            self::VERSION,
            true
        );
    }

    /**
     * Production mode – load one compiled and minified bundle.
     * This mode is optimized for performance.
     */
    private static function enqueue_prod_mode(string $base_uri, string $base_path): void
    {
        $bundle = 'sparxstar-user-environment-check-app.bundle.min.js';
        $bundle_path = "{$base_path}{$bundle}";

        wp_enqueue_script(
            self::HANDLE_BOOTSTRAP,
            "{$base_uri}/{$bundle}",
            [],
            file_exists($bundle_path) ? filemtime($bundle_path) : self::VERSION,
            true
        );
    }

    /**
     * Enqueue frontend stylesheet (dev or minified).
     */
    private static function enqueue_frontend_styles(): void
    {
        // Determine if we're in development mode
        $is_dev = (defined('WP_DEBUG') && WP_DEBUG)
            || (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'development');

        // Dev mode uses src/css, prod mode uses assets/css
        $css_dir = $is_dev ? 'src/css' : 'assets/css';
        $base_uri  = plugins_url($css_dir, dirname(__FILE__, 2));
        $base_path = plugin_dir_path(dirname(__FILE__, 2)) . $css_dir . '/';

        // In dev mode, use unminified file; in prod mode, prefer minified
        if ($is_dev) {
            $style_file = 'sparxstar-user-environment-check.css';
        } else {
            $style_file = file_exists("{$base_path}sparxstar-user-environment-check.min.css")
                ? 'sparxstar-user-environment-check.min.css'
                : 'sparxstar-user-environment-check.css';
        }

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

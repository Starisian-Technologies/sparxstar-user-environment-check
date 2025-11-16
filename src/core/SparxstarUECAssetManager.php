<?php
declare(strict_types=1);

namespace SparxStar\UserEnvironmentCheck;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the enqueuing of all client-side assets for the Sparxstar User Environment Check plugin.
 * This class understands the six-module JavaScript architecture and enforces the correct
 * dependency chain to ensure a deterministic and reliable initialization.
 *
 * @version 2.0.0
 */
final class AssetManager
{
    private const VERSION = '2.0.0';
    private const TEXT_DOMAIN = 'sparxstar-user-environment-check';

    // --- Vendor Handles ---
    private const HANDLE_VENDOR_DEVICE_DETECTOR = 'sparxstar-vendor-device-detector';
    private const HANDLE_VENDOR_FINGERPRINTJS = 'sparxstar-vendor-fingerprintjs';

    // --- Module Handles (The 6-File Architecture) ---
    private const HANDLE_STATE = 'sparxstar-env-state';
    private const HANDLE_COLLECTORS = 'sparxstar-env-collectors';
    private const HANDLE_PROFILE = 'sparxstar-env-profile';
    private const HANDLE_SYNC = 'sparxstar-env-sync';
    private const HANDLE_UI = 'sparxstar-env-ui';
    private const HANDLE_INTEGRATOR = 'sparxstar-env-integrator'; // The master orchestrator

    // --- Style Handle ---
    private const STYLE_HANDLE = 'sparxstar-user-environment-check-styles';

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function enqueue_assets(): void
    {
        $base_uri = plugins_url('assets', dirname(__FILE__, 2));
        $base_path = plugin_dir_path(dirname(__FILE__, 2)) . 'assets/';

        // 1. Register all vendor scripts first.
        // These are dependencies for our main modules.
        wp_register_script(
            self::HANDLE_VENDOR_DEVICE_DETECTOR,
            "{$base_uri}/js/vendor/device-detector.min.js",
            [],
            '3.0.3', // Pin to a specific version for stability
            true
        );
        wp_register_script(
            self::HANDLE_VENDOR_FINGERPRINTJS,
            "{$base_uri}/js/vendor/fingerprintjs.min.js",
            [],
            '4.2.1', // Pin to a specific version
            true
        );

        // 2. Register all six of our JavaScript modules with their explicit dependencies.
        // This creates the dependency tree that WordPress will resolve.

        // `state` has no dependencies. It's the foundation.
        wp_register_script(self::HANDLE_STATE, "{$base_uri}/js/sparxstar-state.js", [], self::VERSION, true);

        // `collectors` depends on state and the vendor libraries.
        wp_register_script(self::HANDLE_COLLECTORS, "{$base_uri}/js/sparxstar-collectors.js", [self::HANDLE_STATE, self::HANDLE_VENDOR_DEVICE_DETECTOR, self::HANDLE_VENDOR_FINGERPRINTJS], self::VERSION, true);

        // `profile` depends on state.
        wp_register_script(self::HANDLE_PROFILE, "{$base_uri}/js/sparxstar-profile.js", [self::HANDLE_STATE], self::VERSION, true);

        // `sync` depends on state.
        wp_register_script(self::HANDLE_SYNC, "{$base_uri}/js/sparxstar-sync.js", [self::HANDLE_STATE], self::VERSION, true);

        // `ui` depends on state.
        wp_register_script(self::HANDLE_UI, "{$base_uri}/js/sparxstar-ui.js", [self::HANDLE_STATE], self::VERSION, true);

        // `integrator` is the final module. It depends on all other five modules.
        wp_register_script(self::HANDLE_INTEGRATOR, "{$base_uri}/js/sparxstar-integrator.js", [
            self::HANDLE_STATE,
            self::HANDLE_COLLECTORS,
            self::HANDLE_PROFILE,
            self::HANDLE_SYNC,
            self::HANDLE_UI,
        ], self::VERSION, true);

        // 3. Enqueue the final script in the chain.
        // WordPress will automatically see its dependencies and load all registered scripts
        // in the correct order. This is the key to a clean, deterministic load.
        wp_enqueue_script(self::HANDLE_INTEGRATOR);

        // 4. Localize data and attach it to the integrator.
        // This ensures the data is available just before the main orchestration script runs.
        wp_localize_script(self::HANDLE_INTEGRATOR, 'sparxstarUserEnvData', self::get_localization_data());

        // 5. Enqueue the stylesheet.
        $style_file = file_exists("{$base_path}css/" . self::STYLE_HANDLE . '.min.css')
            ? self::STYLE_HANDLE . '.min.css'
            : self::STYLE_HANDLE . '.css';
            
        wp_enqueue_style(
            self::STYLE_HANDLE,
            "{$base_uri}/css/{$style_file}",
            [],
            filemtime("{$base_path}css/{$style_file}")
        );
    }

    /**
     * Gathers all necessary server-side data to be passed to the client-side scripts.
     *
     * @return array The data to be localized.
     */
    private static function get_localization_data(): array
    {
        // In a real application, you would replace these with dynamic data.
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        return [
            'nonce' => wp_create_nonce('wp_rest'),
            'ip_address' => esc_html($user_ip),
            'rest_urls' => [
                'technical' => esc_url_raw(rest_url('sparxstar/v1/env/technical')),
                'identifiers' => esc_url_raw(rest_url('sparxstar/v1/env/identifiers')),
            ],
            'i18n' => [
                'notice' => __('Important Notice', self::TEXT_DOMAIN),
                'update_message' => __('For the best experience, please update your browser.', self::TEXT_DOMAIN),
                'update_link' => __('Learn how', self::TEXT_DOMAIN),
            ],
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
        ];
    }
}

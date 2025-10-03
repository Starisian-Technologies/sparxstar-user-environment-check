<?php

/**
 * Front-end asset registration for the User Environment Check (UEC) diagnostics experience.
 *
 * This class handles the registration, enqueueing, and localization of all
 * scripts and stylesheets specifically for the Sparxstar User Environment Check plugin.
 * It manages both internal custom scripts and third-party vendor libraries.
 *
 * @package SparxstarUserEnvironmentCheck
 * @author    Starisian Technologies (Max Barrett)
 * @version 1.0.0
 * @license Proprietary. Copyright 2025 Starisian Technology. All rights reserved.
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if (! defined('ABSPATH')) {
	exit;
}

// Ensure the StarUserEnv class is correctly available in this namespace or imported if different.
// This is used in localize_main_script for ip_address.
use Starisian\SparxstarUEC\StarUserEnv; // Assuming this is the correct namespace for StarUserEnv
use Exception; // For potential exceptions during file checks (e.g., realpath failures)
use function file_exists;
use function filemtime;
use function is_admin;
use function add_action;
use function plugin_dir_url;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_localize_script;
use function wp_register_script;
use function wp_register_style;
use function rest_url;
use function esc_html__;
use function esc_url_raw;
use function trailingslashit;

/**
 * Handles registration and localization of scripts and styles for Sparxstar UEC.
 */
final class SparxstarUECAssetManager
{
	/**
	 * Handle for the main stylesheet of the UEC plugin.
	 */
	private const STYLE_HANDLE = 'star-sparxstar-env-check';

	/**
	 * Handles for internal custom JavaScript bundles.
	 */
	private const SCRIPT_HANDLE_GLOBALS           = 'star-sparxstar-env-check-globals';
	private const SCRIPT_HANDLE_DEVICE            = 'star-sparxstar-env-check-device';
	private const SCRIPT_HANDLE_NETWORK           = 'star-sparxstar-env-check-network';
	private const SCRIPT_HANDLE_MAIN              = 'star-sparxstar-env-check-main';
	private const SCRIPT_HANDLE_FINGERPRINT_INTERNAL = 'star-sparxstar-env-check-fingerprint-internal'; // Internal script using FingerprintJS

    /**
     * Handles for third-party vendor JavaScript libraries.
     */
    private const VENDOR_HANDLE_FINGERPRINTJS    = 'sparxstar-uec-fingerprintjs';
    private const VENDOR_HANDLE_DEVICEDETECTOR   = 'sparxstar-uec-device-detector';


	/**
	 * Constructor: Initializes the asset manager and registers its WordPress hooks.
	 * Hooks are set with priorities to ensure vendor assets are registered/enqueued
	 * before internal assets that might depend on them.
	 */
	public function __construct()
	{
		// Vendor assets should typically be registered/enqueued first if internal scripts depend on them.
		add_action('wp_enqueue_scripts', array($this, 'sparxstar_enqueue_vendor_assets'), 5); 
		// Main plugin assets registered/enqueued next.
		add_action('wp_enqueue_scripts', array($this, 'register_assets'), 10);              
	}

	/**
	 * Registers and enqueues all public-facing assets (stylesheets and scripts).
	 * This method is hooked to 'wp_enqueue_scripts'.
	 */
	public function register_assets(): void
	{
		// Assets should not be enqueued in the WordPress admin area for frontend components.
		if (is_admin()) {
			return;
		}

        // Essential plugin constants must be defined in the main plugin file.
        // These constants are critical for correctly locating plugin files and URLs.
        if (!defined('SPX_ENV_CHECK_PLUGIN_FILE')) {
            error_log('[SparxstarUECAssetManager Error] SPX_ENV_CHECK_PLUGIN_FILE is not defined. Cannot register UEC assets.');
            return;
        }
        if (!defined('SPX_ENV_CHECK_PLUGIN_PATH')) {
            error_log('[SparxstarUECAssetManager Error] SPX_ENV_CHECK_PLUGIN_PATH is not defined. Cannot register UEC assets.');
            return;
        }

		// Determine base URL and path for asset resolution.
		$base_url  = trailingslashit(plugin_dir_url(SPX_ENV_CHECK_PLUGIN_FILE));
		$base_path = SPX_ENV_CHECK_PLUGIN_PATH;

		// Register and enqueue the primary custom stylesheet.
		$this->register_styles($base_url, $base_path);
		wp_enqueue_style(self::STYLE_HANDLE);

		// Register and enqueue all internal custom JavaScript bundles.
		$this->register_scripts($base_url, $base_path);
		wp_enqueue_script(self::SCRIPT_HANDLE_MAIN); // Enqueue the main UEC script, which has dependencies.
	}

	/**
	 * Registers the primary stylesheet for the UEC plugin.
	 *
	 * @param string $base_url  The base URL of the plugin.
	 * @param string $base_path The base file path of the plugin.
	 */
	private function register_styles(string $base_url, string $base_path): void
	{
		$style_path = 'src/css/sparxstar-user-env-check.css';
		$version    = $this->resolve_version($base_path . $style_path);

		wp_register_style(
			self::STYLE_HANDLE,
			$base_url . $style_path,
			[], // Stylesheets typically have no JS dependencies.
			$version
		);
        error_log('[SparxstarUECAssetManager] Registered Style: ' . self::STYLE_HANDLE . ' from ' . $base_url . $style_path);
	}

	/**
	 * Registers all internal modular JavaScript bundles for the UEC plugin.
	 *
	 * @param string $base_url  The base URL of the plugin.
	 * @param string $base_path The base file path of the plugin.
	 */
	private function register_scripts(string $base_url, string $base_path): void
	{
		error_log('[SparxstarUECAssetManager] Registering custom UEC Scripts');
		$scripts_to_register = array(
			self::SCRIPT_HANDLE_GLOBALS              => 'src/js/sparxstar-user-env-check-globals.js',
			self::SCRIPT_HANDLE_NETWORK              => 'src/js/sparxstar-user-env-check-network.js',
			self::SCRIPT_HANDLE_MAIN                 => 'src/js/sparxstar-user-env-check-main.js',
			self::SCRIPT_HANDLE_DEVICE               => 'src/js/sparxstar-user-env-check-device.js',
			self::SCRIPT_HANDLE_FINGERPRINT_INTERNAL => 'src/js/sparxstar-user-env-check-fingerprint.js', // Assuming this is your internal script
		);

		foreach ($scripts_to_register as $handle => $relative_path) {
			$deps    = $this->resolve_dependencies($handle);
			$version = $this->resolve_version($base_path . $relative_path);

			wp_register_script(
				$handle,
				$base_url . $relative_path,
				$deps,
				$version,
				true // Load script in the footer for performance.
			);
            error_log('[SparxstarUECAssetManager] Registered Script: ' . $handle . ' from ' . $base_url . $relative_path);
		}

		// Localize data for the main script once all scripts are registered.
		$this->localize_main_script();
	}

	/**
	 * Provides runtime configuration data (localization) to the main UEC JavaScript bundle.
	 * This data includes nonces, REST API endpoints, IP address, debug mode, and i18n strings.
	 */
	private function localize_main_script(): void
{
	// Ensure the text domain constant is defined for internationalization.
	if ( ! defined( 'SPX_ENV_CHECK_TEXT_DOMAIN' ) ) {
		error_log('[SparxstarUECAssetManager Error] SPX_ENV_CHECK_TEXT_DOMAIN not defined for localization. Using default strings.');
	}

	$data = array(
		'nonce'      => wp_create_nonce( 'wp_rest' ),
		'rest_urls'  => array(
			'log'         => esc_url_raw( rest_url( 'sparxstar-uec/v1/log' ) ),
			'fingerprint' => esc_url_raw( rest_url( 'sparxstar-uec/v1/fingerprint' ) ),
		),
		'ip_address' => StarUserEnv::get_current_visitor_ip(),
		'debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
		'i18n'       => array(
			'notice'         => defined( 'SPX_ENV_CHECK_TEXT_DOMAIN' )
				? esc_html__( 'Your browser is out of date.', SPX_ENV_CHECK_TEXT_DOMAIN )
				: 'Your browser is out of date.',
			'update_message' => defined( 'SPX_ENV_CHECK_TEXT_DOMAIN' )
				? esc_html__( 'Please update for the best SPARXSTAR experience.', SPX_ENV_CHECK_TEXT_DOMAIN )
				: 'Please update for the best SPARXSTAR experience.',
			'update_link'    => defined( 'SPX_ENV_CHECK_TEXT_DOMAIN' )
				? esc_html__( 'Update browser', SPX_ENV_CHECK_TEXT_DOMAIN )
				: 'Update browser',
			'dismiss'        => defined( 'SPX_ENV_CHECK_TEXT_DOMAIN' )
				? esc_html__( 'Dismiss notice', SPX_ENV_CHECK_TEXT_DOMAIN )
				: 'Dismiss notice',
		),
	);

	error_log(
		'[SparxstarUECAssetManager] Localizing script ' . self::SCRIPT_HANDLE_MAIN .
		' with data: ' . wp_json_encode( $data )
	);

	wp_localize_script( self::SCRIPT_HANDLE_MAIN, 'sparxstarUserEnvData', $data );
}
	/**
	 * Determines the WordPress-managed script dependencies for a given script handle.
	 * This uses a `match` expression (PHP 8.0+) for clear dependency mapping.
	 *
	 * @param string $handle The script handle to resolve dependencies for.
	 * @return array An array of script handles that the given handle depends on.
	 */
	private function resolve_dependencies(string $handle): array
	{
		return match ($handle) {
			// Main UEC script depends on all internal modules and vendor libraries.
			self::SCRIPT_HANDLE_MAIN             => array(self::SCRIPT_HANDLE_GLOBALS, self::SCRIPT_HANDLE_NETWORK, self::SCRIPT_HANDLE_DEVICE, self::SCRIPT_HANDLE_FINGERPRINT_INTERNAL, self::VENDOR_HANDLE_FINGERPRINTJS, self::VENDOR_HANDLE_DEVICEDETECTOR),
			// Device-related script depends on globals.
			self::SCRIPT_HANDLE_DEVICE           => array(self::SCRIPT_HANDLE_GLOBALS),
			// Network-related script depends on globals.
			self::SCRIPT_HANDLE_NETWORK          => array(self::SCRIPT_HANDLE_GLOBALS),
			// Globals script has no direct WordPress-managed dependencies.
			self::SCRIPT_HANDLE_GLOBALS          => array(),
            // Internal fingerprint script assumes no direct WordPress-managed dependencies here.
            self::SCRIPT_HANDLE_FINGERPRINT_INTERNAL => array(), 
            // Vendor FingerprintJS is typically self-contained due to its wrapper.
            self::VENDOR_HANDLE_FINGERPRINTJS    => array(), 
            // Vendor Device Detector JS is typically self-contained due to its wrapper.
            self::VENDOR_HANDLE_DEVICEDETECTOR   => array(), 
			// Crucial: Catches any unhandled handles, preventing "Unhandled match case" errors.
			default                              => array(), 
		};
	}

	/**
	 * Produces a cache-busting version identifier for an asset based on its file modification time.
	 * Falls back to a default version if the file does not exist or the constant is not defined.
	 *
	 * @param string $file_path The full file system path to the asset.
	 * @return string A version string.
	 */
	private function resolve_version(string $file_path): string
	{
		if (file_exists($file_path)) {
			$mtime = filemtime($file_path);
			if (is_int($mtime) && $mtime > 0) {
				return (string) $mtime;
			}
		}
        // Fallback version if file doesn't exist or mtime fails.
		return defined('SPX_ENV_CHECK_VERSION') ? SPX_ENV_CHECK_VERSION : '1.0.0';
	}

	/**
	 * Registers and enqueues third-party vendor JavaScript libraries (FingerprintJS, Device Detector).
	 * This method is hooked to 'wp_enqueue_scripts' with an earlier priority to ensure vendors
	 * are available before internal scripts that might depend on them.
	 */
	public function sparxstar_enqueue_vendor_assets(): void
	{
		error_log('[SparxstarUECAssetManager] Enqueueing Vendor Assets');
        // Ensure the plugin path constant is defined for locating vendor files.
        if (!defined('SPX_ENV_CHECK_PLUGIN_PATH')) {
            error_log('[SparxstarUECAssetManager Error] SPX_ENV_CHECK_PLUGIN_PATH is not defined. Cannot enqueue vendor assets.');
            return;
        }

		// Construct full paths to vendor files for file_exists check and versioning.
		$fingerprintjs_file_path = realpath(SPX_ENV_CHECK_PLUGIN_PATH . 'assets/js/fingerprintjs/dist/fp.min.js');
		$device_detector_file_path = realpath(SPX_ENV_CHECK_PLUGIN_PATH . 'assets/js/device-detector.min.js');

		// Get the base URL for plugin assets for correct browser loading.
        $base_url = plugin_dir_url(SPX_ENV_CHECK_PLUGIN_FILE);

		// --- Register and Enqueue FingerprintJS ---
		if (!file_exists($fingerprintjs_file_path)) {
			error_log('[SparxstarUECAssetManager Error] Vendor FingerprintJS not found at expected path: ' . (SPX_ENV_CHECK_PLUGIN_PATH . 'assets/js/fingerprintjs/dist/fp.min.js'));
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>Sparxstar User Environment Check:</strong> Required vendor script "FingerprintJS" not found. Please ensure all plugin files are correctly uploaded.</p></div>';
            });
		} else {
            wp_register_script(
                self::VENDOR_HANDLE_FINGERPRINTJS,
                $base_url . 'assets/js/fingerprintjs/dist/fp.min.js',
                $this->resolve_dependencies(self::VENDOR_HANDLE_FINGERPRINTJS),
                $this->resolve_version($fingerprintjs_file_path),
                true // Load in footer
            );
            wp_enqueue_script(self::VENDOR_HANDLE_FINGERPRINTJS);
            error_log('[SparxstarUECAssetManager] Enqueued Vendor Script: ' . self::VENDOR_HANDLE_FINGERPRINTJS);
		}


		// --- Register and Enqueue Device Detector JS ---
		if (!file_exists($device_detector_file_path)) {
			error_log('[SparxstarUECAssetManager Error] Vendor Device Detector JS not found at expected path: ' . (SPX_ENV_CHECK_PLUGIN_PATH . 'assets/js/device-detector.min.js'));
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>Sparxstar User Environment Check:</strong> Required vendor script "Device Detector JS" not found. Please ensure all plugin files are correctly uploaded.</p></div>';
            });
		} else {
            wp_register_script(
                self::VENDOR_HANDLE_DEVICEDETECTOR,
                $base_url . 'assets/js/device-detector.min.js',
                $this->resolve_dependencies(self::VENDOR_HANDLE_DEVICEDETECTOR),
                $this->resolve_version($device_detector_file_path),
                true // Load in footer
            );
            wp_enqueue_script(self::VENDOR_HANDLE_DEVICEDETECTOR);
            error_log('[SparxstarUECAssetManager] Enqueued Vendor Script: ' . self::VENDOR_HANDLE_DEVICEDETECTOR);
		}
	}
}
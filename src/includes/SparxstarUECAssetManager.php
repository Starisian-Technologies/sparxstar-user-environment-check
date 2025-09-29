<?php
/**
 * Front-end asset registration for the diagnostics experience.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\includes;

use Starisian\SparxstarUEC\StarUserEnv;

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
 * Handles registration and localization of scripts and styles.
 */
final class SparxstarUECAssetManager
{

	/**
	 * Handle for the stylesheet used by the front-end banner components.
	 */
	private const STYLE_HANDLE = 'star-sparxstar-env-check';

	/**
	 * Handles for the modular JavaScript bundles.
	 */
	private const SCRIPT_HANDLE_GLOBALS = 'star-sparxstar-env-check-globals';
	private const SCRIPT_HANDLE_DEVICE = 'star-sparxstar-env-check-device';
	private const SCRIPT_HANDLE_NETWORK = 'star-sparxstar-env-check-network';
	private const SCRIPT_HANDLE_MAIN = 'star-sparxstar-env-check-main';

	/**
	 * Bootstrap WordPress hooks.
	 */
	public function __construct()
	{
		add_action('wp_enqueue_scripts', array($this, 'register_assets'));
	}

	/**
	 * Register and enqueue all public-facing assets.
	 */
	public function register_assets(): void
	{
		if (is_admin()) {
			return;
		}

		$base_url = trailingslashit(plugin_dir_url(SPX_ENV_CHECK_PLUGIN_FILE));
		$base_path = SPX_ENV_CHECK_PLUGIN_PATH;

		$this->register_styles($base_url, $base_path);
		$this->register_scripts($base_url, $base_path);

		wp_enqueue_style(self::STYLE_HANDLE);
		wp_enqueue_script(self::SCRIPT_HANDLE_MAIN);
	}

	/**
	 * Register the front-end stylesheet with a cache-busting version.
	 */
	private function register_styles(string $base_url, string $base_path): void
	{
		$style_path = 'src/css/sparxstar-user-env-check.css';
		$version = $this->resolve_version($base_path . $style_path);

		wp_register_style(
			self::STYLE_HANDLE,
			$base_url . $style_path,
			array(),
			$version
		);
	}

	/**
	 * Register the modular scripts and pass runtime configuration.
	 */
	private function register_scripts(string $base_url, string $base_path): void
	{
		$scripts = array(
			self::SCRIPT_HANDLE_GLOBALS => 'src/js/sparxstar-user-env-check-globals.js',
			self::SCRIPT_HANDLE_DEVICE => 'src/js/sparxstar-user-env-check-device.js',
			self::SCRIPT_HANDLE_NETWORK => 'src/js/sparxstar-user-env-check-network.js',
			self::SCRIPT_HANDLE_MAIN => 'src/js/sparxstar-user-env-check-main.js',
		);

		foreach ($scripts as $handle => $relative_path) {
			$deps = $this->resolve_dependencies($handle);
			$version = $this->resolve_version($base_path . $relative_path);

			wp_register_script(
				$handle,
				$base_url . $relative_path,
				$deps,
				$version,
				true
			);
		}

		$this->localize_main_script();
	}

	/**
	 * Provide runtime configuration to the main diagnostics bundle.
	 */
	private function localize_main_script(): void
	{
		$data = array(
			'nonce' => wp_create_nonce('wp_rest'),
			'rest_url' => esc_url_raw(rest_url('star-sparxstar-user-environment-check/v1/log')),
			'ip_address' => StarUserEnv::getClientIP(),
			'debug' => defined('WP_DEBUG') && WP_DEBUG,
			'i18n' => array(
				'notice' => esc_html__('Your browser is out of date.', SPX_ENV_CHECK_TEXT_DOMAIN),
				'update_message' => esc_html__('Please update for the best SPARXSTAR experience.', SPX_ENV_CHECK_TEXT_DOMAIN),
				'update_link' => esc_html__('Update browser', SPX_ENV_CHECK_TEXT_DOMAIN),
				'dismiss' => esc_html__('Dismiss notice', SPX_ENV_CHECK_TEXT_DOMAIN),
			),
		);

		wp_localize_script(self::SCRIPT_HANDLE_MAIN, 'envCheckData', $data);
	}

	/**
	 * Determine script dependencies based on the handle.
	 */
	private function resolve_dependencies(string $handle): array
	{
		return match ($handle) {
			self::SCRIPT_HANDLE_DEVICE => array(self::SCRIPT_HANDLE_GLOBALS),
			self::SCRIPT_HANDLE_NETWORK => array(self::SCRIPT_HANDLE_GLOBALS),
			self::SCRIPT_HANDLE_MAIN => array(
				self::SCRIPT_HANDLE_GLOBALS,
				self::SCRIPT_HANDLE_DEVICE,
				self::SCRIPT_HANDLE_NETWORK,
			),
			default => array(),
		};
	}

	/**
	 * Produce a cache-busting version identifier for assets.
	 */
	private function resolve_version(string $file_path): string
	{
		if (file_exists($file_path)) {
			$mtime = filemtime($file_path);
			if (is_int($mtime) && $mtime > 0) {
				return (string) $mtime;
			}
		}

		return SPX_ENV_CHECK_VERSION;
	}
}

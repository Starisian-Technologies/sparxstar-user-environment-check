<?php
/**
 * Bootstrapper for the SPARXSTAR User Environment Check plugin.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC;

if (!defined('ABSPATH')) {
	exit;
}

use Starisian\SparxstarUEC\api\SparxstarUECAPI;
use Starisian\SparxstarUEC\includes\SparxstarUECAssetManager;
use Starisian\SparxstarUEC\services\SparxstarUECGeoIPService;
use Starisian\SparxstarUEC\admin\SparxstarUECAdmin;
use Exception;

/**
 * Orchestrates plugin services and exposes shared dependencies.
 */
class SparxstarUserEnvironmentCheck
{

	/**
	 * Shared singleton instance.
	 */
	private static ?SparxstarUserEnvironmentCheck $instance = null;

	/**
	 * REST API handler used to persist environment snapshots.
	 */
	private ?SparxstarUECAPI $api = null;
	/**
	 * Manages registration and localization of scripts and styles.
	 */
	private ?SparxstarUECAssetManager $asset_manager = null;
	private ?SparxstarUECGeoIPService $geoip = null;
	private ?SparxstarUECAdmin $admin = null;

	/**
	 * Retrieve the singleton instance and bootstrap the plugin.
	 */
	public static function get_instance(): SparxstarUserEnvironmentCheck
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire the plugin components together.
	 */
	private function __construct()
	{
		// Intentionally left empty.
	}

	public function init(): void
	{
		try {
			error_log('Instantiating SparxstarUECAPI');
			$this->api = SparxstarUECAPI::get_instance();
			error_log('Instantiating SparxstarUECAssetManager');
			$this->asset_manager = new SparxstarUECAssetManager();
			error_log('Instantiating SparxstarUECGeoIPService');
			$this->geoip = new SparxstarUECGeoIPService();
			error_log('Instantiating SparxstarUECAdmin');
			$this->admin = new SparxstarUECAdmin();
			error_log('Registering hooks');
			self::register_hooks();
		} catch (Exception $e) {
			error_log('Error initializing SparxstarUserEnvironmentCheck: ' . esc_html($e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()));
		}
	}

	/**
	 * Attach WordPress hooks owned by the bootstrapper.
	 */
	private function register_hooks(): void
	{
		add_action('init', array($this, 'load_textdomain'));
		add_action('send_headers', array($this, 'add_client_hints_header'));
	}

	/**
	 * Load the plugin translation files.
	 */
	public function load_textdomain(): void
	{
		load_plugin_textdomain(
			SPX_ENV_CHECK_TEXT_DOMAIN,
			false,
			dirname(plugin_basename(SPX_ENV_CHECK_PLUGIN_FILE)) . '/languages'
		);
	}

	/**
	 * Advertise the client hints required by the diagnostics pipeline.
	 */
	public function add_client_hints_header(): void
	{
		if (is_admin()) {
			return;
		}

		header(
			'Accept-CH: Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Model, '
			. 'Sec-CH-UA-Full-Version, Sec-CH-UA-Platform-Version, Sec-CH-UA-Bitness',
			false
		);
	}

	/**
	 * Expose the REST API handler for dependent services.
	 */
	public function get_api(): SparxstarUECAPI
	{
		return $this->api;
	}
	public function get_asset_manager(): SparxstarUECAssetManager
	{
		return $this->asset_manager;
	}
	public function get_geoip(): SparxstarUECGeoIPService
	{
		return $this->geoip;
	}
	public function get_admin(): SparxstarUECAdmin
	{
		return $this->admin;
	}
}

<?php

/**
 * Bootstrapper for the SPARXSTAR User Environment Check plugin.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC;

if (! defined('ABSPATH')) {
	exit;
}

use Exception;
use LogicException;
use Starisian\SparxstarUEC\helpers\StarLogger;
use Starisian\SparxstarUEC\admin\SparxstarUECAdmin;
use Starisian\SparxstarUEC\api\SparxstarUECRESTController;
use Starisian\SparxstarUEC\core\SparxstarUECAssetManager;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;
use Starisian\SparxstarUEC\core\SparxstarUECKernel;
use Starisian\SparxstarUEC\core\SparxstarUECSnapshotRepository;
use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;

/**
 * Orchestrates plugin services and exposes shared dependencies.
 * This is a thin WordPress integration layer - the Kernel handles service construction.
 */
final class SparxstarUserEnvironmentCheck
{


	/**
	 * Shared singleton instance.
	 */
	private static ?SparxstarUserEnvironmentCheck $instance = null;

	/**
	 * REST API handler used to persist environment snapshots.
	 */
	private ?SparxstarUECRESTController $api = null;

	/**
	 * Database handler for direct SQL operations.
	 */
	private ?SparxstarUECDatabase $database = null;

	/**
	 * Asset manager for enqueueing scripts and styles.
	 */
	private ?SparxstarUECAssetManager $asset_manager = null;

	/**
	 * Session manager for session handling.
	 */
	private ?SparxstarUECSessionManager $session_manager = null;

	/**
	 * Admin interface handler.
	 */
	private ?SparxstarUECAdmin $admin = null;

	/**
	 * Retrieve the singleton instance and bootstrap the plugin.
	 */
	public static function spx_uec_get_instance(): SparxstarUserEnvironmentCheck
	{
		try {
			if (self::$instance !== null) {
				StarLogger::debug('SparxstarUserEnvironmentCheck', 'Returning existing singleton instance');
				return self::$instance;
			}

			StarLogger::debug('SparxstarUserEnvironmentCheck', 'Creating new singleton instance');
			self::$instance = new self();
			return self::$instance;
		} catch (Exception $e) {
			StarLogger::error(
				'SparxstarUserEnvironmentCheck',
				$e,
				array(
					'method' => 'spx_uec_get_instance',
					'context' => 'singleton_initialization',
				)
			);
			throw $e; // Re-throw to allow bootstrap to handle
		}
	}

	/**
	 * Wire the plugin components together via the Kernel.
	 */
	private function __construct()
	{
		try {
			global $wpdb;

			// Kernel builds all services with dependency injection
			$kernel = new SparxstarUECKernel($wpdb);

			// Extract services from kernel
			$this->database        = $kernel->get_database();
			$this->api             = $kernel->get_api();
			$this->asset_manager   = $kernel->get_assets();
			$this->session_manager = $kernel->get_session();
			$this->admin           = $kernel->get_admin();

			StarLogger::info('SparxstarUserEnvironmentCheck', 'Kernel initialized services successfully.');
			$this->register_hooks();
		} catch (Exception $e) {
			StarLogger::error('SparxstarUserEnvironmentCheck', $e, array('method' => '__construct'));
			return;
		}
	}
	/**
	 * Attach WordPress hooks owned by the bootstrapper.
	 */
	public function register_hooks(): void
	{
		try {
			add_action('init', array($this, 'load_textdomain'));
			add_action('send_headers', array($this, 'add_client_hints_header'));

			if ($this->api instanceof SparxstarUECRESTController) {
				add_action('rest_api_init', array($this->api, 'register_routes'));
				StarLogger::info('SparxstarUserEnvironmentCheck', 'REST routes registered.');
			} else {
				StarLogger::warning('SparxstarUserEnvironmentCheck', 'REST controller unavailable, routes not registered.');
			}
			StarLogger::debug('SparxstarUserEnvironmentCheck', 'Hooks registered successfully.');
		} catch (Exception $e) {
			StarLogger::error(
				'SparxstarUserEnvironmentCheck',
				$e,
				array(
					'method' => 'register_hooks',
					'context' => 'hook_registration',
				)
			);
			// Don't re-throw - allow plugin to continue with partial functionality
		}
	}
	/**
	 * Load the plugin translation files.
	 */
	public function load_textdomain(): void
	{
		try {
			$loaded = load_plugin_textdomain(
				SPX_ENV_CHECK_TEXT_DOMAIN,
				false,
				dirname(plugin_basename(SPX_ENV_CHECK_PLUGIN_FILE)) . '/languages'
			);

			if (! $loaded) {
				StarLogger::debug(
					'SparxstarUserEnvironmentCheck',
					'Textdomain not loaded - translation files may be missing.'
				);
			}
		} catch (Exception $e) {
			StarLogger::error(
				'SparxstarUserEnvironmentCheck',
				$e,
				array(
					'method' => 'load_textdomain',
					'context' => 'translation_loading',
				)
			);
		}
	}
	/**
	 * Advertise the client hints required by the diagnostics pipeline.
	 */
	public function add_client_hints_header(): void
	{
		try {
			if (is_admin()) {
				return;
			}

			if (headers_sent($file, $line)) {
				StarLogger::warning(
					'SparxstarUserEnvironmentCheck',
					'Cannot add Client Hints header - headers already sent.',
					array(
						'method' => 'add_client_hints_header',
						'file' => $file,
						'line' => $line,
					)
				);
				return;
			}

			header(
				'Accept-CH: Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Model, '
					. 'Sec-CH-UA-Full-Version, Sec-CH-UA-Platform-Version, Sec-CH-UA-Bitness',
				false
			);
		} catch (Exception $e) {
			StarLogger::error(
				'SparxstarUserEnvironmentCheck',
				$e,
				array(
					'method' => 'add_client_hints_header',
					'context' => 'header_injection',
				)
			);
		}
	}
	/**
	 * Expose the REST API handler for dependent services.
	 */
	public function get_api(): ?SparxstarUECRESTController
	{
		return $this->api;
	}

	/**
	 * Prevents cloning of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to clone the object.
	 */
	public function __clone()
	{
		throw new LogicException('Cloning of ' . esc_html(__CLASS__) . ' is not allowed.');
	}

	/**
	 * Prevents unserializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to unserialize the object.
	 */
	public function __wakeup()
	{
		throw new LogicException('Unserializing of ' . esc_html(__CLASS__) . ' is not allowed.');
	}

	/**
	 * Prevents serializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to serialize the object.
	 */
	public function __sleep(): array
	{
		throw new LogicException('Cannot serialize class');
	}
	/**
	 * Prevents serializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to serialize the object.
	 */
	public function __serialize(): array
	{
		throw new LogicException('Serialization of ' . __CLASS__ . ' is not allowed.');
	}

	/**
	 * Prevents serializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to serialize the object.
	 */
	public function __unserialize(array $data): void
	{
		throw new LogicException('Unserialization of ' . __CLASS__ . ' is not allowed.');
	}
}

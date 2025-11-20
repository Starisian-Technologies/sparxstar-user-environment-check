<?php
/**
 * Kernel: Constructs and wires all service objects.
 * 
 * This is the dependency injection container for the plugin.
 * It builds all services with their dependencies and exposes them
 * to the orchestrator. No WordPress hooks or side effects here.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wpdb;
use Starisian\SparxstarUEC\admin\SparxstarUECAdmin;
use Starisian\SparxstarUEC\api\SparxstarUECRESTController;
use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;

/**
 * Kernel: Pure dependency wiring and service construction.
 */
final class SparxstarUECKernel {

	private SparxstarUECDatabase $database;
	private SparxstarUECRESTController $api;
	private SparxstarUECAssetManager $asset_manager;
	private SparxstarUECSessionManager $session_manager;
	private SparxstarUECAdmin $admin;

	/**
	 * Construct and wire all service objects.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( wpdb $wpdb ) {
		// Build services in dependency order
		$this->database        = new SparxstarUECDatabase( $wpdb );
		$this->api             = new SparxstarUECRESTController( $this->database );
		$this->asset_manager   = new SparxstarUECAssetManager();
		$this->session_manager = new SparxstarUECSessionManager();
		$this->admin           = new SparxstarUECAdmin();
	}

	/**
	 * Get the database service.
	 *
	 * @return SparxstarUECDatabase
	 */
	public function get_database(): SparxstarUECDatabase {
		return $this->database;
	}

	/**
	 * Get the REST API controller.
	 *
	 * @return SparxstarUECRESTController
	 */
	public function get_api(): SparxstarUECRESTController {
		return $this->api;
	}

	/**
	 * Get the asset manager.
	 *
	 * @return SparxstarUECAssetManager
	 */
	public function get_assets(): SparxstarUECAssetManager {
		return $this->asset_manager;
	}

	/**
	 * Get the session manager.
	 *
	 * @return SparxstarUECSessionManager
	 */
	public function get_session(): SparxstarUECSessionManager {
		return $this->session_manager;
	}

	/**
	 * Get the admin interface handler.
	 *
	 * @return SparxstarUECAdmin
	 */
	public function get_admin(): SparxstarUECAdmin {
		return $this->admin;
	}
}

<?php
/**
 * Bootstrapper for the SPARXSTAR User Environment Check plugin.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Exception;
use LogicException;
use Starisian\SparxstarUEC\helpers\StarLogger;
use Starisian\SparxstarUEC\admin\SparxstarUECAdmin;
use Starisian\SparxstarUEC\api\SparxstarUECRESTController;
use Starisian\SparxstarUEC\core\SparxstarUECAssetManager;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;
use Starisian\SparxstarUEC\core\SparxstarUECSnapshotRepository;
use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;

/**
 * Orchestrates plugin services and exposes shared dependencies.
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
         * Manages registration and localization of scripts and styles.
         */
        private ?SparxstarUECDatabase $database = null;
        /**
         * Repository interface that brokers snapshot storage operations.
         */
        private ?SparxstarUECSnapshotRepository $repository = null;
        /**
         * Front-end asset manager responsible for enqueueing scripts and styles.
         */
        private ?SparxstarUECAssetManager $asset_manager = null;
        /**
         * Session manager that maps users to snapshot identifiers.
         */
        private ?SparxstarUECSessionManager $session_manager = null;
        /**
         * Administrative integration for the plugin settings screen.
         */
        private ?SparxstarUECAdmin $admin = null;

	/**
	 * Retrieve the singleton instance and bootstrap the plugin.
	 */
       /**
     * Retrieve the singleton instance and bootstrap the plugin.
     */
    public static function spx_uec_get_instance(): SparxstarUserEnvironmentCheck
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
		try {
			global $wpdb; // Access the global WordPress database object

			// 1. Initialize the database handler
			$this->database = new SparxstarUECDatabase( $wpdb );

			// 2. Initialize the REST API controller (SparxstarUECRESTController)
			// Pass the database handler as a dependency to its constructor.
			StarLogger::debug( 'SparxstarUserEnvironmentCheck', 'Instantiating SparxstarUECRESTController' );
			$this->api = new SparxstarUECRESTController( $this->database );
			StarLogger::debug( 'SparxstarUserEnvironmentCheck', 'Instantiating SparxstarUECSnapshotRepository' );
			$this->repository = new SparxstarUECSnapshotRepository();
			StarLogger::debug( 'SparxstarUserEnvironmentCheck', 'Instantiating SparxstarUECAssetManager' );
			$this->asset_manager = new SparxstarUECAssetManager();
			StarLogger::debug( 'SparxstarUserEnvironmentCheck', 'Instantiating SparxstarUECSessionManager' );
			$this->session_manager = new SparxstarUECSessionManager();
			StarLogger::debug( 'SparxstarUserEnvironmentCheck', 'Instantiating SparxstarUECAdmin' );
			$this->admin = new SparxstarUECAdmin();
			StarLogger::info( 'SparxstarUserEnvironmentCheck', 'Services instantiated successfully.' );
			$this->register_hooks();
		} catch ( Exception $e ) {
			StarLogger::error( 'SparxstarUserEnvironmentCheck', $e, array( 'method' => '__construct' ) );
			return;
		}
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		StarLogger::debug( 'SparxstarUserEnvironmentCheck', '[UEC INIT CALLED] ' . json_encode( array_column( $trace, 'function' ) ) );
	}
	/**
	 * Attach WordPress hooks owned by the bootstrapper.
	 */
	public function register_hooks(): void
	{
		try{
			add_action( 'init', array( $this, 'load_textdomain' ) );
			add_action( 'send_headers', array( $this, 'add_client_hints_header' ) );

			if ( $this->api instanceof SparxstarUECRESTController ) {
				add_action( 'rest_api_init', array( $this->api, 'register_routes' ) );
				StarLogger::info( 'SparxstarUserEnvironmentCheck', 'REST routes registered.' );
			} else {
				StarLogger::warning( 'SparxstarUserEnvironmentCheck', 'REST controller unavailable, routes not registered.' );
			}
			StarLogger::debug( 'SparxstarUserEnvironmentCheck', 'Registering hooks.' );
		} catch ( Exception $e ) {
			StarLogger::error( 'SparxstarUserEnvironmentCheck', $e, array( 'method' => 'register_hooks' ) );
		}
		
	}	/**
	 * Load the plugin translation files.
	 */
	public function load_textdomain(): void
	{
		try {
			load_plugin_textdomain(
				SPX_ENV_CHECK_TEXT_DOMAIN,
				false,
				dirname( plugin_basename( SPX_ENV_CHECK_PLUGIN_FILE ) ) . '/languages'
			);
		} catch ( Exception $e ) {
			StarLogger::error( 'SparxstarUserEnvironmentCheck', $e, array( 'method' => 'load_textdomain' ) );
		}
	}	/**
	 * Advertise the client hints required by the diagnostics pipeline.
	 */
	public function add_client_hints_header(): void
	{
		try {
			if ( is_admin() ) {
				return;
			}

			header(
				'Accept-CH: Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Model, '
				. 'Sec-CH-UA-Full-Version, Sec-CH-UA-Platform-Version, Sec-CH-UA-Bitness',
				false
			);
		} catch ( Exception $e ) {
			StarLogger::error( 'SparxstarUserEnvironmentCheck', $e, array( 'method' => 'add_client_hints_header' ) );
		}
	}	/**
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
                throw new LogicException( 'Cloning of ' . esc_html( __CLASS__ ) . ' is not allowed.' );
        }

	/**
	 * Prevents unserializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to unserialize the object.
	 */
        public function __wakeup()
        {
                throw new LogicException( 'Unserializing of ' . esc_html( __CLASS__ ) . ' is not allowed.' );
        }
	
	/**
	 * Prevents serializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to serialize the object.
	 */
        public function __sleep(): array
        {
                throw new LogicException( 'Cannot serialize class' );
        }
	/**
	 * Prevents serializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to serialize the object.
	 */
        public function __serialize(): array
        {
                throw new LogicException( 'Serialization of ' . __CLASS__ . ' is not allowed.' );
        }

        /**
         * Prevents serializing of the singleton instance.
         *
         * @since 0.1.0
         * @throws LogicException If someone tries to serialize the object.
         */
        public function __unserialize(array $data): void
        {
                throw new LogicException( 'Unserialization of ' . __CLASS__ . ' is not allowed.' );
        }
}

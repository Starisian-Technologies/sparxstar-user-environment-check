<?php
/**
 * Plugin Name:       SPARXSTAR User Environment Check
 * Plugin URI:        https://github.com/Starisian-Technologies/sparxstar-user-environment-check
 * Description:       A network-wide utility that checks browser compatibility and logs anonymized technical data for diagnostics, with consent via the WP Consent API.
 * Version:           2.2.4 (Multinetwork Aware)
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       sparxstar-user-environment-check
 * Domain Path:       /languages
 * License:           Proprietary
 * License URI:       https://github.com/Starisian-Technologies/sparxstar-user-environment-check/LICENSE
 * Update URI:        https://github.com/Starisian-Technologies/sparxstar-user-environment-check.git
 * Copyright:         2025 Starisian Technologies
 * Copyright URI:     https://starisian.com/copyright
 *
 * @package           SparxstarUserEnvironmentCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define the plugin file constant for the new EnvCheckAPI
define( 'SPX_ENV_CHECK_PLUGIN_FILE', __FILE__ );

/**
 * Primary plugin controller for SPARXSTAR User Environment Check.
 *
 * Coordinates asset loading, REST handling, log storage, and housekeeping tasks
 * with awareness of multisite and multinetwork environments.
 */
final class Sparxstar_User_Environment_Check {

	/**
	 * Class instance used to implement the singleton pattern.
	 *
	 * @var ?Sparxstar_User_Environment_Check
	 */
	private static ?Sparxstar_User_Environment_Check $instance = null;

	/**
	 * Absolute path to the directory used for NDJSON log storage.
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Hook name used when scheduling the housekeeping cron task.
	 *
	 * @var string
	 */
	private string $cron_hook;

	/**
	 * Identifier for the active network. Ensures multinetwork isolation.
	 *
	 * @var int
	 */
	private int $network_id;

	/**
	 * Number of days to retain NDJSON log files before pruning.
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Text domain identifier used for all translation lookups.
	 */
	const TEXT_DOMAIN = 'sparxstar-user-environment-check';

	/**
	 * Current plugin version used for asset cache-busting.
	 */
	const VERSION = '2.2.4';

	/**
	 * Instantiate or retrieve the singleton instance.
	 *
	 * @return Sparxstar_User_Environment_Check
	 */
	public static function init(): Sparxstar_User_Environment_Check {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Establish network-aware properties and register hooks.
	 */
	private function __construct() {
		$this->network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
		$this->log_dir    = trailingslashit( WP_CONTENT_DIR ) . 'envcheck-logs/network-' . $this->network_id;
		$this->cron_hook  = 'envcheck_cron_housekeeping_' . $this->network_id;

		// Load the enhanced EnvCheckAPI
		require_once __DIR__ . '/src/includes/EnvCheckAPI.php';

		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
		add_action( $this->cron_hook, [ $this, 'housekeeping' ] );
		add_action( 'init', [ $this, 'schedule_cron_jobs' ] );
	}

	/**
	 * Load the plugin translation files.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Register front-end assets, respecting consent when available.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$consent_category = apply_filters( 'envcheck_consent_category', 'statistics' );
		if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( $consent_category ) ) {
			return;
		}

		$script_path = __DIR__ . '/assets/js/sparxstar-user-environment-check.min.js';
		$style_path  = __DIR__ . '/assets/css/sparxstar-user-environment-check.min.css';
		$base_url    = plugin_dir_url( __FILE__ );

		$script_version = file_exists( $script_path ) ? (string) filemtime( $script_path ) : self::VERSION;
		$style_version  = file_exists( $style_path ) ? (string) filemtime( $style_path ) : self::VERSION;

		wp_enqueue_script(
			'envcheck-js',
			$base_url . 'assets/js/sparxstar-user-environment-check.min.js',
			[],
			$script_version,
			true
		);

		wp_enqueue_style(
			'envcheck-css',
			$base_url . 'assets/css/sparxstar-user-environment-check.min.css',
			[],
			$style_version
		);

		wp_localize_script(
			'envcheck-js',
			'envCheckData',
			[
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'rest_url' => rest_url( 'env/v1/log' ),
				'i18n'     => [
					'notice'         => __( 'Notice:', self::TEXT_DOMAIN ),
					'update_message' => __( 'Your browser may be outdated. For the best experience, please', self::TEXT_DOMAIN ),
					'update_link'    => __( 'update your browser', self::TEXT_DOMAIN ),
					'dismiss'        => __( 'Dismiss', self::TEXT_DOMAIN ),
				],
			]
		);
	}

	/**
	 * Register the REST API endpoint used for logging snapshots.
	 *
	 * @return void
	 */
	public function register_rest_route(): void {
		register_rest_route(
			'env/v1',
			'/log',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_log_request' ],
				'permission_callback' => [ $this, 'validate_rest_request' ],
			]
		);
	}

	/**
	 * Validate REST API requests before processing payloads.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return bool|WP_Error True when the request is allowed or an error when blocked.
	 */
	public function validate_rest_request( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) ) {
			return new WP_Error( 'envcheck_missing_nonce', __( 'Security token missing.', self::TEXT_DOMAIN ), [ 'status' => 403 ] );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'envcheck_invalid_nonce', __( 'Security token is invalid or expired.', self::TEXT_DOMAIN ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Recursively sanitize incoming payload values.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_recursive( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ $this, 'sanitize_recursive' ], $value );
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
	}

	/**
	 * Handle the REST API request to persist an environment snapshot.
	 *
	 * @param WP_REST_Request $request Incoming REST request containing JSON diagnostics.
	 * @return WP_REST_Response REST response indicating success or failure.
	 */
	public function handle_log_request( WP_REST_Request $request ) {
		$consent_category = apply_filters( 'envcheck_consent_category', 'statistics' );
		if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( $consent_category ) ) {
			return new WP_REST_Response( [ 'success' => false, 'data' => __( 'Consent not provided.', self::TEXT_DOMAIN ) ], 403 );
		}

		$ip      = $request->get_ip_address();
		$ip_hash = md5( $ip );

		if ( ! is_user_logged_in() && get_transient( 'envcheck_rate_' . $ip_hash ) ) {
			return new WP_REST_Response( [ 'success' => false, 'data' => __( 'Rate limit hit. Please try again later.', self::TEXT_DOMAIN ) ], 429 );
		}
		set_transient( 'envcheck_rate_' . $ip_hash, 1, MINUTE_IN_SECONDS );

		$raw_data = $request->get_json_params();
		if ( ! is_array( $raw_data ) ) {
			return new WP_REST_Response( [ 'success' => false, 'data' => __( 'Invalid data format.', self::TEXT_DOMAIN ) ], 400 );
		}
		$sanitized_data = $this->sanitize_recursive( $raw_data );

		$current_user_id = get_current_user_id();
		$session_id      = $sanitized_data['sessionId'] ?? null;
		$daily_key       = $this->get_daily_key( $current_user_id, $ip_hash, $session_id );

		if ( get_site_transient( $daily_key ) ) {
			return new WP_REST_Response( [ 'success' => false, 'data' => __( 'Already logged today for this session/device.', self::TEXT_DOMAIN ) ], 429 );
		}
		set_site_transient( $daily_key, 1, DAY_IN_SECONDS );

		$client_hints = [];
		foreach ( [ 'Sec-CH-UA', 'Sec-CH-UA-Mobile', 'Sec-CH-UA-Platform', 'Sec-CH-UA-Model', 'Sec-CH-UA-Arch', 'Sec-CH-UA-Bitness', 'Sec-CH-UA-Full-Version' ] as $hint_name ) {
			$header_value = $request->get_header( $hint_name );
			if ( ! empty( $header_value ) ) {
				$client_hints[ $hint_name ] = sanitize_text_field( $header_value );
			}
		}

		$privacy                 = $sanitized_data['privacy'] ?? [];
		$payload                 = $sanitized_data;
		$payload['client_hints'] = $client_hints;

		if ( ! empty( $privacy['doNotTrack'] ) || ! empty( $privacy['gpc'] ) ) {
			$payload = [
				'privacy'      => $privacy,
				'userAgent'    => $sanitized_data['userAgent'] ?? 'N/A',
				'os'           => $sanitized_data['os'] ?? 'N/A',
				'language'     => $sanitized_data['language'] ?? 'N/A',
				'compatible'   => $sanitized_data['compatible'] ?? 'unknown',
				'features'     => $sanitized_data['features'] ?? [],
				'client_hints' => $client_hints,
				'sessionId'    => $sanitized_data['sessionId'] ?? null,
			];
		}

		$payload = apply_filters( 'sparxstar_env_snapshot_payload', $payload, $request );

		$entry = [
			'timestamp_utc' => gmdate( 'c' ),
			'user_id'       => $current_user_id,
			'site'          => [
				'home'    => home_url(),
				'blog_id' => get_current_blog_id(),
			],
			'diagnostics'   => $payload,
		];

		if ( ! $this->ensure_log_directory() ) {
			return new WP_REST_Response( [ 'success' => false, 'data' => __( 'Log directory unavailable.', self::TEXT_DOMAIN ) ], 500 );
		}

		$log_file = $this->log_dir . '/envcheck-' . gmdate( 'Y-m-d' ) . '.ndjson';
		$result   = file_put_contents( $log_file, wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );

		if ( false === $result ) {
			error_log( 'SPARXSTAR EnvCheck: Log write failed to ' . $log_file );
			do_action( 'envcheck_log_error', $log_file, $entry );
			return new WP_REST_Response( [ 'success' => false, 'data' => __( 'Log write failed.', self::TEXT_DOMAIN ) ], 500 );
		}

		set_transient( 'sparxstar_env_last_' . $daily_key, $entry, DAY_IN_SECONDS );

		do_action( 'sparxstar_env_snapshot_saved', $entry );

		return new WP_REST_Response( [ 'success' => true, 'data' => __( 'Data logged.', self::TEXT_DOMAIN ), 'snapshot_id' => $daily_key ], 200 );
	}

	/**
	 * Public method to retrieve the latest snapshot for a user/session.
	 *
	 * @param int|null    $user_id    The user ID to look for. Defaults to current user.
	 * @param string|null $session_id Optional session ID to narrow the search.
	 * @return array|null The snapshot entry array, or null if not found.
	 */
	public function get_snapshot( $user_id = null, $session_id = null ) {
		$user_id   = is_null( $user_id ) ? get_current_user_id() : (int) $user_id;
		$remote_ip = $this->get_remote_address();
		$ip_hash   = md5( $remote_ip );
		$key       = $this->get_daily_key( $user_id, $ip_hash, $session_id );

		$cached_snapshot = get_transient( 'sparxstar_env_last_' . $key );
		if ( false !== $cached_snapshot && is_array( $cached_snapshot ) ) {
			return $cached_snapshot;
		}

		$log_file = $this->log_dir . '/envcheck-' . gmdate( 'Y-m-d' ) . '.ndjson';
		if ( ! file_exists( $log_file ) || ! is_readable( $log_file ) ) {
			return null;
		}

		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		foreach ( array_reverse( $lines ) as $line ) {
			$entry = json_decode( $line, true );
			if ( ! $entry || ! isset( $entry['user_id'] ) ) {
				continue;
			}

			if ( (int) $entry['user_id'] === $user_id ) {
				if ( ! $session_id || ( $entry['diagnostics']['sessionId'] ?? null ) === $session_id ) {
					return $entry;
				}
			}
		}

		return null;
	}

	/**
	 * Helper to consistently generate the daily transient key.
	 *
	 * @param int         $user_id    User identifier.
	 * @param string      $ip_hash    Hash of the visitor IP address.
	 * @param string|null $session_id Optional session identifier.
	 * @return string Generated transient key scoped per network.
	 */
	private function get_daily_key( $user_id, $ip_hash, $session_id = null ) {
		$key = $user_id ? 'envcheck_daily_user_' . $user_id : 'envcheck_daily_anon_' . $ip_hash;
		if ( $session_id ) {
			$key .= '_' . sanitize_key( $session_id );
		}

		return $key . '_network_' . $this->network_id;
	}

	/**
	 * Delete expired NDJSON log files from the log directory.
	 *
	 * @return void
	 */
	public function housekeeping(): void {
		if ( ! is_dir( $this->log_dir ) ) {
			return;
		}

		foreach ( new DirectoryIterator( $this->log_dir ) as $fileinfo ) {
			if ( $fileinfo->isFile() && 'ndjson' === $fileinfo->getExtension() && str_starts_with( $fileinfo->getFilename(), 'envcheck-' ) ) {
				if ( $fileinfo->getMTime() < time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) ) {
					if ( ! unlink( $fileinfo->getPathname() ) ) {
						error_log( 'SPARXSTAR EnvCheck: Unable to remove expired log file ' . $fileinfo->getPathname() );
					}
				}
			}
		}
	}

	/**
	 * Schedule daily housekeeping tasks on first run.
	 *
	 * @return void
	 */
	public function schedule_cron_jobs(): void {
		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			wp_schedule_event( time(), 'daily', $this->cron_hook );
		}
	}

	/**
	 * Create and protect the network-aware log directory.
	 *
	 * @return bool True when the directory is writable.
	 */
	private function ensure_log_directory(): bool {
		if ( ! wp_mkdir_p( $this->log_dir ) && ! is_dir( $this->log_dir ) ) {
			error_log( 'SPARXSTAR EnvCheck: Failed to create log directory ' . $this->log_dir );
			return false;
		}

		$htaccess_path = $this->log_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_path ) && false === file_put_contents( $htaccess_path, "Require all denied\n", LOCK_EX ) ) {
			error_log( 'SPARXSTAR EnvCheck: Failed to protect log directory with .htaccess.' );
		}

		$index_path = $this->log_dir . '/index.php';
		if ( ! file_exists( $index_path ) && false === file_put_contents( $index_path, "<?php // Silence is golden" ) ) {
			error_log( 'SPARXSTAR EnvCheck: Failed to create log directory index file.' );
		}

		return is_writable( $this->log_dir );
	}

	/**
	 * Retrieve the visitor IP address from server variables.
	 *
	 * @return string IP address or empty string when unavailable.
	 */
	private function get_remote_address(): string {
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
		$remote_addr = is_string( $remote_addr ) ? sanitize_text_field( wp_unslash( $remote_addr ) ) : '';

		if ( filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return $remote_addr;
		}

		return '';
	}
}

/**
 * Global helper function for easy access to the environment snapshot.
 *
 * Provides a simple, predictable way for other plugins and themes to retrieve
 * the most recent environment data for a user and/or session.
 *
 * @param int|null    $user_id    The user ID. Defaults to the current user.
 * @param string|null $session_id Optional. The specific session ID to find.
 * @return array|null The full snapshot data or null if not found.
 */
if ( ! function_exists( 'sparxstar_get_env_snapshot' ) ) {
	function sparxstar_get_env_snapshot( $user_id = null, $session_id = null ) {
		return Sparxstar_User_Environment_Check::init()->get_snapshot( $user_id, $session_id );
	}
}


// Initialize the plugin.
Sparxstar_User_Environment_Check::init();

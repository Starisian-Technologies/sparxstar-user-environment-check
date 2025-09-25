<<<<<<< HEAD:src/includes/EnvCheckAPI.php
<?php
/**
 * Environment Check REST API Handler
 *
 * Handles the collection and storage of environment diagnostics with enhanced
 * security, session awareness, client hints, and a database-first architecture.
 *
 * @package SparxstarUserEnvironmentCheck
 * @since 3.0.0
 */

namespace Sparxstar\EnvCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class EnvCheckAPI {

	private static ?self $instance = null;

	/** Rate limiting settings */
	private const RATE_LIMIT_WINDOW_SECONDS = 300; // 5 minutes
	private const RATE_LIMIT_MAX_REQUESTS   = 15;  // Max requests per window per IP

	/** Database table name for snapshots (without prefix). */
	private const TABLE_NAME = 'sparxstar_env_snapshots';

	/** Snapshot retention in days for cleanup. */
	private const SNAPSHOT_RETENTION_DAYS = 30;

	public static function init(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		\add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
		\add_action( 'init', [ $this, 'schedule_cleanup' ] );
		\add_action( 'sparxstar_env_cleanup_hook', [ $this, 'cleanup_old_snapshots' ] );

		// ## REFINED: The activation hook now correctly points to the installer method. ##
		// Assuming your main plugin file defines SPX_ENV_CHECK_PLUGIN_FILE.
		if ( defined( 'SPX_ENV_CHECK_PLUGIN_FILE' ) ) {
			\register_activation_hook( SPX_ENV_CHECK_PLUGIN_FILE, [ $this, 'create_db_table' ] );
		}
	}

	/**
	 * ## NEW: Database Table Installer using dbDelta. ##
	 * Creates or updates the necessary database table on plugin activation.
	 * This is multisite/network-aware by using $wpdb->base_prefix.
	 */
	public function create_db_table() {
		global $wpdb;
		// Use base_prefix to create one table for the entire network.
		$table_name = $wpdb->base_prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			session_id VARCHAR(128) NULL DEFAULT NULL,
			snapshot_hash VARCHAR(64) NOT NULL,
			client_ip_hash VARCHAR(64) NOT NULL,
			server_side_data JSON NOT NULL,
			client_side_data JSON NOT NULL,
			client_hints_data JSON NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY snapshot_hash (snapshot_hash),
			KEY user_session (user_id, session_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		// We need to load the upgrade file to use dbDelta.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
	
	/**
	 * Registers the REST API endpoint for environment logging.
	 */
	public function register_rest_route() {
		\register_rest_route(
			'sparxstar-env/v1', // Custom namespace for clarity.
			'/log',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_log_request' ],
				'permission_callback' => '__return_true', // Open endpoint for anonymized data.
				'args'                => [
					'data' => [
						'required'    => true,
						'type'        => 'object',
						'description' => 'Environment diagnostic data from client.',
					],
				],
			]
		);
	}

	/**
	 * Handles incoming POST requests to the environment log endpoint.
	 *
	 * @param \WP_REST_Request $request The incoming REST API request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_log_request( \WP_REST_Request $request ) {
		// 1. Nonce Verification
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'invalid_nonce',
				\__( 'Invalid security token.', 'sparxstar-user-environment-check' ),
				[ 'status' => 403 ]
			);
		}

		// 2. Rate Limiting Check
		if ( ! $this->check_rate_limit() ) {
			return new \WP_Error(
				'rate_limited',
				\__( 'Too many requests. Please wait before sending more data.', 'sparxstar-user-environment-check' ),
				[ 'status' => 429 ]
			);
		}

		$client_data = $request->get_json_params();

		if ( empty( $client_data ) || ! is_array( $client_data ) ) {
			return new \WP_Error( 'invalid_data', \__( 'Invalid JSON payload.', 'sparxstar-user-environment-check' ), [ 'status' => 400 ] );
		}

		// 3. Sanitize Client Data
		$sanitized_client_data = $this->sanitize_client_diagnostic_data( $client_data );

		if ( \is_wp_error( $sanitized_client_data ) ) {
			return $sanitized_client_data;
		}

		// 4. Collect Server-Side Data
		$server_side_data = $this->collect_server_side_data();

		// 5. Collect Client Hints
		$client_hints = $this->collect_client_hints();

		// Determine user and session context
		$user_id    = \get_current_user_id() ?: null;
		$session_id = $sanitized_client_data['sessionId'] ?? null;
		$client_ip  = $this->get_client_ip();

		// For anonymous users, create a robust fingerprint (combining IP and User-Agent)
		$user_fingerprint = null;
		if ( ! $user_id ) {
			$user_fingerprint = \hash( 'sha256', $client_ip . $server_side_data['userAgent'] );
		}

		// Hash the combined environment data to detect changes
		// Exclude volatile data like battery level, rtt if they frequently change but don't signify environment change
		$env_hash_data = [
			'user_id'          => $user_id,
			'user_fingerprint' => $user_fingerprint,
			'session_id'       => $session_id,
			'server_side'      => $server_side_data,
			'client_side'      => $sanitized_client_data,
			'client_hints'     => $client_hints,
		];
		// Remove highly dynamic data before hashing for environment change detection
		unset( $env_hash_data['client_side']['battery'], $env_hash_data['client_side']['network']['rtt'] );

		$snapshot_hash = \hash( 'sha256', \wp_json_encode( $env_hash_data ) );

		// 6. Store/Update Snapshot in DB
		$result = $this->store_diagnostic_snapshot(
			$user_id,
			$user_fingerprint,
			$session_id,
			\hash( 'sha256', $client_ip ), // Store hashed IP for privacy
			$snapshot_hash,
			$server_side_data,
			$sanitized_client_data,
			$client_hints
		);

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		$log_message = \sprintf(
			'[EnvCheck] Diagnostic data %s: User: %s, Session: %s, IP: %s, Hash: %s',
			( $result['status'] === 'updated' ? 'updated' : 'stored' ),
			$user_id ?: ( $user_fingerprint ? 'Anon-' . \substr( $user_fingerprint, 0, 8 ) : 'Unknown' ),
			$session_id ?: 'none',
			$client_ip,
			\substr( $snapshot_hash, 0, 8 )
		);
		\error_log( $log_message );

		return new \WP_REST_Response(
			[
				'status'      => 'ok',
				'action'      => $result['status'],
				'snapshot_id' => $result['id'],
				'timestamp'   => \current_time( 'mysql', true ),
			],
			200
		);
	}

	/**
	 * Collect server-side environment data.
	 *
	 * @return array
	 */
	private function collect_server_side_data(): array {
		return [
			'ip'         => $this->get_client_ip(),
			'userAgent'  => $this->get_user_agent(),
			'sessionID'  => \session_id() ?: 'none',
			'currentURL' => \home_url( $_SERVER['REQUEST_URI'] ?? '' ),
			'referrerURL' => \wp_get_referer() ?: '',
			'language'   => \get_locale(),
			'os'         => $this->detect_os(),
			'browser'    => $this->detect_browser(),
			'isBot'      => $this->is_bot(),
			'requestMethod' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
			'isAjax'     => \wp_doing_ajax(),
			'wpEnvType'  => \wp_get_environment_type(),
			'geolocation' => [], // Placeholder for geo data
		];
	}

	/**
	 * Collect Client Hints from headers with a filter for extensibility.
	 */
	private function collect_client_hints(): array {
		$client_hints = [];
		
		// ## REFINED: Added a filter to make the list of Client Hints extensible. ##
		$hint_headers = \apply_filters(
			'sparxstar_env_client_hint_headers',
			[
				'Sec-CH-UA'                  => 'userAgentBrand',
				'Sec-CH-UA-Mobile'           => 'mobile',
				'Sec-CH-UA-Platform'         => 'platform',
				'Sec-CH-UA-Platform-Version' => 'platformVersion',
				'Sec-CH-UA-Arch'             => 'architecture',
				'Sec-CH-UA-Bitness'          => 'bitness',
				'Sec-CH-UA-Model'            => 'model',
				'Sec-CH-UA-Full-Version'     => 'fullVersion',
				'Sec-CH-Prefers-Color-Scheme' => 'prefersColorScheme', // Future-proof
			]
		);

		foreach ( $hint_headers as $header => $key ) {
			// Headers are in $_SERVER as HTTP_SEC_CH_UA...
			$server_key = 'HTTP_' . \strtoupper( \str_replace( '-', '_', $header ) );
			if ( ! empty( $_SERVER[ $server_key ] ) ) {
				$client_hints[ $key ] = \sanitize_text_field( \wp_unslash( $_SERVER[ $server_key ] ) );
			}
		}

		return $client_hints;
	}
	
	// ... (check_rate_limit, sanitize_client_diagnostic_data, sanitize_array_recursively remain the same) ...

	/**
	 * Checks and updates rate limit for the current client IP.
	 *
	 * @return bool True if allowed, false if rate limited.
	 */
	private function check_rate_limit(): bool {
		$client_ip = $this->get_client_ip();
		// Use a hashed IP for transient key to avoid exposing raw IP in options table.
		$rate_key = 'sparxstar_env_rate_' . \hash( 'md5', $client_ip );

		$current_requests = (int) \get_transient( $rate_key );

		if ( $current_requests >= self::RATE_LIMIT_MAX_REQUESTS ) {
			return false;
		}

		// Increment and set/update transient.
		\set_transient( $rate_key, $current_requests + 1, self::RATE_LIMIT_WINDOW_SECONDS );

		return true;
	}

	/**
	 * Sanitizes and validates diagnostic data received from the client.
	 *
	 * @param array $data The raw client data.
	 * @return array|\WP_Error Sanitized data or WP_Error on invalid input.
	 */
	private function sanitize_client_diagnostic_data( array $data ): array|\WP_Error {
		$sanitized = [];

		// Define allowed top-level fields and their expected types/sanitization rules
		$allowed_fields = [
			'sessionId'     => [ 'type' => 'string', 'sanitizer' => 'sanitize_text_field' ],
			'userAgent'     => [ 'type' => 'string', 'sanitizer' => 'sanitize_textarea_field' ], // User agent can be long
			'os'            => [ 'type' => 'string', 'sanitizer' => 'sanitize_text_field' ],
			'language'      => [ 'type' => 'string', 'sanitizer' => 'sanitize_text_field' ],
			'screen'        => [ 'type' => 'array', 'sanitizer' => null ],
			'device'        => [ 'type' => 'array', 'sanitizer' => null ], // From device-detector-js
			'network'       => [ 'type' => 'array', 'sanitizer' => null ], // From Network Information API
			'features'      => [ 'type' => 'array', 'sanitizer' => null ],
			'privacy'       => [ 'type' => 'array', 'sanitizer' => null ],
			'compatible'    => [ 'type' => 'boolean', 'sanitizer' => 'boolval' ],
			'storage'       => [ 'type' => 'array', 'sanitizer' => null ],
			'micPermission' => [ 'type' => 'string', 'sanitizer' => 'sanitize_text_field' ],
			'battery'       => [ 'type' => 'array', 'sanitizer' => null ],
		];

		foreach ( $allowed_fields as $field => $rules ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			$value = $data[ $field ];

			// Type checking
			if ( gettype( $value ) !== $rules['type'] ) {
				// Allow string for numbers if they can be cast
				if ( ( $rules['type'] === 'integer' || $rules['type'] === 'float' ) && is_string( $value ) && is_numeric( $value ) ) {
					// Will be cast later
				} elseif ( $rules['type'] === 'boolean' && ( $value === 'true' || $value === 'false' || is_numeric( $value ) ) ) {
					// Will be cast later
				} else {
					return new \WP_Error(
						'invalid_data_type',
						\sprintf( \__( 'Invalid data type for field "%s". Expected %s.', 'sparxstar-user-environment-check' ), $field, $rules['type'] ),
						[ 'status' => 400, 'field' => $field ]
					);
				}
			}

			if ( $rules['sanitizer'] && is_callable( $rules['sanitizer'] ) ) {
				$sanitized[ $field ] = call_user_func( $rules['sanitizer'], $value );
			} elseif ( is_array( $value ) ) {
				$sanitized[ $field ] = $this->sanitize_array_recursively( $value );
			} else {
				$sanitized[ $field ] = $value; // Should ideally have a sanitizer or type cast
			}
		}

		// Specific validation for sessionId (if present)
		if ( isset( $sanitized['sessionId'] ) && ! \preg_match( '/^[a-zA-Z0-9_-]{10,128}$/', $sanitized['sessionId'] ) ) {
			return new \WP_Error( 'invalid_session', \__( 'Invalid session ID format.', 'sparxstar-user-environment-check' ), [ 'status' => 400 ] );
		}

		// Data minimization if privacy signals are strong
		if ( ! empty( $sanitized['privacy']['doNotTrack'] ) || ! empty( $sanitized['privacy']['gpc'] ) ) {
			$minimized_fields = [
				'sessionId', 'privacy', 'userAgent', 'os', 'compatible', 'features', 'device', 'network',
			];
			// Filter to only include necessary fields
			$sanitized = \array_intersect_key( $sanitized, \array_flip( $minimized_fields ) );
		}

		return $sanitized;
	}

	/**
	 * Recursively sanitize array values.
	 *
	 * @param array $array Input array.
	 * @return array Sanitized array.
	 */
	private function sanitize_array_recursively( array $array ): array {
		$sanitized = [];
		foreach ( $array as $key => $value ) {
			$key = \sanitize_key( $key );
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_array_recursively( $value );
			} elseif ( is_scalar( $value ) ) {
				$sanitized[ $key ] = \sanitize_text_field( (string) $value );
			}
		}
		return $sanitized;
	}

	/**
	 * Stores the diagnostic snapshot in the database.
	 * If a record with the same snapshot_hash exists, it updates the timestamp.
	 *
	 * @return array|\WP_Error Result array with status and ID, or WP_Error on failure.
	 */
	private function store_diagnostic_snapshot( ?int $user_id, ?string $user_fingerprint, ?string $session_id, string $client_ip_hash, string $snapshot_hash, array $server_data, array $client_data, array $client_hints ): array|\WP_Error {
		global $wpdb;
		$table_name = $wpdb->base_prefix . self::TABLE_NAME;

		// Check if a snapshot with this exact environment hash already exists.
		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE snapshot_hash = %s", $snapshot_hash ) );

		if ( $existing_id ) {
			// If it exists, we don't need to re-insert the data. Just update the `updated_at` timestamp.
			$wpdb->update(
				$table_name,
				[ 'updated_at' => \current_time( 'mysql' ) ], // Touch the record
				[ 'id' => $existing_id ],
				[ '%s' ],
				[ '%d' ]
			);
			// ## CONCEPTUAL: Invalidate Redis cache for this user/session if you implement caching ##
			// if ( function_exists('wp_cache_delete') ) {
			//     $cache_key = "snapshot:" . ($user_id ?? $user_fingerprint) . ":" . $session_id;
			//     wp_cache_delete($cache_key, 'sparxstar_env');
			// }
			return [ 'status' => 'updated', 'id' => $existing_id ];
		}

		// If no existing record, insert a new one.
		$result = $wpdb->insert(
			$table_name,
			[
				'user_id'           => $user_id,
				'session_id'        => $session_id,
				'snapshot_hash'     => $snapshot_hash,
				'client_ip_hash'    => $client_ip_hash,
				'server_side_data'  => \wp_json_encode( $server_data ),
				'client_side_data'  => \wp_json_encode( $client_data ),
				'client_hints_data' => ! empty( $client_hints ) ? \wp_json_encode( $client_hints ) : null,
				'created_at'        => \current_time( 'mysql' ),
				'updated_at'        => \current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_error', __( 'Could not write snapshot to the database.', 'sparxstar-user-environment-check' ), [ 'status' => 500 ] );
		}

		return [ 'status' => 'inserted', 'id' => $wpdb->insert_id ];
	}

	/**
	 * Schedule the daily cleanup job using WP Cron.
	 */
	public function schedule_cleanup() {
		if ( ! \wp_next_scheduled( 'sparxstar_env_cleanup_hook' ) ) {
			// Schedule to run daily, around midnight server time.
			\wp_schedule_event( \time(), 'daily', 'sparxstar_env_cleanup_hook' );
		}
	}

	/**
	 * ## NEW: Data Retention / Pruning Cron Job. ##
	 * Deletes old snapshot records from the database based on the retention period.
	 */
	public function cleanup_old_snapshots() {
		global $wpdb;
		$table_name = $wpdb->base_prefix . self::TABLE_NAME;
		$retention_days = (int) \apply_filters( 'sparxstar_env_retention_days', self::SNAPSHOT_RETENTION_DAYS );

		if ( $retention_days <= 0 ) {
			return; // Retention disabled.
		}

		// Prepare and execute the DELETE query.
		$sql = $wpdb->prepare(
			"DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$retention_days
		);

		$rows_deleted = $wpdb->query( $sql );

		if ( $rows_deleted > 0 ) {
			\error_log( \sprintf( '[EnvCheck] Cleaned up %d old snapshot records.', $rows_deleted ) );
		}
	}

	/**
	 * Helper methods for server-side data collection.
	 */
	private function get_client_ip(): string {
		// Check for various forwarded headers
		$ip_keys = [
			'HTTP_CF_CONNECTING_IP',     // Cloudflare
			'HTTP_CLIENT_IP',            // Proxy
			'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
			'HTTP_X_FORWARDED',          // Proxy
			'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
			'HTTP_FORWARDED_FOR',        // Proxy
			'HTTP_FORWARDED',            // Proxy
			'REMOTE_ADDR'                // Standard
		];

		foreach ( $ip_keys as $key ) {
			if ( \array_key_exists( $key, $_SERVER ) === true ) {
				$ip = \sanitize_text_field( \wp_unslash( $_SERVER[ $key ] ) );
				if ( \strpos( $ip, ',' ) !== false ) {
					$ip = \explode( ',', $ip )[0];
				}
				$ip = \trim( $ip );
				if ( \filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	}

	private function get_user_agent(): string {
		return \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? 'unknown' ) );
	}

	private function detect_os(): string {
		$user_agent = $this->get_user_agent();
		
		$os_patterns = [
			'/windows nt 10/i'      => 'Windows 10',
			'/windows nt 6.3/i'     => 'Windows 8.1',
			'/windows nt 6.2/i'     => 'Windows 8',
			'/windows nt 6.1/i'     => 'Windows 7',
			'/windows nt 6.0/i'     => 'Windows Vista',
			'/windows nt 5.2/i'     => 'Windows Server 2003/XP x64',
			'/windows nt 5.1/i'     => 'Windows XP',
			'/windows xp/i'         => 'Windows XP',
			'/windows nt 5.0/i'     => 'Windows 2000',
			'/windows me/i'         => 'Windows ME',
			'/win98/i'              => 'Windows 98',
			'/win95/i'              => 'Windows 95',
			'/win16/i'              => 'Windows 3.11',
			'/macintosh|mac os x/i' => 'Mac OS X',
			'/mac_powerpc/i'        => 'Mac OS 9',
			'/linux/i'              => 'Linux',
			'/ubuntu/i'             => 'Ubuntu',
			'/iphone/i'             => 'iPhone',
			'/ipod/i'               => 'iPod',
			'/ipad/i'               => 'iPad',
			'/android/i'            => 'Android',
			'/blackberry/i'         => 'BlackBerry',
			'/webos/i'              => 'Mobile'
		];

		foreach ( $os_patterns as $regex => $os ) {
			if ( \preg_match( $regex, $user_agent ) ) {
				return $os;
			}
		}

		return 'Unknown OS';
	}

	private function detect_browser(): string {
		$user_agent = $this->get_user_agent();
		
		$browser_patterns = [
			'/msie/i'      => 'Internet Explorer',
			'/firefox/i'   => 'Firefox',
			'/safari/i'    => 'Safari',
			'/chrome/i'    => 'Chrome',
			'/edge/i'      => 'Edge',
			'/opera/i'     => 'Opera',
			'/netscape/i'  => 'Netscape',
			'/maxthon/i'   => 'Maxthon',
			'/konqueror/i' => 'Konqueror',
			'/mobile/i'    => 'Handheld Browser'
		];

		foreach ( $browser_patterns as $regex => $browser ) {
			if ( \preg_match( $regex, $user_agent ) ) {
				return $browser;
			}
		}

		return 'Unknown Browser';
	}

	private function is_bot(): bool {
		$user_agent = $this->get_user_agent();
		$bot_patterns = [
			'/bot/i', '/crawl/i', '/slurp/i', '/spider/i', '/mediapartners/i'
		];

		foreach ( $bot_patterns as $pattern ) {
			if ( \preg_match( $pattern, $user_agent ) ) {
				return true;
			}
		}

		return false;
	}
}

// Initialize the API
EnvCheckAPI::init();
=======
<?php
namespace Starisian\SparxstarUEC\api;
/**
 * Sparxstar Environment Check REST API Handler
 *
 * Handles the collection and storage of environment diagnostics with enhanced
 * security, session awareness, client hints, and concurrency handling,
 * specifically targeting MariaDB storage for snapshots.
 *
 * @package SparxstarUserEnvironmentCheck
 * @since 1.0.0
 * @version 1.0.0
 * @see StarUserUtils for server-side environment data collection.
 * @see SparxstarUserEnvironmentCheckCacheHelper for caching layer integration.
 */

namespace SparxstarUEC\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class SparxstarUserEnvironmentCheckAPI {

	private static ?self $instance = null; // Use nullable type hint for consistency.

	/**
	 * Rate limiting settings
	 */
	private const RATE_LIMIT_WINDOW_SECONDS = 300; // 5 minutes
	private const RATE_LIMIT_MAX_REQUESTS   = 10;  // Max requests per window per IP

	/**
	 * Database table name for snapshots.
	 */
	private const TABLE_NAME = 'sparxstar_env_snapshots';

	/**
	 * Snapshot retention in days for cleanup (if not handled by cron).
	 */
	private const SNAPSHOT_RETENTION_DAYS = 30; // Default retention

	/**
	 * Singleton instance.
	 *
	 * @return self
	 */
	public static function init(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
		add_action( 'init', [ $this, 'schedule_cleanup' ] ); // Schedule on init, ensure DB table
		register_activation_hook( SPX_ENV_CHECK_PLUGIN_FILE, [ $this, 'create_db_table' ] );
	}

	/**
	 * Registers the REST API endpoint for environment logging.
	 */
	public function register_rest_route() {
		register_rest_route(
			'sparxstar-env/v1', // Custom namespace for clarity.
			'/log',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_log_request' ],
				'permission_callback' => '__return_true', // Open endpoint for anonymized data.
				'args'                => [
					'data' => [
						'required'    => true,
						'type'        => 'object',
						'description' => 'Environment diagnostic data from client.',
					],
				],
			]
		);
	}
	/**
	 * Stores the diagnostic snapshot in the database and updates the cache.
	 * If a record with the same snapshot_hash exists, it updates the timestamp and invalidates the cache.
	 *
	 * @return array|\WP_Error Result array with status and ID, or WP_Error on failure.
	 */
	private function store_diagnostic_snapshot( ?int $user_id, ?string $user_fingerprint, ?string $session_id, string $client_ip_hash, string $snapshot_hash, array $server_data, array $client_data, array $client_hints ): array|\WP_Error {
		global $wpdb;
		$table_name = $wpdb->base_prefix . self::TABLE_NAME;

		// Check if a snapshot with this exact environment hash already exists.
		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE snapshot_hash = %s", $snapshot_hash ) );

		if ( $existing_id ) {
			// Environment is identical. Just update the timestamp to show it's still active.
			$wpdb->update(
				$table_name,
				[ 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => $existing_id ],
				[ '%s' ],
				[ '%d' ]
			);

			// ## CACHE WIRING: Invalidate the cache for this user/session. ##
			// The next call to EnvCheckCache::get_snapshot() will trigger a fresh DB lookup.
			EnvCheckCache::delete_snapshot( $user_id, $session_id, $user_fingerprint );

			return [ 'status' => 'updated', 'id' => $existing_id ];
		}

		// Environment is new. Insert a new record.
		$current_time = current_time( 'mysql' );
		$data_to_insert = [
			'user_id'           => $user_id,
			'session_id'        => $session_id,
			'snapshot_hash'     => $snapshot_hash,
			'client_ip_hash'    => $client_ip_hash,
			'server_side_data'  => wp_json_encode( $server_data ),
			'client_side_data'  => wp_json_encode( $client_data ),
			'client_hints_data' => ! empty( $client_hints ) ? wp_json_encode( $client_hints ) : null,
			'created_at'        => $current_time,
			'updated_at'        => $current_time,
		];
		
		$result = $wpdb->insert( $table_name, $data_to_insert );

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_error', __( 'Could not write snapshot to the database.', 'sparxstar-user-environment-check' ), [ 'status' => 500 ] );
		}

		$new_id = $wpdb->insert_id;

		// ## CACHE WIRING: Prime the cache with the new snapshot data. ##
		// This prevents the next read from hitting the database.
		$snapshot_for_cache = [
			'id' => $new_id,
			'user_id' => $user_id,
			'session_id' => $session_id,
			// ... add other fields as they are in the DB row ...
			'server_side_data' => $server_data, // Use the raw array for cache
			'client_side_data' => $client_data,
			'client_hints_data' => $client_hints,
			'created_at' => $current_time,
			'updated_at' => $current_time,
		];
		EnvCheckCache::set_snapshot( $user_id, $session_id, $snapshot_for_cache, $user_fingerprint );

		return [ 'status' => 'inserted', 'id' => $new_id ];
	}

	/**
	 * Handles incoming POST requests to the environment log endpoint.
	 *
	 * @param \WP_REST_Request $request The incoming REST API request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_log_request( \WP_REST_Request $request ) {
		// 1. Nonce Verification
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'invalid_nonce',
				__( 'Invalid security token.', 'sparxstar-user-environment-check' ),
				[ 'status' => 403 ]
			);
		}

		// 2. Rate Limiting Check
		if ( ! $this->check_rate_limit() ) {
			return new \WP_Error(
				'rate_limited',
				__( 'Too many requests. Please wait before sending more data.', 'sparxstar-user-environment-check' ),
				[ 'status' => 429 ]
			);
		}

		$client_data = $request->get_json_params();

		if ( empty( $client_data ) || ! is_array( $client_data ) ) {
			return new \WP_Error( 'invalid_data', __( 'Invalid JSON payload.', 'sparxstar-user-environment-check' ), [ 'status' => 400 ] );
		}

		// 3. Sanitize Client Data
		$sanitized_client_data = $this->sanitize_client_diagnostic_data( $client_data );

		if ( is_wp_error( $sanitized_client_data ) ) {
			return $sanitized_client_data;
		}

		// 4. Collect Server-Side Data
		$server_side_data = $this->collect_server_side_data();

		// 5. Collect Client Hints
		$client_hints = $this->collect_client_hints();

		// Determine user and session context
		$user_id    = get_current_user_id() ?: null;
		$session_id = $sanitized_client_data['sessionId'] ?? null;
		$client_ip  = StarUserUtils::getClientIP();

		// For anonymous users, create a robust fingerprint (combining IP and User-Agent)
		$user_fingerprint = null;
		if ( ! $user_id ) {
			$user_fingerprint = hash( 'sha256', $client_ip . $server_side_data['userAgent'] );
		}

		// Hash the combined environment data to detect changes
		// Exclude volatile data like battery level, rtt if they frequently change but don't signify environment change
		$env_hash_data = [
			'user_id'          => $user_id,
			'user_fingerprint' => $user_fingerprint,
			'session_id'       => $session_id,
			'server_side'      => $server_side_data,
			'client_side'      => $sanitized_client_data,
			'client_hints'     => $client_hints,
		];
		// Remove highly dynamic data before hashing for environment change detection
		unset( $env_hash_data['client_side']['battery'], $env_hash_data['client_side']['network']['rtt'] );

		$snapshot_hash = hash( 'sha256', wp_json_encode( $env_hash_data ) );

		// 6. Store/Update Snapshot in DB
		$result = $this->store_diagnostic_snapshot(
			$user_id,
			$user_fingerprint,
			$session_id,
			hash( 'sha256', $client_ip ), // Store hashed IP for privacy
			$snapshot_hash,
			$server_side_data,
			$sanitized_client_data,
			$client_hints
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$log_message = sprintf(
			'[EnvCheck] Diagnostic data %s: User: %s, Session: %s, IP: %s, Hash: %s',
			( $result['status'] === 'updated' ? 'updated' : 'stored' ),
			$user_id ?: ( $user_fingerprint ? 'Anon-' . substr( $user_fingerprint, 0, 8 ) : 'Unknown' ),
			$session_id ?: 'none',
			$client_ip,
			substr( $snapshot_hash, 0, 8 )
		);
		error_log( $log_message );

		return new \WP_REST_Response(
			[
				'status'      => 'ok',
				'action'      => $result['status'],
				'snapshot_id' => $result['id'],
				'timestamp'   => current_time( 'mysql', true ),
			],
			200
		);
	}

	/**
	 * Collect server-side environment data using StarUserUtils.
	 *
	 * @return array
	 */
	private function collect_server_side_data(): array {
		return [
			'ip'         => StarUserUtils::getClientIP(), // Not hashed for immediate server use, but will be hashed before DB store.
			'userAgent'  => StarUserUtils::getUserAgent(),
			'sessionID'  => StarUserUtils::getSessionID(), // PHP session ID
			'currentURL' => StarUserUtils::getCurrentURL(),
			'referrerURL' => StarUserUtils::getReferrerURL(),
			'language'   => StarUserUtils::getUserLanguage( 'locale' ),
			'os'         => StarUserUtils::getUserOS(),
			'browser'    => StarUserUtils::getUserBrowser(),
			'isBot'      => StarUserUtils::isBot(),
			'requestMethod' => StarUserUtils::getRequestMethod(),
			'isAjax'     => StarUserUtils::isAjax(),
			'wpEnvType'  => StarUserUtils::getWpEnvironmentType(),
			'geolocation' => StarUserUtils::getIPGeoLocation(), // Full geo data
		];
	}

	/**
	 * Collect Client Hints from headers for better device fingerprinting.
	 *
	 * @return array
	 */
	private function collect_client_hints(): array {
		$client_hints = [];

		$hint_headers = [
			'HTTP_SEC_CH_UA'                  => 'userAgentBrand', // Example: "Brave";v="121", "Not A(Brand)";v="8"
			'HTTP_SEC_CH_UA_MOBILE'           => 'mobile',        // ?0 or ?1
			'HTTP_SEC_CH_UA_PLATFORM'         => 'platform',      // Example: "macOS", "Windows"
			'HTTP_SEC_CH_UA_PLATFORM_VERSION' => 'platformVersion',
			'HTTP_SEC_CH_UA_ARCH'             => 'architecture',
			'HTTP_SEC_CH_UA_BITNESS'          => 'bitness',
			'HTTP_SEC_CH_UA_MODEL'            => 'model',
			'HTTP_SEC_CH_UA_FULL_VERSION'     => 'fullVersion',
			'HTTP_DEVICE_MEMORY'              => 'deviceMemory', // Not a standard CH, but common from JS
			'HTTP_DPR'                        => 'devicePixelRatio',
			'HTTP_VIEWPORT_WIDTH'             => 'viewportWidth',
			'HTTP_RW'                         => 'resourceWidth',
		];

		foreach ( $hint_headers as $header => $key ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// Some client hints need parsing (e.g., Sec-CH-UA).
				// For simplicity, we sanitize as text for now.
				$client_hints[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			}
		}

		return $client_hints;
	}

	/**
	 * Checks and updates rate limit for the current client IP.
	 *
	 * @return bool True if allowed, false if rate limited.
	 */
	private function check_rate_limit(): bool {
		$client_ip = StarUserUtils::getClientIP();
		// Use a hashed IP for transient key to avoid exposing raw IP in options table.
		$rate_key = 'sparxstar_env_rate_' . hash( 'md5', $client_ip );

		$current_requests = (int) get_transient( $rate_key );

		if ( $current_requests >= self::RATE_LIMIT_MAX_REQUESTS ) {
			return false;
		}

		// Increment and set/update transient.
		set_transient( $rate_key, $current_requests + 1, self::RATE_LIMIT_WINDOW_SECONDS );

		return true;
	}

	/**
	 * Sanitizes and validates diagnostic data received from the client.
	 *
	 * @param array $data The raw client data.
	 * @return array|\WP_Error Sanitized data or WP_Error on invalid input.
	 */
	private function sanitize_client_diagnostic_data( array $data ): array|\WP_Error {
		$sanitized = [];

		// Define allowed top-level fields and their expected types/sanitization rules
		$allowed_fields = [
			'sessionId'     => [ 'type' => 'string', 'sanitizer' => 'sanitize_text_field' ],
			'userAgent'     => [ 'type' => 'string', 'sanitizer' => 'sanitize_textarea_field' ], // User agent can be long
			'os'            => [ 'type' => 'string', 'sanitizer' => 'sanitize_text_field' ],
			'language'      => [ 'type' => 'string', 'sanitizer' => 'sanitize_text_field' ],
			'screen'        => [ 'type' => 'array', 'sanitizer' => null ],
			'device'        => [ 'type' => 'array', 'sanitizer' => null ], // From device-detector-js
			'network'       => [ 'type' => 'array', 'sanitizer' => null ], // From Network Information API
			'features'      => [ 'type' => 'array', 'sanitizer' => null ],
			'privacy'       => [ 'type' => 'array', 'sanitizer' => null ],
			'compatible'    => [ 'type' => 'boolean', 'sanitizer' => 'boolval' ],
			'storage'       => [ 'type' => 'array', 'sanitizer' => null ],
			'micPermission' => [ 'type' => 'string', 'sanitizer' => 'sanitize_text_field' ],
			'battery'       => [ 'type' => 'array', 'sanitizer' => null ],
		];

		foreach ( $allowed_fields as $field => $rules ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			$value = $data[ $field ];

			// Type checking
			if ( gettype( $value ) !== $rules['type'] ) {
				// Allow string for numbers if they can be cast
				if ( ( $rules['type'] === 'integer' || $rules['type'] === 'float' ) && is_string( $value ) && is_numeric( $value ) ) {
					// Will be cast later
				} elseif ( $rules['type'] === 'boolean' && ( $value === 'true' || $value === 'false' || is_numeric( $value ) ) ) {
					// Will be cast later
				} else {
					return new \WP_Error(
						'invalid_data_type',
						sprintf( __( 'Invalid data type for field "%s". Expected %s.', 'sparxstar-user-environment-check' ), $field, $rules['type'] ),
						[ 'status' => 400, 'field' => $field ]
					);
				}
			}

			if ( $rules['sanitizer'] && is_callable( $rules['sanitizer'] ) ) {
				$sanitized[ $field ] = call_user_func( $rules['sanitizer'], $value );
			} elseif ( is_array( $value ) ) {
				$sanitized[ $field ] = $this->sanitize_array_recursively( $value );
			} else {
				$sanitized[ $field ] = $value; // Should ideally have a sanitizer or type cast
			}
		}

		// Specific validation for sessionId (if present)
		if ( isset( $sanitized['sessionId'] ) && ! preg_match( '/^[a-zA-Z0-9_-]{10,128}$/', $sanitized['sessionId'] ) ) {
			return new \WP_Error( 'invalid_session', __( 'Invalid session ID format.', 'sparxstar-user-environment-check' ), [ 'status' => 400 ] );
		}

		// Data minimization if privacy signals are strong
		if ( ! empty( $sanitized['privacy']['doNotTrack'] ) || ! empty( $sanitized['privacy']['gpc'] ) ) {
			$minimized_fields = [
				'sessionId', 'privacy', 'userAgent', 'os', 'compatible', 'features', 'device', 'network',
			];
			// Filter to only include necessary fields
			$sanitized = array_intersect_key( $sanitized, array_flip( $minimized

>>>>>>> universe:src/api/SparxstarUserEnvironmentCheckAPI.php

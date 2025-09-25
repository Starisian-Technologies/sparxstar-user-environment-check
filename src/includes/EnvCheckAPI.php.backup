<?php
/**
 * Sparxstar Environment Check REST API Handler
 *
 * Handles the collection and storage of environment diagnostics with enhanced
 * security, session awareness, client hints, and concurrency handling,
 * specifically targeting MariaDB storage for snapshots.
 *
 * @package SparxstarUserEnvironmentCheck
 * @since 2.3.0
 */

namespace SparxstarUEC\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class SparxstarUECAPI {

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

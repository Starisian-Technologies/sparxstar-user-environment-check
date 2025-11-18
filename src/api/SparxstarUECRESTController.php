<?php
/**
 * REST controller for handling environment diagnostics.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\api;

use Starisian\SparxstarUEC\helpers\StarLogger;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;
use Starisian\SparxstarUEC\StarUserEnv; // Ensure this class is correctly included and exists
use Starisian\SparxstarUEC\services\SparxstarUECGeoIPService; // Ensure this class is correctly included and exists
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

final class SparxstarUECRESTController
{
	private const RATE_LIMIT_WINDOW_SECONDS = 300;
	private const RATE_LIMIT_MAX_REQUESTS = 15;

	private SparxstarUECDatabase $database; // Changed to non-nullable as it's set in constructor

	public function __construct(SparxstarUECDatabase $database)
	{
		$this->database = $database;
	}

	/**
	 * Register the REST endpoint.
	 */
	public function register_routes(): void
	{
		register_rest_route(
			'star-uec/v1',
			'/log',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_log_request'],
				'permission_callback' => [$this, 'check_permissions'],
			]
		);

		register_rest_route(
			'star-uec/v1',
			'/fingerprint',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'capture_fingerprint'],
				'permission_callback' => '__return_true', // fingerprint is intentionally public
				'args'                => [
					'visitorId' => [
						'type'     => 'string',
						'required' => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'components' => [ // Components can be a complex array, no simple sanitize_callback
						'type'     => 'object', // Changed from array to object as it's typically key-value pairs
						'required' => false,
					],
				],
			]
		);
	}

	public function check_permissions(\WP_REST_Request $request): bool|\WP_Error
	{
		$nonce = $request->get_header('X-WP-Nonce');
		if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
			return new \WP_Error('invalid_nonce', 'Invalid security token.', array('status' => 403));
		}

		if (!$this->check_rate_limit()) {
			return new \WP_Error('rate_limited', 'Too many requests.', array('status' => 429));
		}
		return true;
	}

	public function handle_log_request(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$payload = $request->get_json_params();
		if (!is_array($payload) || empty($payload)) {
			return new \WP_Error('invalid_data', 'Invalid JSON payload.', array('status' => 400));
		}

		$client_ip = $this->get_client_ip();
		$client_ip_hash = hash('sha256', $client_ip); // Changed to sha256 for consistency if not already
		$user_id = get_current_user_id() ?: null;
		$session_id = $this->sanitize_value($payload['sessionId'] ?? null);

		if (isset($payload['delta']) && is_array($payload['delta'])) {
			$latest_snapshot = $this->database->get_latest_snapshot($client_ip_hash, $user_id, $session_id);

			if (is_wp_error($latest_snapshot)) { // Check if get_latest_snapshot returned an error
				return $latest_snapshot;
			}
			if ($latest_snapshot === null) {
				return new \WP_Error('no_snapshot_for_delta', 'Cannot apply a delta without an existing snapshot.', array('status' => 409));
			}

			$client_data = array_replace_recursive($latest_snapshot['client_side_data'], $this->sanitize_array_recursively($payload['delta']));
			$server_data = $latest_snapshot['server_side_data'];
			$hints_data = $latest_snapshot['client_hints_data'];
		} else {
			$client_data = $this->sanitize_array_recursively($payload);
			$server_data = $this->collect_server_side_data($client_ip);
			$hints_data = $this->collect_client_hints();
		}

		// Generate a hash of the current environment state
		$hash_data = array(
			'user_id' => $user_id,
			'session_id' => $session_id,
			'server_side' => $server_data,
			'client_side' => $client_data,
			'client_hints' => $hints_data,
		);
		// Note: The unset here means these won't contribute to the hash, which is fine if they are volatile
		unset($hash_data['client_side']['network']['rtt'], $hash_data['client_side']['battery']);
		$snapshot_hash = hash('sha256', wp_json_encode($hash_data));

		$result = $this->database->store_snapshot(
			array(
				'user_id' => $user_id,
				'session_id' => $session_id,
				'client_ip_hash' => $client_ip_hash,
				'snapshot_hash' => $snapshot_hash,
				'server_data' => $server_data,
				'client_data' => $client_data,
				'client_hints' => $hints_data,
			)
		);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'action' => $result['status'],
				'id' => $result['id'],
			),
			200
		);
	}

	/**
	 * Enforce a per-client request budget to protect the API.
	 */
	private function check_rate_limit(): bool
	{
		// Use a transient to track requests from a specific IP address.
		$rate_key = 'sparxstar_env_rate_' . hash('md5', $this->get_client_ip() ?: 'unknown');
		$current_requests = (int) get_transient($rate_key);

		if ($current_requests >= self::RATE_LIMIT_MAX_REQUESTS) {
			return false;
		}

		// Increment the count and reset the transient's expiration window.
		set_transient($rate_key, $current_requests + 1, self::RATE_LIMIT_WINDOW_SECONDS);

		return true;
	}

	/**
	 * Determine the client IP address using the shared helper.
	 */
	private function get_client_ip(): string
	{
		// FIX: Use the correct method name from StarUserEnv
		return StarUserEnv::get_current_visitor_ip();
	}

	/**
	 * Capture server-side metadata for a snapshot, now including GeoIP data.
	 *
	 * @param string $client_ip The validated client IP address.
	 * @return array The server-side data, enriched with location info if available.
	 */
	private function collect_server_side_data(string $client_ip): array
	{
		// Create an instance of the GeoIP service.
		$geoip_service = new SparxstarUECGeoIPService();

		// The lookup method will gracefully return null if no API key is set
		// or if the lookup fails, preventing any errors.
		return array(
			'ipAddress' => $client_ip,
			'language' => get_locale(),
			'serverTimeUTC' => gmdate('c'),
			'geolocation' => $geoip_service->lookup($client_ip),
		);
	}

	/**
	 * Inspect the current request for client hint headers.
	 */
	private function collect_client_hints(): array
	{
		$client_hints = array();
		$hint_headers = apply_filters(
			'sparxstar_env_client_hint_headers',
			array(
				'Sec-CH-UA',
				'Sec-CH-UA-Mobile',
				'Sec-CH-UA-Platform',
				'Sec-CH-UA-Platform-Version',
				'Sec-CH-UA-Arch',
				'Sec-CH-UA-Bitness',
				'Sec-CH-UA-Model',
				'Sec-CH-UA-Full-Version',
			)
		);

		foreach ($hint_headers as $header) {
			$server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
			if (!empty($_SERVER[$server_key])) {
				$client_hints[$header] = sanitize_text_field(wp_unslash($_SERVER[$server_key]));
			}
		}

		return $client_hints;
	}

	/**
	 * Sanitize an arbitrarily nested array of diagnostic data.
	 */
	private function sanitize_array_recursively(array $array): array
	{
		$sanitized = array();

		foreach ($array as $key => $value) {
			// Sanitize the key first.
			$normalized_key = $this->sanitize_key_preserve_case($key);

			if (is_array($value)) {
				// If the value is an array, recurse into it.
				$sanitized[$normalized_key] = $this->sanitize_array_recursively($value);
			} elseif (is_scalar($value) || $value === null) {
				// If the value is a simple type, sanitize it.
				$sanitized[$normalized_key] = $this->sanitize_value($value);
			}
		}

		return $sanitized;
	}

	/**
	 * Normalize keys while preserving case sensitivity for client payloads.
	 */
	private function sanitize_key_preserve_case(string|int $key): string
	{
		$key_string = (string) $key;
		// Allow alphanumeric characters, underscores, and hyphens.
		$cleaned = preg_replace('/[^A-Za-z0-9_\-]/', '', $key_string);

		// Fallback to the original if sanitization results in an empty string.
		return $cleaned === '' ? $key_string : $cleaned;
	}

	/**
	 * Store fingerprint ID in environment snapshots.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	function capture_fingerprint(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		// Removed global $wpdb; as we now use the injected $this->database
		$visitorId = sanitize_text_field($request->get_param('visitorId'));
		// For 'components', get_param() returns an array/object, no simple sanitize_text_field
		$components = $request->get_param('components'); 

		if (empty($visitorId)) {
			return new \WP_Error('missing_visitor_id', 'Missing visitorId in fingerprint request.', array('status' => 400));
		}

		$client_ip = $this->get_client_ip();
		$client_ip_hash = hash('sha256', $client_ip);
		$user_id = get_current_user_id() ?: null;
		// For fingerprint, we can use visitorId as session_id if no user is logged in
		$session_id = $visitorId; // Using visitorId as session ID for this specific snapshot

		// Construct client_side_data to hold fingerprint information
		$client_data_payload = [
			'type'      => 'fingerprint_data', // Custom type to differentiate in client_side_data
			'visitorId' => $visitorId,
			'components' => $components, // Directly store components
			'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '', // Add other relevant client data
		];

		// Collect server-side and client hint data for a complete snapshot entry
		$server_data = $this->collect_server_side_data($client_ip);
		$hints_data = $this->collect_client_hints();

		// Generate a hash of the current fingerprint state for the snapshot_hash
        $hash_data = [
            'user_id' => $user_id,
            'session_id' => $session_id,
            'server_side' => $server_data,
            'client_side' => $client_data_payload,
            'client_hints' => $hints_data,
        ];
		$snapshot_hash = hash('sha256', wp_json_encode($hash_data));


		// Use the injected SparxstarUECDatabase instance to store the snapshot
		$result = $this->database->store_snapshot(
			[
				'user_id'           => $user_id,
				'session_id'        => $session_id,
				'client_ip_hash'    => $client_ip_hash,
				'snapshot_hash'     => $snapshot_hash,
				'server_data'       => $server_data,
				'client_data'       => $client_data_payload, // Store fingerprint data here
				'client_hints'      => $hints_data,
			]
		);

		if (is_wp_error($result)) {
			// The store_snapshot method now returns WP_Error on failure
			return $result;
		}

		return new \WP_REST_Response(
			[
				'status'    => 'success',
				'visitorId' => $visitorId,
				'action'    => $result['status'], // 'inserted' or 'updated'
				'id'        => $result['id'],     // The ID of the database entry
			],
			200
		);
	}

	/**
	 * Sanitize a single scalar diagnostic value.
	 */
	private function sanitize_value(mixed $value): string
	{
		if ($value === null) {
			return '';
		}

		return sanitize_text_field((string) $value);
	}
}
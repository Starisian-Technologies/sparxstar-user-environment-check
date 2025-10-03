<?php
/**
 * REST controller for handling environment diagnostics.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

use Starisian\SparxstarUEC\core\SparxstarUECDatabase;
use Starisian\SparxstarUEC\StarUserEnv;
use Starisian\SparxstarUEC\services\SparxstarUECGeoIPService;
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

	private ?SparxstarUECDatabase $database = null;

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
			'sparxstar-uec/v1',
			'/log',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_log_request'],
				'permission_callback' => [$this, 'check_permissions'],
			]
		);

		register_rest_route(
			'sparxstar-uec/v1',
			'/fingerprint',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'capture_fingerprint'],
				'permission_callback' => '__return_true', // fingerprint is intentionally public
				'args'                => [
					'visitorId' => [
						'type'     => 'string',
						'required' => true,
					],
					'components' => [
						'type'     => 'array',
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
		$client_ip_hash = hash('sha265', $client_ip);
		$user_id = get_current_user_id() ?: null;
		$session_id = $this->sanitize_value($payload['sessionId'] ?? null);

		if (isset($payload['delta']) && is_array($payload['delta'])) {
			$latest_snapshot = $this->database->get_latest_snapshot($client_ip_hash, $user_id, $session_id);

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

		// Optionally flush a cache if needed
		// StarUserEnv::flush_cache($user_id, $session_id);

		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'action' => $result['status'],
				'id' => $result['id'],
			),
			200
		);
	}

	// ... All helper methods like get_client_ip, sanitize_array_recursively, collect_client_hints, etc. remain here ...
	// These methods are specific to handling and sanitizing the request data.
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
	 * Determine the most reliable client IP address from server variables.
	 */
	/**
	 * Determine the client IP address using the shared helper.
	 */
	private function get_client_ip(): string
	{
		// Use the single source of truth for IP detection.
		return StarUserEnv::getClientIP();
	}
	/**
	 * Capture server-side metadata for a snapshot, now including GeoIP data.
	 *
	 * @param string $client_ip The validated client IP address.
	 * @return array The server-side data, enriched with location info if available.
	 */
	private function collect_server_side_data(string $client_ip): array
	{
		// 1. Ensure the GeoIP service class is available.
		// We include it here because it's only needed for this specific action.
		require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/services/SparxstarUECGeoIPService.php';

		// 2. Create an instance of the GeoIP service.
		$geoip_service = new SparxstarUECGeoIPService();

		// 3. Build the data array.
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
	 * @return \WP_REST_Response
	 */
	function capture_fingerprint(\WP_REST_Request $request): \WP_REST_Response
	{
		global $wpdb;
		$visitorId = sanitize_text_field($request->get_param('visitorId'));
		$components = $request->get_param('components');

		if (empty($visitorId)) {
			return new \WP_REST_Response(['status' => 'error', 'message' => 'Missing visitorId'], 400);
		}

		// Insert into your diagnostics table if available
		$table_name = $wpdb->base_prefix . 'sparxstar_uec_snapshots';
		$wpdb->insert(
			$table_name,
			[
				'user_id' => get_current_user_id(),
				'snapshot_type' => 'fingerprint',
				'snapshot_value' => maybe_serialize([
					'visitorId' => $visitorId,
					'components' => $components,
					'timestamp' => time(),
					'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
				]),
				'created_at' => current_time('mysql'),
			],
			['%d', '%s', '%s', '%s']
		);

		return new \WP_REST_Response([
			'status' => 'success',
			'visitorId' => $visitorId,
		], 200);
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

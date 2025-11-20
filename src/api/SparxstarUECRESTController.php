<?php
/**
 * REST controller for handling environment diagnostics.
 * Version 2.0: Aligned with fingerprint-first identity architecture.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\api;

use Starisian\SparxstarUEC\core\SparxstarUECDatabase;
use Starisian\SparxstarUEC\helpers\StarLogger;
use Starisian\SparxstarUEC\services\SparxstarUECGeoIPService;
use Starisian\SparxstarUEC\StarUserUtils;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SparxstarUECRESTController {

	private const RATE_LIMIT_WINDOW_SECONDS = 300;
	private const RATE_LIMIT_MAX_REQUESTS   = 15;

	private SparxstarUECDatabase $database;

	public function __construct( SparxstarUECDatabase $database ) {
		$this->database = $database;
	}

	/**
	 * Register the single, unified REST endpoint for logging snapshots.
	 */
	public function register_routes(): void {
		register_rest_route(
			'star-uec/v1',
			'/log',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_log_request' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			]
		);
	}

	/**
	 * Handle the incoming snapshot payload.
	 * This method is now stateless and relies on the mapper to prepare data
	 * for an "upsert" operation (update or insert) in the database.
	 */
	public function handle_log_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return new WP_Error( 'invalid_data', 'Invalid JSON payload.', [ 'status' => 400 ] );
		}

		// 1. Enrich the payload with server-side data.
		$client_ip                       = StarUserUtils::get_current_visitor_ip();
		$payload['server_side_data']     = $this->collect_server_side_data( $client_ip );
		$payload['client_hints_data']    = $this->collect_client_hints();
		$payload['user_id']              = get_current_user_id() ?: null;

		// 2. Normalize the raw payload into the final database structure.
		$normalized_data = $this->map_and_normalize_snapshot( $payload );

		// 3. Store the data using the new "upsert" logic.
		$result = $this->database->store_snapshot( $normalized_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'status' => 'ok',
				'action' => $result['status'], // 'inserted' or 'updated'
				'id'     => $result['id'],
			],
			200
		);
	}

	/**
	 * Transform the raw incoming payload into the canonical database schema.
	 * This is the critical link between the REST endpoint and the database layer.
	 */
	private function map_and_normalize_snapshot( array $payload ): array {
		$client      = $payload['client_side_data'] ?? [];
		$identifiers = $client['identifiers'] ?? [];
		$hints       = $payload['client_hints_data'] ?? [];

		// Sanitize the primary identifiers.
		$fingerprint = sanitize_text_field( $identifiers['fingerprint'] ?? '' );
		$session_id  = sanitize_text_field( $identifiers['session_id'] ?? '' );

		// Generate the stable device hash from server-collected Client Hints.
		$h_payload = wp_json_encode( [
			$hints['Sec-CH-UA'] ?? '',
			$hints['Sec-CH-UA-Platform'] ?? '',
			$hints['Sec-CH-UA-Model'] ?? '',
			$hints['Sec-CH-UA-Bitness'] ?? '',
		] );
		$device_hash = hash( 'sha256', $h_payload );

		// Return the final, structured data ready for the database.
		return [
			'fingerprint' => $fingerprint,
			'session_id'  => $session_id,
			'device_hash' => $device_hash,
			'user_id'     => $payload['user_id'],
			'data'        => $payload, // The complete, raw snapshot for the JSON column
			'updated_at'  => gmdate( 'Y-m-d H:i:s' ),    // UTC normalized timestamp
		];
	}

	// --- No changes needed for the helper methods below ---

	public function check_permissions( WP_REST_Request $request ): bool|WP_Error {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'invalid_nonce', 'Invalid security token.', [ 'status' => 403 ] );
		}

		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', 'Too many requests.', [ 'status' => 429 ] );
		}
		return true;
	}

	private function check_rate_limit(): bool {
		$rate_key         = 'sparxstar_env_rate_' . hash( 'md5', StarUserUtils::get_current_visitor_ip() ?: 'unknown' );
		$current_requests = (int) get_transient( $rate_key );

		if ( $current_requests >= self::RATE_LIMIT_MAX_REQUESTS ) {
			return false;
		}

		set_transient( $rate_key, $current_requests + 1, self::RATE_LIMIT_WINDOW_SECONDS );
		return true;
	}

	private function collect_server_side_data( string $client_ip ): array {
		$geoip_service = new SparxstarUECGeoIPService();
		$geo_data      = $geoip_service->lookup( $client_ip );

		// Structure geolocation as individual JSON nodes for easier querying
		$geolocation = [];
		if ( is_array( $geo_data ) && ! empty( $geo_data ) ) {
			$geolocation = [
				'city'        => $geo_data['city'] ?? '',
				'state'       => $geo_data['state'] ?? '',
				'postal_code' => $geo_data['postal_code'] ?? '',
				'region'      => $geo_data['region'] ?? '',
				'country'     => $geo_data['country'] ?? '',
				'latitude'    => $geo_data['latitude'] ?? 0.0,
				'longitude'   => $geo_data['longitude'] ?? 0.0,
				'timezone'    => $geo_data['timezone'] ?? '',
			];
		}

		return [
			'ipAddress'     => $client_ip,
			'language'      => get_locale(),
			'serverTimeUTC' => gmdate( 'c' ),
			'geolocation'   => $geolocation,
		];
	}

	private function collect_client_hints(): array {
		$client_hints = [];
		$hint_headers = apply_filters(
			'sparxstar_env_client_hint_headers',
			[
				'Sec-CH-UA',
				'Sec-CH-UA-Mobile',
				'Sec-CH-UA-Platform',
				'Sec-CH-UA-Platform-Version',
				'Sec-CH-UA-Bitness',
				'Sec-CH-UA-Model',
				'Sec-CH-UA-Full-Version',
			]
		);

		foreach ( $hint_headers as $header ) {
			$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
			if ( ! empty( $_SERVER[ $server_key ] ) ) {
				$client_hints[ $header ] = sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
			}
		}

		return $client_hints;
	}
}
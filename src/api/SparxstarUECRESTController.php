<?php
/**
 * SPARXSTAR User Environment Check
 *
 * REST controller for ingesting client environment payloads and recorder
 * telemetry into the canonical storage schema.
 *
 * @package Starisian\SparxstarUEC\api
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\api;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Starisian\SparxstarUEC\StarUserEnv;
use Starisian\SparxstarUEC\helpers\StarLogger;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;
use Starisian\SparxstarUEC\services\SparxstarUECGeoIPService; // Import Logger

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles API route registration, request validation, and payload mapping.
 */
final readonly class SparxstarUECRESTController
{
    /**
     * Build the REST controller with its database dependency.
     *
     * @param SparxstarUECDatabase $database Snapshot persistence gateway.
     */
    public function __construct(private SparxstarUECDatabase $database) {}

    /**
     * Register REST endpoints for logging snapshots and recorder events.
     */
    public function register_routes(): void
    {
        register_rest_route(
            'star-uec/v1',
            '/log',
            [
                'methods'             => 'POST',
                'callback'            => $this->handle_log_request(...),
                'permission_callback' => $this->check_permissions(...),
            ]
        );

        register_rest_route(
            'star-uec/v1',
            '/recorder-log',
            [
                'methods'             => 'POST',
                'callback'            => $this->handle_recorder_log(...),
                'permission_callback' => '__return_true', // Open endpoint - passive telemetry, no nonce required
            ]
        );
    }

    /**
     * Handle incoming environment snapshot payloads.
     *
     * @param WP_REST_Request $request HTTP request containing JSON payload.
     * @return WP_REST_Response|WP_Error Success response or validation/storage error.
     */
    public function handle_log_request(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();
        if (! is_array($payload) || $payload === []) {
            StarLogger::warning('REST', 'Received empty or invalid JSON payload.');
            return new WP_Error('invalid_data', 'Invalid JSON payload.', ['status' => 400]);
        }

        // 1. Identify the User
        $user_id = get_current_user_id(); // Returns 0 if guest, ID if logged in

        // DEBUG: Log exactly who the server thinks this is
        StarLogger::info('REST', 'Processing snapshot. Detected User ID: ' . $user_id, [
            'fingerprint' => $payload['client_side_data']['identifiers']['fingerprint'] ?? 'unknown'
        ]);

        // 2. Enrich the payload with server-side data.
        $client_ip                    = StarUserEnv::get_current_visitor_ip();
        $payload['server_side_data']  = $this->collect_server_side_data($client_ip);
        $payload['client_hints_data'] = $this->collect_client_hints();
        $payload['user_id']           = $user_id;

        // 3. Normalize the raw payload into the final database structure.
        $normalized_data = $this->map_and_normalize_snapshot($payload);

        // 4. Store the data
        $result = $this->database->store_snapshot($normalized_data);

        if (is_wp_error($result)) {
            StarLogger::error('REST', 'Failed to store snapshot', ['error' => $result->get_error_message()]);
            return $result;
        }

        return new WP_REST_Response(
            [
                'status'        => 'ok',
                'action'        => $result['status'], // 'inserted' or 'updated'
                'id'            => $result['id'],
                'user_detected' => $user_id // Send this back to console for debugging
            ],
            200
        );
    }

    /**
     * Handle incoming recorder event logs from external plugins.
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response The response
     */
    public function handle_recorder_log(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_body();
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return new WP_REST_Response(['status' => 'invalid_json'], 400);
        }

        // Log the recorder event if WP_DEBUG_LOG is enabled
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            StarLogger::info('RecorderEvent', 'External plugin event received', [
                'event_type'   => $data['type'] ?? 'unknown',
                'timestamp'    => $data['ts']   ?? '',
                'has_env_data' => isset($data['env']),
                'event_data'   => $data['event'] ?? []
            ]);
            error_log('[SparxstarUEC Recorder] ' . wp_json_encode($data));
        }

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    /**
     * Transform raw incoming payload into the canonical storage structure.
     *
     * @param array<string, mixed> $payload Raw client+server payload.
     * @return array<string, mixed> Normalized structure expected by database layer.
     */
    private function map_and_normalize_snapshot(array $payload): array
    {
        $client      = $payload['client_side_data']  ?? [];
        $identifiers = $client['identifiers']        ?? [];
        $hints       = $payload['client_hints_data'] ?? [];

        // Sanitize the primary identifiers.
        $fingerprint = sanitize_text_field($identifiers['fingerprint'] ?? '');
        $session_id  = sanitize_text_field($identifiers['session_id'] ?? '');

        // Generate the stable device hash from server-collected Client Hints.
        $h_payload = wp_json_encode([
            $hints['Sec-CH-UA']          ?? '',
            $hints['Sec-CH-UA-Platform'] ?? '',
            $hints['Sec-CH-UA-Model']    ?? '',
            $hints['Sec-CH-UA-Bitness']  ?? '',
        ]);
        $device_hash = hash('sha256', $h_payload);

        return [
            'fingerprint' => $fingerprint,
            'session_id'  => $session_id,
            'device_hash' => $device_hash,
            'user_id'     => (int) $payload['user_id'],
            'data'        => $payload,
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ];
    }

    // --- Helper Methods ---

    /**
     * Validate request nonce for protected logging endpoint.
     *
     * @param WP_REST_Request $request Incoming REST request.
     * @return bool|WP_Error True when allowed, WP_Error when rejected.
     */
    public function check_permissions(WP_REST_Request $request): bool|WP_Error
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (! $nonce || ! wp_verify_nonce($nonce, 'wp_rest')) {
            StarLogger::warning('REST', 'Permission check failed: Invalid Nonce.');
            return new WP_Error('invalid_nonce', 'Invalid security token.', ['status' => 403]);
        }

        return true;
    }

    /**
     * Collect server-side metadata that cannot be trusted from the client.
     *
     * @param string $client_ip Sanitized client IP address.
     * @return array<string, mixed> Server metadata and optional geolocation.
     */
    private function collect_server_side_data(string $client_ip): array
    {
        $geoip_service = new SparxstarUECGeoIPService();
        $geo_data      = $geoip_service->lookup($client_ip);

        $geolocation = [];
        if (is_array($geo_data) && $geo_data !== []) {
            $geolocation = [
                'city'        => $geo_data['city']        ?? '',
                'state'       => $geo_data['state']       ?? '',
                'postal_code' => $geo_data['postal_code'] ?? '',
                'region'      => $geo_data['region']      ?? '',
                'country'     => $geo_data['country']     ?? '',
                'latitude'    => $geo_data['latitude']    ?? 0.0,
                'longitude'   => $geo_data['longitude']   ?? 0.0,
                'timezone'    => $geo_data['timezone']    ?? '',
            ];
        }

        return [
            'ipAddress'     => $client_ip,
            'language'      => get_locale(),
            'serverTimeUTC' => gmdate('c'),
            'geolocation'   => $geolocation,
        ];
    }

    /**
     * Collect supported Client Hint headers from the current HTTP request.
     *
     * @return array<string, string> Header-to-value map of available hints.
     */
    private function collect_client_hints(): array
    {
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

        foreach ($hint_headers as $header) {
            $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            if (! empty($_SERVER[$server_key])) {
                $client_hints[$header] = sanitize_text_field(wp_unslash($_SERVER[$server_key]));
            }
        }

        return $client_hints;
    }
}

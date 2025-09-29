<?php
/**
 * Handles the REST API endpoint, data enrichment, and storage pipeline.
 *
 * This class is the central processor. It receives the client-side snapshot,
 * enriches it, archives the full payload, and extracts key values for
 * fast session-based access by other plugins.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\api;

if (!defined('ABSPATH')) {
	exit;
}

use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;
use Starisian\SparxstarUEC\StarUserEnv;
use wpdb;
use WP_REST_Request;
use WP_REST_Response;

final class SparxstarUECAPI
{
	private \wpdb $wpdb;
	private const DB_TABLE_NAME = SPX_ENV_CHECK_DB_TABLE_NAME;

	/**
	 * The master list of fields to extract from the client's payload for session storage.
	 * KEY = The simple name we use in the session (and in StarUserEnv getters).
	 * VALUE = The dot-notation path in the incoming JSON from the client.
	 */
	private const CLIENT_FIELDS_TO_EXTRACT = [
		'visitor_id' => 'identifiers.visitor_id',
		'device_type' => 'client_side_data.device.type',
		'os_name' => 'client_side_data.os.name',
		'browser_name' => 'client_side_data.client.name',
		'network_type' => 'client_side_data.network.effectiveType',
		'language' => 'client_side_data.context.language',
	];

	public function __construct()
	{
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Hooks this class's methods into WordPress.
	 */
	public function register_hooks(): void
	{
		add_action('rest_api_init', [$this, 'register_rest_route']);
	}

	/**
	 * Registers the /log REST API endpoint.
	 */
	public function register_rest_route(): void
	{
		register_rest_route('star-uec/v1', '/log', [
			'methods' => 'POST',
			'callback' => [$this, 'handle_log_request'],
			'permission_callback' => '__return_true', // Keep it simple; can add nonce validation later.
		]);
	}

	/**
	 * Handles the incoming JSON payload from the client.
	 * This is the central point where the entire data pipeline is executed.
	 */
	public function handle_log_request(WP_REST_Request $request): WP_REST_Response
	{
		$client_payload = $request->get_json_params();
		if (empty($client_payload) || !is_array($client_payload)) {
			return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid data payload.'], 400);
		}

		// --- Step 1: Enrich the data with server-side intelligence (GeoIP) ---
		$server_data = $this->collect_server_side_data();

		// Combine client and server data into one complete dossier.
		$full_snapshot = array_merge_recursive($client_payload, ['server_side_data' => $server_data]);

		// --- Step 2: Archive the full dossier to the database (Cold Storage) ---
		$this->save_raw_snapshot_to_db($full_snapshot);

		// --- Step 3: Extract critical intel for the session (Hot Storage) ---
		$session_data = [];

		// Extract from the full snapshot based on our master list.
		foreach (self::CLIENT_FIELDS_TO_EXTRACT as $key => $path) {
			$value = $this->get_value_from_array($full_snapshot, $path);
			if ($value !== null) {
				$session_data[$key] = $value;
			}
		}

		// Extract geolocation fields directly from the enriched server data.
		if (!empty($server_data['geolocation'])) {
			$geo = $server_data['geolocation'];
			$session_data['location'] = $geo;
			$session_data['country'] = $geo['country'] ?? null;
			$session_data['city'] = $geo['city'] ?? null;
			$session_data['state'] = $geo['region'] ?? null;
		}

		// --- Step 4: THE HOOKUP. Store the extracted intel in the session. ---
		SparxstarUECSessionManager::set_all($session_data);

		return new WP_REST_Response(['status' => 'ok', 'message' => 'Snapshot processed.'], 200);
	}

	/**
	 * Gathers server-side data, including the crucial GeoIP lookup.
	 */
	private function collect_server_side_data(): array
	{
		// Use the public getter from our main class for consistency.
		$ip_address = StarUserEnv::get_user_ip();

		return [
			'timestamp_utc' => gmdate('c'),
			'ip_address' => $ip_address,
			'geolocation' => StarUserEnv::get_user_location($ip_address),
		];
	}

	/**
	 * Archives the complete, raw JSON payload to the database.
	 */
	private function save_raw_snapshot_to_db(array $snapshot): void
	{
		$table_name = $this->wpdb->base_prefix . self::DB_TABLE_NAME;

		$this->wpdb->insert($table_name, [
			'user_id' => get_current_user_id() ?: null,
			'visitor_id' => $snapshot['identifiers']['visitor_id'] ?? null,
			'session_id' => $snapshot['identifiers']['session_id'] ?? null,
			'snapshot_hash' => hash('sha256', wp_json_encode($snapshot)), // A hash of the full data.
			'client_ip_hash' => hash('sha256', $snapshot['server_side_data']['ip_address'] ?? 'unknown'),
			'server_side_data' => wp_json_encode($snapshot['server_side_data'] ?? []),
			'client_side_data' => wp_json_encode($snapshot['client_side_data'] ?? []),
			'created_at' => current_time('mysql', true),
			'updated_at' => current_time('mysql', true),
		]);
	}

	/**
	 * Safely gets a value from a nested array using a dot-notation path.
	 */
	private function get_value_from_array(array $array, string $path)
	{
		$keys = explode('.', $path);
		foreach ($keys as $key) {
			if (!is_array($array) || !array_key_exists($key, $array)) {
				return null;
			}
			$array = $array[$key];
		}
		return $array;
	}

	/**
	 * Retrieves the latest snapshot for a given user/session from the database.
	 * This method is called by the SparxstarUEC_SessionManager::lookup().
	 */
	public function get_latest_snapshot_from_db(?int $user_id, ?string $session_id): ?array
	{
		$table_name = $this->wpdb->base_prefix . self::DB_TABLE_NAME;

		$where_clause = '';
		$params = [];

		if (!empty($user_id)) {
			$where_clause = $this->wpdb->prepare("user_id = %d", $user_id);
		} elseif (!empty($session_id)) {
			$where_clause = $this->wpdb->prepare("session_id = %s", $session_id);
		} else {
			return null;
		}

		$sql = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY id DESC LIMIT 1";

		$row = $this->wpdb->get_row($sql, ARRAY_A);
		if (!$row)
			return null;

		// Decode the JSON columns
		$row['client_side_data'] = json_decode($row['client_side_data'] ?? '[]', true) ?? [];
		$row['server_side_data'] = json_decode($row['server_side_data'] ?? '[]', true) ?? [];

		return $row;
	}
}
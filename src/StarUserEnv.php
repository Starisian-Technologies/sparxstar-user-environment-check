<?php
/**
 * Public "Getter" library for accessing specific user environment data points.
 *
 * This is the designated public-facing API for the plugin. Its methods are
 * simple, globally-callable accessors that read from the user's last
 * recorded snapshot, honoring the "client is the source of truth" principle.
 * It is designed to be the central intelligence source for an entire ecosystem of plugins.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC;

use Starisian\SparxstarUEC\core\SparxstarUECSnapshotRepository;
use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;
use Starisian\SparxstarUEC\includes\SparxstarUECCacheHelper;

if (!defined('ABSPATH')) {
	exit;
}

final class StarUserEnv
{

	/**
	 * A runtime cache to store the snapshot for a single page load,
	 * preventing multiple database lookups if many getters are called.
	 *
	 * @var array|null
	 */
	private static ?array $snapshot_cache = null;

	// --- I. User Identification & Tracking ---

	/**
	 * Get the user's stable, anonymous browser fingerprint ID from the snapshot.
	 * This is the primary key for tracking anonymous users.
	 */
	public static function get_visitor_id(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('identifiers.visitor_id', '', $user_id, $session_id);
	}

	// --- II. Performance & UX Optimization ---

	/**
	 * Get the effective network connection type (e.g., "4g", "3g") from the snapshot.
	 * The most critical value for performance decisions.
	 */
	public static function get_network_type(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('client_side_data.network.effectiveType', 'unknown', $user_id, $session_id);
	}

	/**
	 * Check if the user's browser has the "Data Saver" mode enabled.
	 * A critical signal for respecting user choice.
	 */
	public static function is_data_saver_enabled(?int $user_id = null, ?string $session_id = null): bool
	{
		return (bool) self::_get_value_from_snapshot('client_side_data.network.saveData', false, $user_id, $session_id);
	}

	/**
	 * Get the device type (e.g., "desktop", "smartphone") from the snapshot.
	 * The most critical value for UI/UX decisions.
	 */
	public static function get_user_device(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('client_side_data.device.type', 'unknown', $user_id, $session_id);
	}

	/**
	 * Get the user's Graphics Processing Unit (GPU) model from the snapshot.
	 * A direct measure of hardware capability for demanding visual tasks.
	 */
	public static function get_user_gpu(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('client_side_data.fingerprint.gpu', 'unknown', $user_id, $session_id);
	}

	/**
	 * Get the OS name (e.g., "Windows", "Mac") from the snapshot.
	 */
	public static function get_os_name(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('client_side_data.os.name', 'unknown', $user_id, $session_id);
	}

	/**
	 * Get the browser name (e.g., "Chrome") from the snapshot.
	 */
	public static function get_browser_name(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('client_side_data.client.name', 'unknown', $user_id, $session_id);
	}

	// --- III. Geolocation & Localization ---

	/**
	 * Get the user's IP address *as it was recorded in the snapshot*.
	 */
	public static function get_user_ip(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('server_side_data.ip_address', '0.0.0.0', $user_id, $session_id);
	}

	/**
	 * Get the user's country code (e.g., "US", "DE") *as recorded in the snapshot*.
	 * Essential for licensing, marketing, and legal jurisdiction.
	 */
	public static function get_user_country(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('server_side_data.geolocation.country', 'unknown', $user_id, $session_id);
	}

	/**
	 * Get the user's state/region code (e.g., "NY") *as recorded in the snapshot*.
	 */
	public static function get_user_state(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('server_side_data.geolocation.region', 'unknown', $user_id, $session_id);
	}

	/**
	 * Get the user's city name *as recorded in the snapshot*.
	 */
	public static function get_user_city(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('server_side_data.geolocation.city', 'unknown', $user_id, $session_id);
	}

	/**
	 * Get the user's primary language code (e.g., "en") from the snapshot.
	 */
	public static function get_user_language(?int $user_id = null, ?string $session_id = null): string
	{
		$lang = (string) self::_get_value_from_snapshot('client_side_data.context.language', '', $user_id, $session_id);
		return substr($lang, 0, 2);
	}

	// --- IV. Legal & Security ---

	/**
	 * Get the exact server UTC timestamp of when the snapshot was recorded.
	 * The non-repudiable "when" for legal agreements.
	 */
	public static function get_snapshot_timestamp(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::_get_value_from_snapshot('server_side_data.timestamp_utc', '', $user_id, $session_id);
	}

	/**
	 * Check if the user's IP was flagged as a VPN during the snapshot.
	 * A critical signal for fraud and rights management.
	 */
	public static function is_on_vpn(?int $user_id = null, ?string $session_id = null): bool
	{
		return (bool) self::_get_value_from_snapshot('server_side_data.geolocation.is_vpn', false, $user_id, $session_id);
	}

	// --- V. Utility Methods ---

	/**
	 * Flushes the cache for a given user, forcing the next getter call to fetch fresh data.
	 */
	public static function flush_cache(?int $user_id = null, ?string $session_id = null): void
	{
		$resolved_user_id = $user_id ?? (get_current_user_id() ?: null);
		$visitor_id = isset($_COOKIE['spx_visitor_id']) ? sanitize_text_field($_COOKIE['spx_visitor_id']) : null;

		$cache_key = SparxstarUECCacheHelper::make_key($resolved_user_id, $session_id, $visitor_id);
		SparxstarUECCacheHelper::delete($cache_key);
		self::$snapshot_cache = null; // Clear the runtime cache as well.
	}

	// --- Internal Logic: The Engine Under the Hood ---

	private static function _get_value_from_snapshot(string $path, mixed $default, ?int $user_id, ?string $session_id): mixed
	{
		$snapshot = self::_fetch_snapshot($user_id, $session_id);
		if ($snapshot === null) {
			return $default;
		}

		$current = $snapshot;
		foreach (explode('.', $path) as $key) {
			if (!is_array($current) || !isset($current[$key])) {
				return $default;
			}
			$current = $current[$key];
		}
		return $current;
	}

	/**
	 * Fetches the full snapshot from the runtime cache, object cache, or database.
	 * THIS METHOD CONTAINS THE FIX.
	 */
	private static function _fetch_snapshot(?int $user_id, ?string $session_id): ?array
	{
		$resolved_user_id = $user_id ?? (get_current_user_id() ?: null);

		if (self::$snapshot_cache !== null) {
			return self::$snapshot_cache;
		}

		// --- V2.0 Identity Resolution ---
		// Extract fingerprint from cookie (set by JS) or fallback to IP-based hash
		$visitor_id = isset($_COOKIE['spx_visitor_id']) ? sanitize_text_field($_COOKIE['spx_visitor_id']) : null;
		$fingerprint = $visitor_id ?? hash('sha256', self::get_current_visitor_ip());

		// Extract device_hash from request header (set by JS) or fallback to UserAgent+IP hash
		$device_hash_header = isset($_SERVER['HTTP_X_SPX_DEVICE_HASH']) 
			? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SPX_DEVICE_HASH'])) 
			: null;
		$device_hash = $device_hash_header ?? hash('sha1', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ':' . self::get_current_visitor_ip());

		// Build cache key using BOTH fingerprint AND device_hash to prevent cross-device collisions
		$cache_key = SparxstarUECCacheHelper::make_key($resolved_user_id, $session_id, $fingerprint . ':' . $device_hash);

		$cached = SparxstarUECCacheHelper::get($cache_key);
		if ($cached !== null) {
			self::$snapshot_cache = $cached;
			return $cached;
		}

		// Query database using v2.0 identity model (fingerprint + device_hash)
		$from_db = SparxstarUECSnapshotRepository::get($fingerprint, $device_hash);
		if ($from_db !== null) {
			SparxstarUECCacheHelper::set($cache_key, $from_db);
			self::$snapshot_cache = $from_db;
		}
		return $from_db;
	}

	/**
	 * Internal helper to get the LIVE IP of the CURRENT visitor.
	 */
	public static function get_current_visitor_ip(): string
	{
		$ip_headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
		foreach ($ip_headers as $header) {
			if (!empty($_SERVER[$header])) {
				$ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])))[0]);
				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	public static function get_current_user_session_id(): string {
		return SparxstarUECSessionManager::get_session_id();
	}

	// --- VI. Geolocation Data Access ---

	/**
	 * Get the visitor's city from geolocation data.
	 *
	 * @param int|null    $user_id User ID (null for current user).
	 * @param string|null $session_id Session ID (null for current session).
	 * @param string      $default Default value if unavailable.
	 * @return string City name.
	 */
	public static function get_city(?int $user_id = null, ?string $session_id = null, string $default = ''): string {
		return (string) self::_get_value_from_snapshot('server_side_data.geolocation.city', $default, $user_id, $session_id);
	}

	/**
	 * Get the visitor's state/province from geolocation data.
	 *
	 * @param int|null    $user_id User ID (null for current user).
	 * @param string|null $session_id Session ID (null for current session).
	 * @param string      $default Default value if unavailable.
	 * @return string State/province name.
	 */
	public static function get_state(?int $user_id = null, ?string $session_id = null, string $default = ''): string {
		return (string) self::_get_value_from_snapshot('server_side_data.geolocation.state', $default, $user_id, $session_id);
	}

	/**
	 * Get the visitor's postal/ZIP code from geolocation data.
	 *
	 * @param int|null    $user_id User ID (null for current user).
	 * @param string|null $session_id Session ID (null for current session).
	 * @param string      $default Default value if unavailable.
	 * @return string Postal code.
	 */
	public static function get_postal_code(?int $user_id = null, ?string $session_id = null, string $default = ''): string {
		return (string) self::_get_value_from_snapshot('server_side_data.geolocation.postal_code', $default, $user_id, $session_id);
	}

	/**
	 * Get the visitor's region from geolocation data.
	 *
	 * @param int|null    $user_id User ID (null for current user).
	 * @param string|null $session_id Session ID (null for current session).
	 * @param string      $default Default value if unavailable.
	 * @return string Region name.
	 */
	public static function get_region(?int $user_id = null, ?string $session_id = null, string $default = ''): string {
		return (string) self::_get_value_from_snapshot('server_side_data.geolocation.region', $default, $user_id, $session_id);
	}

	/**
	 * Get the visitor's country from geolocation data.
	 *
	 * @param int|null    $user_id User ID (null for current user).
	 * @param string|null $session_id Session ID (null for current session).
	 * @param string      $default Default value if unavailable.
	 * @return string Country name.
	 */
	public static function get_country(?int $user_id = null, ?string $session_id = null, string $default = ''): string {
		return (string) self::_get_value_from_snapshot('server_side_data.geolocation.country', $default, $user_id, $session_id);
	}

	/**
	 * Get the visitor's full geolocation data array.
	 *
	 * @param int|null    $user_id User ID (null for current user).
	 * @param string|null $session_id Session ID (null for current session).
	 * @return array Geolocation data with city, state, postal_code, region, country, latitude, longitude, timezone.
	 */
	public static function get_geolocation(?int $user_id = null, ?string $session_id = null): array {
		$geo = self::_get_value_from_snapshot('server_side_data.geolocation', array(), $user_id, $session_id);
		return is_array($geo) ? $geo : array();
	}

	/**
	 * Retrieves the entire raw snapshot for debugging or full-data use cases.
	 */
	public static function get_full_snapshot(?int $user_id = null, ?string $session_id = null): ?array
	{
		return self::_fetch_snapshot($user_id, $session_id);
	}
}

<?php

/**
 * Shared utility helpers and public snapshot accessors
 * for SPARXSTAR environment diagnostics.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC;

use Starisian\SparxstarUEC\core\SparxstarUECSnapshotRepository;
use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;
use Starisian\SparxstarUEC\includes\SparxstarUECCacheHelper;
use Starisian\SparxstarUEC\helpers\StarLogger;
use Throwable;
use function __;
use function apply_filters;
use function esc_url_raw;
use function explode;
use function filter_var;
use function get_current_user_id;
use function gmdate;
use function hash;
use function headers_sent;
use function is_array;
use function is_ssl;
use function preg_match;
use function sanitize_text_field;
use function session_id;
use function session_start;
use function session_status;
use function stripos;
use function strtolower;
use function str_contains;
use function strtok;
use function substr;
use function trim;
use function wp_json_encode;
use function wp_unslash;
use const FILTER_VALIDATE_IP;
use const PHP_SESSION_ACTIVE;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Collection of static helper methods for retrieving sanitized visitor metadata
 * AND reading environment snapshots as the public API.
 */
final class StarUserUtils
{

	/**
	 * Session namespace key used to avoid collisions with other plugins.
	 */
	private const SESSION_NAMESPACE = 'sparxstar_env';

	/**
	 * Session storage key for the most recent environment snapshot.
	 */
	private const SESSION_KEY = 'sparxstar_env_snapshot';

	/**
	 * Runtime cache for the current request's snapshot.
	 *
	 * @var array|null
	 */
	private static ?array $snapshot_cache = null;

	// -------------------------------------------------------------------------
	// I. IDENTITY HELPERS (MODERN MODEL)
	// -------------------------------------------------------------------------

	/**
	 * Return the stable plugin fingerprint used by JS + DB.
	 * Priority: header → cookie → IP hash.
	 */
	public static function getFingerprint(): string
	{
		// 1. Header from JS (authoritative)
		$header = self::get_server_value('HTTP_X_SPX_FINGERPRINT');
		if ($header !== '') {
			return sanitize_text_field($header);
		}

		// 2. Cookie (persistence)
		if (isset($_COOKIE['spx_visitor_id'])) {
			return sanitize_text_field(wp_unslash($_COOKIE['spx_visitor_id']));
		}

		// 3. Fallback to legacy (IP-based) fingerprint
		return hash('sha256', self::getClientIP() ?: 'unknown');
	}

	/**
	 * Return stable device hash used as second identity key.
	 * Priority: header → UA+IP hash.
	 */
	public static function getDeviceHash(): string
	{
		$header = self::get_server_value('HTTP_X_SPX_DEVICE_HASH');
		if ($header !== '') {
			return sanitize_text_field($header);
		}

		// Fallback for backward compatibility
		return hash('sha1', self::getUserAgent() . ':' . self::getClientIP());
	}

	// -------------------------------------------------------------------------
	// II. SNAPSHOT FETCHING & CACHING (UNIFIED ENGINE)
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the latest stored snapshot for a user/session (public).
	 */
	public static function get_snapshot(?int $user_id = null, ?string $session_id = null): ?array
	{
		return self::fetch_snapshot($user_id, $session_id);
	}

	/**
	 * Internal engine: fetch the full snapshot from runtime cache, object cache, or database.
	 */
	private static function fetch_snapshot(?int $user_id, ?string $session_id): ?array
	{
		$resolved_user_id = $user_id ?? (get_current_user_id() ?: null);

		if (self::$snapshot_cache !== null) {
			return self::$snapshot_cache;
		}

		// Identity resolution v2.0: fingerprint + device_hash
		$fingerprint = self::getFingerprint();
		$device_hash = self::getDeviceHash();

		// Build cache key using BOTH fingerprint AND device_hash to prevent cross-device collisions
		$cache_key = SparxstarUECCacheHelper::make_key(
			$resolved_user_id,
			$session_id,
			$fingerprint . ':' . $device_hash
		);

		$cached = SparxstarUECCacheHelper::get($cache_key);
		if ($cached !== null) {
			self::$snapshot_cache = $cached;
			return $cached;
		}

		// Query database using v2.0 identity model
		$from_db = SparxstarUECSnapshotRepository::get($fingerprint, $device_hash);
		if ($from_db !== null) {
			SparxstarUECCacheHelper::set($cache_key, $from_db);
			self::$snapshot_cache = $from_db;
		}

		return $from_db;
	}

	/**
	 * Generic "dot path" accessor into the snapshot structure.
	 */
	private static function get_value_from_snapshot(
		string $path,
		mixed $default,
		?int $user_id,
		?string $session_id
	): mixed {
		$snapshot = self::fetch_snapshot($user_id, $session_id);
		if ($snapshot === null) {
			return $default;
		}

		$current = $snapshot;
		foreach (explode('.', $path) as $key) {
			if (! is_array($current) || ! isset($current[$key])) {
				return $default;
			}
			$current = $current[$key];
		}

		return $current;
	}

	/**
	 * Flushes the cache for a given user, forcing the next getter call to fetch fresh data.
	 */
	public static function flush_cache(?int $user_id = null, ?string $session_id = null): void
	{
		$resolved_user_id = $user_id ?? (get_current_user_id() ?: null);
		$fingerprint      = self::getFingerprint();
		$device_hash      = self::getDeviceHash();

		$cache_key = SparxstarUECCacheHelper::make_key(
			$resolved_user_id,
			$session_id,
			$fingerprint . ':' . $device_hash
		);

		SparxstarUECCacheHelper::delete($cache_key);
		self::$snapshot_cache = null;
	}

	/**
	 * Retrieves the entire raw snapshot for debugging or full-data use cases.
	 */
	public static function get_full_snapshot(?int $user_id = null, ?string $session_id = null): ?array
	{
		return self::fetch_snapshot($user_id, $session_id);
	}

	// -------------------------------------------------------------------------
	// III. PUBLIC SNAPSHOT GETTERS (EX-STARUSERENV)
	// -------------------------------------------------------------------------

	// --- Identification & Tracking ---

	/**
	 * Get the user's stable, anonymous browser fingerprint ID from the snapshot.
	 * This is the primary key for tracking anonymous users.
	 */
	public static function get_visitor_id(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'identifiers.visitor_id',
			'',
			$user_id,
			$session_id
		);
	}

	// --- Performance & UX Optimization ---

	public static function get_network_type(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'client_side_data.network.effectiveType',
			'unknown',
			$user_id,
			$session_id
		);
	}

	public static function is_data_saver_enabled(?int $user_id = null, ?string $session_id = null): bool
	{
		return (bool) self::get_value_from_snapshot(
			'client_side_data.network.saveData',
			false,
			$user_id,
			$session_id
		);
	}

	public static function get_user_device(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'client_side_data.device.type',
			'unknown',
			$user_id,
			$session_id
		);
	}

	public static function get_user_gpu(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'client_side_data.fingerprint.gpu',
			'unknown',
			$user_id,
			$session_id
		);
	}

	public static function get_os_name(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'client_side_data.os.name',
			'unknown',
			$user_id,
			$session_id
		);
	}

	public static function get_browser_name(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'client_side_data.client.name',
			'unknown',
			$user_id,
			$session_id
		);
	}

	// --- Geolocation & Localization (Snapshot-based) ---

	public static function get_user_ip(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'server_side_data.ip_address',
			'0.0.0.0',
			$user_id,
			$session_id
		);
	}

	public static function get_user_country(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'server_side_data.geolocation.country',
			'unknown',
			$user_id,
			$session_id
		);
	}

	public static function get_user_state(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'server_side_data.geolocation.region',
			'unknown',
			$user_id,
			$session_id
		);
	}

	public static function get_user_city(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'server_side_data.geolocation.city',
			'unknown',
			$user_id,
			$session_id
		);
	}

	public static function get_user_language(?int $user_id = null, ?string $session_id = null): string
	{
		$lang = (string) self::get_value_from_snapshot(
			'client_side_data.context.language',
			'',
			$user_id,
			$session_id
		);

		return substr($lang, 0, 2);
	}

	// --- Legal & Security ---

	public static function get_snapshot_timestamp(?int $user_id = null, ?string $session_id = null): string
	{
		return (string) self::get_value_from_snapshot(
			'server_side_data.timestamp_utc',
			'',
			$user_id,
			$session_id
		);
	}

	public static function is_on_vpn(?int $user_id = null, ?string $session_id = null): bool
	{
		return (bool) self::get_value_from_snapshot(
			'server_side_data.geolocation.is_vpn',
			false,
			$user_id,
			$session_id
		);
	}

	public static function get_current_user_session_id(): string
	{
		return SparxstarUECSessionManager::get_session_id();
	}

	// --- Snapshot-based Geolocation Convenience (camelCase variants) ---

	public static function get_geolocation(?int $user_id = null, ?string $session_id = null): array
	{
		$geo = self::get_value_from_snapshot(
			'server_side_data.geolocation',
			array(),
			$user_id,
			$session_id
		);

		return is_array($geo) ? $geo : array();
	}

	public static function get_city(
		?int $user_id = null,
		?string $session_id = null,
		string $default = ''
	): string {
		return (string) self::get_value_from_snapshot(
			'server_side_data.geolocation.city',
			$default,
			$user_id,
			$session_id
		);
	}

	public static function get_state(
		?int $user_id = null,
		?string $session_id = null,
		string $default = ''
	): string {
		return (string) self::get_value_from_snapshot(
			'server_side_data.geolocation.state',
			$default,
			$user_id,
			$session_id
		);
	}

	public static function get_postal_code(
		?int $user_id = null,
		?string $session_id = null,
		string $default = ''
	): string {
		return (string) self::get_value_from_snapshot(
			'server_side_data.geolocation.postal_code',
			$default,
			$user_id,
			$session_id
		);
	}

	public static function get_region(
		?int $user_id = null,
		?string $session_id = null,
		string $default = ''
	): string {
		return (string) self::get_value_from_snapshot(
			'server_side_data.geolocation.region',
			$default,
			$user_id,
			$session_id
		);
	}

	public static function get_country(
		?int $user_id = null,
		?string $session_id = null,
		string $default = ''
	): string {
		return (string) self::get_value_from_snapshot(
			'server_side_data.geolocation.country',
			$default,
			$user_id,
			$session_id
		);
	}

	// -------------------------------------------------------------------------
	// IV. SESSION + SERVER HELPERS (LEGACY + MODERN)
	// -------------------------------------------------------------------------

	/**
	 * Ensure a PHP session is initialised before attempting to read or write data.
	 */
	private static function ensure_session(): void
	{
		if (PHP_SESSION_ACTIVE === session_status()) {
			self::initialise_namespace();
			return;
		}

		if (headers_sent()) {
			return;
		}

		$options = array(
			'name'            => 'spxenv',
			'cookie_httponly' => true,
			'cookie_samesite' => 'Lax',
		);

		if (is_ssl()) {
			$options['cookie_secure'] = true;
		}

		try {
			session_start($options);
		} catch (Throwable $throwable) {
			StarLogger::error('StarUserUtils', $throwable, array('method' => 'ensure_session'));
			return;
		}

		self::initialise_namespace();
	}

	/**
	 * Guarantee that the plugin-specific session namespace exists.
	 */
	private static function initialise_namespace(): void
	{
		if (! isset($_SESSION[self::SESSION_NAMESPACE]) || ! is_array($_SESSION[self::SESSION_NAMESPACE])) {
			$_SESSION[self::SESSION_NAMESPACE] = array();
		}
	}

	/**
	 * Retrieve a value from the $_SERVER superglobal with sanitization applied.
	 */
	private static function get_server_value(string $key): string
	{
		return isset($_SERVER[$key]) ? sanitize_text_field(wp_unslash($_SERVER[$key])) : '';
	}

	/**
	 * Sanitize and validate an IP address string.
	 */
	private static function filter_ip_address(?string $value): string
	{
		if (! is_string($value)) {
			return '';
		}

		$value = trim($value);
		if ($value === '') {
			return '';
		}

		if (str_contains($value, ',')) {
			$value = strtok($value, ',');
		}

		$filtered_ip = filter_var(trim($value), FILTER_VALIDATE_IP);

		return $filtered_ip ? trim($filtered_ip) : '';
	}

	/**
	 * Retrieve the client IP address considering proxy headers.
	 */
	public static function getClientIP(): string
	{
		$ip_headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ($ip_headers as $header) {
			$value = self::get_server_value($header);
			if ($value === '') {
				continue;
			}

			$ips = array_map('trim', explode(',', $value));
			foreach ($ips as $ip) {
				$filtered_ip = self::filter_ip_address($ip);
				if ($filtered_ip !== '') {
					return $filtered_ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Internal helper to get the LIVE IP of the CURRENT visitor.
	 * (alias for snapshot-independent IP lookup).
	 */
	public static function get_current_visitor_ip(): string
	{
		return self::getClientIP();
	}

	/**
	 * Persist an arbitrary value in the session namespace.
	 */
	public static function setSessionValue(string $key, mixed $value): void
	{
		self::ensure_session();
		$_SESSION[self::SESSION_NAMESPACE][$key] = $value;
	}

	/**
	 * Retrieve a value from the session namespace.
	 */
	public static function getSessionValue(string $key, mixed $default = null): mixed
	{
		self::ensure_session();
		return $_SESSION[self::SESSION_NAMESPACE][$key] ?? $default;
	}

	/**
	 * Store an environment snapshot and its context within the PHP session.
	 */
	public static function storeEnvironmentSnapshot(array $snapshot, array $context = array()): void
	{
		self::ensure_session();

		$_SESSION[self::SESSION_NAMESPACE][self::SESSION_KEY] = array(
			'snapshot'  => $snapshot,
			'context'   => $context,
			'stored_at' => gmdate('c'),
		);
	}

	/**
	 * Retrieve the stored environment snapshot from the PHP session.
	 */
	public static function getEnvironmentSnapshot(): array
	{
		self::ensure_session();

		$stored = $_SESSION[self::SESSION_NAMESPACE][self::SESSION_KEY] ?? array();

		return is_array($stored) ? $stored : array();
	}

	/**
	 * Retrieve the active PHP session identifier when available.
	 */
	public static function getSessionID(): string
	{
		return PHP_SESSION_ACTIVE === session_status() ? (string) session_id() : '';
	}

	/**
	 * Access the current user agent string with sanitization applied.
	 */
	public static function getUserAgent(): string
	{
		return sanitize_text_field(self::get_server_value('HTTP_USER_AGENT'));
	}

	/**
	 * Build the current request URL using sanitized server globals.
	 */
	public static function getCurrentURL(): string
	{
		$host = self::get_server_value('HTTP_HOST');
		$uri  = self::get_server_value('REQUEST_URI');

		if ($host === '' || $uri === '') {
			return '';
		}

		$scheme = is_ssl() ? 'https://' : 'http://';

		return esc_url_raw($scheme . $host . $uri);
	}

	/**
	 * Retrieve the referring URL from the request headers.
	 */
	public static function getReferrerURL(): string
	{
		$referer = self::get_server_value('HTTP_REFERER');
		return $referer ? esc_url_raw($referer) : '';
	}

	/**
	 * Fetch geolocation data using an external provider hooked via WordPress filters.
	 */
	public static function getIPGeoLocation(string $ip = ''): array
	{
		$ip_to_lookup = $ip !== '' ? $ip : self::getClientIP();
		$data         = apply_filters('sparxstar_env_geolocation_lookup', null, $ip_to_lookup);
		return is_array($data) ? $data : array();
	}

	/**
	 * Retrieve a specific field from the geolocation payload or the full JSON
	 * from the external provider (snapshot-independent).
	 */
	public static function getGeoLocationData(string $field = '', string $ip = ''): string
	{
		$location = self::getIPGeoLocation($ip);

		if ($location === array()) {
			return __('Location data unavailable.', SPX_ENV_CHECK_TEXT_DOMAIN);
		}

		if ($field === '') {
			return wp_json_encode($location, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$map = array(
			'city'         => 'city',
			'region'       => 'regionName',
			'country'      => 'countryName',
			'country_code' => 'countryCode',
			'latitude'     => 'lat',
			'longitude'    => 'lon',
			'zip'          => 'zip',
			'timezone'     => 'timezone',
		);

		$key = $map[strtolower($field)] ?? null;
		if ($key !== null && isset($location[$key])) {
			return sanitize_text_field((string) $location[$key]);
		}

		return __('Specific location data unavailable.', SPX_ENV_CHECK_TEXT_DOMAIN);
	}

	/**
	 * Determine the preferred language from the Accept-Language header.
	 */
	public static function getUserLanguage(string $ret_type = 'code'): string
	{
		$raw = self::get_server_value('HTTP_ACCEPT_LANGUAGE');
		if ($raw === '') {
			return '';
		}

		$parts   = explode(',', $raw);
		$primary = trim(explode(';', $parts[0])[0]);

		if (strtolower($ret_type) === 'code') {
			return sanitize_text_field(substr($primary, 0, 2));
		}

		return sanitize_text_field($primary);
	}

	/**
	 * Determine the visitor operating system based on the User-Agent string.
	 */
	public static function getUserOS(): string
	{
		$user_agent = strtolower(self::getUserAgent());
		$map        = array(
			'windows'                  => 'Windows',
			'macintosh|mac os x|macos' => 'Mac',
			'linux'                    => 'Linux',
			'ipad|ipod|iphone'         => 'iOS',
			'android'                  => 'Android',
			'blackberry'               => 'BlackBerry',
			'webos'                    => 'webOS',
			'windows phone'            => 'Windows Phone',
		);

		foreach ($map as $needle => $label) {
			if (preg_match('/' . $needle . '/', $user_agent)) {
				return $label;
			}
		}

		return 'Other';
	}

	/**
	 * Get the approximate browser name based on the User-Agent string.
	 */
	public static function getUserBrowser(): string
	{
		$user_agent = self::getUserAgent();

		if (preg_match('/MSIE/i', $user_agent) && ! preg_match('/Opera/i', $user_agent)) {
			return 'Internet Explorer';
		}

		if (preg_match('/Firefox/i', $user_agent)) {
			return 'Firefox';
		}

		if (preg_match('/Chrome/i', $user_agent)) {
			return 'Chrome';
		}

		if (preg_match('/Safari/i', $user_agent)) {
			return 'Safari';
		}

		if (preg_match('/Opera/i', $user_agent)) {
			return 'Opera';
		}

		if (preg_match('/Netscape/i', $user_agent)) {
			return 'Netscape';
		}

		if (preg_match('/Edge/i', $user_agent)) {
			return 'Edge';
		}

		if (preg_match('/CriOS/i', $user_agent)) {
			return 'Chrome iOS';
		}

		if (preg_match('/FxiOS/i', $user_agent)) {
			return 'Firefox iOS';
		}

		return 'Unknown';
	}

	/**
	 * Determine if the request is from a bot/crawler using common user agent patterns.
	 */
	public static function isBot(): bool
	{
		$user_agent = self::getUserAgent();
		$bots       = array(
			'googlebot',
			'bingbot',
			'yandexbot',
			'duckduckbot',
			'slurp',
			'baiduspider',
			'facebookexternalhit',
			'pinterestbot',
			'linkedinbot',
			'twitterbot',
			'mj12bot',
			'ahrefsbot',
			'semrushbot',
			'petalbot',
			'sogouspider',
			'exabot',
			'facebot',
			'ia_archiver',
			'alexabot',
			'megaindex',
			'developers.google.com/speed/pagespeed/insights',
			'gtmetrix',
			'pingdom',
			'uptimebot',
			'lighthouse',
			'w3c_validator',
			'screaming frog',
		);

		foreach ($bots as $bot) {
			if (stripos($user_agent, $bot) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieve the current HTTP method (GET, POST, etc.).
	 */
	public static function getRequestMethod(): string
	{
		return sanitize_text_field(self::get_server_value('REQUEST_METHOD'));
	}

	/**
	 * Check if the current request is an AJAX request.
	 */
	public static function isAjax(): bool
	{
		if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
			return true;
		}

		$requested_with = strtolower(self::get_server_value('HTTP_X_REQUESTED_WITH'));

		return $requested_with === 'xmlhttprequest';
	}

	/**
	 * Get the current WordPress environment type.
	 */
	public static function getWpEnvironmentType(): string
	{
		if (function_exists('wp_get_environment_type')) {
			return wp_get_environment_type();
		}

		if (defined('WP_ENVIRONMENT_TYPE')) {
			return WP_ENVIRONMENT_TYPE;
		}

		return 'production';
	}

	/**
	 * Allow snapshot creation if none exists for current identity.
	 * Clears the session block flag when admin visits settings but no snapshot exists.
	 */
	public static function allow_snapshot_if_none_exist(): void
	{
		try {
			// Get current identity
			$fingerprint = self::getFingerprint();
			$device_hash = self::getDeviceHash();

			// Check if snapshot exists for this identity
			$snapshot = SparxstarUECSnapshotRepository::get($fingerprint, $device_hash);

			if ($snapshot === null) {
				// No snapshot stored → allow next snapshot creation
				SparxstarUECSessionManager::clear_snapshot_flag();
				StarLogger::debug(
					'StarUserUtils',
					'No snapshot found for current identity. Session flag cleared to allow creation.'
				);
			}
		} catch (\Throwable $e) {
			StarLogger::error(
				'StarUserUtils',
				$e,
				array(
					'method' => 'allow_snapshot_if_none_exist',
					'context' => 'snapshot_regeneration_check',
				)
			);
		}
	}
}

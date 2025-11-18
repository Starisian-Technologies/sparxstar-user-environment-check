<?php
/**
 * Shared utility helpers for SPARXSTAR environment diagnostics.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC;

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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collection of static helper methods for retrieving sanitized visitor metadata.
 */
final class StarUserUtils {


	/**
	 * Session namespace key used to avoid collisions with other plugins.
	 */
	private const SESSION_NAMESPACE = 'sparxstar_env';

	/**
	 * Session storage key for the most recent environment snapshot.
	 */
	private const SESSION_KEY = 'sparxstar_env_snapshot';

	/**
	 * Return the stable plugin fingerprint used by JS + DB.
	 */
	public static function getFingerprint(): string {
		// Try to read the JS-generated fingerprint from request headers
		$header = self::get_server_value( 'HTTP_X_SPX_FINGERPRINT' );
		if ( $header !== '' ) {
			return sanitize_text_field( $header );
		}

		// Fallback to legacy (IP-based) fingerprint
		return hash( 'sha256', self::getClientIP() ?: 'unknown' );
	}

	/**
	 * Return stable device hash used as second identity key.
	 */
	public static function getDeviceHash(): string {
		$header = self::get_server_value( 'HTTP_X_SPX_DEVICE_HASH' );
		if ( $header !== '' ) {
			return sanitize_text_field( $header );
		}

		// Fallback for backward compatibility
		return hash( 'sha1', self::getUserAgent() . ':' . self::getClientIP() );
	}

	/**
	 * Retrieve the latest stored snapshot for a user/session.
	 */
	public static function get_snapshot( ?int $user_id = null, ?string $session_id = null ): ?array {
		$resolved_user_id = $user_id ?? ( get_current_user_id() ?: null );
		$fingerprint      = self::getFingerprint();

		return SparxstarUECCacheHelper::get_snapshot(
			$resolved_user_id,
			$session_id,
			$fingerprint
		);
	}

	/**
	 * Get geolocation data for the current visitor.
	 * Returns array with city, state, postal_code, region, country fields.
	 *
	 * @return array Geolocation data or empty array if unavailable.
	 */
	public static function getGeolocation(): array {
		$snapshot = self::get_snapshot();
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) {
			return array();
		}

		$geo = $snapshot['server_side_data']['geolocation'] ?? array();
		return is_array( $geo ) ? $geo : array();
	}

	/**
	 * Get a specific geolocation field for the current visitor.
	 *
	 * @param string $field Field name: city, state, postal_code, region, country, latitude, longitude, timezone
	 * @param mixed  $default Default value if field is not available.
	 * @return mixed Field value or default.
	 */
	public static function getGeolocationField( string $field, $default = '' ) {
		$geo = self::getGeolocation();
		return $geo[ $field ] ?? $default;
	}

	/**
	 * Get the visitor's city.
	 *
	 * @param string $default Default value if unavailable.
	 * @return string City name.
	 */
	public static function getCity( string $default = '' ): string {
		return (string) self::getGeolocationField( 'city', $default );
	}

	/**
	 * Get the visitor's state/province.
	 *
	 * @param string $default Default value if unavailable.
	 * @return string State/province name.
	 */
	public static function getState( string $default = '' ): string {
		return (string) self::getGeolocationField( 'state', $default );
	}

	/**
	 * Get the visitor's postal/ZIP code.
	 *
	 * @param string $default Default value if unavailable.
	 * @return string Postal code.
	 */
	public static function getPostalCode( string $default = '' ): string {
		return (string) self::getGeolocationField( 'postal_code', $default );
	}

	/**
	 * Get the visitor's region.
	 *
	 * @param string $default Default value if unavailable.
	 * @return string Region name.
	 */
	public static function getRegion( string $default = '' ): string {
		return (string) self::getGeolocationField( 'region', $default );
	}

	/**
	 * Get the visitor's country.
	 *
	 * @param string $default Default value if unavailable.
	 * @return string Country name.
	 */
	public static function getCountry( string $default = '' ): string {
		return (string) self::getGeolocationField( 'country', $default );
	}

	/**
	 * Get the visitor's latitude.
	 *
	 * @param float $default Default value if unavailable.
	 * @return float Latitude.
	 */
	public static function getLatitude( float $default = 0.0 ): float {
		return (float) self::getGeolocationField( 'latitude', $default );
	}

	/**
	 * Get the visitor's longitude.
	 *
	 * @param float $default Default value if unavailable.
	 * @return float Longitude.
	 */
	public static function getLongitude( float $default = 0.0 ): float {
		return (float) self::getGeolocationField( 'longitude', $default );
	}

	/**
	 * Get the visitor's timezone.
	 *
	 * @param string $default Default value if unavailable.
	 * @return string IANA timezone identifier.
	 */
	public static function getTimezone( string $default = '' ): string {
		return (string) self::getGeolocationField( 'timezone', $default );
	}

	/**
	 * Remove a cached snapshot entry.
	 */
	public static function flush_cache( ?int $user_id = null, ?string $session_id = null ): void {
		$resolved_user_id = $user_id ?? ( get_current_user_id() ?: null );
		$fingerprint      = self::getFingerprint();

		SparxstarUECCacheHelper::delete_snapshot(
			$resolved_user_id,
			$session_id,
			$fingerprint
		);
	}

	/**
	 * Ensure a PHP session is initialised before attempting to read or write data.
	 */
	private static function ensure_session(): void {
		if ( PHP_SESSION_ACTIVE === session_status() ) {
			self::initialise_namespace();
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		$options = array(
			'name'            => 'spxenv',
			'cookie_httponly' => true,
			'cookie_samesite' => 'Lax',
		);

		if ( is_ssl() ) {
			$options['cookie_secure'] = true;
		}

		try {
			session_start( $options );
		} catch ( Throwable $throwable ) {
			StarLogger::error( 'StarUserUtils', $throwable, array( 'method' => 'ensure_session' ) );
			return;
		}

		self::initialise_namespace();
	}

	/**
	 * Guarantee that the plugin-specific session namespace exists.
	 */
	private static function initialise_namespace(): void {
		if ( ! isset( $_SESSION[ self::SESSION_NAMESPACE ] ) || ! is_array( $_SESSION[ self::SESSION_NAMESPACE ] ) ) {
			$_SESSION[ self::SESSION_NAMESPACE ] = array();
		}
	}

	/**
	 * Retrieve a value from the $_SERVER superglobal with sanitization applied.
	 */
	private static function get_server_value( string $key ): string {
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	/**
	 * Sanitize and validate an IP address string.
	 */
	private static function filter_ip_address( ?string $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		if ( str_contains( $value, ',' ) ) {
			$value = strtok( $value, ',' );
		}

		$filtered_ip = filter_var( trim( $value ), FILTER_VALIDATE_IP );

		return $filtered_ip ? trim( $filtered_ip ) : '';
	}

	/**
	 * Retrieve the client IP address considering proxy headers.
	 */
	public static function getClientIP(): string {
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

		foreach ( $ip_headers as $header ) {
			$value = self::get_server_value( $header );
			if ( $value === '' ) {
				continue;
			}

			$ips = array_map( 'trim', explode( ',', $value ) );
			foreach ( $ips as $ip ) {
				$filtered_ip = self::filter_ip_address( $ip );
				if ( $filtered_ip !== '' ) {
					return $filtered_ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Persist an arbitrary value in the session namespace.
	 */
	public static function setSessionValue( string $key, mixed $value ): void {
		self::ensure_session();
		$_SESSION[ self::SESSION_NAMESPACE ][ $key ] = $value;
	}

	/**
	 * Retrieve a value from the session namespace.
	 */
	public static function getSessionValue( string $key, mixed $default = null ): mixed {
		self::ensure_session();

		return $_SESSION[ self::SESSION_NAMESPACE ][ $key ] ?? $default;
	}

	/**
	 * Store an environment snapshot and its context within the PHP session.
	 */
	public static function storeEnvironmentSnapshot( array $snapshot, array $context = array() ): void {
		self::ensure_session();

		$_SESSION[ self::SESSION_NAMESPACE ][ self::SESSION_KEY ] = array(
			'snapshot'  => $snapshot,
			'context'   => $context,
			'stored_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Retrieve the stored environment snapshot from the PHP session.
	 */
	public static function getEnvironmentSnapshot(): array {
		self::ensure_session();

		$stored = $_SESSION[ self::SESSION_NAMESPACE ][ self::SESSION_KEY ] ?? array();

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Retrieve the active PHP session identifier when available.
	 */
	public static function getSessionID(): string {
		return PHP_SESSION_ACTIVE === session_status() ? (string) session_id() : '';
	}

	/**
	 * Access the current user agent string with sanitization applied.
	 */
	public static function getUserAgent(): string {
		return sanitize_text_field( self::get_server_value( 'HTTP_USER_AGENT' ) );
	}

	/**
	 * Build the current request URL using sanitized server globals.
	 */
	public static function getCurrentURL(): string {
		$host = self::get_server_value( 'HTTP_HOST' );
		$uri  = self::get_server_value( 'REQUEST_URI' );

		if ( $host === '' || $uri === '' ) {
			return '';
		}

		$scheme = is_ssl() ? 'https://' : 'http://';

		return esc_url_raw( $scheme . $host . $uri );
	}

	/**
	 * Retrieve the referring URL from the request headers.
	 */
	public static function getReferrerURL(): string {
		$referer = self::get_server_value( 'HTTP_REFERER' );

		return $referer ? esc_url_raw( $referer ) : '';
	}

	/**
	 * Fetch geolocation data using an external provider hooked via WordPress filters.
	 */
	public static function getIPGeoLocation( string $ip = '' ): array {
		$ip_to_lookup = $ip !== '' ? $ip : self::getClientIP();
		$data         = apply_filters( 'sparxstar_env_geolocation_lookup', null, $ip_to_lookup );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Retrieve a specific field from the geolocation payload or the full JSON.
	 */
	public static function getGeoLocationData( string $field = '', string $ip = '' ): string {
		$location = self::getIPGeoLocation( $ip );

		if ( $location === array() ) {
			return __( 'Location data unavailable.', SPX_ENV_CHECK_TEXT_DOMAIN );
		}

		if ( $field === '' ) {
			return wp_json_encode( $location, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
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

		$key = $map[ strtolower( $field ) ] ?? null;
		if ( $key !== null && isset( $location[ $key ] ) ) {
			return sanitize_text_field( (string) $location[ $key ] );
		}

		return __( 'Specific location data unavailable.', SPX_ENV_CHECK_TEXT_DOMAIN );
	}

	/**
	 * Determine the preferred language from the Accept-Language header.
	 */
	public static function getUserLanguage( string $ret_type = 'code' ): string {
		$raw = self::get_server_value( 'HTTP_ACCEPT_LANGUAGE' );
		if ( $raw === '' ) {
			return '';
		}

		$parts   = explode( ',', $raw );
		$primary = trim( explode( ';', $parts[0] )[0] );

		if ( strtolower( $ret_type ) === 'code' ) {
			return sanitize_text_field( substr( $primary, 0, 2 ) );
		}

		return sanitize_text_field( $primary );
	}

	/**
	 * Determine the visitor operating system based on the User-Agent string.
	 */
	public static function getUserOS(): string {
		$user_agent = strtolower( self::getUserAgent() );
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

		foreach ( $map as $needle => $label ) {
			if ( preg_match( '/' . $needle . '/', $user_agent ) ) {
				return $label;
			}
		}

		return 'Other';
	}

	/**
	 * Get the approximate browser name based on the User-Agent string.
	 */
	public static function getUserBrowser(): string {
		$user_agent = self::getUserAgent();

		if ( preg_match( '/MSIE/i', $user_agent ) && ! preg_match( '/Opera/i', $user_agent ) ) {
			return 'Internet Explorer';
		}

		if ( preg_match( '/Firefox/i', $user_agent ) ) {
			return 'Firefox';
		}

		if ( preg_match( '/Chrome/i', $user_agent ) ) {
			return 'Chrome';
		}

		if ( preg_match( '/Safari/i', $user_agent ) ) {
			return 'Safari';
		}

		if ( preg_match( '/Opera/i', $user_agent ) ) {
			return 'Opera';
		}

		if ( preg_match( '/Netscape/i', $user_agent ) ) {
			return 'Netscape';
		}

		if ( preg_match( '/Edge/i', $user_agent ) ) {
			return 'Edge';
		}

		if ( preg_match( '/CriOS/i', $user_agent ) ) {
			return 'Chrome iOS';
		}

		if ( preg_match( '/FxiOS/i', $user_agent ) ) {
			return 'Firefox iOS';
		}

		return 'Unknown';
	}

	/**
	 * Determine if the request is from a bot/crawler using common user agent patterns.
	 */
	public static function isBot(): bool {
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

		foreach ( $bots as $bot ) {
			if ( stripos( $user_agent, $bot ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieve the current HTTP method (GET, POST, etc.).
	 */
	public static function getRequestMethod(): string {
		return sanitize_text_field( self::get_server_value( 'REQUEST_METHOD' ) );
	}

	/**
	 * Check if the current request is an AJAX request.
	 */
	public static function isAjax(): bool {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return true;
		}

		$requested_with = strtolower( self::get_server_value( 'HTTP_X_REQUESTED_WITH' ) );

		return $requested_with === 'xmlhttprequest';
	}

	/**
	 * Get the current WordPress environment type.
	 */
	public static function getWpEnvironmentType(): string {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return wp_get_environment_type();
		}

		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			return WP_ENVIRONMENT_TYPE;
		}

		return 'production';
	}
}

<?php
/**
 * Utility helpers for SPARXSTAR environment diagnostics.
 *
 * @package SparxstarUserEnvironmentCheck
 * @version 1.0.0
 * @since 1.0.0
 * @license GLP-3.0-or-later
 */

namespace Starisian\SparxstarUEC\utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collection of static helper methods for retrieving sanitized visitor metadata.
 */
final class StarUserUtils {

	/**
	 * Retrieves a sanitized value from the $_SERVER superglobal.
	 *
	 * @param string $key The key to retrieve from $_SERVER.
	 * @return string The sanitized value or an empty string if not set.
	 */
	private static function get_server_value( string $key ): string {
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	/**
	 * Sanitize and validate an IP address string.
	 *
	 * @param string|null $value Potential IP address.
	 * @return string Normalized IP or empty string on failure.
	 */
	private static function filter_ip_address( ?string $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		// Handle comma-separated list (e.g., from X-Forwarded-For)
		if ( str_contains( $value, ',' ) ) {
			$value = strtok( $value, ',' );
		}

		// Ensure it's a valid IP and not a private/reserved range if applicable
		// FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		// These flags are for stricter validation, might need to remove if you want to log private IPs.
		$filtered_ip = filter_var( $value, FILTER_VALIDATE_IP );

		return trim($filtered_ip) ?: ''; // Return the valid IP or empty string.
	}

	/**
	 * Retrieve the client IP address considering proxy headers.
	 *
	 * This method attempts to get the real client IP by checking various
	 * proxy-related headers, falling back to REMOTE_ADDR.
	 *
	 * @return string The client's IP address or '0.0.0.0' if undetectable.
	 */
	public static function getClientIP(): string {
		$ip_headers = [
			'HTTP_CF_CONNECTING_IP',     // Cloudflare
			'HTTP_CLIENT_IP',            // Generic proxy
			'HTTP_X_FORWARDED_FOR',      // Standard proxy
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',               // Last resort
		];

		foreach ( $ip_headers as $header ) {
			$value = self::get_server_value( $header );
			if ( empty( $value ) ) {
				continue;
			}

			// Handle comma-separated lists (e.g., X-Forwarded-For can contain multiple IPs)
			$ips = array_map( 'trim', explode( ',', $value ) );
			foreach ( $ips as $ip ) {
				$filtered_ip = self::filter_ip_address( $ip );
				if ( $filtered_ip ) {
					// Found a valid public IP.
					// You might want to add a check here for known proxy IPs if you need even more accurate 'real' IP.
					return trim($filtered_ip);
				}
			}
		}

		return '0.0.0.0'; // Fallback if no valid IP is found.
	}

	/**
	 * Retrieve the active PHP session identifier when available.
	 *
	 * @return string Session identifier or an empty string when no session is active.
	 */
	public static function getSessionID(): string {
		return PHP_SESSION_ACTIVE === session_status() ? session_id() : '';
	}

	/**
	 * Access the current user agent string with sanitization applied.
	 *
	 * @return string Sanitized user agent string.
	 */
	public static function getUserAgent(): string {
		return trim(self::get_server_value( 'HTTP_USER_AGENT' ));
	}

	/**
	 * Build the current request URL using sanitized server globals.
	 *
	 * @return string Normalized current URL or an empty string when incomplete data is available.
	 */
	public static function getCurrentURL(): string {
		$host = self::get_server_value( 'HTTP_HOST' );
		$uri  = self::get_server_value( 'REQUEST_URI' );

		if ( empty( $host ) || empty( $uri ) ) {
			return '';
		}

		$scheme = is_ssl() ? 'https://' : 'http://';

		// Use esc_url_raw for sanitization before returning.
		return esc_url_raw( $scheme . $host . $uri );
	}

	/**
	 * Retrieve the referring URL from the request headers.
	 *
	 * @return string Normalized referrer URL or an empty string when not provided.
	 */
	public static function getReferrerURL(): string {
		$referer = self::get_server_value( 'HTTP_REFERER' );

		return $referer ? esc_url_raw( $referer ) : '';
	}

	/**
	 * Fetch geolocation data using an external provider hooked via WordPress filters.
	 *
	 * @param string $ip The IP address to lookup. Defaults to the current client IP.
	 * @return array Associative array of geolocation data supplied by integrations.
	 */
	public static function getIPGeoLocation( string $ip = '' ): array {
		if ( empty( $ip ) ) {
			$ip = esc_html(self::getClientIP());
		}

		/**
		 * Filters the geolocation lookup result for a given IP.
		 *
		 * @param array|null $data Initial geolocation data (null).
		 * @param string     $ip   The IP address being looked up.
		 */
		$data = apply_filters( 'sparxstar_env_geolocation_lookup', null, $ip );

		// Ensure the returned data is an array.
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Retrieve a specific field from the geolocation payload or the full JSON.
	 *
	 * @param string $field Optional field name to fetch (e.g., 'city', 'region', 'country', 'latitude', 'longitude').
	 * @param string $ip The IP address to lookup. Defaults to the current client IP.
	 * @return string Human-readable geolocation string, JSON string, or a fallback message.
	 */
	public static function getGeoLocationData( string $field = '', string $ip = '' ): string {
		$location = self::getIPGeoLocation( $ip );

		if ( empty( $location ) ) {
			return __( 'Location data unavailable.', 'sparxstar-user-environment-check' );
		}

		if ( '' === $field ) {
			return wp_json_encode( $location, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		// Common fields and their expected keys in the lookup result (adjust if your provider uses different keys).
		$map = [
			'city'      => 'city',
			'region'    => 'regionName', // Common for some providers
			'country'   => 'countryName', // Common for some providers
			'country_code' => 'countryCode',
			'latitude'  => 'lat',
			'longitude' => 'lon',
			'zip'       => 'zip',
			'timezone'  => 'timezone',
		];

		$key = $map[ strtolower( $field ) ] ?? null;
		if ( $key && isset( $location[ $key ] ) ) {
			return trim( (string) $location[ $key ] );
		}

		return __( 'Specific location data unavailable.', 'sparxstar-user-environment-check' );
	}

	/**
	 * Determine the preferred language from the Accept-Language header.
	 *
	 * @param string $ret_type Either 'code' for the ISO 639-1 code (e.g., 'en') or 'locale' for the full locale string (e.g., 'en-US').
	 * @return string Sanitized language representation.
	 */
	public static function getUserLanguage( string $ret_type = 'code' ): string {
		$raw = self::get_server_value( 'HTTP_ACCEPT_LANGUAGE' );
		if ( empty( $raw ) ) {
			return '';
		}

		// Extract the primary language preference
		$parts = explode( ',', $raw );
		$primary = explode( ';', $parts[0] )[0]; // Remove q-value if present
		$primary = trim( $primary );

		if ( 'code' === strtolower( $ret_type ) ) {
			return esc_attr(trim(substr( $primary, 0, 2 ))); // e.g., 'en' from 'en-US'
		}

		return esc_attr(trim($primary)); // e.g., 'en-US'
	}

	/**
	 * Determine the visitor operating system based on the User-Agent string.
	 * This is a lightweight, server-side detection and might not be as precise as client-side JS (device-detector-js).
	 *
	 * @return string Friendly operating system name (e.g., "Windows", "Mac", "Linux", "iOS", "Android").
	 */
	public static function getUserOS(): string {
		$user_agent = strtolower( self::getUserAgent() );
		$map        = [
			'windows'      => 'Windows',
			'macintosh|mac os x|macos' => 'Mac',
			'linux'        => 'Linux',
			'ipad|ipod|iphone' => 'iOS',
			'android'      => 'Android',
			'blackberry'   => 'BlackBerry',
			'webos'        => 'webOS',
			'windows phone' => 'Windows Phone',
		];

		foreach ( $map as $needle => $label ) {
			if ( preg_match( '/' . $needle . '/', $user_agent ) ) {
				return $label;
			}
		}

		return 'Other';
	}

	/**
	 * Get the approximate browser name based on User-Agent string.
	 * This is a lightweight, server-side detection and might not be as precise as client-side JS (device-detector-js).
	 *
	 * @return string Friendly browser name (e.g., "Chrome", "Firefox", "Safari").
	 */
	public static function getUserBrowser(): string {
		$user_agent = self::getUserAgent();

		if ( preg_match( '/MSIE/i', $user_agent ) && ! preg_match( '/Opera/i', $user_agent ) ) {
			return 'Internet Explorer';
		} elseif ( preg_match( '/Firefox/i', $user_agent ) ) {
			return 'Firefox';
		} elseif ( preg_match( '/Chrome/i', $user_agent ) ) {
			return 'Chrome';
		} elseif ( preg_match( '/Safari/i', $user_agent ) ) {
			return 'Safari';
		} elseif ( preg_match( '/Opera/i', $user_agent ) ) {
			return 'Opera';
		} elseif ( preg_match( '/Netscape/i', $user_agent ) ) {
			return 'Netscape';
		} elseif ( preg_match( '/Edge/i', $user_agent ) ) {
			return 'Edge';
		} elseif ( preg_match( '/CriOS/i', $user_agent ) ) {
			return 'Chrome iOS';
		} elseif ( preg_match( '/FxiOS/i', $user_agent ) ) {
			return 'Firefox iOS';
		}

		return 'Unknown';
	}

	/**
	 * Determine if the request is from a bot/crawler using common user agent patterns.
	 * This is a simplified check. For robust bot detection, a dedicated library or service is recommended.
	 *
	 * @return bool True if a bot is detected, false otherwise.
	 */
	public static function isBot(): bool {
		$user_agent = self::getUserAgent();
		$bots = [
			'googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'slurp', 'baiduspider',
			'facebookexternalhit', 'pinterestbot', 'linkedinbot', 'twitterbot', 'mj12bot',
			'ahrefsbot', 'semrushbot', 'petalbot', 'sogouspider', 'exabot', 'facebot',
			'ia_archiver', 'alexabot', 'megaindex', 'developers.google.com/speed/pagespeed/insights', // Google PageSpeed Insights
			'gtmetrix', 'pingdom', 'uptimebot', 'lighthouse', 'w3c_validator', 'screaming frog'
		];

		foreach ( $bots as $bot ) {
			if ( stripos( $user_agent, $bot ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Retrieve the current HTTP method (GET, POST, etc.).
	 *
	 * @return string The HTTP method.
	 */
	public static function getRequestMethod(): string {
		return esc_attr(trim(self::get_server_value( 'REQUEST_METHOD' )));
	}

	/**
	 * Check if the current request is an AJAX request.
	 *
	 * @return bool True if it's an AJAX request, false otherwise.
	 */
	public static function isAjax(): bool {
		return ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
			( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) === 'xmlhttprequest' );
	}

	/**
	 * Get the current WordPress environment type.
	 *
	 * @return string The environment type (e.g., 'development', 'staging', 'production').
	 */
	public static function getWpEnvironmentType(): string {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return wp_get_environment_type();
		}
		// Fallback for older WordPress versions.
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			return WP_ENVIRONMENT_TYPE;
		}
		return 'production';
	}

	// You can add more utilities as needed here, e.g., for security checks, referer parsing, etc.
}

<?php declare(strict_types=1);
/**
 * Service for performing GeoIP lookups.
 */

namespace Starisian\SparxstarUEC\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\helpers\StarLogger;

final class SparxstarUECGeoIPService {

	/**
	 * Look up an IP address and return its geographic information.
	 * Uses ipinfo.io as the example provider.
	 *
	 * @param string $ip_address The IP to look up.
	 * @return array|null The location data or null if lookup fails.
	 */
	public function lookup( string $ip_address ): ?array {
		try {
			$api_key = get_option( 'sparxstar_uec_geoip_api_key', '' );

			// If no API key is set, or IP is invalid, do nothing.
			if ( empty( $api_key ) || ! filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
				return null;
			}

			// 1. Check for a cached result first to save API calls.
			$transient_key = 'sparxstar_geoip_' . md5( $ip_address );
			$cached_data = get_transient( $transient_key );
			if ( $cached_data !== false && is_array( $cached_data ) ) {
				return $cached_data;
			}

			// 2. Make the API call.
			$url      = "https://ipinfo.io/{$ip_address}?token={$api_key}";
			$response = wp_remote_get( $url );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				return null;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return null;
			}

			// 3. Extract only the data we need.
			$location_data = array(
				'city'    => sanitize_text_field( $data['city'] ?? '' ),
				'region'  => sanitize_text_field( $data['region'] ?? '' ),
				'country' => sanitize_text_field( $data['country'] ?? '' ),
				'org'     => sanitize_text_field( $data['org'] ?? '' ),
			);

			// 4. Cache the result for 24 hours.
			set_transient( $transient_key, $location_data, DAY_IN_SECONDS );

			return $location_data;
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECGeoIPService', $e, array( 'method' => 'lookup', 'ip_address' => $ip_address ) );
			return null;
		}
	}
}

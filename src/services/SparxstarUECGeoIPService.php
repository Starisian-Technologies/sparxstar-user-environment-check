<?php declare(strict_types=1);
/**
 * Service for performing GeoIP lookups.
 */

namespace Starisian\SparxstarUEC\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\helpers\StarLogger;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;

final class SparxstarUECGeoIPService {

	/**
	 * Look up an IP address and return its geographic information.
	 * Supports both ipinfo.io (API) and MaxMind GeoIP2 (local database).
	 *
	 * @param string $ip_address The IP to look up.
	 * @return array|null The location data or null if lookup fails.
	 */
	public function lookup( string $ip_address ): ?array {
		try {
			// Validate IP address
			if ( ! filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
				return null;
			}

			// Get provider selection from settings
			$provider = get_option( 'sparxstar_uec_geoip_provider', 'none' );

			if ( $provider === 'none' ) {
				return null;
			}

			// Check cache first
			$transient_key = 'sparxstar_geoip_' . md5( $ip_address );
			$cached_data = get_transient( $transient_key );
			if ( $cached_data !== false && is_array( $cached_data ) ) {
				return $cached_data;
			}

			// Route to appropriate provider
			$location_data = null;
			if ( $provider === 'ipinfo' ) {
				$location_data = $this->lookup_ipinfo( $ip_address );
			} elseif ( $provider === 'maxmind' ) {
				$location_data = $this->lookup_maxmind( $ip_address );
			}

			// Cache the result for 24 hours if successful
			if ( is_array( $location_data ) && ! empty( $location_data ) ) {
				set_transient( $transient_key, $location_data, DAY_IN_SECONDS );
			}

			return $location_data;
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECGeoIPService', $e, array( 'method' => 'lookup', 'ip_address' => $ip_address ) );
			return null;
		}
	}

	/**
	 * Perform lookup using ipinfo.io API.
	 *
	 * @param string $ip_address The IP to look up.
	 * @return array|null Location data or null.
	 */
	private function lookup_ipinfo( string $ip_address ): ?array {
		try {
			$api_key = get_option( 'sparxstar_uec_ipinfo_api_key', '' );

			if ( empty( $api_key ) ) {
				return null;
			}

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

			// Normalize to standard format
			return array(
				'city'        => sanitize_text_field( $data['city'] ?? '' ),
				'state'       => sanitize_text_field( $data['region'] ?? '' ),
				'postal_code' => sanitize_text_field( $data['postal'] ?? '' ),
				'region'      => sanitize_text_field( $data['region'] ?? '' ),
				'country'     => sanitize_text_field( $data['country'] ?? '' ),
				'latitude'    => isset( $data['loc'] ) ? (float) explode( ',', $data['loc'] )[0] : 0.0,
				'longitude'   => isset( $data['loc'] ) ? (float) explode( ',', $data['loc'] )[1] : 0.0,
				'timezone'    => sanitize_text_field( $data['timezone'] ?? '' ),
			);
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECGeoIPService', $e, array( 'method' => 'lookup_ipinfo', 'ip_address' => $ip_address ) );
			return null;
		}
	}

	/**
	 * Perform lookup using MaxMind GeoIP2 local database.
	 *
	 * @param string $ip_address The IP to look up.
	 * @return array|null Location data or null.
	 */
	private function lookup_maxmind( string $ip_address ): ?array {
		try {
			$db_path = get_option( 'sparxstar_uec_maxmind_db_path', '' );

			if ( empty( $db_path ) || ! file_exists( $db_path ) ) {
				return null;
			}

			// Check if MaxMind library is available
			if ( ! class_exists( 'GeoIp2\Database\Reader' ) ) {
				StarLogger::warning(
					'SparxstarUECGeoIPService',
					'MaxMind GeoIP2 library not found. Run: composer require geoip2/geoip2',
					array( 'method' => 'lookup_maxmind' )
				);
				return null;
			}

			$reader = new Reader( $db_path );
			$record = $reader->city( $ip_address );

			// Normalize to standard format
			return array(
				'city'        => sanitize_text_field( $record->city->name ?? '' ),
				'state'       => sanitize_text_field( $record->mostSpecificSubdivision->name ?? '' ),
				'postal_code' => sanitize_text_field( $record->postal->code ?? '' ),
				'region'      => sanitize_text_field( $record->mostSpecificSubdivision->name ?? '' ),
				'country'     => sanitize_text_field( $record->country->name ?? '' ),
				'latitude'    => (float) ( $record->location->latitude ?? 0.0 ),
				'longitude'   => (float) ( $record->location->longitude ?? 0.0 ),
				'timezone'    => sanitize_text_field( $record->location->timeZone ?? '' ),
			);
		} catch ( AddressNotFoundException $e ) {
			// IP not found in database - this is normal for private IPs
			return null;
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECGeoIPService', $e, array( 'method' => 'lookup_maxmind', 'ip_address' => $ip_address ) );
			return null;
		}
	}
}

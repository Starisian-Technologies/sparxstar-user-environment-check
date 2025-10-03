<?php
declare(strict_types=1);
namespace Starisian\SparxstarUEC\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\StarUserEnv;
use Starisian\SparxstarUEC\api\SparxstarUECAPI;

final class SparxstarUECSessionManager {

	private const SESSION_NAMESPACE = 'sparxstar_uec_data';

	/** Set multiple values in the session at once. */
	public static function set_all( array $data ): void {
		self::ensure_session();
		foreach ( $data as $key => $value ) {
			$_SESSION[ self::SESSION_NAMESPACE ][ $key ] = $value;
		}
	}

	/** Get a single value from the session. */
	public static function get( string $key, $default = null ) {
		self::ensure_session();
		return $_SESSION[ self::SESSION_NAMESPACE ][ $key ] ?? $default;
	}

	private static function ensure_session(): void {
		if ( session_status() !== PHP_SESSION_ACTIVE && ! headers_sent() ) {
			session_start(
				array(
					'name'            => 'spxenv',
					'cookie_httponly' => true,
					'cookie_samesite' => 'Lax',
				)
			);
		}
		if ( ! isset( $_SESSION[ self::SESSION_NAMESPACE ] ) ) {
			$_SESSION[ self::SESSION_NAMESPACE ] = array();
		}
	}

	/**
	 * Looks up a value for ANY USER/SESSION by querying the historical database record.
	 */
	public static function lookup( string $key, ?int $user_id, ?string $session_id, $default = null ) {
		if ( empty( $user_id ) && empty( $session_id ) ) {
			return $default;
		}

		$path = self::DATA_PATHS[ $key ] ?? null;
		if ( $path === null ) {
			return $default;
		}

		// --- THE FIX ---
		// 1. Get the Singleton instance of the API class.
		$api = SparxstarUECAPI::get_instance();

		// 2. Call the public method on that instance.
		$snapshot = $api->get_latest_snapshot_from_db( $user_id, $session_id );
		// ---------------

		if ( $snapshot === null ) {
			return $default;
		}

		return self::get_value_from_array( $snapshot, $path, $default );
	}
	/**
	 * Retrieve the active PHP session identifier when available.
	 */
	public static function get_session_id(): string {
		self::ensure_session(); // Make sure the session is active before checking
		return session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
	}

	public static function get_value_from_array( array $array, string $path, $default = null ) {
		$keys = explode( '.', $path );
		foreach ( $keys as $key ) {
			if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
				return $default;
			}
			$value = $array[ $key ];
		}
		return $value;
	}
}

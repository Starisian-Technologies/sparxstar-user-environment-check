<?php
declare(strict_types=1);
namespace Starisian\SparxstarUEC\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\core\SparxstarUECSnapshotRepository;
use Starisian\SparxstarUEC\helpers\StarLogger;

final class SparxstarUECSessionManager {

	private const SESSION_NAMESPACE = 'sparxstar_uec_data';
	private const SESSION_USER_VARS = array();

	public function __construct() {
		// empty
	}

	/** Set multiple values in the session at once. */
	public static function set_all( array $data ): void {
		try {
			if ( empty( $data ) ) {
				return;
			}
			self::ensure_session();
			foreach ( $data as $key => $value ) {
				$_SESSION[ self::SESSION_NAMESPACE ][ $key ] = $value;
			}
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECSessionManager', $e, array( 'method' => 'set_all' ) );
		}
	}

	/** Get a single value from the session. */
	public static function get( string $key, ?string $default = null ): ?string {
		try {
			self::ensure_session();
			return $_SESSION[ self::SESSION_NAMESPACE ][ $key ] ?? $default;
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECSessionManager', $e, array( 'method' => 'get', 'key' => $key ) );
			return $default;
		}
	}

	private static function ensure_session(): void {
		try {
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
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECSessionManager', $e, array( 'method' => 'ensure_session' ) );
		}
	}

	/**
	 * Looks up a value for ANY USER/SESSION by querying the historical database record.
	 */
	public static function lookup( string $key, ?int $user_id, ?string $session_id, ?string $default = null ): ?string {
		try {
			if ( empty( $user_id ) && empty( $session_id ) ) {
				return $default;
			}

			$path = self::SESSION_USER_VARS[ $key ] ?? null;
			if ( ! empty( $path ) ) {
				// Note: This is legacy code - repository now expects fingerprint/device_hash
				// For backward compatibility, we pass null values which triggers fallback behavior
				$snapshot = SparxstarUECSnapshotRepository::get( null, null );
				if ( ! is_array( $snapshot ) ) {
					return $default;
				}
				return self::get_value_from_array( $snapshot, $path, $default );
			}

			return $default;
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECSessionManager', $e, array( 'method' => 'lookup', 'key' => $key, 'user_id' => $user_id, 'session_id' => $session_id ) );
			return $default;
		}
	}
	/**
	 * Retrieve the active PHP session identifier when available.
	 */
	public static function get_session_id(): string {
		try {
			self::ensure_session(); // Make sure the session is active before checking
			return session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECSessionManager', $e, array( 'method' => 'get_session_id' ) );
			return '';
		}
	}

	public static function get_value_from_array( array $array, string $path, ?string $default = null ): ?string {
		try {
			$keys = explode( '.', $path );
			foreach ( $keys as $key ) {
				if ( empty( $array ) || ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
					return $default;
				}
				$array = $array[ $key ];
			}
			// Only return scalar values as strings; otherwise return default.
			if ( is_scalar( $array ) ) {
				return (string) $array;
			}
			return $default;
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECSessionManager', $e, array( 'method' => 'get_value_from_array', 'path' => $path ) );
			return $default;
		}
	}
}

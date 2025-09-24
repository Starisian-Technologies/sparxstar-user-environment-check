<?php
namespace Sparxstar\EnvCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a caching layer for environment snapshots using the WordPress Object Cache API.
 * Gracefully falls back to direct database lookups if the cache is unavailable.
 * @version 1.1 (Corrected)
 */
class EnvCheckCache {

	private const GROUP = 'sparxstar_env';
	private const TTL   = DAY_IN_SECONDS; // Cache for 1 day

	/**
	 * Get a snapshot from the cache if available, else fall back to the database.
	 *
	 * @param int|null    $user_id
	 * @param string|null $session_id
	 * @param string|null $user_fingerprint ## FIX APPLIED: Added parameter ##
	 * @return array|null
	 */
	public static function get_snapshot( ?int $user_id, ?string $session_id = null, ?string $user_fingerprint = null ): ?array {
		if ( ! $user_id && ! $session_id ) {
			return null;
		}
		// ## FIX APPLIED: Pass fingerprint to make_key ##
		$cache_key = self::make_key( $user_id, $session_id, $user_fingerprint );

		if ( function_exists( 'wp_cache_get' ) ) {
			$snapshot = wp_cache_get( $cache_key, self::GROUP );
			if ( false !== $snapshot ) {
				return is_array( $snapshot ) ? $snapshot : null;
			}
		}

		$entry = self::db_lookup( $user_id, $session_id ); // DB lookup remains the same
		if ( $entry && function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $cache_key, $entry, self::GROUP, self::TTL );
		}

		return $entry;
	}

	/**
	 * Set or update a snapshot in the cache.
	 *
	 * @param int|null    $user_id
	 * @param string|null $session_id
	 * @param array       $snapshot
	 * @param string|null $user_fingerprint ## FIX APPLIED: Added parameter ##
	 */
	public static function set_snapshot( ?int $user_id, ?string $session_id, array $snapshot, ?string $user_fingerprint = null ): void {
		if ( ! $user_id && ! $session_id ) {
			return;
		}
		// ## FIX APPLIED: Pass fingerprint to make_key ##
		$cache_key = self::make_key( $user_id, $session_id, $user_fingerprint );
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $cache_key, $snapshot, self::GROUP, self::TTL );
		}
	}

	/**
	 * Invalidate a cached snapshot.
	 *
	 * @param int|null    $user_id
	 * @param string|null $session_id
	 * @param string|null $user_fingerprint ## FIX APPLIED: Added parameter ##
	 */
	public static function delete_snapshot( ?int $user_id, ?string $session_id, ?string $user_fingerprint = null ): void {
		if ( ! $user_id && ! $session_id ) {
			return;
		}
		// ## FIX APPLIED: Pass fingerprint to make_key ##
		$cache_key = self::make_key( $user_id, $session_id, $user_fingerprint );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $cache_key, self::GROUP );
		}
	}

	/**
	 * Build a deterministic cache key.
	 */
	private static function make_key( ?int $user_id, ?string $session_id, ?string $user_fingerprint = null ): string {
		$uid = $user_id ? 'u' . $user_id : 'anon-' . substr( $user_fingerprint ?? 'unknown', 0, 12 );
		$sid = $session_id ? 's' . substr( hash( 'sha256', $session_id ), 0, 12 ) : 'nosession';
		return "{$uid}:{$sid}";
	}

	/**
	 * Direct DB lookup for the latest snapshot for a given user/session.
	 */
	private static function db_lookup( ?int $user_id, ?string $session_id ): ?array {
		global $wpdb;
		// The constant should be defined in EnvCheckAPI, but for decoupling, we can hardcode it here
		// or pass it in. Let's assume EnvCheckAPI is loaded.
		$table = $wpdb->base_prefix . 'sparxstar_env_snapshots';

		$where_clauses = [];
		$params = [];

		if ( $user_id ) {
			$where_clauses[] = 'user_id = %d';
			$params[] = $user_id;
		} else {
			$where_clauses[] = 'user_id IS NULL';
		}

		if ( $session_id ) {
			$where_clauses[] = 'session_id = %s';
			$params[] = $session_id;
		}

		if ( empty( $where_clauses ) ) {
			return null;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where_clauses ) . ' ORDER BY updated_at DESC LIMIT 1';

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}

		$row = $wpdb->get_row( $sql, ARRAY_A );

		// Decode JSON columns for consistency
		if ( $row ) {
			$json_columns = ['server_side_data', 'client_side_data', 'client_hints_data'];
			foreach ( $json_columns as $col ) {
				if ( isset( $row[ $col ] ) ) {
					$row[ $col ] = json_decode( $row[ $col ], true );
				}
			}
		}

		return $row ?: null;
	}
}

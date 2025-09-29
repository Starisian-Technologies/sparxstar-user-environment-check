<?php
/**
 * Provides a caching layer for environment snapshots via the WordPress object cache.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SparxstarUECCacheHelper {

	private const GROUP = 'sparxstar_env';
	private const TTL   = DAY_IN_SECONDS;

	/**
	 * Retrieve a snapshot from the object cache.
	 *
	 * @param string $cache_key The deterministic key for the resource.
	 * @return array|null Null on cache miss, array on hit.
	 */
	public static function get( string $cache_key ): ?array {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return null;
		}

		$snapshot = wp_cache_get( $cache_key, self::GROUP );
		return is_array( $snapshot ) ? $snapshot : null;
	}

	/**
	 * Store a snapshot in the object cache.
	 *
	 * @param string $cache_key The key to store the data under.
	 * @param array  $snapshot The snapshot data to store.
	 */
	public static function set( string $cache_key, array $snapshot ): void {
		if ( ! function_exists( 'wp_cache_set' ) ) {
			return;
		}
		wp_cache_set( $cache_key, $snapshot, self::GROUP, self::TTL );
	}

	/**
	 * Invalidate a snapshot from the object cache.
	 *
	 * @param string $cache_key The key to delete.
	 */
	public static function delete( string $cache_key ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( $cache_key, self::GROUP );
	}

	/**
	 * Build a deterministic cache key.
	 */
	public static function make_key( ?int $user_id, ?string $session_id, string $ip_hash ): string {
		$user_segment    = $user_id !== null ? 'u' . $user_id : 'anon-' . substr( $ip_hash, 0, 12 );
		$session_segment = $session_id ? 's' . substr( hash( 'sha256', $session_id ), 0, 12 ) : 'nosession';
		return $user_segment . ':' . $session_segment;
	}
}

<?php
/**
 * Repository for retrieving snapshots from the database.
 * Version 2.0: Aligned with fingerprint-first identity architecture.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\helpers\StarLogger;

final class SparxstarUECSnapshotRepository {

	/**
	 * Retrieve the latest snapshot for a given stable device identity.
	 *
	 * @param string|null $fingerprint The device's stable fingerprint.
	 * @param string|null $device_hash The device's stable hardware hash.
	 * @return array|null The complete snapshot data or null if not found.
	 */
	public static function get( ?string $fingerprint, ?string $device_hash ): ?array {
		// A valid fingerprint and device_hash are required to find a record.
		if ( empty( $fingerprint ) || empty( $device_hash ) ) {
			return null;
		}

		try {
			global $wpdb;
			$db = new SparxstarUECDatabase( $wpdb );

			// Query the database using the new primary identity vectors.
			$snapshot_row = $db->get_latest_snapshot( $fingerprint, $device_hash );

			if ( ! $snapshot_row ) {
				return null;
			}

			// The full payload is now in a single JSON column.
			// Rehydrate the data into the format the application expects.
			$data = $snapshot_row['snapshot_data'] ?? [];

			// Overwrite with the authoritative root-level data from the DB row
			// to ensure the returned data is always the absolute latest.
			$data['user_id']        = $snapshot_row['user_id'];
			$data['session_id']     = $snapshot_row['session_id'];
			$data['fingerprint_id'] = $snapshot_row['fingerprint']; // Expose the stable IDs
			$data['device_hash_id'] = $snapshot_row['device_hash'];
			$data['created_at']     = $snapshot_row['created_at'];
			$data['updated_at']     = $snapshot_row['updated_at'];

			return $data;

		} catch ( \Exception $e ) {
			StarLogger::error(
				'SparxstarUECSnapshotRepository',
				$e,
				[
					'method'      => 'get',
					'fingerprint' => $fingerprint,
					'device_hash' => $device_hash,
				]
			);
			return null;
		}
	}

	/**
	 * Flush any cache layers for a specific device identity.
	 * (Placeholder for future object caching).
	 *
	 * @param string|null $fingerprint
	 * @param string|null $device_hash
	 */
	public static function flush( ?string $fingerprint, ?string $device_hash ): void {
		// This method's signature is updated for consistency with the new identity model.
		// When object caching is implemented (e.g., using WP_Object_Cache), the cache
		// key should be derived from the fingerprint and device_hash to invalidate the correct entry.
		// Example:
		// $cache_key = 'uec_snapshot_' . md5( $fingerprint . $device_hash );
		// wp_cache_delete( $cache_key, 'sparxstar_uec' );
	}
}

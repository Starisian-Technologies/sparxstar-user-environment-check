<?php

/**
 * Repository for retrieving snapshots from the database.
 * Version 2.1: Added Admin-specific retrieval methods.
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if (! defined('ABSPATH')) {
    exit;
}

use Starisian\SparxstarUEC\helpers\StarLogger;

final class SparxstarUECSnapshotRepository
{
    /**
     * Retrieve the latest snapshot for a given stable device identity.
     * USE CASE: Frontend verification (Current User).
     *
     * @param string|null $fingerprint The device's stable fingerprint.
     * @param string|null $device_hash The device's stable hardware hash.
     * @return array|null The complete snapshot data or null if not found.
     */
    public static function get(?string $fingerprint, ?string $device_hash): ?array
    {
        // A valid fingerprint and device_hash are required to find a record.
        if (in_array($fingerprint, [null, '', '0'], true) || in_array($device_hash, [null, '', '0'], true)) {
            return null;
        }

        try {
            global $wpdb;
            $db = new SparxstarUECDatabase($wpdb);

            $snapshot_row = $db->get_latest_snapshot($fingerprint, $device_hash);

            if (! $snapshot_row) {
                return null;
            }

            return self::hydrate($snapshot_row);
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSnapshotRepository', 'error', $throwable->getMessage(), [
                'method' => 'get',
                'fingerprint' => $fingerprint,
                'device_hash' => $device_hash,
                'exception' => $throwable::class,
                'trace' => $throwable->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Retrieve the latest snapshot by User ID.
     * USE CASE: Admin Area Snapshot Viewer.
     * 
     * This bypasses the need for the Admin to have the User's fingerprint.
     *
     * @param int $user_id The WordPress User ID.
     * @return array|null The complete snapshot data or null if not found.
     */
    public static function get_by_user_id(int $user_id): ?array
    {
        if ($user_id <= 0) {
            return null;
        }

        try {
            global $wpdb;
            // Ensure your Database class has a method to fetch by user_id
            // or write raw SQL here if the method doesn't exist yet.
            // Assuming standard table structure:
            $table_name = $wpdb->prefix . SPX_ENV_CHECK_DB_TABLE_NAME; // Check your actual table name

            $query = $wpdb->prepare(
                sprintf('SELECT * FROM %s WHERE user_id = %%d ORDER BY created_at DESC LIMIT 1', $table_name),
                $user_id
            );

            $snapshot_row = $wpdb->get_row($query, ARRAY_A);

            if (! $snapshot_row) {
                return null;
            }

            return self::hydrate($snapshot_row);
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSnapshotRepository', 'error', $throwable->getMessage(), [
                'method' => 'get_by_user_id',
                'user_id' => $user_id,
                'exception' => $throwable::class,
                'trace' => $throwable->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Helper to rehydrate row data into the expected array format.
     */
    private static function hydrate(array $snapshot_row): array
    {
        // The full payload is now in a single JSON column.
        $data = json_decode($snapshot_row['snapshot_data'] ?? '{}', true);
        if (!is_array($data)) {
            $data = [];
        }

        // Overwrite with authoritative root-level data
        $data['user_id']        = $snapshot_row['user_id'];
        $data['session_id']     = $snapshot_row['session_id'];
        $data['fingerprint_id'] = $snapshot_row['fingerprint'];
        $data['device_hash_id'] = $snapshot_row['device_hash'];
        $data['created_at']     = $snapshot_row['created_at'];
        $data['updated_at']     = $snapshot_row['updated_at'];

        return $data;
    }

    /**
     * Flush cache layers.
     * Updated to accept arguments to prevent fatal errors if called with parameters.
     *
     * @param string|null $fingerprint Optional fingerprint to target flush.
     * @param string|null $device_hash Optional hash to target flush.
     */
    public static function flush(?string $fingerprint = null, ?string $device_hash = null): void
    {
        if ($fingerprint && $device_hash && function_exists('wp_cache_delete')) {
            $cache_key = 'uec_snapshot_' . md5($fingerprint . $device_hash);
            wp_cache_delete($cache_key, 'sparxstar_uec');
        }
    }
}

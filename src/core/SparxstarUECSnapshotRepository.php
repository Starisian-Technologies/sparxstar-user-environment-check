<?php

/**
 * Repository for retrieving UEC snapshots.
 * Version 3.0: Full production build. Supports:
 * - Frontend lookups by fingerprint + device_hash
 * - Admin lookups by User ID
 * - Unified JSON payload column (snapshot_data)
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if (!defined('ABSPATH')) {
    exit;
}

use wpdb;
use Starisian\SparxstarUEC\helpers\StarLogger;

final class SparxstarUECSnapshotRepository
{
    /**
     * Table name helper.
     */
    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . SPX_ENV_CHECK_DB_TABLE_NAME;
    }

    /**
     * FRONTEND LOOKUP (fingerprint + device hash)
     */
    public static function get(?string $fingerprint, ?string $device_hash): ?array
    {
        if (!$fingerprint || !$device_hash) {
            return null;
        }

        try {
            global $wpdb;

            $sql = $wpdb->prepare(
                "SELECT * FROM " . self::table() . " 
                 WHERE fingerprint = %s AND device_hash = %s 
                 ORDER BY updated_at DESC 
                 LIMIT 1",
                $fingerprint,
                $device_hash
            );

            $row = $wpdb->get_row($sql, ARRAY_A);
            if (!$row) {
                return null;
            }

            return self::hydrate($row);

        } catch (\Throwable $throwable) {
            StarLogger::error('SnapshotRepo:get', $throwable);
            return null;
        }
    }

    /**
     * ADMIN LOOKUP (by WordPress User ID ONLY)
     * This is the correct production method.
     * The Admin DOES NOT and SHOULD NOT use fingerprint/device.
     */
    public static function get_by_user_id(int $user_id): ?array
    {
        if ($user_id <= 0) {
            return null;
        }

        try {
            global $wpdb;

            $sql = $wpdb->prepare(
                "SELECT * FROM " . self::table() . "
                 WHERE user_id = %d
                 ORDER BY updated_at DESC
                 LIMIT 1",
                $user_id
            );

            $row = $wpdb->get_row($sql, ARRAY_A);
            if (!$row) {
                return null;
            }

            return self::hydrate($row);

        } catch (\Throwable $throwable) {
            StarLogger::error('SnapshotRepo:get_by_user_id', $throwable);
            return null;
        }
    }

    /**
     * Convert DB row → canonical array for Admin / API use.
     */
    private static function hydrate(array $row): array
    {
        $payload = [];

        // Stored JSON
        if (!empty($row['snapshot_data'])) {
            $decoded = json_decode((string) $row['snapshot_data'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        // Authoritative DB fields
        $payload['user_id']     = (int) $row['user_id'];
        $payload['fingerprint'] = $row['fingerprint'] ?? null;
        $payload['device_hash'] = $row['device_hash'] ?? null;
        $payload['session_id']  = $row['session_id'] ?? null;
        $payload['created_at']  = $row['created_at'] ?? null;
        $payload['updated_at']  = $row['updated_at'] ?? null;

        return $payload;
    }

    /**
     * Optional cache flush helper.
     */
    public static function flush(?string $fingerprint = null, ?string $device_hash = null): void
    {
        if (!$fingerprint || !$device_hash) {
            return;
        }

        if (function_exists('wp_cache_delete')) {
            $cache_key = 'uec_snapshot_' . md5($fingerprint . $device_hash);
            wp_cache_delete($cache_key, 'sparxstar_uec');
        }
    }
}

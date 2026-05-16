<?php
/**
 * SPARXSTAR User Environment Check
 *
 * Repository for reading persisted environment snapshots using identity-safe
 * query paths for frontend and admin contexts.
 *
 * @package Starisian\SparxstarUEC\core
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if (!defined('ABSPATH')) {
    exit;
}

use Starisian\SparxstarUEC\helpers\StarLogger;

/**
 * Snapshot read repository for identity-keyed and admin user lookups.
 */
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
     * Fetch the latest snapshot by fingerprint and device hash.
     *
     * This is the canonical anonymous identity lookup path and must use both
     * values together to avoid cross-device collisions.
     *
     * @param string|null $fingerprint Stable anonymous fingerprint.
     * @param string|null $device_hash Device-derived identity hash.
     * @return array<string, mixed>|null Snapshot payload or null when missing.
     */
    public static function get(?string $fingerprint, ?string $device_hash): ?array
    {
        if (!$fingerprint || !$device_hash) {
            return null;
        }

        try {
            global $wpdb;

            $sql = $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' 
                 WHERE fingerprint = %s AND device_hash = %s 
                 ORDER BY updated_at DESC 
                 LIMIT 1',
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
     * Fetch the latest snapshot for a logged-in WordPress user.
     *
     * @param int $user_id WordPress user ID.
     * @return array<string, mixed>|null Snapshot payload or null when missing.
     */
    public static function get_by_user_id(int $user_id): ?array
    {
        if ($user_id <= 0) {
            return null;
        }

        try {
            global $wpdb;

            $sql = $wpdb->prepare(
                'SELECT * FROM ' . self::table() . '
                 WHERE user_id = %d
                 ORDER BY updated_at DESC
                 LIMIT 1',
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
     * Convert a raw database row into the canonical snapshot payload shape.
     *
     * @param array<string, mixed> $row Raw row fetched from the snapshots table.
     * @return array<string, mixed> Hydrated payload consumed by API/admin readers.
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
        $payload['session_id']  = $row['session_id']  ?? null;
        $payload['created_at']  = $row['created_at']  ?? null;
        $payload['updated_at']  = $row['updated_at']  ?? null;

        return $payload;
    }

    /**
     * Clear object-cache entry for an identity pair.
     *
     * @param string|null $fingerprint Stable anonymous fingerprint.
     * @param string|null $device_hash Device-derived identity hash.
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

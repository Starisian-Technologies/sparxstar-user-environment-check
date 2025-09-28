<?php
/**
 * Cache helper for environment snapshots.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Includes;

use function function_exists;
use function hash;
use function implode;
use function json_decode;
use function substr;
use function wp_cache_delete;
use function wp_cache_get;
use function wp_cache_set;
use const DAY_IN_SECONDS;
use const ARRAY_A;
use const ABSPATH;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provides a caching layer for environment snapshots via the WordPress object cache.
 */
final class SparxstarUserEnvironmentCheckCacheHelper
{
    /**
     * Cache group name used for all environment snapshot entries.
     */
    private const GROUP = 'sparxstar_env';

    /**
     * Time-to-live for cached entries in seconds.
     */
    private const TTL = DAY_IN_SECONDS;

    /**
     * Retrieve a cached snapshot or fall back to the database.
     */
    public static function get_snapshot(
        ?int $user_id,
        ?string $session_id = null,
        ?string $user_fingerprint_hash = null
    ): ?array {
        if ($user_id === null && $session_id === null && $user_fingerprint_hash === null) {
            return null;
        }

        $cache_key = self::make_key($user_id, $session_id, $user_fingerprint_hash);

        if (function_exists('wp_cache_get')) {
            $snapshot = wp_cache_get($cache_key, self::GROUP);
            if ($snapshot !== false) {
                return is_array($snapshot) ? $snapshot : null;
            }
        }

        $entry = self::db_lookup($user_id, $session_id, $user_fingerprint_hash);

        if ($entry !== null && function_exists('wp_cache_set')) {
            wp_cache_set($cache_key, $entry, self::GROUP, self::TTL);
        }

        return $entry;
    }

    /**
     * Store or update a cached snapshot.
     */
    public static function set_snapshot(
        ?int $user_id,
        ?string $session_id,
        array $snapshot,
        ?string $user_fingerprint_hash = null
    ): void {
        if ($user_id === null && $session_id === null && $user_fingerprint_hash === null) {
            return;
        }

        if (!function_exists('wp_cache_set')) {
            return;
        }

        $cache_key = self::make_key($user_id, $session_id, $user_fingerprint_hash);
        wp_cache_set($cache_key, $snapshot, self::GROUP, self::TTL);
    }

    /**
     * Invalidate a cached snapshot entry.
     */
    public static function delete_snapshot(
        ?int $user_id,
        ?string $session_id,
        ?string $user_fingerprint_hash = null
    ): void {
        if ($user_id === null && $session_id === null && $user_fingerprint_hash === null) {
            return;
        }

        if (!function_exists('wp_cache_delete')) {
            return;
        }

        $cache_key = self::make_key($user_id, $session_id, $user_fingerprint_hash);
        wp_cache_delete($cache_key, self::GROUP);
    }

    /**
     * Build a deterministic cache key for the current context.
     */
    public static function make_key(
        ?int $user_id,
        ?string $session_id,
        ?string $user_fingerprint_hash = null
    ): string {
        $user_segment    = $user_id !== null ? 'u' . $user_id : 'anon-' . substr($user_fingerprint_hash ?? 'unknown', 0, 12);
        $session_segment = $session_id ? 's' . substr(hash('sha256', $session_id), 0, 12) : 'nosession';

        return $user_segment . ':' . $session_segment;
    }

    /**
     * Fetch the most recent snapshot directly from the database.
     */
    private static function db_lookup(
        ?int $user_id,
        ?string $session_id,
        ?string $user_fingerprint_hash
    ): ?array {
        global $wpdb;

        $table = $wpdb->base_prefix . 'sparxstar_env_snapshots';

        $where_clauses = [];
        $params        = [];

        if ($user_id !== null) {
            $where_clauses[] = 'user_id = %d';
            $params[]        = $user_id;
        } elseif ($user_fingerprint_hash !== null) {
            $where_clauses[] = 'client_ip_hash = %s';
            $params[]        = $user_fingerprint_hash;
        } else {
            $where_clauses[] = 'user_id IS NULL';
        }

        if ($session_id !== null && $session_id !== '') {
            $where_clauses[] = 'session_id = %s';
            $params[]        = $session_id;
        }

        if ($where_clauses === []) {
            return null;
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where_clauses) . ' ORDER BY updated_at DESC LIMIT 1';
        if ($params !== []) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row) {
            return null;
        }

        foreach (['server_side_data', 'client_side_data', 'client_hints_data'] as $column) {
            if (isset($row[$column])) {
                $decoded = json_decode((string) $row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : [];
            }
        }

        return $row;
    }
}

<?php
/**
 * Internal repository for fetching and caching snapshots.
 * This class contains the business logic for data retrieval.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

use Starisian\SparxstarUEC\api\SparxstarUECAPI;
use Starisian\SparxstarUEC\includes\SparxstarUECCacheHelper;
use Starisian\SparxstarUEC\includes\SparxstarUECHelper;

if (!defined('ABSPATH')) {
    exit;
}

final class SparxstarUECSnapshotRepository
{
    /**
     * Retrieve the latest environment snapshot, handling cache and database logic.
     */
    public static function get(?int $user_id, ?string $session_id): ?array
    {
        $ip_hash = hash('sha256', SparxstarUECHelper::get_client_ip());

        $cache_key = SparxstarUECCacheHelper::make_key($user_id, $session_id, $ip_hash);
        $cached_snapshot = SparxstarUECCacheHelper::get($cache_key);

        if ($cached_snapshot !== null) {
            return $cached_snapshot;
        }

        $database = SparxstarUECAPI::init()->database;
        $db_snapshot = $database->get_latest_snapshot($ip_hash, $user_id, $session_id);

        if ($db_snapshot !== null) {
            SparxstarUECCacheHelper::set($cache_key, $db_snapshot);
        }
        return $db_snapshot;
    }

    /**
     * Flushes the cache for a given user/session.
     */
    public static function flush(?int $user_id, ?string $session_id): void
    {
        $ip_hash = hash('sha256', SparxstarUECHelper::get_client_ip());
        $cache_key = SparxstarUECCacheHelper::make_key($user_id, $session_id, $ip_hash);
        SparxstarUECCacheHelper::delete($cache_key);
    }
}
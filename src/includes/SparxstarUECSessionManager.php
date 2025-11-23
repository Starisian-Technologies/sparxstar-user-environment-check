<?php

declare(strict_types=1);

namespace Starisian\SparxstarUEC\includes;

if (! defined('ABSPATH')) {
    exit;
}

use Starisian\SparxstarUEC\helpers\StarLogger;

final class SparxstarUECSessionManager
{
    private const SESSION_NAMESPACE = 'sparxstar_uec_data';


    public function __construct()
    {
        // empty
    }

    /** Set multiple values in the session at once. */
    public static function set_all(array $data): void
    {
        try {
            if ($data === []) {
                return;
            }

            self::ensure_session();
            foreach ($data as $key => $value) {
                $_SESSION[self::SESSION_NAMESPACE][$key] = $value;
            }
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSessionManager', 'error', $throwable->getMessage(), [
                'method' => 'set_all',
                'exception' => $throwable::class,
                'trace' => $throwable->getTraceAsString()
            ]);
        }
    }

    /** Get a single value from the session. */
    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            self::ensure_session();
            return $_SESSION[self::SESSION_NAMESPACE][$key] ?? $default;
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSessionManager', 'error', $throwable->getMessage(), [
                'method' => 'get',
                'key' => $key,
                'exception' => $throwable::class,
                'trace' => $throwable->getTraceAsString()
            ]);
            return $default;
        }
    }

    private static function ensure_session(): void
    {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE && ! headers_sent()) {
                session_start(
                    [
                        'name'            => 'spxenv',
                        'cookie_httponly' => true,
                        'cookie_samesite' => 'Lax',
                    ]
                );
            }

            if (! isset($_SESSION[self::SESSION_NAMESPACE])) {
                $_SESSION[self::SESSION_NAMESPACE] = [];
            }
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSessionManager', 'error', $throwable->getMessage(), [
                'method' => 'ensure_session',
                'exception' => $throwable::class,
                'trace' => $throwable->getTraceAsString()
            ]);
        }
    }

    /**
     * Looks up a value for ANY USER/SESSION by querying the historical database record.
     */
    public static function lookup(string $key, ?int $user_id, ?string $session_id, ?string $default = null): ?string
    {
        // SESSION_USER_VARS is currently empty - this is a stub for future functionality
        // Early return since this feature is not yet implemented
        return $default;
    }

    /**
     * Retrieve the active PHP session identifier when available.
     */
    public static function get_session_id(): string
    {
        try {
            self::ensure_session(); // Make sure the session is active before checking
            return session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSessionManager', 'error', $throwable->getMessage(), [
                'method' => 'get_session_id',
                'exception' => $throwable::class,
                'trace' => $throwable->getTraceAsString()
            ]);
            return '';
        }
    }

    /**
     * Clear the snapshot creation flag to allow re-generation.
     * Used when admin views settings but no snapshot exists.
     */
    public static function clear_snapshot_flag(): void
    {
        try {
            self::ensure_session();
            unset($_SESSION[self::SESSION_NAMESPACE]['spx_snapshot_created']);
            StarLogger::log('SparxstarUECSessionManager', 'debug', 'Snapshot creation flag cleared - next frontend visit will generate snapshot.');
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSessionManager', 'error', $throwable->getMessage(), [
                'method' => 'clear_snapshot_flag',
                'exception' => $throwable::class,
                'trace' => $throwable->getTraceAsString()
            ]);
        }
    }

    public static function get_value_from_array(array $array, string $path, ?string $default = null): ?string
    {
        try {
            $keys = explode('.', $path);
            foreach ($keys as $key) {
                if (empty($array) || ! is_array($array) || ! array_key_exists($key, $array)) {
                    return $default;
                }

                $array = $array[$key];
            }

            // Only return scalar values as strings; otherwise return default.
            if (is_scalar($array)) {
                return (string) $array;
            }

            return $default;
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSessionManager', 'error', $throwable->getMessage(), [
                'method' => 'get_value_from_array',
                'path' => $path,
                'exception' => $throwable::class,
                'trace' => $throwable->getTraceAsString()
            ]);
            return $default;
        }
    }
}

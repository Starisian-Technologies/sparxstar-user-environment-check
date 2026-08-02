<?php

/**
 * SPARXSTAR User Environment Check
 *
 * Session helper responsible for plugin-scoped session read/write operations.
 *
 * @package Starisian\SparxstarUEC\includes
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\includes;

if (! defined('ABSPATH')) {
    exit;
}

use Starisian\SparxstarUEC\helpers\StarLogger;

/**
 * Manages namespaced session state for diagnostics workflows.
 */
final class SparxstarUECSessionManager
{
    private const SESSION_NAMESPACE = 'sparxstar_uec_data';

    /**
     * Constructor kept for DI symmetry across services.
     */
    public function __construct()
    {
        // empty
    }

    /**
     * Set multiple values in the plugin session namespace.
     *
     * @param array<string, mixed> $data Values to persist.
     */
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
            StarLogger::log('SparxstarUECSessionManager', $throwable);
        }
    }

    /**
     * Read a single string value from the session namespace.
     *
     * @param string $key Session key to read.
     * @param string|null $default Default value when key is missing.
     * @return string|null Resolved session value.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            self::ensure_session();
            return $_SESSION[self::SESSION_NAMESPACE][$key] ?? $default;
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSessionManager', $throwable);
            return $default;
        }
    }

    /**
     * Ensure a PHP session is active and namespaced storage is initialized.
     */
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
            StarLogger::log('SparxstarUECSessionManager', $throwable);
        }
    }

    /**
     * Looks up a value for ANY USER/SESSION by querying the historical database record.
     *
     * @param string $key Dot-path key to resolve.
     * @param int|null $user_id User context, when available.
     * @param string|null $session_id Session context, when available.
     * @param string|null $default Default return value.
     * @return string|null Resolved value or default.
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
            StarLogger::log('SparxstarUECSessionManager', $throwable);
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
            StarLogger::debug('SparxstarUECSessionManager', 'Snapshot creation flag cleared - next frontend visit will generate snapshot.');
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECSessionManager', $throwable);
        }
    }

    /**
     * Resolve a dot-path from a nested array.
     *
     * @param array<string, mixed> $array Nested source array.
     * @param string $path Dot-path expression.
     * @param string|null $default Value returned when lookup fails.
     * @return string|null Scalar value cast to string, otherwise default.
     */
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
            StarLogger::log('SparxstarUECSessionManager', $throwable);
            return $default;
        }
    }
}

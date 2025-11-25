<?php

/**
 * Handles all direct database interactions for snapshots.
 * Version 3.0.0: Finalized schema. Uses LONGTEXT for compatibility.
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if (! defined('ABSPATH')) {
    exit;
}

use Starisian\SparxstarUEC\helpers\StarLogger;

final readonly class SparxstarUECDatabase
{
    private const TABLE_NAME              = SPX_ENV_CHECK_DB_TABLE_NAME;

    private const SNAPSHOT_RETENTION_DAYS = 90;

    // Bumped to 3.0.0 to force a final schema check/update on next load.
    private const DB_VERSION = '3.0.0';

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
        $this->maybe_update_table_schema();
    }

    /**
     * Check and update the table schema if the DB_VERSION has changed.
     */
    private function maybe_update_table_schema(): void
    {
        try {
            $installed_db_version = get_option('sparxstar_uec_db_version', '0.0');

            if (version_compare($installed_db_version, self::DB_VERSION, '<')) {
                $this->create_or_update_table();
                update_option('sparxstar_uec_db_version', self::DB_VERSION);
            }
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECDatabase', $throwable);
        }
    }

    /**
     * Create or update the diagnostics snapshot table using dbDelta.
     */
    public function create_or_update_table(): void
    {
        try {
            $table_name      = $this->get_table_name();
            $charset_collate = $this->get_charset_collate();

            // CHANGE: Used 'longtext' instead of 'json' for maximum server compatibility.
            $sql = "CREATE TABLE {$table_name} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                fingerprint varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
                device_hash varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
                session_id varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                user_id bigint(20) unsigned DEFAULT NULL,
                snapshot_data longtext COLLATE utf8mb4_unicode_ci NOT NULL, 
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY fingerprint_device (fingerprint,device_hash),
                KEY user_id (user_id),
                KEY created_at (created_at)
            ) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            \dbDelta($sql);
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECDatabase', $throwable);
            throw $throwable;
        }
    }

    /**
     * Insert or update a snapshot.
     */
    public function store_snapshot(array $data): array|\WP_Error
    {
        try {
            $table_name = $this->get_table_name();

            if (! $this->table_exists($table_name)) {
                return new \WP_Error(
                    'db_table_missing',
                    'Database table is missing for snapshots.',
                    ['status' => 500]
                );
            }

            if (! array_key_exists('fingerprint', $data) || ! array_key_exists('device_hash', $data)) {
                $data = $this->normalize_legacy_snapshot($data);
            }

            if (empty($data['fingerprint']) || empty($data['device_hash'])) {
                return new \WP_Error(
                    'snapshot_identity_missing',
                    'Snapshot missing fingerprint or device_hash.',
                    ['status' => 400]
                );
            }

            $existing_id = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    sprintf('SELECT id FROM %s WHERE fingerprint = %%s AND device_hash = %%s', $table_name),
                    $data['fingerprint'],
                    $data['device_hash']
                )
            );

            $db_data = [
                'user_id'       => $data['user_id'] ?? null,
                'fingerprint'   => $data['fingerprint'],
                'device_hash'   => $data['device_hash'],
                'session_id'    => $data['session_id'] ?? null,
                'snapshot_data' => wp_json_encode($data['data']),
                'updated_at'    => $data['updated_at'],
            ];

            if ($existing_id > 0) {
                $this->wpdb->update($table_name, $db_data, ['id' => $existing_id]);
                return ['status' => 'updated', 'id' => $existing_id];
            }

            $db_data['created_at'] = $data['updated_at'];

            $result = $this->wpdb->insert($table_name, $db_data);

            if ($result === false) {
                StarLogger::error(
                    'SparxstarUECDatabase',
                    'Database insert error: ' . $this->wpdb->last_error,
                    ['method' => 'store_snapshot']
                );
                return new \WP_Error('db_insert_error', 'Could not write snapshot to DB.', ['status' => 500]);
            }

            return ['status' => 'inserted', 'id' => (int) $this->wpdb->insert_id];
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECDatabase', $throwable);
            return new \WP_Error('db_exception', $throwable->getMessage(), ['status' => 500]);
        }
    }

    private function normalize_legacy_snapshot(array $raw): array
    {
        $user_id = null;
        if (array_key_exists('user_id', $raw) && $raw['user_id'] !== null && $raw['user_id'] !== '') {
            $user_id = (int) $raw['user_id'];
        }

        $session_id = null;
        if (isset($raw['client_side_data']['identifiers']['session_id']) && $raw['client_side_data']['identifiers']['session_id'] !== '') {
            $session_id = (string) $raw['client_side_data']['identifiers']['session_id'];
        } elseif (! empty($raw['session_id'])) {
            $session_id = (string) $raw['session_id'];
        }

        $fingerprint = null;
        if (isset($raw['client_side_data']['identifiers']['visitorId']) && $raw['client_side_data']['identifiers']['visitorId'] !== '') {
            $fingerprint = (string) $raw['client_side_data']['identifiers']['visitorId'];
        } elseif (isset($raw['client_side_data']['client_side_data'])) {
            $fingerprint = hash('sha256', wp_json_encode($raw['client_side_data']['client_side_data']));
        } elseif (function_exists('wp_generate_uuid4')) {
            $fingerprint = 'fp_' . wp_generate_uuid4();
        } else {
            $fingerprint = 'fp_' . wp_rand() . '_' . time();
        }

        $device_hash_source = '';
        if (! empty($raw['client_hints_data']) && is_array($raw['client_hints_data'])) {
            $device_hash_source = wp_json_encode($raw['client_hints_data']);
        } elseif (isset($raw['client_side_data']['client_side_data']['device']['full']) && is_array($raw['client_side_data']['client_side_data']['device']['full'])) {
            $device_hash_source = wp_json_encode($raw['client_side_data']['client_side_data']['device']['full']);
        } else {
            $ip = isset($raw['server_side_data']['ipAddress']) ? (string) $raw['server_side_data']['ipAddress'] : '';
            $device_hash_source = $fingerprint . '|' . $ip;
        }

        $device_hash = hash('sha256', $device_hash_source);
        $updated_at = empty($raw['updated_at']) ? gmdate('Y-m-d H:i:s') : (string) $raw['updated_at'];

        return [
            'user_id'     => $user_id,
            'fingerprint' => $fingerprint,
            'device_hash' => $device_hash,
            'session_id'  => $session_id,
            'data'        => $raw,
            'updated_at'  => $updated_at,
        ];
    }

    public function get_latest_snapshot(string $fingerprint, string $device_hash): ?array
    {
        try {
            $table_name = $this->get_table_name();
            if (! $this->table_exists($table_name)) {
                return null;
            }

            $sql = $this->wpdb->prepare(
                sprintf('SELECT * FROM %s WHERE fingerprint = %%s AND device_hash = %%s', $table_name),
                $fingerprint,
                $device_hash
            );

            $snapshot = $this->wpdb->get_row($sql, ARRAY_A);
            if (! $snapshot) {
                return null;
            }

            if (isset($snapshot['snapshot_data']) && is_string($snapshot['snapshot_data'])) {
                $decoded = json_decode($snapshot['snapshot_data'], true);
                $snapshot['snapshot_data'] = is_array($decoded) ? $decoded : [];
            }

            return $snapshot;

        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECDatabase', $throwable);
            return null;
        }
    }

    public function delete_table(): void
    {
        try {
            $table_name = $this->get_table_name();
            $sql        = sprintf('DROP TABLE IF EXISTS %s;', $table_name);
            $this->wpdb->query($sql);
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECDatabase', $throwable);
            throw $throwable;
        }
    }

    public function cleanup_old_snapshots(): void
    {
        try {
            $table_name = $this->get_table_name();
            if (! $this->table_exists($table_name)) {
                return;
            }

            $retention_days = (int) apply_filters('sparxstar_env_retention_days', self::SNAPSHOT_RETENTION_DAYS);
            if ($retention_days <= 0) {
                return;
            }

            $this->wpdb->query(
                $this->wpdb->prepare(
                    sprintf('DELETE FROM %s WHERE created_at < DATE_SUB(NOW(), INTERVAL %%d DAY)', $table_name),
                    $retention_days
                )
            );
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECDatabase', $throwable);
        }
    }

    public function get_table_name(): string
    {
        return $this->wpdb->base_prefix . self::TABLE_NAME;
    }

    public function get_charset_collate(): string
    {
        return $this->wpdb->get_charset_collate();
    }

    private function table_exists(string $table_name): bool
    {
        try {
            return (bool) $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        } catch (\Throwable $throwable) {
            StarLogger::log('SparxstarUECDatabase', $throwable);
            return false;
        }
    }
}
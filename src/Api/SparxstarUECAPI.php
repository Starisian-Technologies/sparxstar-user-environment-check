<?php
/**
 * REST controller for SPARXSTAR User Environment Check diagnostics.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Api;

use Starisian\SparxstarUEC\StarUserUtils;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use function add_action;
use function apply_filters;
use function current_time;
use function dbDelta;
use function explode;
use function filter_var;
use function function_exists;
use function get_current_user_id;
use function get_locale;
use function get_transient;
use function gmdate;
use function hash;
use function implode;
use function is_array;
use function is_scalar;
use function json_decode;
use function preg_replace;
use function register_rest_route;
use function sanitize_text_field;
use function set_transient;
use function str_replace;
use function time;
use function trim;
use function wp_json_encode;
use function wp_unslash;
use function wp_verify_nonce;
use const ABSPATH;
use const DAY_IN_SECONDS;
use const FILTER_VALIDATE_IP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles request validation and persistence of environment snapshots.
 */
final class SparxstarUECAPI
{
    /**
     * Singleton instance reference.
     */
    private static ?self $instance = null;

    /**
     * Rate limit window length in seconds.
     */
    private const RATE_LIMIT_WINDOW_SECONDS = 300;

    /**
     * Maximum allowed requests per window from the same client.
     */
    private const RATE_LIMIT_MAX_REQUESTS = 15;

    /**
     * Database table that stores serialized snapshots.
     */
    private const TABLE_NAME = 'sparxstar_env_snapshots';

    /**
     * Default retention period for stored snapshots.
     */
    private const SNAPSHOT_RETENTION_DAYS = 30;

    /**
     * Action Scheduler hook used to purge stale snapshots.
     */
    private const CLEANUP_HOOK = 'sparxstar_env_cleanup_snapshots';

    /**
     * Retrieve or create the API singleton.
     */
    public static function init(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register WordPress hooks for the REST controller lifecycle.
     */
    private function __construct()
    {
        add_action('rest_api_init', [$this, 'register_rest_route']);
        add_action('init', [$this, 'schedule_cleanup_action']);
        add_action(self::CLEANUP_HOOK, [$this, 'cleanup_old_snapshots']);
    }

    /**
     * Create or update the diagnostics snapshot table.
     */
    public function create_db_table(): void
    {
        global $wpdb;

        $table_name      = $wpdb->base_prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            session_id VARCHAR(128) NULL DEFAULT NULL,
            snapshot_hash VARCHAR(64) NOT NULL,
            client_ip_hash VARCHAR(64) NOT NULL,
            server_side_data JSON NOT NULL,
            client_side_data JSON NOT NULL,
            client_hints_data JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY snapshot_hash (snapshot_hash),
            KEY user_session (user_id, session_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Register the REST endpoint that receives environment diagnostics.
     */
    public function register_rest_route(): void
    {
        register_rest_route(
            'star-sparxstar-user-environment-check/v1',
            '/log',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_log_request'],
                'permission_callback' => [$this, 'check_permissions'],
            ]
        );
    }

    /**
     * Validate the request-level security requirements.
     */
    public function check_permissions(WP_REST_Request $request): bool|WP_Error
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'invalid_nonce',
                __('Invalid security token.', SPX_ENV_CHECK_TEXT_DOMAIN),
                ['status' => 403]
            );
        }

        if (!$this->check_rate_limit()) {
            return new WP_Error(
                'rate_limited',
                __('Too many requests.', SPX_ENV_CHECK_TEXT_DOMAIN),
                ['status' => 429]
            );
        }

        return true;
    }

    /**
     * Persist a submitted diagnostics payload.
     */
    public function handle_log_request(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();
        if (!is_array($payload) || $payload === []) {
            return new WP_Error(
                'invalid_data',
                __('Invalid JSON payload.', SPX_ENV_CHECK_TEXT_DOMAIN),
                ['status' => 400]
            );
        }

        if (isset($payload['delta']) && is_array($payload['delta'])) {
            $session_id      = $this->sanitize_value($payload['sessionId'] ?? null);
            $latest_snapshot = $this->get_latest_snapshot(null, $session_id);

            if ($latest_snapshot === null) {
                return new WP_Error(
                    'no_snapshot_for_delta',
                    __('Cannot apply a delta without an existing snapshot.', SPX_ENV_CHECK_TEXT_DOMAIN),
                    ['status' => 409]
                );
            }

            $client_data_for_update = array_replace_recursive(
                (array) $latest_snapshot['client_side_data'],
                $this->sanitize_array_recursively($payload['delta'])
            );
            $server_data_for_update = (array) $latest_snapshot['server_side_data'];
            $hints_data_for_update  = (array) $latest_snapshot['client_hints_data'];
        } else {
            $client_data_for_update = $this->sanitize_array_recursively($payload);
            $server_data_for_update = $this->collect_server_side_data();
            $hints_data_for_update  = $this->collect_client_hints();
        }

        $user_id    = get_current_user_id() ?: null;
        $session_id = $client_data_for_update['sessionId'] ?? null;
        $client_ip  = $this->get_client_ip();

        $env_hash_data = [
            'user_id'      => $user_id,
            'session_id'   => $session_id,
            'server_side'  => $server_data_for_update,
            'client_side'  => $client_data_for_update,
            'client_hints' => $hints_data_for_update,
        ];

        unset($env_hash_data['client_side']['network']['rtt'], $env_hash_data['client_side']['battery']);

        $snapshot_hash = hash('sha256', wp_json_encode($env_hash_data) ?: '');

        $result = $this->store_diagnostic_snapshot(
            $user_id,
            $session_id,
            hash('sha256', $client_ip ?: 'unknown'),
            $snapshot_hash,
            $server_data_for_update,
            $client_data_for_update,
            $hints_data_for_update
        );

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (class_exists(StarUserUtils::class)) {
            StarUserUtils::flush_cache($user_id, $session_id);
        }

        return new WP_REST_Response(
            [
                'status' => 'ok',
                'action' => $result['status'],
                'id'     => $result['id'],
            ],
            200
        );
    }

    /**
     * Retrieve the newest snapshot for a given user/session or requesting IP.
     */
    public function get_latest_snapshot(?int $user_id = null, ?string $session_id = null): ?array
    {
        global $wpdb;

        $table_name = $wpdb->base_prefix . self::TABLE_NAME;
        $user_id    = $user_id ?? get_current_user_id() ?: null;

        $where_clauses = [];
        $params        = [];

        if ($user_id !== null) {
            $where_clauses[] = 'user_id = %d';
            $params[]        = $user_id;
        } else {
            $where_clauses[] = 'client_ip_hash = %s';
            $params[]        = hash('sha256', $this->get_client_ip() ?: 'unknown');
        }

        if ($session_id !== null && $session_id !== '') {
            $where_clauses[] = 'session_id = %s';
            $params[]        = $session_id;
        }

        if ($where_clauses === []) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE " . implode(' AND ', $where_clauses) . ' ORDER BY updated_at DESC LIMIT 1',
            ...$params
        );

        $snapshot = $wpdb->get_row($sql, ARRAY_A);
        if (!$snapshot) {
            return null;
        }

        foreach (['server_side_data', 'client_side_data', 'client_hints_data'] as $column) {
            if (isset($snapshot[$column]) && is_string($snapshot[$column])) {
                $decoded = json_decode($snapshot[$column], true);
                $snapshot[$column] = is_array($decoded) ? $decoded : [];
            }
        }

        return $snapshot;
    }

    /**
     * Insert or update a snapshot row.
     */
    private function store_diagnostic_snapshot(
        ?int $user_id,
        ?string $session_id,
        string $client_ip_hash,
        string $snapshot_hash,
        array $server_data,
        array $client_data,
        array $client_hints
    ): array|WP_Error {
        global $wpdb;

        $table_name = $wpdb->base_prefix . self::TABLE_NAME;

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table_name} WHERE snapshot_hash = %s", $snapshot_hash)
        );

        if ($existing_id > 0) {
            $wpdb->update(
                $table_name,
                ['updated_at' => current_time('mysql')],
                ['id' => $existing_id]
            );

            return ['status' => 'updated', 'id' => $existing_id];
        }

        $data = [
            'snapshot_hash'    => $snapshot_hash,
            'client_ip_hash'   => $client_ip_hash,
            'server_side_data' => wp_json_encode($server_data) ?: '{}',
            'client_side_data' => wp_json_encode($client_data) ?: '{}',
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s'];

        if ($user_id !== null) {
            $data['user_id'] = $user_id;
            $formats[]       = '%d';
        }

        if ($session_id !== null && $session_id !== '') {
            $data['session_id'] = $session_id;
            $formats[]          = '%s';
        }

        if ($client_hints !== []) {
            $data['client_hints_data'] = wp_json_encode($client_hints) ?: '{}';
            $formats[]                 = '%s';
        }

        $result = $wpdb->insert($table_name, $data, $formats);
        if ($result === false) {
            return new WP_Error(
                'db_insert_error',
                __('Could not write snapshot to the database.', SPX_ENV_CHECK_TEXT_DOMAIN),
                ['status' => 500]
            );
        }

        return ['status' => 'inserted', 'id' => (int) $wpdb->insert_id];
    }

    /**
     * Schedule the nightly cleanup task if Action Scheduler is present.
     */
    public function schedule_cleanup_action(): void
    {
        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_next_scheduled_action')) {
            return;
        }

        if (false === \as_next_scheduled_action(self::CLEANUP_HOOK)) {
            \as_schedule_recurring_action(time(), DAY_IN_SECONDS, self::CLEANUP_HOOK);
        }
    }

    /**
     * Remove snapshots older than the configured retention period.
     */
    public function cleanup_old_snapshots(): void
    {
        global $wpdb;

        $table_name     = $wpdb->base_prefix . self::TABLE_NAME;
        $retention_days = (int) apply_filters('sparxstar_env_retention_days', self::SNAPSHOT_RETENTION_DAYS);

        if ($retention_days <= 0) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $retention_days
            )
        );
    }

    /**
     * Capture server-side metadata for a snapshot.
     */
    private function collect_server_side_data(): array
    {
        return [
            'ipAddress'     => $this->get_client_ip(),
            'language'      => get_locale(),
            'serverTimeUTC' => gmdate('c'),
        ];
    }

    /**
     * Inspect the current request for client hint headers.
     */
    private function collect_client_hints(): array
    {
        $client_hints = [];
        $hint_headers = apply_filters(
            'sparxstar_env_client_hint_headers',
            [
                'Sec-CH-UA',
                'Sec-CH-UA-Mobile',
                'Sec-CH-UA-Platform',
                'Sec-CH-UA-Platform-Version',
                'Sec-CH-UA-Arch',
                'Sec-CH-UA-Bitness',
                'Sec-CH-UA-Model',
                'Sec-CH-UA-Full-Version',
            ]
        );

        foreach ($hint_headers as $header) {
            $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            if (!empty($_SERVER[$server_key])) {
                $client_hints[$header] = sanitize_text_field(wp_unslash($_SERVER[$server_key]));
            }
        }

        return $client_hints;
    }

    /**
     * Enforce a per-client request budget to protect the API.
     */
    private function check_rate_limit(): bool
    {
        $rate_key         = 'sparxstar_env_rate_' . hash('md5', $this->get_client_ip() ?: 'unknown');
        $current_requests = (int) get_transient($rate_key);

        if ($current_requests >= self::RATE_LIMIT_MAX_REQUESTS) {
            return false;
        }

        set_transient($rate_key, $current_requests + 1, self::RATE_LIMIT_WINDOW_SECONDS);

        return true;
    }

    /**
     * Sanitize an arbitrarily nested array of diagnostic data.
     */
    private function sanitize_array_recursively(array $array): array
    {
        $sanitized = [];

        foreach ($array as $key => $value) {
            $normalized_key = $this->sanitize_key_preserve_case($key);

            if (is_array($value)) {
                $sanitized[$normalized_key] = $this->sanitize_array_recursively($value);
            } elseif (is_scalar($value) || $value === null) {
                $sanitized[$normalized_key] = $this->sanitize_value($value);
            }
        }

        return $sanitized;
    }

    /**
     * Normalize keys while preserving case sensitivity for client payloads.
     */
    private function sanitize_key_preserve_case(string|int $key): string
    {
        $key_string = (string) $key;
        $cleaned    = preg_replace('/[^A-Za-z0-9_\-]/', '', $key_string);

        return $cleaned === '' ? $key_string : $cleaned;
    }

    /**
     * Sanitize a scalar diagnostic value.
     */
    private function sanitize_value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * Determine the most reliable client IP address.
     */
    private function get_client_ip(): string
    {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $ip = explode(',', sanitize_text_field(wp_unslash($_SERVER[$key])))[0];
            $ip = trim($ip);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return 'unknown';
    }
}

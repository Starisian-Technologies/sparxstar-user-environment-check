<?php
/**
 * Handles all direct database interactions for snapshots.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wpdb;
use WP_Error;



final class SparxstarUECDatabase {


	private const TABLE_NAME              = SPX_ENV_CHECK_DB_TABLE_NAME;
	private const SNAPSHOT_RETENTION_DAYS = 90;

	private \wpdb $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Create or update the diagnostics snapshot table.
	 */
	public function create_table(): void {
		$table_name      = $this->get_table_name();
		$charset_collate = $this->get_charset_collate();

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
		\dbDelta( $sql );
	}
	public function update_table(): void {
		$table_name      = $this->get_table_name();
		$charset_collate = $this->get_charset_collate();

		$sql = "ALTER TABLE {$table_name} ADD COLUMN client_hints_data JSON NULL;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$dbDelta( $sql );
	}

	public function delete_table(): void {
		$table_name = $this->get_table_name();
		$sql        = "DROP TABLE IF EXISTS {$table_name};";
		$this->wpdb->query( $sql );
	}



	/**
	 * Delete snapshots older than the retention period.
	 */
	public function delete_old_snapshots(): void {
		$table_name     = $this->get_table_name();
		$retention_days = self::SNAPSHOT_RETENTION_DAYS;

		$sql = $this->wpdb->prepare(
			"DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$retention_days
		);

		$this->wpdb->query( $sql );
	}




	/**
	 * Retrieve the newest snapshot for a given user/session or requesting IP.
	 */
	public function get_latest_snapshot( string $ip_hash, ?int $user_id = null, ?string $session_id = null ): ?array {
		$table_name    = $this->get_table_name();
		$where_clauses = array();
		$params        = array();

		if ( $user_id !== null ) {
			$where_clauses[] = 'user_id = %d';
			$params[]        = $user_id;
		} else {
			$where_clauses[] = 'client_ip_hash = %s';
			$params[]        = $ip_hash;
		}

		if ( $session_id !== null && $session_id !== '' ) {
			$where_clauses[] = 'session_id = %s';
			$params[]        = $session_id;
		}

		if ( empty( $where_clauses ) ) {
			return null;
		}

		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE " . implode( ' AND ', $where_clauses ) . ' ORDER BY updated_at DESC LIMIT 1',
			...$params
		);

		$snapshot = $this->wpdb->get_row( $sql, ARRAY_A );
		if ( ! $snapshot ) {
			return null;
		}

		// Decode JSON columns for use in PHP
		foreach ( array( 'server_side_data', 'client_side_data', 'client_hints_data' ) as $column ) {
			if ( isset( $snapshot[ $column ] ) && is_string( $snapshot[ $column ] ) ) {
				$decoded             = json_decode( $snapshot[ $column ], true );
				$snapshot[ $column ] = is_array( $decoded ) ? $decoded : array();
			}
		}
		return $snapshot;
	}

	/**
	 * Insert or update a snapshot row.
	 */
	public function store_snapshot( array $data ): array|\WP_Error {
		$table_name = $this->get_table_name();

		$existing_id = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT id FROM {$table_name} WHERE snapshot_hash = %s", $data['snapshot_hash'] )
		);

		if ( $existing_id > 0 ) {
			$this->wpdb->update( $table_name, array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $existing_id ) );
			return array(
				'status' => 'updated',
				'id'     => $existing_id,
			);
		}

		$result = $this->wpdb->insert(
			$table_name,
			array(
				'user_id'           => $data['user_id'],
				'session_id'        => $data['session_id'],
				'snapshot_hash'     => $data['snapshot_hash'],
				'client_ip_hash'    => $data['client_ip_hash'],
				'server_side_data'  => wp_json_encode( $data['server_data'] ),
				'client_side_data'  => wp_json_encode( $data['client_data'] ),
				'client_hints_data' => wp_json_encode( $data['client_hints'] ),
				'created_at'        => current_time( 'mysql' ),
				'updated_at'        => current_time( 'mysql' ),
			)
		);

		if ( $result === false ) {
			return new \WP_Error( 'db_insert_error', 'Could not write snapshot to the database.', array( 'status' => 500 ) );
		}
		return array(
			'status' => 'inserted',
			'id'     => (int) $this->wpdb->insert_id,
		);
	}

	/**
	 * Remove snapshots older than the configured retention period.
	 */
	public function cleanup_old_snapshots(): void {
		$table_name     = $this->get_table_name();
		$retention_days = (int) apply_filters( 'sparxstar_env_retention_days', self::SNAPSHOT_RETENTION_DAYS );

		if ( $retention_days <= 0 ) {
			return;
		}

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$retention_days
			)
		);
	}

	public function get_table_name(): string {
		return $this->wpdb->base_prefix . self::TABLE_NAME;
	}

	public function get_charset_collate(): string {
		return $this->wpdb->get_charset_collate();
	}
}

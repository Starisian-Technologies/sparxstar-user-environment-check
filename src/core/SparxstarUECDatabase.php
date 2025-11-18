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
use Starisian\SparxstarUEC\helpers\StarLogger;


final class SparxstarUECDatabase {

	private const TABLE_NAME              = SPX_ENV_CHECK_DB_TABLE_NAME;
	private const SNAPSHOT_RETENTION_DAYS = 90;
	// Define a database version for schema management
	private const DB_VERSION = '1.1'; // Increment this whenever you change the table schema

	private \wpdb $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
		$this->maybe_update_table_schema(); // Call the schema check/update during construction
	}

	/**
	 * Check and update the table schema if necessary.
	 * This method ensures the table exists and is up to date based on DB_VERSION.
	 */
	private function maybe_update_table_schema(): void {
		try {
			$installed_db_version = get_option( 'sparxstar_uec_db_version', '0.0' );

			// Only run dbDelta if the table hasn't been created or if the schema version is old
			if ( version_compare( $installed_db_version, self::DB_VERSION, '<' ) ) {
				$this->create_table(); // dbDelta is inside this method, handling both create and update
				update_option( 'sparxstar_uec_db_version', self::DB_VERSION );
			}
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECDatabase', $e, array( 'method' => 'maybe_update_table_schema' ) );
		}
	}


	/**
	 * Create or update the diagnostics snapshot table.
	 * This method uses dbDelta which handles both creation and schema updates.
	 */
	public function create_table(): void {
		try {
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
			\dbDelta( $sql ); // Corrected: ensure backslash for global function
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECDatabase', $e, array( 'method' => 'create_table' ) );
			throw $e; // Re-throw since table creation failure is critical
		}
	}

	// Removed the separate update_table() method
	// dbDelta in create_table() is robust enough to handle schema updates
	// by comparing the SQL with the existing table structure.
	// If more complex migrations (data transformation) are needed,
	// a dedicated migration system is usually built.

	public function delete_table(): void {
		try {
			$table_name = $this->get_table_name();
			$sql        = "DROP TABLE IF EXISTS {$table_name};";
			$this->wpdb->query( $sql );
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECDatabase', $e, array( 'method' => 'delete_table' ) );
			throw $e; // Re-throw since table deletion failure should be known
		}
	}

	/**
	 * Delete snapshots older than the retention period.
	 */
	public function delete_old_snapshots(): void {
		try {
			$table_name     = $this->get_table_name();
			$retention_days = (int) apply_filters( 'sparxstar_env_retention_days', self::SNAPSHOT_RETENTION_DAYS );

			if ( $retention_days <= 0 ) {
				return;
			}

			$sql = $this->wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$retention_days
			);

			$this->wpdb->query( $sql );
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECDatabase', $e, array( 'method' => 'delete_old_snapshots' ) );
		}
	}

	/**
	 * Retrieve the newest snapshot for a given user/session or requesting IP.
	 */
	public function get_latest_snapshot( string $ip_hash, ?int $user_id = null, ?string $session_id = null ): ?array {
		try {
			$table_name    = $this->get_table_name();

			// Add a check for table existence before querying
			if ( ! $this->table_exists( $table_name ) ) {
				StarLogger::warning( 'SparxstarUECDatabase', "Table {$table_name} does not exist when trying to get_latest_snapshot.", array( 'method' => 'get_latest_snapshot' ) );
				return null;
			}

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
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECDatabase', $e, array( 'method' => 'get_latest_snapshot' ) );
			return null;
		}
	}

	/**
	 * Insert or update a snapshot row.
	 */
	public function store_snapshot( array $data ): array|\WP_Error {
		try {
			$table_name = $this->get_table_name();

			// Add a check for table existence before inserting
			if ( ! $this->table_exists( $table_name ) ) {
				StarLogger::error( 'SparxstarUECDatabase', "Table {$table_name} does not exist when trying to store_snapshot.", array( 'method' => 'store_snapshot' ) );
				return new \WP_Error( 'db_table_missing', 'Database table is missing for snapshots.', array( 'status' => 500 ) );
			}

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
				// Log the specific MySQL error for better debugging
				StarLogger::error( 'SparxstarUECDatabase', 'Database insert error: ' . $this->wpdb->last_error, array( 'method' => 'store_snapshot' ) );
				return new \WP_Error( 'db_insert_error', 'Could not write snapshot to the database.', array( 'status' => 500 ) );
			}
			return array(
				'status' => 'inserted',
				'id'     => (int) $this->wpdb->insert_id,
			);
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECDatabase', $e, array( 'method' => 'store_snapshot' ) );
			return new \WP_Error( 'db_exception', 'Exception occurred while storing snapshot: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Remove snapshots older than the configured retention period.
	 */
	public function cleanup_old_snapshots(): void {
		try {
			$table_name     = $this->get_table_name();

			if ( ! $this->table_exists( $table_name ) ) {
				StarLogger::warning( 'SparxstarUECDatabase', "Table {$table_name} does not exist when trying to cleanup_old_snapshots.", array( 'method' => 'cleanup_old_snapshots' ) );
				return;
			}

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
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECDatabase', $e, array( 'method' => 'cleanup_old_snapshots' ) );
		}
	}

	public function get_table_name(): string {
		return $this->wpdb->base_prefix . self::TABLE_NAME;
	}

	public function get_charset_collate(): string {
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * Helper function to check if the table exists.
	 */
	private function table_exists( string $table_name ): bool {
		try {
			return (bool) $this->wpdb->get_var( $this->wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) );
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECDatabase', $e, array( 'method' => 'table_exists', 'table_name' => $table_name ) );
			return false;
		}
	}
}
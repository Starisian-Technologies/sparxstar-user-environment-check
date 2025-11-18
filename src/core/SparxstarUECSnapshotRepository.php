<?php
declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;
use Starisian\SparxstarUEC\helpers\StarLogger;
use Starisian\SparxstarUEC\StarUserEnv;


final class SparxstarUECSnapshotRepository {

	public static function get( ?int $user_id, ?string $session_id ): ?array {
		try {
			global $wpdb;
			$db = new SparxstarUECDatabase( $wpdb );

			$ip_hash = hash( 'sha256', StarUserEnv::get_current_visitor_ip() );
			$snapshot_row = $db->get_latest_snapshot( $ip_hash, $user_id, $session_id );

			if ( ! $snapshot_row ) {
				return null;
			}

			// Merge into a single array for the consumer (StarUserEnv)
			return [
				'server_side_data'  => $snapshot_row['server_side_data'] ?? [],
				'client_side_data'  => $snapshot_row['client_side_data'] ?? [],
				'client_hints_data' => $snapshot_row['client_hints_data'] ?? [],
				'user_id'           => $snapshot_row['user_id'],
				'session_id'        => $snapshot_row['session_id'],
				'updated_at'        => $snapshot_row['updated_at'],
			];
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECSnapshotRepository', $e, array( 'method' => 'get', 'user_id' => $user_id, 'session_id' => $session_id ) );
			return null;
		}
	}

	public static function flush( ?int $user_id, ?string $session_id ): void {
		try {
			global $wpdb;
			$db = new SparxstarUECDatabase( $wpdb );

			$ip_hash = hash( 'sha256', StarUserEnv::get_current_visitor_ip() );

			// For now, no cache layer is needed here — if you re-enable object cache, hook it here.
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECSnapshotRepository', $e, array( 'method' => 'flush', 'user_id' => $user_id, 'session_id' => $session_id ) );
		}
	}
}

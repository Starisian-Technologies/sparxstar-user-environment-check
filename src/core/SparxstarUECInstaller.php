<?php
declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\cron\SparxstarUECScheduler;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;

class SparxstarUECInstaller {

	public static function spx_uec_activate(): void {
		global $wpdb;

		// Use the Database class to create/update the table.
		$database = new SparxstarUECDatabase( $wpdb );
		$database->create_or_update_table();

		// Schedule the cleanup task.
		SparxstarUECScheduler::schedule_recurring( 'sparxstar_env_cleanup_snapshots', DAY_IN_SECONDS );
	}

	public static function spx_uec_deactivate(): void {
		SparxstarUECScheduler::clear( 'sparxstar_env_cleanup_snapshots' );
	}
}

<?php
declare(strict_types=1);

namespace Starisian\SparxstarUEC\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\cron\SparxstarUECScheduler;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;

class SparxstarUECInstaller {


	public static function activate(): void {
		global $wpdb;

		require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/core/SparxstarUECDatabase.php';
		require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/cron/SparxstarUECScheduler.php';

		// Use the Database class to create the table.
		$database = new SparxstarUECDatabase( $wpdb );
		$database->create_table();

		// Use the Scheduler to schedule the cleanup task.
		SparxstarUECScheduler::schedule_recurring( 'sparxstar_env_cleanup_snapshots', DAY_IN_SECONDS );
	}

	public static function deactivate(): void {
		require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/core/SparxstarUECScheduler.php';
		SparxstarUECScheduler::clear( 'sparxstar_env_cleanup_snapshots' );
	}
}

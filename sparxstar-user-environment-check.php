<?php
/**
 * Plugin Name:       SPARXSTAR User Environment Check
 * Plugin URI:        https://github.com/Starisian-Technologies/sparxstar-user-environment-check
 * Description:       A network-wide utility that checks browser compatibility and logs anonymized technical data for diagnostics, with consent via the WP Consent API.
 * Version:           2.2 (Hardened)
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       sparxstar-user-environment-check
 * Domain Path:       /languages
 * License:           Proprietary
 * License URI:       https://github.com/Starisian-Technologies/sparxstar-user-environment-check/LICENSE
 * Update URI:        https://github.com/Starisian-Technologies/sparxstar-user-environment-check.git
 *
 * @package           SparxstarUserEnvironmentCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The directory where environment check logs are stored.
 *
 * @since 2.0
 */
if ( ! defined( 'ENVCHECK_LOG_DIR' ) ) {
	define( 'ENVCHECK_LOG_DIR', WP_CONTENT_DIR . '/envcheck-logs' );
}

/**
 * The number of days to retain log files before deletion.
 *
 * @since 2.0
 */
if ( ! defined( 'ENVCHECK_RETENTION_DAYS' ) ) {
	define( 'ENVCHECK_RETENTION_DAYS', 30 );
}

/**
 * Loads the plugin's translated strings.
 *
 * Hooks into the 'init' action.
 *
 * @since 1.0
 * @return void
 */
function envcheck_load_textdomain() {
	load_plugin_textdomain(
		'sparxstar-user-environment-check',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'envcheck_load_textdomain' );

/**
 * Enqueues the necessary JavaScript and CSS assets for the front-end and login pages.
 *
 * This function checks for user consent before enqueueing. It also localizes
 * scripts with necessary data like a nonce, AJAX URL, and translated strings.
 *
 * Hooks into 'wp_enqueue_scripts' and 'login_enqueue_scripts'.
 *
 * @since 1.0
 * @return void
 */
function envcheck_enqueue_assets() {
	/**
	 * Filters the consent category required to enable logging.
	 *
	 * @since 2.1
	 * @param string $category The consent category. Default 'statistics'.
	 */
	$consent_category = apply_filters( 'envcheck_consent_category', 'statistics' );

	// Abort if the WP Consent API is active and consent has not been given.
	if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( $consent_category ) ) {
		return;
	}

	$script_path = __DIR__ . '/assets/js/sparxstar-user-environment-check.js';
	$style_path  = __DIR__ . '/assets/css/sparxstar-user-environment-check.css';
	$base_url    = plugin_dir_url( __FILE__ );

	// Enqueue the main JavaScript file.
	wp_enqueue_script(
		'envcheck-js',
		$base_url . 'assets/js/sparxstar-user-environment-check.js',
		[],
		filemtime( $script_path ), // Use file modification time for cache busting.
		true
	);

	// Enqueue the main CSS file.
	wp_enqueue_style(
		'envcheck-css',
		$base_url . 'assets/css/sparxstar-user-environment-check.css',
		[],
		filemtime( $style_path ) // Use file modification time for cache busting.
	);

	// Localize script with data for the client-side.
	wp_localize_script(
		'envcheck-js',
		'envCheckData',
		[
			'nonce'    => wp_create_nonce( 'envcheck_log_nonce' ),
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'i18n'     => [
				'notice'         => __( 'Notice:', 'sparxstar-user-environment-check' ),
				'update_message' => __( 'Your browser may be outdated. For the best experience, please', 'sparxstar-user-environment-check' ),
				'update_link'    => __( 'update your browser', 'sparxstar-user-environment-check' ),
				'dismiss'        => __( 'Dismiss', 'sparxstar-user-environment-check' ),
			],
		]
	);
}
add_action( 'wp_enqueue_scripts', 'envcheck_enqueue_assets' );
add_action( 'login_enqueue_scripts', 'envcheck_enqueue_assets' );

/**
 * Recursively sanitizes a variable, typically an array from user input.
 *
 * Iterates through an array and applies `sanitize_text_field` to all scalar values.
 *
 * @since 2.0
 * @param mixed $value The variable or array to sanitize.
 * @return mixed The sanitized variable or array.
 */
function envcheck_sanitize_recursive( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'envcheck_sanitize_recursive', $value );
	}
	// Sanitize scalar values, return others (like objects) as-is.
	return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
}

/**
 * Handles the AJAX request to log user environment data.
 *
 * This function performs several checks:
 * 1. Verifies the nonce for security.
 * 2. Enforces user consent via the WP Consent API.
 * 3. Throttles requests to once per day per user (based on IP hash).
 * 4. Sanitizes all incoming data.
 * 5. Minimizes the payload if Do-Not-Track or GPC signals are present.
 * 6. Writes the final data as a new line in a daily NDJSON log file.
 *
 * Hooks into 'wp_ajax_envcheck_log' and 'wp_ajax_nopriv_envcheck_log'.
 *
 * @since 1.5
 * @return void Sends a JSON success or error response and then exits.
 */
function envcheck_handle_log_data() {
	// 1. Security: Verify the nonce.
	check_ajax_referer( 'envcheck_log_nonce', 'nonce' );

	// 2. Consent Enforcement: Re-check consent on the server-side.
	$consent_category = apply_filters( 'envcheck_consent_category', 'statistics' );
	if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( $consent_category ) ) {
		wp_send_json_error( __( 'Consent not provided.', 'sparxstar-user-environment-check' ), 403 );
	}

	// 3. Daily Throttle: Limit logging to once per day per user.
	$ip        = $_SERVER['REMOTE_ADDR'] ?? '';
	$hash      = md5( $ip );
	$daily_key = 'envcheck_daily_' . $hash;

	if ( get_site_transient( $daily_key ) ) {
		wp_send_json_error( __( 'Already logged today.', 'sparxstar-user-environment-check' ), 429 );
	}
	set_site_transient( $daily_key, 1, DAY_IN_SECONDS );

	// 4. Decode & Sanitize Input.
	$raw_json = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
	$raw_data = json_decode( $raw_json, true );

	if ( ! is_array( $raw_data ) ) {
		wp_send_json_error( __( 'Invalid data format.', 'sparxstar-user-environment-check' ), 400 );
	}
	$sanitized_data = envcheck_sanitize_recursive( $raw_data );

	// 5. Privacy Signals: Minimize payload if DNT/GPC is enabled.
	$privacy = $sanitized_data['privacy'] ?? [];
	$payload = $sanitized_data;

	if ( ! empty( $privacy['doNotTrack'] ) || ! empty( $privacy['gpc'] ) ) {
		$payload = [
			'privacy'    => $privacy,
			'userAgent'  => $sanitized_data['userAgent'] ?? 'N/A',
			'os'         => $sanitized_data['os'] ?? 'N/A',
			'compatible' => $sanitized_data['compatible'] ?? 'unknown',
		];
	}

	// 6. Assemble the final log entry.
	$entry = [
		'timestamp_utc' => gmdate( 'c' ), // ISO 8601 format.
		'site'          => [
			'home'    => home_url(),
			'blog_id' => get_current_blog_id(),
		],
		'diagnostics'   => $payload,
	];

	// 7. Write to log file.
	// Ensure log directory exists and is protected.
	if ( ! is_dir( ENVCHECK_LOG_DIR ) ) {
		wp_mkdir_p( ENVCHECK_LOG_DIR );
	}
	// Add security files to prevent direct access.
	@file_put_contents( ENVCHECK_LOG_DIR . '/.htaccess', "Require all denied\n", LOCK_EX );
	@file_put_contents( ENVCHECK_LOG_DIR . '/index.php', "<?php // Silence is golden", LOCK_EX );

	// Write entry to the daily NDJSON file with a file lock.
	$log_file = ENVCHECK_LOG_DIR . '/envcheck-' . gmdate( 'Y-m-d' ) . '.ndjson';
	if ( false === file_put_contents( $log_file, wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX ) ) {
		// This error is for the server logs, not the user.
		error_log( 'SPARXSTAR EnvCheck: Log write failed to ' . $log_file );
		wp_send_json_error( __( 'Log write failed.', 'sparxstar-user-environment-check' ), 500 );
	}

	wp_send_json_success( __( 'Data logged.', 'sparxstar-user-environment-check' ) );
}
add_action( 'wp_ajax_envcheck_log', 'envcheck_handle_log_data' );
add_action( 'wp_ajax_nopriv_envcheck_log', 'envcheck_handle_log_data' );


/**
 * Schedules and performs daily housekeeping to delete old log files.
 *
 * This function is the callback for the 'envcheck_cron_housekeeping' cron event.
 *
 * @since 2.0
 * @return void
 */
function envcheck_housekeeping() {
	if ( ! is_dir( ENVCHECK_LOG_DIR ) ) {
		return;
	}

	// Use DirectoryIterator for a more robust way to handle files.
	$iterator = new DirectoryIterator( ENVCHECK_LOG_DIR );

	foreach ( $iterator as $fileinfo ) {
		if ( $fileinfo->isFile() &&
			 $fileinfo->getExtension() === 'ndjson' &&
			 strpos( $fileinfo->getFilename(), 'envcheck-' ) === 0 ) {

			if ( $fileinfo->getMTime() < time() - ( ENVCHECK_RETENTION_DAYS * DAY_IN_SECONDS ) ) {
				@unlink( $fileinfo->getPathname() );
			}
		}
	}
}
add_action( 'envcheck_cron_housekeeping', 'envcheck_housekeeping' );


/**
 * Schedules the daily cron job for log file housekeeping if it is not already scheduled.
 *
 * @since 2.0
 * @return void
 */
function envcheck_schedule_cron_jobs() {
	if ( ! wp_next_scheduled( 'envcheck_cron_housekeeping' ) ) {
		wp_schedule_event( time(), 'daily', 'envcheck_cron_housekeeping' );
	}
}
add_action( 'init', 'envcheck_schedule_cron_jobs' );

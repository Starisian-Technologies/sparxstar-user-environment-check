<?php
/**
 * Plugin Name: Environment Check & Diagnostics
 * Description: A network-wide utility that checks browser compatibility and logs anonymized technical data for diagnostics, with consent via the WP Consent API.
 * Version: 2.1 (Hardened)
 * Author: Starisian Technologies
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueues the necessary CSS and JavaScript assets for the environment check.
 * Runs on both the front-end and the login page.
 */
function envcheck_enqueue_assets() {
    $script_path = __DIR__ . '/environment-check.js';
    $style_path  = __DIR__ . '/environment-check.css';

    if ( ! file_exists( $script_path ) || ! file_exists( $style_path ) ) {
        return;
    }

    wp_enqueue_script(
        'envcheck-js',
        plugins_url( 'environment-check.js', __FILE__ ),
        [],
        filemtime( $script_path ),
        true
    );

    wp_enqueue_style(
        'envcheck-css',
        plugins_url( 'environment-check.css', __FILE__ ),
        [],
        filemtime( $style_path )
    );

    $consent_category = apply_filters( 'envcheck_consent_category', 'statistics' );

    wp_localize_script(
        'envcheck-js',
        'envCheckData',
        [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'envcheck_log_nonce' ),
            'consent_cat' => $consent_category,
        ]
    );
}
add_action( 'wp_enqueue_scripts', 'envcheck_enqueue_assets' );
add_action( 'login_enqueue_scripts', 'envcheck_enqueue_assets' );


/**
 * Creates the AJAX endpoint to securely receive and log the diagnostic data.
 * This version includes server-side consent validation and a secure, rotating log file system.
 */
function envcheck_handle_log_data() {
    // 1. Security First: Verify the nonce.
    check_ajax_referer( 'envcheck_log_nonce', 'nonce' );

    // 2. HARDENING: Add a server-side consent gate.
    // This prevents malicious clients from posting data without consent, even if they bypass the JS check.
    $consent_category = apply_filters( 'envcheck_consent_category', 'statistics' );
    if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( $consent_category ) ) {
        wp_send_json_error( 'Consent not provided on the server.' );
    }

    // 3. Get and decode the JSON data sent from the browser.
    $raw_data = isset( $_POST['data'] ) ? json_decode( stripslashes( $_POST['data'] ), true ) : null;
    if ( ! is_array( $raw_data ) ) {
        wp_send_json_error( 'Invalid data provided.' );
    }
    
    // 4. Respect server-side privacy signals (Do Not Track / Global Privacy Control).
    $privacy_signals = $raw_data['privacy'] ?? [];
    $diagnostics_payload = ( ! empty( $privacy_signals['doNotTrack'] ) || ! empty( $privacy_signals['gpc'] ) )
        ? [ // If DNT/GPC is on, log only the bare minimum.
            'privacy'    => $privacy_signals,
            'userAgent'  => $raw_data['userAgent'] ?? 'N/A',
            'os'         => $raw_data['os'] ?? 'N/A',
            'compatible' => $raw_data['compatible'] ?? 'unknown',
        ]
        : $raw_data; // Otherwise, log the full diagnostic payload.

    // 5. Prepare the final log entry in a structured, machine-readable format.
    $log_entry = [
        'timestamp_utc' => gmdate( 'Y-m-d H:i:s' ),
        'session_id'    => 'sid_' . substr( md5( ( $raw_data['userAgent'] ?? '' ) . ( $raw_data['os'] ?? '' ) ), 0, 12 ),
        'diagnostics'   => $diagnostics_payload,
    ];

    // 6. HARDENING: Log to a secure, private, rotating file in the uploads directory.
    $uploads = wp_upload_dir();
    $log_dir = trailingslashit( $uploads['basedir'] ) . 'envcheck-logs';
    
    // Create the directory if it doesn't exist.
    if ( ! is_dir( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
    }
    
    // Create a .htaccess file to block direct web access to the logs.
    $htaccess_file = $log_dir . '/.htaccess';
    if ( ! file_exists( $htaccess_file ) ) {
        $htaccess_content = "Require all denied\nDeny from all\n";
        @file_put_contents( $htaccess_file, $htaccess_content );
    }

    // Define the rotating log file name (e.g., envcheck-2025-08-25.ndjson).
    $log_file = $log_dir . '/envcheck-' . gmdate( 'Y-m-d' ) . '.ndjson';
    $log_line = wp_json_encode( $log_entry, JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";

    // Append the JSON line to the log file with an exclusive lock to prevent race conditions.
    if ( false === @file_put_contents( $log_file, $log_line, FILE_APPEND | LOCK_EX ) ) {
        wp_send_json_error( 'Failed to write to log file.' );
    }

    wp_send_json_success( 'Data logged.' );
}
add_action( 'wp_ajax_envcheck_log', 'envcheck_handle_log_data' );
add_action( 'wp_ajax_nopriv_envcheck_log', 'envcheck_handle_log_data' );

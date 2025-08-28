<?php
/**
 * Plugin Name:       SPARXSTAR User Environment Check
 * Plugin URI:        https://github.com/Starisian-Technologies/sparxstar-user-environment-check
 * Description:       A network-wide utility that checks browser compatibility and logs anonymized technical data for diagnostics, with consent via the WP Consent API.
 * Version:           2.1 (Hardened)
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       sparxstar-user-environment-check
 * Domain Path:       /languages
 * License:           
 * License URI:       https://github.com/Starisian-Technologies/sparxstar-user-environment-check/LICENSE
 * Update URI:        https://github.com/Starisian-Technologies/sparxstar-user-environment-check.git
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Load plugin text domain for translations.
 */
function envcheck_load_textdomain() {
    load_plugin_textdomain( 'sparxstar-user-environment-check', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'envcheck_load_textdomain' );

/**
 * Enqueues the necessary CSS and JavaScript assets for the environment check.
 * Runs on both the front-end and the login page.
 */
function envcheck_enqueue_assets() {
    $script_path = __DIR__ . '/assets/js/sparxstar-user-environment-check.js';
    $style_path  = __DIR__ . '/assets/css/sparxstar-user-environment-check.css';
    $base_url    = plugin_dir_url( __FILE__ );
    $cat         = apply_filters( 'envcheck_consent_category', 'statistics' );

    if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( $cat ) ) {
        return;
    }

    wp_enqueue_script( 'envcheck-js', $base_url . 'assets/js/sparxstar-user-environment-check.js', [], filemtime( $script_path ), true );
    wp_enqueue_style( 'envcheck-css', $base_url . 'assets/css/sparxstar-user-environment-check.css', [], filemtime( $style_path ) );

    $data = [
        'nonce'       => wp_create_nonce( 'envcheck_log_nonce' ),
        'ajax_url'    => admin_url( 'admin-ajax.php' ),
        'consent_cat' => $cat,
        'i18n'        => [
            'notice'          => __( 'Notice:', 'sparxstar-user-environment-check' ),
            'update_message'  => __( 'Your browser may be outdated. For the best experience, please', 'sparxstar-user-environment-check' ),
            'update_link'     => __( 'update your browser', 'sparxstar-user-environment-check' ),
            'dismiss'         => __( 'Dismiss', 'sparxstar-user-environment-check' ),
        ],
    ];
    wp_localize_script( 'envcheck-js', 'envCheckData', $data );
}
add_action( 'wp_enqueue_scripts', 'envcheck_enqueue_assets' );
add_action( 'login_enqueue_scripts', 'envcheck_enqueue_assets' );


/**
 * Recursively sanitize incoming data.
 *
 * @param mixed $value Value to sanitize.
 * @return mixed
 */
function envcheck_sanitize_recursive( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'envcheck_sanitize_recursive', $value );
    }
    return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
}

/**
 * Creates the AJAX endpoint to securely receive and log the diagnostic data.
 * This version includes server-side consent validation and a secure, rotating log file system.
 */
function envcheck_handle_log_data() {
    // 1. Security First: Verify the nonce.
    check_ajax_referer( 'envcheck_log_nonce', 'nonce' );

    // Simple rate limiting: one request per minute per IP hash.
    $rate_key = 'envcheck_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    if ( get_transient( $rate_key ) ) {
        wp_send_json_error( __( 'Too many requests. Please try again later.', 'sparxstar-user-environment-check' ) );
    }
    set_transient( $rate_key, 1, MINUTE_IN_SECONDS );

    // 2. HARDENING: Add a server-side consent gate.
    // This prevents malicious clients from posting data without consent, even if they bypass the JS check.
    $consent_category = apply_filters( 'envcheck_consent_category', 'statistics' );
    if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( $consent_category ) ) {
        wp_send_json_error( __( 'Consent not provided on the server.', 'sparxstar-user-environment-check' ) );
    }

    // 3. Get and decode the JSON data sent from the browser.
    $raw_json = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
    $raw_data = json_decode( $raw_json, true );
    if ( ! is_array( $raw_data ) ) {
        wp_send_json_error( __( 'Invalid data provided.', 'sparxstar-user-environment-check' ) );
    }
    $raw_data = envcheck_sanitize_recursive( $raw_data );
    
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
    
    // Create a .htaccess file and index.html to block direct web access to the logs.
    $htaccess_file = $log_dir . '/.htaccess';
    if ( ! file_exists( $htaccess_file ) ) {
        $htaccess_content = "Require all denied\nDeny from all\n";
        if ( false === file_put_contents( $htaccess_file, $htaccess_content ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'envcheck: failed to create .htaccess in log directory.' );
        }
    }
    $index_file = $log_dir . '/index.html';
    if ( ! file_exists( $index_file ) && false === file_put_contents( $index_file, '' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'envcheck: failed to create index.html in log directory.' );
    }

    // Define the rotating log file name (e.g., envcheck-2025-08-25.ndjson).
    $log_file = $log_dir . '/envcheck-' . gmdate( 'Y-m-d' ) . '.ndjson';
    $log_line = wp_json_encode( $log_entry, JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";

    // Append the JSON line to the log file with an exclusive lock to prevent race conditions.
    if ( false === file_put_contents( $log_file, $log_line, FILE_APPEND | LOCK_EX ) ) {
        wp_send_json_error( __( 'Failed to write to log file.', 'sparxstar-user-environment-check' ) );
    }

    wp_send_json_success( __( 'Data logged.', 'sparxstar-user-environment-check' ) );
}
add_action( 'wp_ajax_envcheck_log', 'envcheck_handle_log_data' );
add_action( 'wp_ajax_nopriv_envcheck_log', 'envcheck_handle_log_data' );

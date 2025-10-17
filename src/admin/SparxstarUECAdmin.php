<?php
/**
 * Administrative settings interface for the SPARXSTAR User Environment Check plugin.
 *
 * This file renders the settings page, manages settings registration, and exposes
 * diagnostic data for administrators in a secure, fully escaped manner.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\StarUserEnv;

/**
 * Provides WordPress admin integrations such as settings pages and notices.
 */
final class SparxstarUECAdmin
{

	/**
	 * Option key used to store the GeoIP API credential.
	 */
	private const OPTION_KEY = 'sparxstar_uec_geoip_api_key';

	/**
	 * Slug for the plugin's settings page.
	 */
	private const PAGE_SLUG = 'sparxstar-uec-settings';

	/**
	 * Bootstraps admin-specific hooks when the current request is for the dashboard.
	 */
        public function __construct()
        {
                if ( is_admin() ) {
                        $this->register_hooks();
                        error_log( 'SparxstarUECAdmin: Admin hooks registered for current request.' );
                }
        }

        /**
         * Connect admin-specific actions that render menus, settings, and notices.
         */
        public function register_hooks(): void
        {
                add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
                add_action( 'admin_init', array( $this, 'register_settings' ) );
                add_action( 'admin_notices', array( $this, 'admin_notices' ) );
                error_log( 'SparxstarUECAdmin: Hook callbacks bound to admin_menu, admin_init, and admin_notices.' );
        }

	/**
	 * Registers the plugin's settings page under the WordPress "Settings" menu.
	 */
        public function add_admin_menu(): void
        {
                add_options_page(
                        esc_html__( 'SPARXSTAR UserEnv Settings', 'sparxstar-user-environment-check' ),
                        esc_html__( 'SPARXSTAR UserEnv', 'sparxstar-user-environment-check' ),
                        'manage_options',
                        self::PAGE_SLUG,
                        array( $this, 'render_settings_page' )
                );
                error_log( 'SparxstarUECAdmin: Options page registered under Settings menu.' );
        }

	/**
	 * Registers settings, sections, and fields used by the admin page.
	 */
        public function register_settings(): void
        {
                // --- GeoIP API Key Section ---
                register_setting(
                        'sparxstar_uec_options_group',
                        self::OPTION_KEY,
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '',
			)
		);

		add_settings_section(
			'sparxstar_uec_geoip_section',
                        esc_html__( 'GeoIP Settings', 'sparxstar-user-environment-check' ),
                        array( $this, 'render_geoip_section_text' ),
                        self::PAGE_SLUG
                );

		add_settings_field(
			'sparxstar_uec_geoip_api_key_field',
                        esc_html__( 'GeoIP API Key', 'sparxstar-user-environment-check' ),
                        array( $this, 'render_api_key_field' ),
                        self::PAGE_SLUG,
                        'sparxstar_uec_geoip_section'
                );

		// --- Snapshot Viewer Section ---
		add_settings_section(
			'sparxstar_uec_snapshot_viewer_section',
                        esc_html__( 'Your Current Environment Snapshot', 'sparxstar-user-environment-check' ),
                        array( $this, 'render_snapshot_viewer_section_description' ),
                        self::PAGE_SLUG
                );
                error_log( 'SparxstarUECAdmin: Settings sections and fields registered.' );
        }

	/**
	 * Outputs the main settings page markup and associated sections.
	 */
	public function render_settings_page(): void
	{
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SPARXSTAR User Environment Check Settings', 'sparxstar-user-environment-check' ); ?></h1>

			<!-- Settings Form -->
			<form action="options.php" method="post">
				<?php
                                settings_fields( 'sparxstar_uec_options_group' );
                                do_settings_sections( self::PAGE_SLUG );
                                submit_button( esc_html__( 'Save Settings', 'sparxstar-user-environment-check' ) );
                                ?>
                        </form>

			<!-- Snapshot Viewer (not part of the form) -->
			<?php $this->render_snapshot_viewer_section(); ?>
		</div>
		<?php
	}

	/**
	 * Provides helper text for the GeoIP settings section.
	 */
	public function render_geoip_section_text(): void
	{
                printf(
                        '<p>%s</p>',
                        esc_html__(
                                'Enter the API key for your chosen GeoIP service (e.g., ipinfo.io).',
                                'sparxstar-user-environment-check'
                        )
                );
        }

	/**
	 * Renders the GeoIP API key input field while ensuring proper escaping.
	 */
	public function render_api_key_field(): void
	{
                $api_key = get_option( self::OPTION_KEY, '' );
                printf(
                        '<input type="text" name="%1$s" value="%2$s" class="regular-text" />',
                        esc_attr( self::OPTION_KEY ),
                        esc_attr( $api_key )
                );
        }

	/**
	 * Main content renderer for the snapshot viewer section in the settings form.
	 * Fetches, formats, and displays the current admin user's environment snapshot data,
	 * including any related messaging or error notices.
	 */
	public function render_snapshot_viewer_section(): void
	{
		// Ensure the StarUserEnv class is available (prefer autoloading; fallback to file include).
                if ( ! class_exists( '\\Starisian\\SparxstarUEC\\StarUserEnv' ) ) {
                        $pending = SPX_ENV_CHECK_PLUGIN_PATH . 'src/StarUserEnv.php';
                        if ( is_readable( $pending ) ) {
                                require_once $pending;
			}
		}

		// Get the ID of the currently logged-in user.
                $current_user_id = get_current_user_id();

                // Fetch the snapshot. We pass null for session_id as it's not relevant here.
                $snapshot = StarUserEnv::get_full_snapshot( $current_user_id, StarUserEnv::get_current_user_session_id() );
                error_log( 'SparxstarUECAdmin: Snapshot retrieval attempted for user ' . (string) $current_user_id );

                if ( $snapshot === null ) {
                        printf(
				'<p>%s</p>',
				esc_html__(
					'No snapshot has been recorded for your user account yet. Please visit the front-end of the site to have your browser data logged, then return to this page.',
					'sparxstar-user-environment-check'
				)
			);
			return;
		}

		// Pretty-print the JSON for readability.
                $json_dump = wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
                if ( $json_dump === false ) {
                        printf(
                                '<p class="notice notice-error"><strong>%s</strong></p>',
                                esc_html__(
					'Unable to display the snapshot data because encoding failed.',
					'sparxstar-user-environment-check'
				)
			);
			return;
		}

		// --- Debugging: capture environment metrics to help diagnose truncated responses ---
                $debug_enabled    = current_user_can( 'manage_options' );
                $debug_start      = microtime( true );
                $json_len         = is_string( $json_dump ) ? strlen( $json_dump ) : 0;
                $mem_usage        = memory_get_usage( true );
                $mem_peak         = memory_get_peak_usage( true );
                $ob_level         = ob_get_level();
                $zlib             = ini_get( 'zlib.output_compression' );
                $output_buffering = ini_get( 'output_buffering' );
                $max_exec         = ini_get( 'max_execution_time' );
                $headers_sent     = headers_sent();
                $conn_aborted     = connection_aborted();
                $conn_status      = connection_status();

		// Log lightweight diagnostics to PHP error log for server-side inspection.
                if ( $debug_enabled ) {
                        error_log(
                                sprintf(
                                        'SPX-UEC DEBUG START: snapshot_id=%s size=%dB mem=%dB peak=%dB ob_level=%d zlib=%s output_buffering=%s max_exec=%s headers_sent=%s conn_aborted=%d conn_status=%d',
                                        isset( $snapshot['id'] ) ? (string) $snapshot['id'] : 'N/A',
                                        $json_len,
                                        $mem_usage,
                                        $mem_peak,
                                        $ob_level,
                                        $zlib ?: 'off',
                                        $output_buffering ?: 'default',
                                        $max_exec,
                                        $headers_sent ? 'true' : 'false',
                                        $conn_aborted,
                                        $conn_status
                                )
                        );
                }

		// Display the data in a preformatted block for easy viewing.
		// Safely access snapshot fields to avoid notices if keys are missing.
		$snapshot_id = isset( $snapshot['id'] ) ? (string) $snapshot['id'] : esc_html__( 'N/A', 'sparxstar-user-environment-check' );
		$updated_at  = isset( $snapshot['updated_at'] ) ? (string) $snapshot['updated_at'] : '';

		printf(
			'<p>%s <strong>%s</strong>%s</p>',
			esc_html__(
				'This is the most recent data collected by the plugin for your user account. The snapshot ID is',
				'sparxstar-user-environment-check'
			),
			esc_html( $snapshot_id ),
			$updated_at ? ' &middot; ' . esc_html__( 'last updated on', 'sparxstar-user-environment-check' ) . ' <strong>' . esc_html( $updated_at ) . '</strong> UTC' : ''
		);

		// If the JSON is extremely large, only show a truncated preview. Large outputs can cause
		// the web server to close the response early (incomplete chunked encoding).
                $max_display_bytes = 200000; // ~200 KB
                $is_truncated      = false;
                $display_dump      = $json_dump;
                if ( is_string( $json_dump ) && strlen( $json_dump ) > $max_display_bytes ) {
                        $display_dump = substr( $json_dump, 0, $max_display_bytes );
                        $is_truncated = true;
		}

		// Show a diagnostics block to help debug truncated responses (visible only to admins).
		if ( $debug_enabled ) {
			echo '<details style="margin-bottom:1rem;padding: .5rem .75rem;border:1px solid #ddd;border-radius:4px;background:#fff;">';
			echo '<summary><strong>' . esc_html__( 'Snapshot diagnostics', 'sparxstar-user-environment-check' ) . '</strong></summary>';
			echo '<ul style="margin: .5rem 0 0 1rem;">';
			echo '<li>' . esc_html__( 'Snapshot size (bytes):', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) $json_len ) . '</li>';
			echo '<li>' . esc_html__( 'Memory usage (bytes):', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) $mem_usage ) . '</li>';
			echo '<li>' . esc_html__( 'Memory peak (bytes):', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) $mem_peak ) . '</li>';
			echo '<li>' . esc_html__( 'Output buffering level:', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) $ob_level ) . '</li>';
			echo '<li>' . esc_html__( 'zlib.output_compression:', 'sparxstar-user-environment-check' ) . ' ' . esc_html( $zlib ?: 'off' ) . '</li>';
			echo '<li>' . esc_html__( 'output_buffering:', 'sparxstar-user-environment-check' ) . ' ' . esc_html( $output_buffering ?: 'default' ) . '</li>';
			echo '<li>' . esc_html__( 'headers_sent:', 'sparxstar-user-environment-check' ) . ' ' . esc_html( $headers_sent ? 'true' : 'false' ) . '</li>';
			echo '<li>' . esc_html__( 'connection_aborted:', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) $conn_aborted ) . '</li>';
			echo '<li>' . esc_html__( 'connection_status:', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) $conn_status ) . '</li>';
			echo '</ul>';
			echo '</details>';
		}

                $render_start = microtime( true );

		echo '<pre style="' . esc_attr( 'background-color: #f1f1f1; padding: 15px; border-radius: 4px; max-height: 500px; overflow: auto;' ) . '"><code>';
		echo esc_html( $display_dump );
		if ( $is_truncated ) {
			echo "\n\n" . esc_html__( 'The snapshot is large and has been truncated for display. Download the full snapshot via the debug endpoint or enable a smaller capture window in settings.', 'sparxstar-user-environment-check' );
		}
		echo '</code></pre>';

		// Compute rendering timings and additional diagnostics
		$render_end = microtime( true );
		$total_time = $render_end - $debug_start;
		$render_time = $render_end - $render_start;
		$ob_length = function_exists( 'ob_get_length' ) ? ob_get_length() : null;

                if ( $debug_enabled ) {
			// Append the timing info visibly
			echo '<p><em>' . esc_html__( 'Rendering diagnostics:', 'sparxstar-user-environment-check' ) . '</em></p>';
			echo '<ul style="font-size:90%;">';
			echo '<li>' . esc_html__( 'JSON length (bytes):', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) $json_len ) . '</li>';
			echo '<li>' . esc_html__( 'Encode+prep time (s):', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) number_format( $render_start - $debug_start, 4 ) ) . '</li>';
			echo '<li>' . esc_html__( 'Render time (s):', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) number_format( $render_time, 4 ) ) . '</li>';
			echo '<li>' . esc_html__( 'Total time (s):', 'sparxstar-user-environment-check' ) . ' ' . esc_html( (string) number_format( $total_time, 4 ) ) . '</li>';
			echo '<li>' . esc_html__( 'Output buffer length (bytes):', 'sparxstar-user-environment-check' ) . ' ' . esc_html( $ob_length !== null ? (string) $ob_length : 'n/a' ) . '</li>';
			echo '</ul>';

			// Also log to the error log for server-side correlation.
                        error_log(
                                sprintf(
                                        'SPX-UEC DEBUG END: snapshot_id=%s json_len=%d encode_prep_s=%.4f render_s=%.4f total_s=%.4f ob_length=%s',
                                        isset( $snapshot['id'] ) ? (string) $snapshot['id'] : 'N/A',
                                        $json_len,
                                        ( $render_start - $debug_start ),
                                        $render_time,
                                        $total_time,
                                        $ob_length !== null ? (string) $ob_length : 'n/a'
                                )
                        );
                }
        }

        /**
         * Outputs a short explanatory blurb for the snapshot viewer section.
         */
        public function render_snapshot_viewer_description(): void
        {
                echo wp_kses_post( '<p>' . esc_html__( 'This is the most recent environment snapshot collected for your user account.', 'sparxstar-user-environment-check' ) . '</p>' );
        }

        /**
         * Placeholder callback required by WordPress settings sections.
         */
        public function render_snapshot_viewer_section_description(): void
        {
                // Nothing to output because the section renders via render_snapshot_viewer_section().
        }

	/**
	 * Displays admin notices when required configuration is missing.
	 */
	public function admin_notices(): void
	{
		// Check if the GeoIP API key is set
                $api_key = get_option( self::OPTION_KEY, '' );
                if ( empty( $api_key ) ) {
                        printf(
                                '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a> %5$s</p></div>',
                                esc_html__( 'SPARXSTAR User Environment Check:', 'sparxstar-user-environment-check' ),
                                esc_html__(
                                        'The GeoIP API key is not set. Please go to the',
                                        'sparxstar-user-environment-check'
                                ),
                                esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
                                esc_html__( 'settings page', 'sparxstar-user-environment-check' ),
                                esc_html__(
                                        'to configure it.',
                                        'sparxstar-user-environment-check'
                                )
                        );
                        error_log( 'SparxstarUECAdmin: GeoIP API key missing notice displayed.' );
                }
        }
}

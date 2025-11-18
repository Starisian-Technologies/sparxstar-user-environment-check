<?php
/**
 * SPARXSTAR User Environment Check - Admin Settings (Minimal, Stable)
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\SparxstarUEC\StarUserEnv;
use Starisian\SparxstarUEC\helpers\StarLogger;

final class SparxstarUECAdmin {

	private const OPTION_KEY = 'sparxstar_uec_geoip_api_key';
	private const PAGE_SLUG  = 'sparxstar-uec-settings';

	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		}
	}

	/** Register the options menu */
	public function add_admin_menu(): void {
		try {
			add_options_page(
				esc_html__( 'SPARXSTAR UserEnv Settings', 'sparxstar-user-environment-check' ),
				esc_html__( 'SPARXSTAR UserEnv', 'sparxstar-user-environment-check' ),
				'manage_options',
				self::PAGE_SLUG,
				[ $this, 'render_settings_page' ]
			);
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECAdmin', $e, array( 'method' => 'add_admin_menu' ) );
		}
	}

	/** Register settings and fields */
	public function register_settings(): void {
		try {
			register_setting(
				'sparxstar_uec_options_group',
				self::OPTION_KEY,
				[
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				]
			);

			add_settings_section(
				'sparxstar_uec_geoip_section',
				esc_html__( 'GeoIP Settings', 'sparxstar-user-environment-check' ),
				function() {
					echo '<p>' . esc_html__( 'Enter the API key for your chosen GeoIP service (e.g., ipinfo.io).', 'sparxstar-user-environment-check' ) . '</p>';
				},
				self::PAGE_SLUG
			);

			add_settings_field(
				'sparxstar_uec_geoip_api_key_field',
				esc_html__( 'GeoIP API Key', 'sparxstar-user-environment-check' ),
				[ $this, 'render_api_key_field' ],
				self::PAGE_SLUG,
				'sparxstar_uec_geoip_section'
			);

			add_settings_section(
				'sparxstar_uec_snapshot_viewer_section',
				esc_html__( 'Raw Snapshot Dump', 'sparxstar-user-environment-check' ),
				[ $this, 'render_snapshot_viewer_section' ],
				self::PAGE_SLUG
			);
		} catch ( \Exception $e ) {
			StarLogger::error( 'SparxstarUECAdmin', $e, array( 'method' => 'register_settings' ) );
		}
	}

	/** Settings page output */
	public function render_settings_page(): void {
		ob_start();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SPARXSTAR User Environment Check Settings', 'sparxstar-user-environment-check' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'sparxstar_uec_options_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( esc_html__( 'Save Settings', 'sparxstar-user-environment-check' ) );
				?>
			</form>
		</div>
		<?php
		echo ob_get_clean();
	}

	/** API key input */
	public function render_api_key_field(): void {
		$api_key = get_option( self::OPTION_KEY, '' );
		printf(
			'<input type="text" name="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $api_key )
		);
	}

	/** Raw snapshot dump (for debugging) */
	public function render_snapshot_viewer_section(): void {
		try {
			$session_id = \Starisian\SparxstarUEC\StarUserEnv::get_current_user_session_id();
			$snapshot = \Starisian\SparxstarUEC\StarUserEnv::get_full_snapshot(
				get_current_user_id(),
				$session_id
			);
		} catch ( \Throwable $e ) {
			StarLogger::error( 'SparxstarUECAdmin', $e, array( 'method' => 'render_snapshot_viewer_section' ) );
			echo '<p>' . esc_html__( 'Snapshot retrieval failed. Check the error log.', 'sparxstar-user-environment-check' ) . '</p>';
			return;
		}

		if ( empty( $snapshot ) ) {
			echo '<p>' . esc_html__( 'No snapshot available.', 'sparxstar-user-environment-check' ) . '</p>';
			return;
		}

		echo '<pre style="background:#f1f1f1; padding:10px; max-height:400px; overflow:scroll;">';
		var_dump($snapshot);
		echo '</pre>';
	}

	/** Warning if API key is missing */
	public function admin_notices(): void {
		$api_key = get_option( self::OPTION_KEY, '' );
		if ( empty( $api_key ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a>.</p></div>',
				esc_html__( 'SPARXSTAR User Environment Check', 'sparxstar-user-environment-check' ),
				esc_html__( 'The GeoIP API key is not set. Please go to the', 'sparxstar-user-environment-check' ),
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'settings page', 'sparxstar-user-environment-check' )
			);
		}
	}
}

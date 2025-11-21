<?php

/**
 * SPARXSTAR User Environment Check - Admin Settings (Minimal, Stable)
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\admin;

if (! defined('ABSPATH')) {
	exit;
}

use Starisian\SparxstarUEC\StarUserUtils;
use Starisian\SparxstarUEC\helpers\StarLogger;

final class SparxstarUECAdmin
{

	private const OPTION_KEY_PROVIDER      = 'sparxstar_uec_geoip_provider';
	private const OPTION_KEY_IPINFO_KEY    = 'sparxstar_uec_ipinfo_api_key';
	private const OPTION_KEY_MAXMIND_PATH  = 'sparxstar_uec_maxmind_db_path';
	private const PAGE_SLUG                = 'sparxstar-uec-settings';

	public function __construct()
	{
		if (is_admin()) {
			add_action('admin_menu', [$this, 'add_admin_menu']);
			add_action('admin_init', [$this, 'register_settings']);
			add_action('admin_notices', [$this, 'admin_notices']);
		}
	}

	/** Register the options menu */
	public function add_admin_menu(): void
	{
		try {
			add_options_page(
				esc_html__('SPARXSTAR UserEnv Settings', 'sparxstar-user-environment-check'),
				esc_html__('SPARXSTAR UserEnv', 'sparxstar-user-environment-check'),
				'manage_options',
				self::PAGE_SLUG,
				[$this, 'render_settings_page']
			);
		} catch (\Exception $e) {
			StarLogger::error('SparxstarUECAdmin', $e, array('method' => 'add_admin_menu'));
		}
	}

	/** Register settings and fields */
	public function register_settings(): void
	{
		try {
			// Register provider selection
			register_setting(
				'sparxstar_uec_options_group',
				self::OPTION_KEY_PROVIDER,
				[
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => 'none',
				]
			);

			// Register ipinfo.io API key
			register_setting(
				'sparxstar_uec_options_group',
				self::OPTION_KEY_IPINFO_KEY,
				[
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				]
			);

			// Register MaxMind database path
			register_setting(
				'sparxstar_uec_options_group',
				self::OPTION_KEY_MAXMIND_PATH,
				[
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				]
			);

			add_settings_section(
				'sparxstar_uec_geoip_section',
				esc_html__('GeoIP Settings', 'sparxstar-user-environment-check'),
				function () {
					echo '<p>' . esc_html__('Configure your GeoIP provider. Choose between ipinfo.io (API-based) or MaxMind GeoIP2 (local database).', 'sparxstar-user-environment-check') . '</p>';
				},
				self::PAGE_SLUG
			);

			add_settings_field(
				'sparxstar_uec_geoip_provider_field',
				esc_html__('GeoIP Provider', 'sparxstar-user-environment-check'),
				[$this, 'render_provider_field'],
				self::PAGE_SLUG,
				'sparxstar_uec_geoip_section'
			);

			add_settings_field(
				'sparxstar_uec_ipinfo_api_key_field',
				esc_html__('ipinfo.io API Key', 'sparxstar-user-environment-check'),
				[$this, 'render_ipinfo_key_field'],
				self::PAGE_SLUG,
				'sparxstar_uec_geoip_section'
			);

			add_settings_field(
				'sparxstar_uec_maxmind_db_path_field',
				esc_html__('MaxMind Database Path', 'sparxstar-user-environment-check'),
				[$this, 'render_maxmind_path_field'],
				self::PAGE_SLUG,
				'sparxstar_uec_geoip_section'
			);

			add_settings_section(
				'sparxstar_uec_snapshot_viewer_section',
				esc_html__('Raw Snapshot Dump', 'sparxstar-user-environment-check'),
				[$this, 'render_snapshot_viewer_section'],
				self::PAGE_SLUG
			);
		} catch (\Exception $e) {
			StarLogger::error('SparxstarUECAdmin', $e, array('method' => 'register_settings'));
		}
	}

	/** Settings page output */
	public function render_settings_page(): void
	{
		ob_start();
?>
		<div class="wrap">
			<h1><?php esc_html_e('SPARXSTAR User Environment Check Settings', 'sparxstar-user-environment-check'); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('sparxstar_uec_options_group');
				do_settings_sections(self::PAGE_SLUG);
				submit_button(esc_html__('Save Settings', 'sparxstar-user-environment-check'));
				?>
			</form>
		</div>
	<?php
		echo ob_get_clean();
	}

	/** Provider selection dropdown */
	public function render_provider_field(): void
	{
		$provider = get_option(self::OPTION_KEY_PROVIDER, 'none');
	?>
		<select name="<?php echo esc_attr(self::OPTION_KEY_PROVIDER); ?>">
			<option value="none" <?php selected($provider, 'none'); ?>><?php esc_html_e('None (Disabled)', 'sparxstar-user-environment-check'); ?></option>
			<option value="ipinfo" <?php selected($provider, 'ipinfo'); ?>><?php esc_html_e('ipinfo.io (API)', 'sparxstar-user-environment-check'); ?></option>
			<option value="maxmind" <?php selected($provider, 'maxmind'); ?>><?php esc_html_e('MaxMind GeoIP2 (Local Database)', 'sparxstar-user-environment-check'); ?></option>
		</select>
		<p class="description"><?php esc_html_e('Select your preferred GeoIP lookup provider.', 'sparxstar-user-environment-check'); ?></p>
<?php
	}

	/** ipinfo.io API key input */
	public function render_ipinfo_key_field(): void
	{
		$api_key = get_option(self::OPTION_KEY_IPINFO_KEY, '');
		printf(
			'<input type="text" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr(self::OPTION_KEY_IPINFO_KEY),
			esc_attr($api_key),
			esc_attr__('Enter your ipinfo.io API token', 'sparxstar-user-environment-check')
		);
		echo '<p class="description">' . esc_html__('Required only if using ipinfo.io provider. Get your key at https://ipinfo.io', 'sparxstar-user-environment-check') . '</p>';
	}

	/** MaxMind database path input */
	public function render_maxmind_path_field(): void
	{
		$db_path = get_option(self::OPTION_KEY_MAXMIND_PATH, '');
		printf(
			'<input type="text" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr(self::OPTION_KEY_MAXMIND_PATH),
			esc_attr($db_path),
			esc_attr__('/path/to/GeoLite2-City.mmdb', 'sparxstar-user-environment-check')
		);
		echo '<p class="description">' . esc_html__('Required only if using MaxMind provider. Absolute path to GeoLite2-City.mmdb or GeoIP2-City.mmdb file.', 'sparxstar-user-environment-check') . '</p>';
	}

	/** Raw snapshot dump (for debugging) */
	public function render_snapshot_viewer_section(): void
	{
		try {
			// Fetch snapshot using v2.0 identity model (fingerprint + device_hash)
			// Pass null for both user_id and session_id to use current visitor's identity
			$snapshot = \Starisian\SparxstarUEC\StarUserUtils::get_full_snapshot(null, null);

			if (empty($snapshot)) {
				try {
					$fingerprint = \Starisian\SparxstarUEC\StarUserUtils::getFingerprint();
					$device_hash = \Starisian\SparxstarUEC\StarUserUtils::getDeviceHash();

					echo '<div class="notice notice-info inline">';
					echo '<p><strong>' . esc_html__('No snapshot available for the current browser.', 'sparxstar-user-environment-check') . '</strong></p>';
					echo '<p>' . esc_html__('Visit your website\'s front-end to trigger a snapshot, then return here to view it.', 'sparxstar-user-environment-check') . '</p>';
					echo '<p>' . esc_html__('Identity: Fingerprint = ', 'sparxstar-user-environment-check') . '<code>' . esc_html($fingerprint) . '</code>, ';
					echo esc_html__('Device Hash = ', 'sparxstar-user-environment-check') . '<code>' . esc_html($device_hash) . '</code></p>';
					echo '</div>';
				} catch (\Throwable $identity_error) {
					StarLogger::error('SparxstarUECAdmin', $identity_error, array('method' => 'render_snapshot_viewer_section', 'context' => 'identity_resolution'));
					echo '<div class="notice notice-warning inline">';
					echo '<p><strong>' . esc_html__('No snapshot available.', 'sparxstar-user-environment-check') . '</strong></p>';
					echo '<p>' . esc_html__('Error retrieving identity information: ', 'sparxstar-user-environment-check') . esc_html($identity_error->getMessage()) . '</p>';
					echo '</div>';
				}
				return;
			}

			try {
				echo '<div class="notice notice-success inline"><p><strong>' . esc_html__('Snapshot found for current browser:', 'sparxstar-user-environment-check') . '</strong></p></div>';
				echo '<pre style="background:#f1f1f1; padding:10px; max-height:400px; overflow:auto; border:1px solid #ddd; border-radius:4px;">';
				echo esc_html(print_r($snapshot, true));
				echo '</pre>';
			} catch (\Throwable $display_error) {
				StarLogger::error('SparxstarUECAdmin', $display_error, array('method' => 'render_snapshot_viewer_section', 'context' => 'snapshot_display'));
				echo '<div class="notice notice-error inline">';
				echo '<p><strong>' . esc_html__('Error displaying snapshot:', 'sparxstar-user-environment-check') . '</strong> ' . esc_html($display_error->getMessage()) . '</p>';
				echo '</div>';
			}
		} catch (\Throwable $e) {
			StarLogger::error('SparxstarUECAdmin', $e, array('method' => 'render_snapshot_viewer_section', 'context' => 'snapshot_retrieval'));
			echo '<div class="notice notice-error inline">';
			echo '<p><strong>' . esc_html__('Snapshot retrieval failed:', 'sparxstar-user-environment-check') . '</strong> ' . esc_html($e->getMessage()) . '</p>';
			echo '<p>' . esc_html__('Check the error log for details.', 'sparxstar-user-environment-check') . '</p>';
			echo '</div>';
		}
	}

	/** Warning if GeoIP configuration is incomplete */
	public function admin_notices(): void
	{
		$provider = get_option(self::OPTION_KEY_PROVIDER, 'none');
		$message  = '';

		if ($provider === 'ipinfo') {
			$api_key = get_option(self::OPTION_KEY_IPINFO_KEY, '');
			if (empty($api_key)) {
				$message = esc_html__('ipinfo.io is selected but the API key is missing.', 'sparxstar-user-environment-check');
			}
		} elseif ($provider === 'maxmind') {
			$db_path = get_option(self::OPTION_KEY_MAXMIND_PATH, '');
			if (empty($db_path)) {
				$message = esc_html__('MaxMind is selected but the database path is missing.', 'sparxstar-user-environment-check');
			} elseif (! file_exists($db_path)) {
				$message = esc_html__('MaxMind database file not found at the specified path.', 'sparxstar-user-environment-check');
			}
		}

		if (! empty($message)) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a>.</p></div>',
				esc_html__('SPARXSTAR User Environment Check', 'sparxstar-user-environment-check'),
				$message . ' ' . esc_html__('Please go to the', 'sparxstar-user-environment-check'),
				esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)),
				esc_html__('settings page', 'sparxstar-user-environment-check')
			);
		}
	}
}

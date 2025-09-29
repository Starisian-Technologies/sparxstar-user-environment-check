<?php
/**
 * Administrative settings interface for the SPARXSTAR User Environment Check plugin.
 *
 * This file renders the settings page, manages settings registration, and exposes
 * diagnostic data for administrators in a secure, fully escaped manner.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\admin;

use Starisian\SparxstarUEC\StarUserEnv;
use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;

if (!defined('ABSPATH')) {
    exit;
}

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
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_notices', array($this, 'admin_notices'));
        }
    }

    /**
     * Registers the plugin's settings page under the WordPress "Settings" menu.
     */
    public function add_admin_menu(): void
    {
        add_options_page(
            esc_html__('SPARXSTAR Env Check Settings', 'sparxstar-user-environment-check'),
            esc_html__('SPARXSTAR Env Check', 'sparxstar-user-environment-check'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page')
        );
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
            esc_html__('GeoIP Settings', 'sparxstar-user-environment-check'),
            array($this, 'render_geoip_section_text'),
            self::PAGE_SLUG
        );

        add_settings_field(
            'sparxstar_uec_geoip_api_key_field',
            esc_html__('GeoIP API Key', 'sparxstar-user-environment-check'),
            array($this, 'render_api_key_field'),
            self::PAGE_SLUG,
            'sparxstar_uec_geoip_section'
        );

        // --- Snapshot Viewer Section ---
        add_settings_section(
            'sparxstar_uec_snapshot_viewer_section',
            esc_html__('Your Current Environment Snapshot', 'sparxstar-user-environment-check'),
            array($this, 'render_snapshot_viewer_section_description'),
            self::PAGE_SLUG
        );
    }

    /**
     * Outputs the main settings page markup and associated sections.
     */
    public function render_settings_page(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SPARXSTAR User Environment Check Settings', 'sparxstar-user-environment-check'); ?></h1>

            <!-- Settings Form -->
            <form action="options.php" method="post">
                <?php
                settings_fields('sparxstar_uec_options_group');
                do_settings_sections(self::PAGE_SLUG);
                submit_button(esc_html__('Save Settings', 'sparxstar-user-environment-check'));
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
        $api_key = get_option(self::OPTION_KEY, '');
        printf(
            '<input type="text" name="%1$s" value="%2$s" class="regular-text" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($api_key)
        );
    }

    /**
     * Renders the content for the snapshot viewer section in the settings form.
     * This includes fetching the current admin user's environment snapshot and displaying it as formatted JSON.
     */
    public function render_snapshot_viewer_section(): void
    {
        // We need the StarUserEnv class to fetch the snapshot.
        require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/StarUserEnv.php';

        // Get the ID of the currently logged-in user.
        $current_user_id = get_current_user_id();

        // Fetch the snapshot. We pass null for session_id as it's not relevant here.
        $snapshot = StarUserEnv::get_full_snapshot($current_user_id, SparxstarUECSessionManager::get_session_id());


        if ($snapshot === null) {
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
        $json_dump = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json_dump === false) {
            printf(
                '<p class="notice notice-error"><strong>%s</strong></p>',
                esc_html__(
                    'Unable to display the snapshot data because encoding failed.',
                    'sparxstar-user-environment-check'
                )
            );
            return;
        }

        // Display the data in a preformatted block for easy viewing.
        printf(
            '<p>%s <strong>%s</strong>, %s <strong>%s UTC</strong>.</p>',
            esc_html__(
                'This is the most recent data collected by the plugin for your user account. The snapshot ID is',
                'sparxstar-user-environment-check'
            ),
            isset($snapshot['id']) ? esc_html((string) $snapshot['id']) : esc_html__('unknown', 'sparxstar-user-environment-check'),
            esc_html__(
                'last updated on',
                'sparxstar-user-environment-check'
            ),
            isset($snapshot['updated_at']) ? esc_html((string) $snapshot['updated_at']) : esc_html__('an unknown date', 'sparxstar-user-environment-check')
        );

        echo '<pre style="background-color: #f1f1f1; padding: 15px; border-radius: 4px; max-height: 500px; overflow: auto;"><code>';
        echo esc_html($json_dump);
        echo '</code></pre>';
    }

    /**
     * Displays admin notices when required configuration is missing.
     */
    public function admin_notices(): void
    {
        // Check if the GeoIP API key is set
        $api_key = get_option(self::OPTION_KEY, '');
        if (empty($api_key)) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a> %5$s</p></div>',
                esc_html__('SPARXSTAR User Environment Check:', 'sparxstar-user-environment-check'),
                esc_html__(
                    'The GeoIP API key is not set. Please go to the',
                    'sparxstar-user-environment-check'
                ),
                esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)),
                esc_html__('settings page', 'sparxstar-user-environment-check'),
                esc_html__(
                    'to configure it.',
                    'sparxstar-user-environment-check'
                )
            );
        }
    }
}

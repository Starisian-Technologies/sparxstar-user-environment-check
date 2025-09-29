<?php
/**
 * Handles the admin settings page and debug display for the plugin.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\admin;

use Starisian\SparxstarUEC\StarUserEnv;
use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;

if (!defined('ABSPATH')) {
    exit;
}

final class SparxstarUECAdmin
{

    private const OPTION_KEY = 'sparxstar_uec_geoip_api_key';
    private const PAGE_SLUG = 'sparxstar-uec-settings';

    public function __construct()
    {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_notices', array($this, 'admin_notices'));
        }
    }

    public function add_admin_menu(): void
    {
        add_options_page(
            'SPARXSTAR Env Check Settings',
            'SPARXSTAR Env Check',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page')
        );
    }

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
            'GeoIP Settings',
            array($this, 'render_geoip_section_text'),
            self::PAGE_SLUG
        );

        add_settings_field(
            'sparxstar_uec_geoip_api_key_field',
            'GeoIP API Key',
            array($this, 'render_api_key_field'),
            self::PAGE_SLUG,
            'sparxstar_uec_geoip_section'
        );

        // --- NEW: Snapshot Viewer Section ---
        add_settings_section(
            'sparxstar_uec_snapshot_viewer_section',
            'Your Current Environment Snapshot',
            array($this, 'render_snapshot_viewer_section'),
            self::PAGE_SLUG
        );
    }

    public function render_settings_page(): void
    {
        ?>
        <div class="wrap">
            <h1>SPARXSTAR User Environment Check Settings</h1>

            <!-- Settings Form -->
            <form action="options.php" method="post">
                <?php
                settings_fields('sparxstar_uec_options_group');
                do_settings_sections(self::PAGE_SLUG);
                submit_button('Save Settings');
                ?>
            </form>

            <!-- Snapshot Viewer (not part of the form) -->
            <?php $this->render_snapshot_viewer_section(); ?>
        </div>
        <?php
    }

    public function render_geoip_section_text(): void
    {
        echo '<p>Enter the API key for your chosen GeoIP service (e.g., ipinfo.io).</p>';
    }

    public function render_api_key_field(): void
    {
        $api_key = get_option(self::OPTION_KEY, '');
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '" value="' . esc_attr($api_key) . '" class="regular-text" />';
    }

    /**
     * NEW: Renders the data dump section.
     *
     * Fetches the snapshot for the current admin user and displays it as formatted JSON.
     */
    public function render_snapshot_viewer_section(): void
    {
        // We need the StarUserEnv class to fetch the snapshot.
        require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/StarUserEnv.php';

        // Get the ID of the currently logged-in user.
        $current_user_id = get_current_user_id();

        // Fetch the snapshot. We pass null for session_id as it's not relevant here.
        $snapshot = StarUserEnv::get_full_snapshot($current_user_id, SparxstarUECSessionManager::get_session_id());

        echo '<h2>Your Current Environment Snapshot</h2>';

        if ($snapshot === null) {
            echo '<p>No snapshot has been recorded for your user account yet. Please visit the front-end of the site to have your browser data logged, then return to this page.</p>';
            return;
        }

        // Pretty-print the JSON for readability.
        $json_dump = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Display the data in a preformatted block for easy viewing.
        echo '<p>This is the most recent data collected by the plugin for your user account. The snapshot ID is <strong>' . esc_html($snapshot['id']) . '</strong>, last updated on <strong>' . esc_html($snapshot['updated_at']) . ' UTC</strong>.</p>';
        echo '<pre style="background-color: #f1f1f1; padding: 15px; border-radius: 4px; max-height: 500px; overflow: auto;"><code>';
        echo esc_html($json_dump);
        echo '</code></pre>';
    }

    public function admin_notices(): void
    {
        // Check if the GeoIP API key is set
        $api_key = get_option(self::OPTION_KEY, '');
        if (empty($api_key)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>SPARXSTAR User Environment Check:</strong> The GeoIP API key is not set. Please go to the <a href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)) . '">settings page</a> to configure it.</p>';
            echo '</div>';
        }
    }
}
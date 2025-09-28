<?php
/**
 * Bootstrapper for the SPARXSTAR User Environment Check plugin.
 *
 * @package SparxstarUserEnvironmentCheck
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC;

use Starisian\SparxstarUEC\Api\SparxstarUECAPI;

if (!defined('ABSPATH')) {
    exit;
}

require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/Api/SparxstarUECAPI.php';
require_once SPX_ENV_CHECK_PLUGIN_PATH . 'src/AssetManager.php';

/**
 * Orchestrates plugin services and exposes shared dependencies.
 */
class SparxstarUserEnvironmentCheck
{
    /**
     * Shared singleton instance.
     */
    private static ?self $instance = null;

    /**
     * REST API handler used to persist environment snapshots.
     */
    private SparxstarUECAPI $api;

    /**
     * Retrieve the singleton instance and bootstrap the plugin.
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Wire the plugin components together.
     */
    private function __construct()
    {
        $this->api = SparxstarUECAPI::init();

        new AssetManager();

        $this->register_hooks();
    }

    /**
     * Attach WordPress hooks owned by the bootstrapper.
     */
    private function register_hooks(): void
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('send_headers', [$this, 'add_client_hints_header']);
    }

    /**
     * Load the plugin translation files.
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            SPX_ENV_CHECK_TEXT_DOMAIN,
            false,
            dirname(plugin_basename(SPX_ENV_CHECK_PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * Advertise the client hints required by the diagnostics pipeline.
     */
    public function add_client_hints_header(): void
    {
        if (is_admin()) {
            return;
        }

        header(
            'Accept-CH: Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Model, '
            . 'Sec-CH-UA-Full-Version, Sec-CH-UA-Platform-Version, Sec-CH-UA-Bitness',
            false
        );
    }

    /**
     * Expose the REST API handler for dependent services.
     */
    public function get_api(): SparxstarUECAPI
    {
        return $this->api;
    }
}

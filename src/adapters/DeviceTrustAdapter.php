<?php

/**
 * SPARXSTAR User Environment Check
 *
 * Thin adapter onto the 3iAtlas Device Trust Node.
 *
 * @package Starisian\SparxstarUEC\adapters
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\adapters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Loads the shared Device Trust browser client and hands it its configuration.
 *
 * WHAT THIS ADAPTER IS NOT
 *
 * It owns no identity, no device, no session, and no snapshot. It never sees
 * an access token, an assertion, or a device key: those stay in the browser
 * between Identity Node, the Device Trust Node, and the shared client. It
 * passes no WordPress user ID, nonce, or IP address to the client — none of
 * them is 3iAtlas identity (see the Device Trust Node's
 * docs/adr/ADR-PROPOSED-identity-device-trust-boundary.md).
 *
 * MODES (option `sparxstar_uec_device_trust_mode`)
 *
 * - off    (default) nothing is loaded; the legacy pipeline runs unchanged.
 * - shadow the shared client loads alongside the legacy pipeline, which keeps
 *          running unchanged, so the two can be compared before cut-over.
 *
 * There is deliberately no "primary" mode yet: the legacy pipeline is not
 * removed until the Node, the Identity integration, this adapter, the
 * migration and the rollback have all been proven (migration plan, phase 4).
 */
final class DeviceTrustAdapter
{
    public const OPTION_MODE = 'sparxstar_uec_device_trust_mode';

    public const OPTION_URL = 'sparxstar_uec_device_trust_url';

    public const OPTION_ISSUER = 'sparxstar_uec_device_trust_issuer';

    public const MODES = ['off', 'shadow'];

    private const HANDLE_CLIENT = 'sparxstar-device-trust-client';

    private const HANDLE_BRIDGE = 'sparxstar-device-trust-bridge';

    /**
     * Version of the vendored client; see src/js/vendor/device-trust-client.PROVENANCE.md.
     */
    private const CLIENT_VERSION = '0.1.0';

    /**
     * Attach hooks. Safe to call when the mode is off: the enqueue callback
     * exits before touching anything.
     */
    public function register(): void
    {
        add_action('wp_enqueue_scripts', $this->enqueue(...));
        add_action('admin_init', $this->register_settings(...));
    }

    /**
     * Current mode, falling back to off for anything unrecognised.
     */
    public function mode(): string
    {
        $mode = get_option(self::OPTION_MODE, 'off');
        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    /**
     * Configuration handed to the browser, or null when the adapter must not
     * load (off, or not fully configured — fail closed, never half-load).
     *
     * @return array{baseUrl: string, issuer: string, mode: string}|null
     */
    public function client_config(): ?array
    {
        $mode = $this->mode();
        if ($mode === 'off') {
            return null;
        }
        $url    = $this->https_url(get_option(self::OPTION_URL, ''));
        $issuer = $this->https_url(get_option(self::OPTION_ISSUER, ''));
        if ($url === '' || $issuer === '') {
            return null;
        }
        return [
            'baseUrl' => untrailingslashit($url),
            'issuer'  => $issuer,
            'mode'    => $mode,
        ];
    }

    /**
     * Enqueue the shared client and the bridge on the front end.
     */
    public function enqueue(): void
    {
        $config = $this->client_config();
        if ($config === null) {
            return;
        }
        $root = dirname(__FILE__, 3);
        wp_enqueue_script(
            self::HANDLE_CLIENT,
            plugins_url('src/js/vendor/device-trust-client.js', $root . '/sparxstar-user-environment-check.php'),
            [],
            self::CLIENT_VERSION,
            true
        );
        wp_enqueue_script(
            self::HANDLE_BRIDGE,
            plugins_url('src/js/device-trust/sparxstar-device-trust-bridge.js', $root . '/sparxstar-user-environment-check.php'),
            [self::HANDLE_CLIENT],
            self::CLIENT_VERSION,
            true
        );
        wp_localize_script(self::HANDLE_BRIDGE, 'sparxstarDeviceTrustConfig', $config);
    }

    /**
     * Register the three options on the existing settings page.
     */
    public function register_settings(): void
    {
        register_setting(
            'sparxstar_uec_options_group',
            self::OPTION_MODE,
            [
                'type'              => 'string',
                'default'           => 'off',
                'sanitize_callback' => fn (mixed $v): string => is_string($v) && in_array($v, self::MODES, true) ? $v : 'off',
            ]
        );
        foreach ([self::OPTION_URL, self::OPTION_ISSUER] as $option) {
            register_setting(
                'sparxstar_uec_options_group',
                $option,
                [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => $this->https_url(...),
                ]
            );
        }
    }

    /**
     * Accept only an absolute https URL; anything else becomes ''.
     *
     * @param mixed $value Raw option value.
     */
    public function https_url(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        $clean = esc_url_raw(trim($value), ['https']);
        return str_starts_with($clean, 'https://') ? $clean : '';
    }
}

<?php

/**
 * Unit tests for SparxstarUECGeoIPService.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\services\SparxstarUECGeoIPService;

/**
 * Covers IP validation, provider selection, and the transient cache layer.
 */
final class SparxstarUECGeoIPServiceTest extends TestCase
{
    /**
     * Reset shared state before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_options']    = [];
        $GLOBALS['wp_transients'] = [];
    }

    // -----------------------------------------------------------------------
    // Invalid IP address
    // -----------------------------------------------------------------------

    /**
     * A non-IP string should fail FILTER_VALIDATE_IP and return null immediately.
     */
    public function test_lookup_returns_null_for_invalid_ip(): void
    {
        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup('not-an-ip-address');

        $this->assertNull($result);
    }

    /**
     * An empty string is not a valid IP and should return null.
     */
    public function test_lookup_returns_null_for_empty_string(): void
    {
        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup('');

        $this->assertNull($result);
    }

    /**
     * A hostname that looks plausible but is not an IP should be rejected.
     */
    public function test_lookup_returns_null_for_hostname(): void
    {
        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup('example.com');

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // Provider = 'none'
    // -----------------------------------------------------------------------

    /**
     * When the provider option is 'none' the method should return null for a
     * valid IP without consulting any external service.
     */
    public function test_lookup_returns_null_when_provider_is_none(): void
    {
        // 'none' is the default from get_option stub when no option is set.
        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup('1.2.3.4');

        $this->assertNull($result);
    }

    /**
     * The option explicitly set to 'none' should have the same effect.
     */
    public function test_lookup_returns_null_when_provider_explicitly_none(): void
    {
        $GLOBALS['wp_options'][1]['sparxstar_uec_geoip_provider'] = 'none';

        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup('8.8.8.8');

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // Transient cache hit
    // -----------------------------------------------------------------------

    /**
     * When a valid cached result exists it should be returned without calling
     * any provider.
     */
    public function test_lookup_returns_cached_data_when_transient_exists(): void
    {
        $ip            = '5.5.5.5';
        $transientKey  = 'sparxstar_geoip_' . md5($ip);
        $cachedPayload = [
            'city'        => 'CacheCity',
            'country'     => 'CC',
            'state'       => 'CacheState',
            'postal_code' => '00000',
            'region'      => 'CacheRegion',
            'latitude'    => 10.0,
            'longitude'   => 20.0,
            'timezone'    => 'UTC',
        ];

        // Set up the provider to something other than 'none' so the early-out
        // doesn't fire before the cache check.
        $GLOBALS['wp_options'][1]['sparxstar_uec_geoip_provider'] = 'ipinfo';

        // Seed the transient cache.
        $GLOBALS['wp_transients'][$transientKey] = $cachedPayload;

        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup($ip);

        $this->assertSame($cachedPayload, $result);
    }

    // -----------------------------------------------------------------------
    // ipinfo provider – missing API key
    // -----------------------------------------------------------------------

    /**
     * When the ipinfo provider is selected but no API key is configured the
     * lookup should return null without making an HTTP call.
     */
    public function test_lookup_ipinfo_returns_null_when_api_key_missing(): void
    {
        $GLOBALS['wp_options'][1]['sparxstar_uec_geoip_provider'] = 'ipinfo';
        // Ensure API key is absent (get_option returns '' by default).

        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup('10.0.0.1');

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // maxmind provider – missing DB file
    // -----------------------------------------------------------------------

    /**
     * When the maxmind provider is selected but no database path is configured
     * the lookup should return null.
     */
    public function test_lookup_maxmind_returns_null_when_db_path_missing(): void
    {
        $GLOBALS['wp_options'][1]['sparxstar_uec_geoip_provider']  = 'maxmind';
        $GLOBALS['wp_options'][1]['sparxstar_uec_maxmind_db_path'] = '';

        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup('10.0.0.2');

        $this->assertNull($result);
    }

    /**
     * A non-existent database file path should also yield null.
     */
    public function test_lookup_maxmind_returns_null_when_db_file_not_found(): void
    {
        $GLOBALS['wp_options'][1]['sparxstar_uec_geoip_provider']  = 'maxmind';
        $GLOBALS['wp_options'][1]['sparxstar_uec_maxmind_db_path'] = '/tmp/nonexistent_geoip.mmdb';

        $service = new SparxstarUECGeoIPService();
        $result  = $service->lookup('10.0.0.3');

        $this->assertNull($result);
    }
}

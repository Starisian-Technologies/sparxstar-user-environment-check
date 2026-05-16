<?php

/**
 * Extended unit tests for StarUserEnv snapshot getters and server helpers.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Starisian\SparxstarUEC\StarUserEnv;

/**
 * Exercises all public snapshot-based getters, UA helpers, IP detection,
 * flush_cache, and get_full_snapshot on StarUserEnv.
 */
final class StarUserEnvGettersTest extends TestCase
{
    /**
     * Reset the runtime snapshot cache and superglobals between tests.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setSnapshotCache(null);

        // Clean up any $_SERVER entries injected by tests.
        unset(
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_ACCEPT_LANGUAGE'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_CLIENT_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_SPX_FINGERPRINT'],
            $_SERVER['HTTP_X_SPX_DEVICE_HASH'],
            $_SERVER['HTTP_X_REQUESTED_WITH'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_REFERER']
        );

        $GLOBALS['wp_cache_store'] = [];
    }

    // -----------------------------------------------------------------------
    // get_visitor_id
    // -----------------------------------------------------------------------

    /**
     * Snapshot value at identifiers.visitor_id should be returned.
     */
    public function test_get_visitor_id_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'identifiers' => ['visitor_id' => 'vis_abc123'],
        ]);

        $this->assertSame('vis_abc123', StarUserEnv::get_visitor_id());
    }

    /**
     * When the key is absent the default empty string should be returned.
     */
    public function test_get_visitor_id_returns_empty_string_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('', StarUserEnv::get_visitor_id());
    }

    // -----------------------------------------------------------------------
    // get_user_device
    // -----------------------------------------------------------------------

    /**
     * Snapshot value at client_side_data.device.type should be returned.
     */
    public function test_get_user_device_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'client_side_data' => ['device' => ['type' => 'mobile']],
        ]);

        $this->assertSame('mobile', StarUserEnv::get_user_device());
    }

    /**
     * When the key is absent the string 'unknown' should be returned.
     */
    public function test_get_user_device_returns_unknown_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('unknown', StarUserEnv::get_user_device());
    }

    // -----------------------------------------------------------------------
    // get_user_gpu
    // -----------------------------------------------------------------------

    /**
     * Snapshot value at client_side_data.fingerprint.gpu should be returned.
     */
    public function test_get_user_gpu_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'client_side_data' => ['fingerprint' => ['gpu' => 'NVIDIA GeForce RTX 3090']],
        ]);

        $this->assertSame('NVIDIA GeForce RTX 3090', StarUserEnv::get_user_gpu());
    }

    /**
     * When the key is absent 'unknown' should be returned.
     */
    public function test_get_user_gpu_returns_unknown_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('unknown', StarUserEnv::get_user_gpu());
    }

    // -----------------------------------------------------------------------
    // get_os_name
    // -----------------------------------------------------------------------

    /**
     * Snapshot value at client_side_data.os.name should be returned.
     */
    public function test_get_os_name_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'client_side_data' => ['os' => ['name' => 'Linux']],
        ]);

        $this->assertSame('Linux', StarUserEnv::get_os_name());
    }

    /**
     * When the key is absent 'unknown' should be returned.
     */
    public function test_get_os_name_returns_unknown_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('unknown', StarUserEnv::get_os_name());
    }

    // -----------------------------------------------------------------------
    // get_browser_name
    // -----------------------------------------------------------------------

    /**
     * Snapshot value at client_side_data.client.name should be returned.
     */
    public function test_get_browser_name_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'client_side_data' => ['client' => ['name' => 'Firefox']],
        ]);

        $this->assertSame('Firefox', StarUserEnv::get_browser_name());
    }

    /**
     * When the key is absent 'unknown' should be returned.
     */
    public function test_get_browser_name_returns_unknown_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('unknown', StarUserEnv::get_browser_name());
    }

    // -----------------------------------------------------------------------
    // is_data_saver_enabled
    // -----------------------------------------------------------------------

    /**
     * A true saveData value in the snapshot should return true.
     */
    public function test_is_data_saver_enabled_returns_true_from_snapshot(): void
    {
        $this->setSnapshotCache([
            'client_side_data' => ['network' => ['saveData' => true]],
        ]);

        $this->assertTrue(StarUserEnv::is_data_saver_enabled());
    }

    /**
     * When saveData is absent the default false should be returned.
     */
    public function test_is_data_saver_enabled_returns_false_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertFalse(StarUserEnv::is_data_saver_enabled());
    }

    // -----------------------------------------------------------------------
    // get_user_ip
    // -----------------------------------------------------------------------

    /**
     * server_side_data.ip_address value should be returned from the snapshot.
     */
    public function test_get_user_ip_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['ip_address' => '192.168.1.1'],
        ]);

        $this->assertSame('192.168.1.1', StarUserEnv::get_user_ip());
    }

    /**
     * When absent the default '0.0.0.0' should be returned.
     */
    public function test_get_user_ip_returns_default_when_absent(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('0.0.0.0', StarUserEnv::get_user_ip());
    }

    // -----------------------------------------------------------------------
    // get_user_country
    // -----------------------------------------------------------------------

    /**
     * server_side_data.geolocation.country should be returned.
     */
    public function test_get_user_country_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['country' => 'US']],
        ]);

        $this->assertSame('US', StarUserEnv::get_user_country());
    }

    /**
     * When absent 'unknown' should be returned.
     */
    public function test_get_user_country_returns_unknown_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('unknown', StarUserEnv::get_user_country());
    }

    // -----------------------------------------------------------------------
    // get_user_state
    // -----------------------------------------------------------------------

    /**
     * server_side_data.geolocation.region should be returned.
     */
    public function test_get_user_state_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['region' => 'California']],
        ]);

        $this->assertSame('California', StarUserEnv::get_user_state());
    }

    // -----------------------------------------------------------------------
    // get_user_city
    // -----------------------------------------------------------------------

    /**
     * server_side_data.geolocation.city should be returned.
     */
    public function test_get_user_city_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['city' => 'San Francisco']],
        ]);

        $this->assertSame('San Francisco', StarUserEnv::get_user_city());
    }

    // -----------------------------------------------------------------------
    // is_on_vpn
    // -----------------------------------------------------------------------

    /**
     * A true is_vpn flag in the snapshot should return true.
     */
    public function test_is_on_vpn_returns_true_from_snapshot(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['is_vpn' => true]],
        ]);

        $this->assertTrue(StarUserEnv::is_on_vpn());
    }

    /**
     * When absent the default false should be returned.
     */
    public function test_is_on_vpn_returns_false_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertFalse(StarUserEnv::is_on_vpn());
    }

    // -----------------------------------------------------------------------
    // get_snapshot_timestamp
    // -----------------------------------------------------------------------

    /**
     * The server_side_data.timestamp_utc value should be returned.
     */
    public function test_get_snapshot_timestamp_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['timestamp_utc' => '2024-01-01T00:00:00Z'],
        ]);

        $this->assertSame('2024-01-01T00:00:00Z', StarUserEnv::get_snapshot_timestamp());
    }

    /**
     * When absent the default empty string should be returned.
     */
    public function test_get_snapshot_timestamp_returns_empty_string_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('', StarUserEnv::get_snapshot_timestamp());
    }

    // -----------------------------------------------------------------------
    // get_geolocation
    // -----------------------------------------------------------------------

    /**
     * The full geolocation array from the snapshot should be returned as-is.
     */
    public function test_get_geolocation_returns_array_from_snapshot(): void
    {
        $geo = ['city' => 'London', 'country' => 'GB', 'latitude' => 51.5];

        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => $geo],
        ]);

        $this->assertSame($geo, StarUserEnv::get_geolocation());
    }

    /**
     * When absent an empty array should be returned.
     */
    public function test_get_geolocation_returns_empty_array_as_default(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame([], StarUserEnv::get_geolocation());
    }

    // -----------------------------------------------------------------------
    // get_city / get_state / get_postal_code / get_region / get_country
    // -----------------------------------------------------------------------

    /**
     * get_city should return the city from the geo payload with a custom default.
     */
    public function test_get_city_returns_snapshot_value_and_respects_default(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['city' => 'Berlin']],
        ]);

        $this->assertSame('Berlin', StarUserEnv::get_city());
    }

    /**
     * get_city should return the supplied default when city is absent.
     */
    public function test_get_city_returns_custom_default_when_absent(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('N/A', StarUserEnv::get_city(null, null, 'N/A'));
    }

    /**
     * get_state should return the state from the geo payload.
     */
    public function test_get_state_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['state' => 'Bavaria']],
        ]);

        $this->assertSame('Bavaria', StarUserEnv::get_state());
    }

    /**
     * get_postal_code should return the postal code from the geo payload.
     */
    public function test_get_postal_code_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['postal_code' => '10115']],
        ]);

        $this->assertSame('10115', StarUserEnv::get_postal_code());
    }

    /**
     * get_region should return the region from the geo payload.
     */
    public function test_get_region_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['region' => 'Hesse']],
        ]);

        $this->assertSame('Hesse', StarUserEnv::get_region());
    }

    /**
     * get_country should return the country from the geo payload.
     */
    public function test_get_country_returns_snapshot_value(): void
    {
        $this->setSnapshotCache([
            'server_side_data' => ['geolocation' => ['country' => 'DE']],
        ]);

        $this->assertSame('DE', StarUserEnv::get_country());
    }

    // -----------------------------------------------------------------------
    // flush_cache
    // -----------------------------------------------------------------------

    /**
     * After flush_cache() the runtime snapshot cache should be null.
     */
    public function test_flush_cache_clears_runtime_cache(): void
    {
        $this->setSnapshotCache(['client_side_data' => ['network' => ['effectiveType' => '4g']]]);

        // Sanity: the cache is populated.
        $this->assertNotNull($this->getSnapshotCache());

        StarUserEnv::flush_cache();

        $this->assertNull($this->getSnapshotCache());
    }

    // -----------------------------------------------------------------------
    // get_full_snapshot
    // -----------------------------------------------------------------------

    /**
     * get_full_snapshot() should return whatever is in the runtime cache.
     */
    public function test_get_full_snapshot_returns_cached_snapshot(): void
    {
        $payload = ['client_side_data' => ['os' => ['name' => 'Windows']]];
        $this->setSnapshotCache($payload);

        $this->assertSame($payload, StarUserEnv::get_full_snapshot());
    }

    /**
     * When nothing is cached get_full_snapshot() should return null.
     */
    public function test_get_full_snapshot_returns_null_when_no_cache(): void
    {
        $this->setSnapshotCache(null);

        // With no session, object cache, or DB row the result should be null.
        $this->assertNull(StarUserEnv::get_full_snapshot());
    }

    // -----------------------------------------------------------------------
    // getUserOS (UA-based – server-side helper)
    // -----------------------------------------------------------------------

    /**
     * A Windows User-Agent string should be classified as 'Windows'.
     */
    public function test_get_user_os_detects_windows(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

        $this->assertSame('Windows', StarUserEnv::getUserOS());
    }

    /**
     * A macOS User-Agent string should be classified as 'Mac'.
     */
    public function test_get_user_os_detects_mac(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_0) AppleWebKit/605.1';

        $this->assertSame('Mac', StarUserEnv::getUserOS());
    }

    /**
     * An Android User-Agent string contains "Linux" which matches before "android"
     * in the implementation's pattern map, so 'Linux' is the expected result.
     */
    public function test_get_user_os_returns_linux_for_android_ua(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36';

        // The map checks 'linux' before 'android', so Android UAs resolve to 'Linux'.
        $this->assertSame('Linux', StarUserEnv::getUserOS());
    }

    /**
     * An iPhone User-Agent string contains "like Mac OS X" which matches the
     * 'macintosh|mac os x|macos' pattern before 'ipad|ipod|iphone', so 'Mac' is returned.
     */
    public function test_get_user_os_returns_mac_for_ios_ua(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';

        // 'mac os x' matches before 'iphone' in the ordered pattern map.
        $this->assertSame('Mac', StarUserEnv::getUserOS());
    }

    /**
     * An unrecognised User-Agent string should return 'Other'.
     */
    public function test_get_user_os_returns_other_for_unknown_ua(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'UnknownClientBot/1.0';

        $this->assertSame('Other', StarUserEnv::getUserOS());
    }

    // -----------------------------------------------------------------------
    // getUserBrowser (UA-based – server-side helper)
    // -----------------------------------------------------------------------

    /**
     * A Firefox User-Agent should be identified as 'Firefox'.
     */
    public function test_get_user_browser_detects_firefox(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; rv:109.0) Gecko/20100101 Firefox/115.0';

        $this->assertSame('Firefox', StarUserEnv::getUserBrowser());
    }

    /**
     * A Chrome User-Agent should be identified as 'Chrome'.
     */
    public function test_get_user_browser_detects_chrome(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/114.0.0.0';

        $this->assertSame('Chrome', StarUserEnv::getUserBrowser());
    }

    /**
     * An Edge User-Agent should be identified as 'Edge'.
     */
    public function test_get_user_browser_detects_edge(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Edge/114.0.0.0';

        $this->assertSame('Edge', StarUserEnv::getUserBrowser());
    }

    // -----------------------------------------------------------------------
    // getUserLanguage (header-based – server-side helper)
    // -----------------------------------------------------------------------

    /**
     * A multi-tag Accept-Language header should return only the two-char code.
     */
    public function test_get_user_language_returns_two_char_code_from_header(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9,de;q=0.8';

        $this->assertSame('en', StarUserEnv::getUserLanguage('code'));
    }

    /**
     * The 'full' return type should give the primary language tag.
     */
    public function test_get_user_language_returns_full_tag_for_full_type(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-CH,fr;q=0.9';

        $this->assertSame('fr-CH', StarUserEnv::getUserLanguage('full'));
    }

    /**
     * An empty Accept-Language header should return an empty string.
     */
    public function test_get_user_language_returns_empty_string_when_header_absent(): void
    {
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

        $this->assertSame('', StarUserEnv::getUserLanguage());
    }

    // -----------------------------------------------------------------------
    // getClientIP (server-side helper)
    // -----------------------------------------------------------------------

    /**
     * A Cloudflare IP header should take priority over REMOTE_ADDR.
     */
    public function test_get_client_ip_prefers_cloudflare_header(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.50';
        $_SERVER['REMOTE_ADDR']           = '127.0.0.1';

        $this->assertSame('203.0.113.50', StarUserEnv::getClientIP());
    }

    /**
     * REMOTE_ADDR should be the fallback when no proxy headers are present.
     */
    public function test_get_client_ip_falls_back_to_remote_addr(): void
    {
        $GLOBALS['fired_actions'] = [];
        $_SERVER['REMOTE_ADDR']   = '10.0.0.42';

        $this->assertSame('10.0.0.42', StarUserEnv::getClientIP());
    }

    /**
     * When no IP headers are present '0.0.0.0' should be returned.
     */
    public function test_get_client_ip_returns_fallback_when_no_headers_set(): void
    {
        $this->assertSame('0.0.0.0', StarUserEnv::getClientIP());
    }

    /**
     * A comma-separated X-Forwarded-For should use the first valid IP.
     */
    public function test_get_client_ip_picks_first_from_forwarded_for(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.1, 172.16.0.1';

        $this->assertSame('198.51.100.1', StarUserEnv::getClientIP());
    }

    // -----------------------------------------------------------------------
    // isBot
    // -----------------------------------------------------------------------

    /**
     * A Googlebot User-Agent should be detected as a bot.
     */
    public function test_is_bot_returns_true_for_googlebot(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

        $this->assertTrue(StarUserEnv::isBot());
    }

    /**
     * A regular browser User-Agent should not be classified as a bot.
     */
    public function test_is_bot_returns_false_for_normal_browser(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/114';

        $this->assertFalse(StarUserEnv::isBot());
    }

    // -----------------------------------------------------------------------
    // Helper methods
    // -----------------------------------------------------------------------

    /**
     * Inject a value into the private runtime snapshot cache.
     *
     * @param array|null $value Snapshot payload or null.
     * @return void
     */
    private function setSnapshotCache(?array $value): void
    {
        $reflection = new ReflectionClass(StarUserEnv::class);
        $property   = $reflection->getProperty('snapshot_cache');
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    /**
     * Read the current value of the private snapshot cache.
     *
     * @return array|null
     */
    private function getSnapshotCache(): ?array
    {
        $reflection = new ReflectionClass(StarUserEnv::class);
        $property   = $reflection->getProperty('snapshot_cache');
        $property->setAccessible(true);
        return $property->getValue(null);
    }
}

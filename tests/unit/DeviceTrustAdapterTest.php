<?php

/**
 * Unit tests for the Device Trust Node adapter and the legacy export.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace {
    if (! function_exists('wp_enqueue_script')) {
        /**
         * Record enqueued scripts.
         *
         * @param string             $handle Handle.
         * @param string             $src    URL.
         * @param array<int, string> $deps   Dependencies.
         * @param mixed              $ver    Version.
         * @param bool               $footer In footer.
         */
        function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $footer = false): void
        {
            $GLOBALS['spx_enqueued'][$handle] = ['src' => $src, 'deps' => $deps];
        }
    }
    if (! function_exists('wp_localize_script')) {
        /**
         * Record localized data.
         *
         * @param string               $handle Handle.
         * @param string               $name   Global name.
         * @param array<string, mixed> $data   Data.
         */
        function wp_localize_script(string $handle, string $name, array $data): bool
        {
            $GLOBALS['spx_localized'][$name] = $data;
            return true;
        }
    }
    if (! function_exists('plugins_url')) {
        /**
         * @param string $path   Path.
         * @param string $plugin Plugin file.
         */
        function plugins_url(string $path = '', string $plugin = ''): string
        {
            return 'https://site.example/wp-content/plugins/sparxstar-user-environment-check/' . ltrim($path, '/');
        }
    }
    if (! function_exists('untrailingslashit')) {
        /**
         * @param string $value Value.
         */
        function untrailingslashit(string $value): string
        {
            return rtrim($value, '/\\');
        }
    }
    if (! function_exists('register_setting')) {
        /**
         * @param string               $group  Group.
         * @param string               $name   Option.
         * @param array<string, mixed> $args   Args.
         */
        function register_setting(string $group, string $name, array $args = []): void
        {
            $GLOBALS['spx_settings'][$name] = $args;
        }
    }
}

namespace Starisian\SparxstarUEC\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use Starisian\SparxstarUEC\adapters\DeviceTrustAdapter;
    use Starisian\SparxstarUEC\cli\LegacyExportCommand;
    use Starisian\SparxstarUEC\core\SparxstarUECDatabase;

    /**
     * wpdb stub returning pages of rows for the export.
     */
    final class ExportWpdb extends \wpdb
    {
        /** @var array<int, array<string, mixed>> */
        public array $rows = [];

        /**
         * @param string $query SQL.
         * @return int|null
         */
        public function get_var(string $query): ?int
        {
            $this->queries[] = ['query' => $query];
            return str_contains($query, 'SHOW TABLES') ? 1 : null;
        }

        /**
         * @param string $query  SQL.
         * @param string $output Output type.
         * @return array<int, array<string, mixed>>
         */
        public function get_results(string $query, string $output = 'OBJECT'): array
        {
            $this->queries[] = ['query' => $query];
            preg_match('/id > (\d+) ORDER BY id ASC LIMIT (\d+)/', $query, $m);
            $after = (int) ($m[1] ?? 0);
            $limit = (int) ($m[2] ?? 0);
            return array_values(array_slice(array_filter($this->rows, static fn (array $r): bool => $r['id'] > $after), 0, $limit));
        }
    }

    /**
     * The adapter is thin: it loads the shared client and owns nothing.
     */
    final class DeviceTrustAdapterTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['wp_options']    = [];
            $GLOBALS['spx_enqueued']  = [];
            $GLOBALS['spx_localized'] = [];
            $GLOBALS['spx_settings']  = [];
        }

        /**
         * @param array<string, string> $options Option values.
         */
        private function options(array $options): void
        {
            $GLOBALS['wp_options'][1] = $options;
        }

        public function test_off_by_default_loads_nothing(): void
        {
            $adapter = new DeviceTrustAdapter();
            $this->assertSame('off', $adapter->mode());
            $adapter->enqueue();
            $this->assertSame([], $GLOBALS['spx_enqueued']);
        }

        public function test_unknown_mode_falls_back_to_off(): void
        {
            $this->options([DeviceTrustAdapter::OPTION_MODE => 'primary']);
            $this->assertSame('off', (new DeviceTrustAdapter())->mode());
        }

        public function test_shadow_without_https_config_fails_closed(): void
        {
            $this->options([
                DeviceTrustAdapter::OPTION_MODE   => 'shadow',
                DeviceTrustAdapter::OPTION_URL    => 'http://device-trust.example',
                DeviceTrustAdapter::OPTION_ISSUER => 'https://device-trust.example',
            ]);
            $adapter = new DeviceTrustAdapter();
            $this->assertNull($adapter->client_config());
            $adapter->enqueue();
            $this->assertSame([], $GLOBALS['spx_enqueued']);
        }

        public function test_shadow_loads_client_and_passes_no_wordpress_identity(): void
        {
            $this->options([
                DeviceTrustAdapter::OPTION_MODE   => 'shadow',
                DeviceTrustAdapter::OPTION_URL    => 'https://device-trust.example/',
                DeviceTrustAdapter::OPTION_ISSUER => 'https://device-trust.example',
            ]);
            (new DeviceTrustAdapter())->enqueue();

            $this->assertArrayHasKey('sparxstar-device-trust-client', $GLOBALS['spx_enqueued']);
            $this->assertSame(['sparxstar-device-trust-client'], $GLOBALS['spx_enqueued']['sparxstar-device-trust-bridge']['deps']);
            $config = $GLOBALS['spx_localized']['sparxstarDeviceTrustConfig'];
            $this->assertSame(
                ['baseUrl' => 'https://device-trust.example', 'issuer' => 'https://device-trust.example', 'mode' => 'shadow'],
                $config
            );
            // No WordPress user id, nonce, IP, fingerprint, or token is handed over.
            $json = (string) json_encode($GLOBALS['spx_localized']);
            foreach (['user', 'nonce', 'ip', 'fingerprint', 'token', 'visitor'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $json);
            }
        }

        public function test_settings_sanitizers_reject_unsafe_values(): void
        {
            (new DeviceTrustAdapter())->register_settings();
            $mode = $GLOBALS['spx_settings'][DeviceTrustAdapter::OPTION_MODE]['sanitize_callback'];
            $url  = $GLOBALS['spx_settings'][DeviceTrustAdapter::OPTION_URL]['sanitize_callback'];
            $this->assertSame('off', $mode('primary'));
            $this->assertSame('shadow', $mode('shadow'));
            $this->assertSame('', $url('http://x.example'));
            $this->assertSame('', $url('javascript:alert(1)'));
            $this->assertSame('https://x.example', $url('https://x.example'));
        }

        public function test_vendored_client_matches_its_provenance_record(): void
        {
            $root   = dirname(__DIR__, 2);
            $record = (string) file_get_contents($root . '/src/js/vendor/device-trust-client.PROVENANCE.md');
            $this->assertMatchesRegularExpression('/sha256 \| `([0-9a-f]{64})`/', $record);
            preg_match('/sha256 \| `([0-9a-f]{64})`/', $record, $m);
            $this->assertSame($m[1], hash_file('sha256', $root . '/src/js/vendor/device-trust-client.js'));
        }

        public function test_no_helios_or_sirus_in_the_device_trust_path(): void
        {
            $root = dirname(__DIR__, 2);
            foreach ([
                '/src/adapters/DeviceTrustAdapter.php',
                '/src/cli/LegacyExportCommand.php',
                '/src/js/device-trust/sparxstar-device-trust-bridge.js',
                '/src/js/vendor/device-trust-client.js',
            ] as $file) {
                $this->assertDoesNotMatchRegularExpression('/helios|sirus/i', (string) file_get_contents($root . $file), $file);
            }
        }

        public function test_export_pages_through_every_row_read_only(): void
        {
            $wpdb = new ExportWpdb();
            for ($i = 1; $i <= 5; $i++) {
                $wpdb->rows[] = ['id' => $i, 'fingerprint' => 'f', 'device_hash' => 'd', 'session_id' => null, 'user_id' => $i, 'snapshot_data' => '{}', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
            }
            $file  = tempnam(sys_get_temp_dir(), 'spx');
            $count = (new LegacyExportCommand(new SparxstarUECDatabase($wpdb)))->export($file, 2, 'aiwa.example');
            $lines = array_filter(explode("\n", (string) file_get_contents($file)));
            unlink($file);

            $this->assertSame(5, $count);
            $this->assertCount(5, $lines);
            $this->assertSame('aiwa.example', json_decode((string) $lines[0], true)['site']);
            foreach ($wpdb->queries as $q) {
                $this->assertDoesNotMatchRegularExpression('/\b(UPDATE|DELETE|INSERT|DROP|SELECT \*)\b/i', $q['query']);
            }
            $this->assertStringContainsString('LIMIT 2', $wpdb->queries[1]['query']);
        }
    }
}

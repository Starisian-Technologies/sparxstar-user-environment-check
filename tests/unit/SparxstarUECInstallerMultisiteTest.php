<?php
/**
 * Multisite lifecycle coverage for SparxstarUECInstaller.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\core\SparxstarUECInstaller;
use WP_Site;

/**
 * Ensures activation, new site init, and scheduling operate per site without nested loops.
 */
final class SparxstarUECInstallerMultisiteTest extends TestCase
{
    /**
     * Reset global state between tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->reset_environment();
    }

    /**
     * Activation on a single site uses the site prefix and seeds defaults.
     */
    public function test_single_site_activation_uses_prefix_and_defaults(): void
    {
        $GLOBALS['__is_multisite'] = false;

        SparxstarUECInstaller::spx_uec_activate(false);

        $this->assertNotEmpty($GLOBALS['dbDelta_queries']);
        $this->assertStringContainsString('CREATE TABLE wp_sparxstar_uec_snapshots', $GLOBALS['dbDelta_queries'][0]);
        $this->assertSame('none', get_option('sparxstar_uec_geoip_provider'));

        $scheduled_hooks = array_column($GLOBALS['scheduled_hooks'], 'hook');
        $this->assertContains('sparxstar_env_cleanup_snapshots', $scheduled_hooks);
    }

    /**
     * Network activation switches to each site exactly once and respects prefixes.
     */
    public function test_network_activation_runs_per_site_once(): void
    {
        $GLOBALS['__is_multisite'] = true;
        $GLOBALS['__sites']        = [new WP_Site(['blog_id' => 1]), new WP_Site(['blog_id' => 3])];

        SparxstarUECInstaller::spx_uec_activate(true);

        $this->assertSame([1, 3], $GLOBALS['switched_blogs']);

        $sql_for_site_1 = array_filter($GLOBALS['dbDelta_queries'], static fn (string $sql): bool => str_contains($sql, 'wp_sparxstar_uec_snapshots'));
        $sql_for_site_3 = array_filter($GLOBALS['dbDelta_queries'], static fn (string $sql): bool => str_contains($sql, 'wp_3_sparxstar_uec_snapshots'));

        $this->assertNotEmpty($sql_for_site_1);
        $this->assertNotEmpty($sql_for_site_3);
    }

    /**
     * New site provisioning initialises only the target blog.
     */
    public function test_new_site_initialization_targets_single_blog(): void
    {
        $GLOBALS['__is_multisite'] = true;

        SparxstarUECInstaller::spx_uec_initialize_new_site(new WP_Site(['blog_id' => 5]));

        $sql_for_site_5 = array_filter($GLOBALS['dbDelta_queries'], static fn (string $sql): bool => str_contains($sql, 'wp_5_sparxstar_uec_snapshots'));
        $this->assertNotEmpty($sql_for_site_5);
        $this->assertSame('none', $GLOBALS['wp_options'][5]['sparxstar_uec_geoip_provider'] ?? null);
        $this->assertSame(1, $GLOBALS['current_blog_id']);
    }

    /**
     * Reset global fixtures used by stubbed WordPress helpers.
     */
    private function reset_environment(): void
    {
        $GLOBALS['__is_multisite'] = false;
        $GLOBALS['__sites']        = [];
        $GLOBALS['dbDelta_queries'] = [];
        $GLOBALS['scheduled_hooks'] = [];
        $GLOBALS['wp_options']      = [];
        $GLOBALS['switched_blogs']  = [];
        $GLOBALS['current_blog_id'] = 1;

        if (isset($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb']->prefix = $GLOBALS['wpdb']->base_prefix;
            $GLOBALS['wpdb']->queries = [];
        }
    }
}

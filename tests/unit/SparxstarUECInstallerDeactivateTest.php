<?php

/**
 * Tests for the SparxstarUECInstaller deactivation paths.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\core\SparxstarUECInstaller;

/**
 * Verifies that deactivation clears scheduled hooks for single-site and
 * network-wide contexts.
 */
final class SparxstarUECInstallerDeactivateTest extends TestCase
{
    /**
     * Reset global state before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->reset_environment();
    }

    // -----------------------------------------------------------------------
    // Single-site deactivation
    // -----------------------------------------------------------------------

    /**
     * Single-site deactivation should clear the cleanup cron hook.
     */
    public function test_single_site_deactivation_clears_scheduled_hook(): void
    {
        $GLOBALS['__is_multisite'] = false;

        $hook = 'sparxstar_env_cleanup_snapshots';
        $hash = md5('1|' . $hook . serialize([]));

        // Pre-seed the schedule as if the plugin was previously activated.
        $GLOBALS['scheduled_hooks'][$hash] = [
            'blog_id'    => 1,
            'timestamp'  => time(),
            'recurrence' => 'daily',
            'hook'       => $hook,
            'args'       => [],
        ];

        SparxstarUECInstaller::spx_uec_deactivate(false);

        $remaining = array_filter(
            $GLOBALS['scheduled_hooks'],
            static fn (array $e): bool => ($e['hook'] ?? '') === $hook
        );

        $this->assertEmpty($remaining, 'Scheduled hook must be cleared on single-site deactivation.');
    }

    /**
     * Deactivation should work cleanly even when no hook was previously scheduled.
     */
    public function test_single_site_deactivation_with_no_existing_hook_does_not_throw(): void
    {
        $GLOBALS['__is_multisite'] = false;

        $this->expectNotToPerformAssertions();
        SparxstarUECInstaller::spx_uec_deactivate(false);
    }

    // -----------------------------------------------------------------------
    // Network-wide deactivation
    // -----------------------------------------------------------------------

    /**
     * Network-wide deactivation should iterate over all sites and clear the hook
     * on each one.
     */
    public function test_network_deactivation_clears_hooks_across_all_sites(): void
    {
        $GLOBALS['__is_multisite'] = true;
        $GLOBALS['__sites']        = [
            new \WP_Site(['blog_id' => 1]),
            new \WP_Site(['blog_id' => 4]),
        ];

        $hook = 'sparxstar_env_cleanup_snapshots';

        // Seed hooks for both sites using blog-scoped hashes.
        foreach ([1, 4] as $blog_id) {
            $hash = md5($blog_id . '|' . $hook . serialize([]));
            $GLOBALS['scheduled_hooks'][$hash] = [
                'blog_id'    => $blog_id,
                'timestamp'  => time(),
                'recurrence' => 'daily',
                'hook'       => $hook,
                'args'       => [],
            ];
        }

        SparxstarUECInstaller::spx_uec_deactivate(true);

        $remaining = array_filter(
            $GLOBALS['scheduled_hooks'],
            static fn (array $e): bool => ($e['hook'] ?? '') === $hook
        );

        $this->assertEmpty($remaining, 'All site-scoped cron hooks must be cleared on network deactivation.');
    }

    /**
     * Each site in the network should be visited exactly once.
     */
    public function test_network_deactivation_visits_each_site_once(): void
    {
        $GLOBALS['__is_multisite'] = true;
        $GLOBALS['__sites']        = [
            new \WP_Site(['blog_id' => 1]),
            new \WP_Site(['blog_id' => 2]),
            new \WP_Site(['blog_id' => 3]),
        ];

        SparxstarUECInstaller::spx_uec_deactivate(true);

        $this->assertSame([1, 2, 3], $GLOBALS['switched_blogs']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Reset all global fixtures to a clean baseline.
     */
    private function reset_environment(): void
    {
        $GLOBALS['__is_multisite']  = false;
        $GLOBALS['__sites']         = [];
        $GLOBALS['scheduled_hooks'] = [];
        $GLOBALS['switched_blogs']  = [];
        $GLOBALS['current_blog_id'] = 1;

        if (isset($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb']->prefix     = $GLOBALS['wpdb']->base_prefix;
            $GLOBALS['wpdb']->queries    = [];
        }
    }
}

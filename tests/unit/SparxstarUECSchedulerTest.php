<?php

/**
 * Unit tests for SparxstarUECScheduler.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Starisian\SparxstarUEC\cron\SparxstarUECScheduler;

/**
 * Covers the WP-Cron key mapping, scheduling, and cleanup logic.
 */
final class SparxstarUECSchedulerTest extends TestCase
{
    /**
     * Reset scheduled hooks between tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['scheduled_hooks'] = [];
        $GLOBALS['current_blog_id'] = 1;
    }

    // -----------------------------------------------------------------------
    // get_wp_schedule_key (private, tested via reflection)
    // -----------------------------------------------------------------------

    /**
     * 3 600 seconds should map to the 'hourly' key.
     */
    public function test_get_wp_schedule_key_returns_hourly_for_3600(): void
    {
        $result = $this->invoke_get_wp_schedule_key(3600);
        $this->assertSame('hourly', $result);
    }

    /**
     * 43 200 seconds (12 hours) should map to 'twicedaily'.
     */
    public function test_get_wp_schedule_key_returns_twicedaily_for_43200(): void
    {
        $result = $this->invoke_get_wp_schedule_key(43200);
        $this->assertSame('twicedaily', $result);
    }

    /**
     * 86 400 seconds (24 hours) should map to 'daily'.
     */
    public function test_get_wp_schedule_key_returns_daily_for_86400(): void
    {
        $result = $this->invoke_get_wp_schedule_key(86400);
        $this->assertSame('daily', $result);
    }

    /**
     * 604 800 seconds (7 days) should map to 'weekly'.
     */
    public function test_get_wp_schedule_key_returns_weekly_for_604800(): void
    {
        $result = $this->invoke_get_wp_schedule_key(604800);
        $this->assertSame('weekly', $result);
    }

    /**
     * An arbitrary interval that does not match any standard key should return null.
     */
    public function test_get_wp_schedule_key_returns_null_for_unmapped_interval(): void
    {
        $result = $this->invoke_get_wp_schedule_key(99999);
        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // schedule_recurring
    // -----------------------------------------------------------------------

    /**
     * After scheduling a hook it should appear in the registry.
     */
    public function test_schedule_recurring_registers_the_hook(): void
    {
        SparxstarUECScheduler::schedule_recurring('my_test_hook', 3600);

        $found = false;
        foreach ($GLOBALS['scheduled_hooks'] as $entry) {
            if (isset($entry['hook']) && $entry['hook'] === 'my_test_hook') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected my_test_hook to be in the schedule registry.');
    }

    /**
     * Calling schedule_recurring twice for the same hook should not create a
     * duplicate entry (the AS stub's as_next_scheduled_action always returns
     * false, so a second call would still attempt to schedule via AS; however,
     * the hash key is idempotent so it overwrites, keeping a single entry).
     */
    public function test_schedule_recurring_does_not_duplicate_entries(): void
    {
        SparxstarUECScheduler::schedule_recurring('dedup_hook', 86400);
        SparxstarUECScheduler::schedule_recurring('dedup_hook', 86400);

        $count = 0;
        foreach ($GLOBALS['scheduled_hooks'] as $entry) {
            if (isset($entry['hook']) && $entry['hook'] === 'dedup_hook') {
                $count++;
            }
        }

        $this->assertSame(1, $count, 'Duplicate scheduling should result in at most one entry.');
    }

    // -----------------------------------------------------------------------
    // clear
    // -----------------------------------------------------------------------

    /**
     * After clearing a hook it should be absent from the registry.
     */
    public function test_clear_removes_scheduled_hook(): void
    {
        $hook = 'removable_hook';
        $hash = md5('1|' . $hook . serialize([]));

        // Manually seed the registry as if the hook had been scheduled.
        $GLOBALS['scheduled_hooks'][$hash] = [
            'blog_id'    => 1,
            'timestamp'  => time(),
            'recurrence' => 'daily',
            'hook'       => $hook,
            'args'       => [],
        ];

        SparxstarUECScheduler::clear($hook);

        $remaining = array_filter(
            $GLOBALS['scheduled_hooks'],
            static fn (array $e): bool => ($e['hook'] ?? '') === $hook
        );

        $this->assertEmpty($remaining, 'Hook should be cleared from the registry.');
    }

    /**
     * Clearing a hook that was never scheduled should not throw.
     */
    public function test_clear_on_nonexistent_hook_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        SparxstarUECScheduler::clear('never_scheduled_hook');
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Invoke the private get_wp_schedule_key method via reflection.
     *
     * @param int $seconds Interval in seconds.
     * @return string|null Schedule key or null.
     */
    private function invoke_get_wp_schedule_key(int $seconds): ?string
    {
        $ref    = new ReflectionClass(SparxstarUECScheduler::class);
        $method = $ref->getMethod('get_wp_schedule_key');
        $method->setAccessible(true);
        return $method->invoke(null, $seconds);
    }
}

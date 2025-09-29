<?php
/**
 * Unit tests for the public StarUserEnv facade.
 *
 * @package SparxstarUserEnvironmentCheck\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Starisian\SparxstarUEC\StarUserEnv;

/**
 * Exercises representative data accessors on the StarUserEnv facade.
 */
final class StarUserEnvTest extends TestCase
{
    /**
     * Reset the cached snapshot before every test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setSnapshotCache(null);
    }

    /**
     * Restore the snapshot cache after each test run.
     */
    protected function tearDown(): void
    {
        $this->setSnapshotCache(null);
        parent::tearDown();
    }

    /**
     * The language accessor should trim locale suffixes to the ISO code.
     */
    public function test_get_user_language_trims_locale_suffix(): void
    {
        $this->setSnapshotCache(array(
            'client_side_data' => array(
                'context' => array('language' => 'en-US'),
            ),
        ));

        $this->assertSame('en', StarUserEnv::get_user_language());
    }

    /**
     * Ensure that missing snapshots fall back to the documented default.
     */
    public function test_get_network_type_returns_default_when_missing(): void
    {
        $this->setSnapshotCache(null);

        $this->assertSame('unknown', StarUserEnv::get_network_type());
    }

    /**
     * Helper to mutate the private snapshot cache via reflection.
     *
     * @param array<string, mixed>|null $value Snapshot payload or null to reset.
     */
    private function setSnapshotCache(?array $value): void
    {
        $property = new ReflectionProperty(StarUserEnv::class, 'snapshot_cache');
        $property->setAccessible(true);
        $property->setValue($value);
    }
}

<?php
/**
 * Tests for the StarUserEnv read-only facade.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Starisian\SparxstarUEC\StarUserEnv;

/**
 * Exercises the high-level getters exposed by StarUserEnv.
 */
final class StarUserEnvTest extends TestCase
{
    /**
     * Reset the cached snapshot between tests to avoid cross-test pollution.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setSnapshotCache(null);
    }

    /**
     * The network type getter should return the recorded value when present.
     */
    public function testGetNetworkTypeReturnsSnapshotValue(): void
    {
        $this->setSnapshotCache([
            'client_side_data' => [
                'network' => ['effectiveType' => '5g'],
            ],
        ]);

        $this->assertSame('5g', StarUserEnv::get_network_type());
    }

    /**
     * The network type getter should fall back to the default when missing.
     */
    public function testGetNetworkTypeFallsBackToDefault(): void
    {
        $this->setSnapshotCache(['client_side_data' => []]);

        $this->assertSame('unknown', StarUserEnv::get_network_type());
    }

    /**
     * Helper to inject values into the private runtime snapshot cache.
     *
     * @param array|null $value Snapshot payload or null to clear it.
     * @return void
     */
    private function setSnapshotCache(?array $value): void
    {
        $reflection = new ReflectionClass(StarUserEnv::class);
        $property = $reflection->getProperty('snapshot_cache');
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}

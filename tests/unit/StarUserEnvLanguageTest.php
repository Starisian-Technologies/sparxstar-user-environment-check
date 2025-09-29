<?php
/**
 * Tests covering locale helpers exposed by StarUserEnv.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Starisian\SparxstarUEC\StarUserEnv;

/**
 * Validates language parsing behaviour on stored snapshots.
 */
final class StarUserEnvLanguageTest extends TestCase
{
    /**
     * Reset the runtime cache between assertions.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setSnapshotCache(null);
    }

    /**
     * The getter should trim the locale down to its ISO 639-1 prefix.
     */
    public function testGetUserLanguageReturnsTwoLetterCode(): void
    {
        $this->setSnapshotCache([
            'client_side_data' => [
                'context' => ['language' => 'en-US'],
            ],
        ]);

        $this->assertSame('en', StarUserEnv::get_user_language());
    }

    /**
     * When the language is missing the getter should fall back to an empty string.
     */
    public function testGetUserLanguageFallsBackToEmptyString(): void
    {
        $this->setSnapshotCache([]);

        $this->assertSame('', StarUserEnv::get_user_language());
    }

    /**
     * Helper to manipulate the private snapshot cache on StarUserEnv.
     *
     * @param array|null $value Snapshot payload to inject or null to clear.
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

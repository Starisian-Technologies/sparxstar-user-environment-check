<?php
/**
 * Tests for the plugin orchestrator singleton.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\SparxstarUserEnvironmentCheck;

/**
 * Ensures the public bootstrapper behaves like a singleton.
 */
final class SparxstarUserEnvironmentCheckTest extends TestCase
{
    /**
     * The orchestrator should always return the same instance when requested.
     */
    public function testGetInstanceReturnsSingleton(): void
    {
        $first = SparxstarUserEnvironmentCheck::get_instance();
        $second = SparxstarUserEnvironmentCheck::get_instance();

        $this->assertSame($first, $second, 'The bootstrapper must behave as a singleton.');
    }
}

<?php
/**
 * Tests for the cache helper responsible for snapshot storage.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\includes\SparxstarUECCacheHelper;

/**
 * Validates the deterministic cache key generation and storage helpers.
 */
final class SparxstarUECCacheHelperTest extends TestCase
{
    /**
     * Ensure that make_key creates distinct keys for different identifiers.
     */
    public function testMakeKeyProducesDeterministicSegments(): void
    {
        $first = SparxstarUECCacheHelper::make_key(42, 'session', 'hash-one');
        $second = SparxstarUECCacheHelper::make_key(null, null, 'hash-two');

        $this->assertSame('u42:s' . substr(hash('sha256', 'session'), 0, 12), $first);
        $this->assertStringStartsWith('anon-' . substr('hash-two', 0, 12), $second);
        $this->assertStringEndsWith(':nosession', $second);
    }

    /**
     * Confirm that the helper proxies values through the WordPress cache shims.
     */
    public function testSetGetAndDeleteOperateOnCacheStore(): void
    {
        $cache_key = 'u1:s123';
        $payload = ['foo' => 'bar'];

        SparxstarUECCacheHelper::set($cache_key, $payload);
        $this->assertSame($payload, SparxstarUECCacheHelper::get($cache_key));

        SparxstarUECCacheHelper::delete($cache_key);
        $this->assertNull(SparxstarUECCacheHelper::get($cache_key));
    }
}

<?php

/**
 * Unit tests for SparxstarUECSessionManager.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\includes\SparxstarUECSessionManager;

/**
 * Exercises the session data helpers and dot-notation path traversal.
 */
final class SparxstarUECSessionManagerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // get_value_from_array
    // -----------------------------------------------------------------------

    /**
     * A single-level key should return the corresponding scalar value.
     */
    public function test_get_value_from_array_single_level_key(): void
    {
        $data   = ['city' => 'London'];
        $result = SparxstarUECSessionManager::get_value_from_array($data, 'city');

        $this->assertSame('London', $result);
    }

    /**
     * A dot-notation path should traverse nested arrays.
     */
    public function test_get_value_from_array_dot_notation_path(): void
    {
        $data   = ['geo' => ['city' => 'Tokyo', 'country' => 'JP']];
        $result = SparxstarUECSessionManager::get_value_from_array($data, 'geo.city');

        $this->assertSame('Tokyo', $result);
    }

    /**
     * A three-level dot path should resolve to the correct leaf value.
     */
    public function test_get_value_from_array_three_level_path(): void
    {
        $data   = ['a' => ['b' => ['c' => 'deep_value']]];
        $result = SparxstarUECSessionManager::get_value_from_array($data, 'a.b.c');

        $this->assertSame('deep_value', $result);
    }

    /**
     * A missing key should return the provided default value.
     */
    public function test_get_value_from_array_returns_default_for_missing_key(): void
    {
        $data   = ['existing' => 'yes'];
        $result = SparxstarUECSessionManager::get_value_from_array($data, 'missing', 'fallback');

        $this->assertSame('fallback', $result);
    }

    /**
     * A missing intermediate segment should return the default.
     */
    public function test_get_value_from_array_returns_default_for_missing_segment(): void
    {
        $data   = ['a' => ['x' => 'v']];
        $result = SparxstarUECSessionManager::get_value_from_array($data, 'a.b.c', 'default');

        $this->assertSame('default', $result);
    }

    /**
     * When the resolved value is an array (non-scalar) the default should be
     * returned because only scalar values are cast to strings.
     */
    public function test_get_value_from_array_returns_default_for_non_scalar_value(): void
    {
        $data   = ['nested' => ['array' => ['more' => 'data']]];
        $result = SparxstarUECSessionManager::get_value_from_array($data, 'nested', 'default');

        $this->assertSame('default', $result);
    }

    /**
     * An integer value should be cast to its string representation.
     */
    public function test_get_value_from_array_casts_integer_to_string(): void
    {
        $data   = ['count' => 42];
        $result = SparxstarUECSessionManager::get_value_from_array($data, 'count');

        $this->assertSame('42', $result);
    }

    /**
     * An empty source array should return the default.
     */
    public function test_get_value_from_array_returns_default_for_empty_array(): void
    {
        $result = SparxstarUECSessionManager::get_value_from_array([], 'any.path', 'empty');

        $this->assertSame('empty', $result);
    }

    // -----------------------------------------------------------------------
    // lookup
    // -----------------------------------------------------------------------

    /**
     * lookup() is a stub that always returns the provided default.
     */
    public function test_lookup_always_returns_default(): void
    {
        $this->assertSame(
            'my_default',
            SparxstarUECSessionManager::lookup('some_key', 1, 'sess_id', 'my_default')
        );
    }

    /**
     * lookup() with a null default should return null.
     */
    public function test_lookup_returns_null_when_no_default_provided(): void
    {
        $this->assertNull(
            SparxstarUECSessionManager::lookup('key', null, null)
        );
    }

    // -----------------------------------------------------------------------
    // set_all with empty array
    // -----------------------------------------------------------------------

    /**
     * Calling set_all() with an empty array must not throw.
     */
    public function test_set_all_with_empty_array_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        SparxstarUECSessionManager::set_all([]);
    }
}

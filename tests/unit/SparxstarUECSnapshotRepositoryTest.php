<?php

/**
 * Unit tests for SparxstarUECSnapshotRepository.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\core\SparxstarUECSnapshotRepository;

/**
 * Verifies the null-guard paths and cache flush helper in the snapshot repository.
 */
final class SparxstarUECSnapshotRepositoryTest extends TestCase
{
    /**
     * Reset the global wpdb stub between tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->queries   = [];
        $GLOBALS['wp_cache_store']  = [];
    }

    // -----------------------------------------------------------------------
    // get – null-guard on fingerprint
    // -----------------------------------------------------------------------

    /**
     * A null fingerprint should cause an immediate null return without querying.
     */
    public function test_get_returns_null_when_fingerprint_is_null(): void
    {
        $result = SparxstarUECSnapshotRepository::get(null, 'dh_hash');

        $this->assertNull($result);
        $this->assertEmpty($GLOBALS['wpdb']->queries, 'No DB query should be made for a null fingerprint.');
    }

    /**
     * An empty string fingerprint triggers the same early return.
     */
    public function test_get_returns_null_when_fingerprint_is_empty_string(): void
    {
        $result = SparxstarUECSnapshotRepository::get('', 'dh_hash');

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // get – null-guard on device_hash
    // -----------------------------------------------------------------------

    /**
     * A null device_hash should cause an immediate null return.
     */
    public function test_get_returns_null_when_device_hash_is_null(): void
    {
        $result = SparxstarUECSnapshotRepository::get('fp_abc', null);

        $this->assertNull($result);
        $this->assertEmpty($GLOBALS['wpdb']->queries, 'No DB query should be made for a null device_hash.');
    }

    /**
     * An empty string device_hash triggers the same early return.
     */
    public function test_get_returns_null_when_device_hash_is_empty_string(): void
    {
        $result = SparxstarUECSnapshotRepository::get('fp_abc', '');

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // get – no row found
    // -----------------------------------------------------------------------

    /**
     * When valid identifiers are provided but no row exists the method should
     * return null (the stub wpdb::get_row always returns null).
     */
    public function test_get_returns_null_when_no_row_exists(): void
    {
        $result = SparxstarUECSnapshotRepository::get('fp_valid', 'dh_valid');

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // get_by_user_id – null-guard
    // -----------------------------------------------------------------------

    /**
     * A user ID of zero should be rejected before hitting the database.
     */
    public function test_get_by_user_id_returns_null_for_zero(): void
    {
        $result = SparxstarUECSnapshotRepository::get_by_user_id(0);

        $this->assertNull($result);
        $this->assertEmpty($GLOBALS['wpdb']->queries);
    }

    /**
     * A negative user ID should be rejected.
     */
    public function test_get_by_user_id_returns_null_for_negative_id(): void
    {
        $result = SparxstarUECSnapshotRepository::get_by_user_id(-1);

        $this->assertNull($result);
    }

    /**
     * A valid positive user ID with no matching row returns null.
     */
    public function test_get_by_user_id_returns_null_when_no_row_found(): void
    {
        $result = SparxstarUECSnapshotRepository::get_by_user_id(999);

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // flush – early-return guards
    // -----------------------------------------------------------------------

    /**
     * flush() with a null fingerprint should return without touching the cache.
     */
    public function test_flush_returns_early_when_fingerprint_is_null(): void
    {
        $GLOBALS['wp_cache_store'] = ['sparxstar_uec' => ['some_key' => ['data']]];

        SparxstarUECSnapshotRepository::flush(null, 'dh_hash');

        // Cache must remain untouched.
        $this->assertNotEmpty($GLOBALS['wp_cache_store']['sparxstar_uec']);
    }

    /**
     * flush() with a null device_hash should return without touching the cache.
     */
    public function test_flush_returns_early_when_device_hash_is_null(): void
    {
        $GLOBALS['wp_cache_store'] = ['sparxstar_uec' => ['some_key' => ['data']]];

        SparxstarUECSnapshotRepository::flush('fp_abc', null);

        $this->assertNotEmpty($GLOBALS['wp_cache_store']['sparxstar_uec']);
    }

    /**
     * flush() with valid inputs should remove the corresponding cache entry.
     */
    public function test_flush_removes_cache_entry_for_valid_inputs(): void
    {
        $fp      = 'fp_to_flush';
        $dh      = 'dh_to_flush';
        $cacheKey = 'uec_snapshot_' . md5($fp . $dh);

        // Seed the cache.
        $GLOBALS['wp_cache_store']['sparxstar_uec'][$cacheKey] = ['snapshot' => 'data'];

        SparxstarUECSnapshotRepository::flush($fp, $dh);

        $this->assertArrayNotHasKey(
            $cacheKey,
            $GLOBALS['wp_cache_store']['sparxstar_uec'] ?? [],
            'Cache entry should be removed after flush.'
        );
    }
}

<?php

/**
 * Unit tests for SparxstarUECDatabase.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;

/**
 * Lightweight wpdb override that can simulate a present table and an
 * existing snapshot row so that the insert/update branches are exercised.
 */
final class MockWpdbWithTable extends \wpdb
{
    /** @var bool Controls the return value of SHOW TABLES queries. */
    public bool $table_present = true;

    /** @var int|null ID returned by SELECT id lookups; null = no existing row. */
    public ?int $existing_row_id = null;

    /**
     * @param string $query SQL string.
     * @return int|null
     */
    public function get_var(string $query): ?int
    {
        $this->queries[] = ['query' => $query];

        if (str_contains($query, 'SHOW TABLES')) {
            return $this->table_present ? 1 : null;
        }

        if (str_contains($query, 'SELECT id')) {
            return $this->existing_row_id;
        }

        return null;
    }
}

/**
 * Covers the SparxstarUECDatabase data-access layer.
 */
final class SparxstarUECDatabaseTest extends TestCase
{
    /**
     * Reset option and query state between tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['dbDelta_queries']    = [];
        $GLOBALS['wp_options']         = [];
        $GLOBALS['current_blog_id']    = 1;
        $GLOBALS['wpdb']->queries      = [];
        $GLOBALS['wpdb']->insert_id    = 0;
        $GLOBALS['wpdb']->prefix       = 'wp_';
        $GLOBALS['wpdb']->base_prefix  = 'wp_';
    }

    // -----------------------------------------------------------------------
    // get_table_name
    // -----------------------------------------------------------------------

    /**
     * Table name should concatenate the active prefix with the constant suffix.
     */
    public function test_get_table_name_uses_wpdb_prefix(): void
    {
        $db = new SparxstarUECDatabase($GLOBALS['wpdb']);

        $this->assertSame('wp_sparxstar_uec_snapshots', $db->get_table_name());
    }

    /**
     * A multisite context with a different prefix should produce a scoped name.
     */
    public function test_get_table_name_respects_multisite_prefix(): void
    {
        $wpdb         = clone $GLOBALS['wpdb'];
        $wpdb->prefix = 'wp_5_';
        $db           = new SparxstarUECDatabase($wpdb);

        $this->assertSame('wp_5_sparxstar_uec_snapshots', $db->get_table_name());
    }

    // -----------------------------------------------------------------------
    // get_charset_collate
    // -----------------------------------------------------------------------

    /**
     * The charset string should be a non-empty value from the database adapter.
     */
    public function test_get_charset_collate_returns_non_empty_string(): void
    {
        $db = new SparxstarUECDatabase($GLOBALS['wpdb']);

        $this->assertNotEmpty($db->get_charset_collate());
    }

    // -----------------------------------------------------------------------
    // ensure_schema
    // -----------------------------------------------------------------------

    /**
     * When no version is stored the schema creation SQL should be queued.
     */
    public function test_ensure_schema_triggers_dbdelta_when_version_outdated(): void
    {
        $db = new SparxstarUECDatabase($GLOBALS['wpdb']);
        // No wp_options entry → installed version defaults to '0.0' < '3.0.0'
        $db->ensure_schema();

        $this->assertNotEmpty($GLOBALS['dbDelta_queries']);
        $this->assertStringContainsString(
            'CREATE TABLE wp_sparxstar_uec_snapshots',
            $GLOBALS['dbDelta_queries'][0]
        );
    }

    /**
     * When the stored version equals the current version no dbDelta call is made.
     */
    public function test_ensure_schema_skips_dbdelta_when_version_current(): void
    {
        // Simulate an up-to-date installation.
        $GLOBALS['wp_options'][1]['sparxstar_uec_db_version'] = '3.0.0';

        $db = new SparxstarUECDatabase($GLOBALS['wpdb']);
        $db->ensure_schema();

        $this->assertEmpty($GLOBALS['dbDelta_queries']);
    }

    // -----------------------------------------------------------------------
    // store_snapshot – error paths
    // -----------------------------------------------------------------------

    /**
     * A WP_Error should be returned when the target table does not exist.
     */
    public function test_store_snapshot_returns_wp_error_when_table_missing(): void
    {
        // Default stub get_var returns null → table_exists = false.
        $db     = new SparxstarUECDatabase($GLOBALS['wpdb']);
        $result = $db->store_snapshot([
            'fingerprint' => 'fp123',
            'device_hash' => 'dh456',
            'data'        => [],
            'updated_at'  => '2024-01-01 00:00:00',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('db_table_missing', $result->get_error_code());
    }

    /**
     * A WP_Error should be returned when both fingerprint and device_hash are absent.
     */
    public function test_store_snapshot_returns_wp_error_when_identity_missing(): void
    {
        $mock               = new MockWpdbWithTable();
        $mock->table_present = true;
        $mock->existing_row_id = null;

        $db     = new SparxstarUECDatabase($mock);
        // Pass a payload that has the keys but with empty values.
        $result = $db->store_snapshot([
            'fingerprint' => '',
            'device_hash' => '',
            'data'        => [],
            'updated_at'  => '2024-01-01 00:00:00',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('snapshot_identity_missing', $result->get_error_code());
    }

    // -----------------------------------------------------------------------
    // store_snapshot – happy paths
    // -----------------------------------------------------------------------

    /**
     * A new snapshot should be inserted and the result should contain 'inserted'.
     */
    public function test_store_snapshot_inserts_new_record(): void
    {
        $mock                  = new MockWpdbWithTable();
        $mock->table_present   = true;
        $mock->existing_row_id = null; // No duplicate.

        $db     = new SparxstarUECDatabase($mock);
        $result = $db->store_snapshot([
            'fingerprint' => 'fp_new',
            'device_hash' => 'dh_new',
            'data'        => ['foo' => 'bar'],
            'updated_at'  => '2024-06-01 12:00:00',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('inserted', $result['status']);
        $this->assertGreaterThan(0, $result['id']);
    }

    /**
     * An existing snapshot should be updated and the result should contain 'updated'.
     */
    public function test_store_snapshot_updates_existing_record(): void
    {
        $mock                  = new MockWpdbWithTable();
        $mock->table_present   = true;
        $mock->existing_row_id = 42; // Simulate an existing row.

        $db     = new SparxstarUECDatabase($mock);
        $result = $db->store_snapshot([
            'fingerprint' => 'fp_existing',
            'device_hash' => 'dh_existing',
            'data'        => ['updated' => true],
            'updated_at'  => '2024-06-01 12:00:00',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('updated', $result['status']);
        $this->assertSame(42, $result['id']);
    }

    // -----------------------------------------------------------------------
    // store_snapshot – legacy normalisation
    // -----------------------------------------------------------------------

    /**
     * A payload without top-level fingerprint/device_hash keys should be
     * normalised automatically and then stored.
     */
    public function test_store_snapshot_normalizes_legacy_payload(): void
    {
        $mock                  = new MockWpdbWithTable();
        $mock->table_present   = true;
        $mock->existing_row_id = null;

        $db = new SparxstarUECDatabase($mock);

        // Provide a legacy-style payload using nested identifiers.
        $result = $db->store_snapshot([
            'client_side_data' => [
                'identifiers' => [
                    'visitorId'  => 'legacy_visitor_id',
                    'session_id' => 'sess_abc',
                ],
            ],
        ]);

        // Should succeed with an insert (fingerprint derived from visitorId).
        $this->assertIsArray($result);
        $this->assertSame('inserted', $result['status']);
    }

    // -----------------------------------------------------------------------
    // get_latest_snapshot
    // -----------------------------------------------------------------------

    /**
     * When the table does not exist the method should return null.
     */
    public function test_get_latest_snapshot_returns_null_when_table_missing(): void
    {
        // Default stub returns null for get_var → table missing.
        $db     = new SparxstarUECDatabase($GLOBALS['wpdb']);
        $result = $db->get_latest_snapshot('fp_x', 'dh_x');

        $this->assertNull($result);
    }

    /**
     * When the table exists but no row matches, null should be returned.
     */
    public function test_get_latest_snapshot_returns_null_when_no_row_found(): void
    {
        $mock              = new MockWpdbWithTable();
        $mock->table_present = true;
        // get_row in base stub always returns null.

        $db     = new SparxstarUECDatabase($mock);
        $result = $db->get_latest_snapshot('fp_unknown', 'dh_unknown');

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // cleanup_old_snapshots
    // -----------------------------------------------------------------------

    /**
     * When the table does not exist no DELETE query should be issued.
     */
    public function test_cleanup_old_snapshots_skips_when_table_missing(): void
    {
        $db = new SparxstarUECDatabase($GLOBALS['wpdb']);
        $db->cleanup_old_snapshots();

        $deleteQueries = array_filter(
            $GLOBALS['wpdb']->queries,
            static fn (array $q): bool => isset($q['query']) && str_contains($q['query'], 'DELETE')
        );

        $this->assertEmpty($deleteQueries, 'No DELETE should be issued when the table is absent.');
    }
}

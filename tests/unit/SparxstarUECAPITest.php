<?php
/**
 * Unit tests for the REST API orchestrator.
 *
 * @package SparxstarUserEnvironmentCheck\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\api\SparxstarUECAPI;

/**
 * Validates how the REST API handler wires WordPress hooks.
 */
final class SparxstarUECAPITest extends TestCase
{
    /**
     * Reset route and hook registries before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['spx_registered_actions'] = array();
        $GLOBALS['spx_registered_routes'] = array();
    }

    /**
     * Verify that register_hooks() wires into rest_api_init.
     */
    public function test_register_hooks_adds_rest_api_init_action(): void
    {
        $api = SparxstarUECAPI::get_instance();

        $api->register_hooks();

        $this->assertArrayHasKey('rest_api_init', $GLOBALS['spx_registered_actions']);
        $actions = $GLOBALS['spx_registered_actions']['rest_api_init'];
        $this->assertNotEmpty($actions, 'Expected at least one rest_api_init hook to be registered.');
    }

    /**
     * Ensure the REST endpoint is registered under the expected namespace.
     */
    public function test_register_rest_route_persists_expected_path(): void
    {
        $api = SparxstarUECAPI::get_instance();

        $api->register_rest_route();

        $this->assertNotEmpty($GLOBALS['spx_registered_routes']);
        $route = end($GLOBALS['spx_registered_routes']);
        $this->assertSame('star-uec/v1', $route['namespace']);
        $this->assertSame('/log', $route['route']);
    }
}

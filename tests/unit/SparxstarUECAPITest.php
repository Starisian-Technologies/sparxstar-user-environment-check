<?php
/**
 * Unit tests for the REST API controller.
 *
 * @package SparxstarUserEnvironmentCheck\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\api\SparxstarUECRESTController;
use Starisian\SparxstarUEC\core\SparxstarUECDatabase;

/**
 * Validates that the REST controller registers the expected routes.
 */
final class SparxstarUECAPITest extends TestCase
{
    /**
     * Reset the route registry before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['spx_registered_routes'] = [];
    }

    /**
     * Verify that register_routes() registers the primary log endpoint.
     */
    public function test_register_routes_registers_log_endpoint(): void
    {
        $controller = new SparxstarUECRESTController(new SparxstarUECDatabase($GLOBALS['wpdb']));
        $controller->register_routes();

        $this->assertNotEmpty($GLOBALS['spx_registered_routes'], 'Expected at least one route to be registered.');
        $namespaces = array_column($GLOBALS['spx_registered_routes'], 'namespace');
        $this->assertContains('star-uec/v1', $namespaces);
    }

    /**
     * Verify that the /log route path is registered.
     */
    public function test_register_routes_includes_log_path(): void
    {
        $controller = new SparxstarUECRESTController(new SparxstarUECDatabase($GLOBALS['wpdb']));
        $controller->register_routes();

        $routes = array_column($GLOBALS['spx_registered_routes'], 'route');
        $this->assertContains('/log', $routes);
    }

    /**
     * Verify that the /recorder-log route path is also registered.
     */
    public function test_register_routes_includes_recorder_log_path(): void
    {
        $controller = new SparxstarUECRESTController(new SparxstarUECDatabase($GLOBALS['wpdb']));
        $controller->register_routes();

        $routes = array_column($GLOBALS['spx_registered_routes'], 'route');
        $this->assertContains('/recorder-log', $routes);
    }
}

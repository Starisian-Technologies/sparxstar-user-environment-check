<?php
/**
 * Tests focused on the main plugin bootstrap file.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Validates constants and hook registrations defined in the bootstrap file.
 */
final class PluginBootstrapTest extends TestCase
{
    /**
     * Load the plugin bootstrap once before running assertions.
     */
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/sparxstar-user-environment-check.php';
    }

    /**
     * Ensure the bootstrap defines the expected plugin constants.
     */
    public function testPluginConstantsAreDefined(): void
    {
        $this->assertTrue(defined('SPX_ENV_CHECK_PLUGIN_FILE'));
        $this->assertTrue(defined('SPX_ENV_CHECK_PLUGIN_PATH'));
        $this->assertTrue(defined('SPX_ENV_CHECK_VERSION'));
        $this->assertTrue(defined('SPX_ENV_CHECK_TEXT_DOMAIN'));
        $this->assertTrue(defined('SPX_ENV_CHECK_DB_TABLE_NAME'));
    }

    /**
     * The bootstrap should register activation and deactivation hooks.
     */
    public function testActivationHooksAreRegistered(): void
    {
        $this->assertArrayHasKey('callback', $GLOBALS['registered_activation_hook'] ?? []);
        $this->assertArrayHasKey('callback', $GLOBALS['registered_deactivation_hook'] ?? []);
    }
}

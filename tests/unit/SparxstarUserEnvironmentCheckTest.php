<?php
/**
 * Unit tests for the plugin bootstrapper.
 *
 * @package SparxstarUserEnvironmentCheck\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\SparxstarUEC\SparxstarUserEnvironmentCheck;

/**
 * Verifies the high-level orchestration logic of the bootstrapper.
 */
final class SparxstarUserEnvironmentCheckTest extends TestCase
{
    /**
     * Reset global tracking arrays before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['spx_registered_actions'] = array();
        $GLOBALS['spx_loaded_textdomains'] = array();
    }

    /**
     * Ensures that the bootstrapper adheres to the singleton contract.
     */
    public function test_get_instance_returns_singleton(): void
    {
        $instance_one = SparxstarUserEnvironmentCheck::get_instance();
        $instance_two = SparxstarUserEnvironmentCheck::get_instance();

        $this->assertSame($instance_one, $instance_two, 'Expected the same instance on repeated calls.');
    }

    /**
     * Confirm that the bootstrapper requests its translation files.
     */
    public function test_load_textdomain_registers_text_domain(): void
    {
        $bootstrapper = SparxstarUserEnvironmentCheck::get_instance();

        $bootstrapper->load_textdomain();

        $this->assertNotEmpty(
            $GLOBALS['spx_loaded_textdomains'],
            'The bootstrapper should request loading its text domain.'
        );
        $last = end($GLOBALS['spx_loaded_textdomains']);
        $this->assertSame('sparxstar-user-environment-check', $last['text_domain']);
    }
}

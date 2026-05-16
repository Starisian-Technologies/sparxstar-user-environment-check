<?php

/**
 * Unit tests for StarLogger.
 *
 * @package Starisian\SparxstarUEC\Tests\Unit
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Starisian\SparxstarUEC\helpers\StarLogger;

/**
 * Validates level filtering, data sanitisation, JSON mode, and timer helpers.
 */
final class StarLoggerTest extends TestCase
{
    /**
     * Restore static state mutated during each test.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $ref = new ReflectionClass(StarLogger::class);

        // Reset minimum log level to INFO.
        $minLevel = $ref->getProperty('min_log_level');
        $minLevel->setAccessible(true);
        $minLevel->setValue(null, StarLogger::INFO);

        // Disable JSON mode.
        $jsonMode = $ref->getProperty('json_mode');
        $jsonMode->setAccessible(true);
        $jsonMode->setValue(null, false);

        // Clear correlation ID.
        $corrId = $ref->getProperty('correlation_id');
        $corrId->setAccessible(true);
        $corrId->setValue(null, null);

        // Clear timers.
        $timers = $ref->getProperty('timers');
        $timers->setAccessible(true);
        $timers->setValue(null, []);

        // Clear captured actions.
        $GLOBALS['fired_actions'] = [];
    }

    // -----------------------------------------------------------------------
    // Level constants
    // -----------------------------------------------------------------------

    /**
     * The public level constants must have the expected numeric values.
     */
    public function test_level_constants_have_correct_values(): void
    {
        $this->assertSame(100, StarLogger::DEBUG);
        $this->assertSame(200, StarLogger::INFO);
        $this->assertSame(300, StarLogger::WARNING);
        $this->assertSame(400, StarLogger::ERROR);
        $this->assertSame(500, StarLogger::CRITICAL);
    }

    // -----------------------------------------------------------------------
    // getLevelInt
    // -----------------------------------------------------------------------

    /**
     * Known level names should map to the expected integer values.
     */
    public function test_get_level_int_returns_correct_integers(): void
    {
        $ref    = new ReflectionClass(StarLogger::class);
        $method = $ref->getMethod('getLevelInt');
        $method->setAccessible(true);

        $this->assertSame(StarLogger::DEBUG,   $method->invoke(null, 'debug'));
        $this->assertSame(StarLogger::INFO,    $method->invoke(null, 'info'));
        $this->assertSame(StarLogger::WARNING, $method->invoke(null, 'warning'));
        $this->assertSame(StarLogger::ERROR,   $method->invoke(null, 'error'));
    }

    /**
     * An unknown level name should fall back to ERROR.
     */
    public function test_get_level_int_defaults_to_error_for_unknown_level(): void
    {
        $ref    = new ReflectionClass(StarLogger::class);
        $method = $ref->getMethod('getLevelInt');
        $method->setAccessible(true);

        $this->assertSame(StarLogger::ERROR, $method->invoke(null, 'nonexistent'));
    }

    // -----------------------------------------------------------------------
    // setMinLogLevel & level filtering
    // -----------------------------------------------------------------------

    /**
     * Messages below the configured minimum level should be silently discarded.
     */
    public function test_log_filters_messages_below_min_level(): void
    {
        StarLogger::setMinLogLevel('warning');

        $GLOBALS['fired_actions'] = [];

        // INFO is below WARNING – should be dropped.
        StarLogger::info('TestCtx', 'this should not fire');
        $this->assertEmpty($GLOBALS['fired_actions']['star_log_event'] ?? []);
    }

    /**
     * Messages at or above the minimum level must be processed.
     */
    public function test_log_processes_messages_at_or_above_min_level(): void
    {
        StarLogger::setMinLogLevel('warning');

        $GLOBALS['fired_actions'] = [];

        StarLogger::warning('TestCtx', 'this should fire');
        $this->assertNotEmpty($GLOBALS['fired_actions']['star_log_event'] ?? []);
    }

    /**
     * setMinLogLevel should silently ignore unknown level names.
     */
    public function test_set_min_log_level_ignores_unknown_levels(): void
    {
        $ref  = new ReflectionClass(StarLogger::class);
        $prop = $ref->getProperty('min_log_level');
        $prop->setAccessible(true);

        $before = $prop->getValue(null);

        StarLogger::setMinLogLevel('nonexistent_level');

        // Value should be unchanged.
        $this->assertSame($before, $prop->getValue(null));
    }

    // -----------------------------------------------------------------------
    // sanitizeData
    // -----------------------------------------------------------------------

    /**
     * Keys matching sensitive patterns should have their values replaced.
     */
    public function test_sanitize_data_redacts_sensitive_keys(): void
    {
        $ref    = new ReflectionClass(StarLogger::class);
        $method = $ref->getMethod('sanitizeData');
        $method->setAccessible(true);

        $input  = [
            'ip'          => '1.2.3.4',
            'email'       => 'user@example.com',
            'user_id'     => '42',
            'token'       => 'abc123',
            'fingerprint' => 'fp_xyz',
            'name'        => 'John',
        ];

        $result = $method->invoke(null, $input);

        $this->assertSame('[REDACTED]', $result['ip']);
        $this->assertSame('[REDACTED]', $result['email']);
        $this->assertSame('[REDACTED]', $result['user_id']);
        $this->assertSame('[REDACTED]', $result['token']);
        $this->assertSame('[REDACTED]', $result['fingerprint']);
        // Non-sensitive keys must pass through unchanged.
        $this->assertSame('John', $result['name']);
    }

    /**
     * Nested arrays should also be recursively sanitised.
     */
    public function test_sanitize_data_redacts_nested_sensitive_keys(): void
    {
        $ref    = new ReflectionClass(StarLogger::class);
        $method = $ref->getMethod('sanitizeData');
        $method->setAccessible(true);

        $input  = ['outer' => ['ip' => '9.9.9.9', 'label' => 'test']];
        $result = $method->invoke(null, $input);

        $this->assertSame('[REDACTED]', $result['outer']['ip']);
        $this->assertSame('test', $result['outer']['label']);
    }

    // -----------------------------------------------------------------------
    // enableJsonMode
    // -----------------------------------------------------------------------

    /**
     * Enabling JSON mode should flip the internal flag to true.
     */
    public function test_enable_json_mode_sets_flag(): void
    {
        $ref  = new ReflectionClass(StarLogger::class);
        $prop = $ref->getProperty('json_mode');
        $prop->setAccessible(true);

        StarLogger::enableJsonMode(true);
        $this->assertTrue($prop->getValue(null));

        StarLogger::enableJsonMode(false);
        $this->assertFalse($prop->getValue(null));
    }

    // -----------------------------------------------------------------------
    // setCorrelationId
    // -----------------------------------------------------------------------

    /**
     * A custom correlation ID should be stored verbatim.
     */
    public function test_set_correlation_id_stores_supplied_id(): void
    {
        $ref  = new ReflectionClass(StarLogger::class);
        $prop = $ref->getProperty('correlation_id');
        $prop->setAccessible(true);

        StarLogger::setCorrelationId('my-trace-id');
        $this->assertSame('my-trace-id', $prop->getValue(null));
    }

    /**
     * Calling setCorrelationId() without an argument should generate a UUID.
     */
    public function test_set_correlation_id_auto_generates_when_null(): void
    {
        $ref  = new ReflectionClass(StarLogger::class);
        $prop = $ref->getProperty('correlation_id');
        $prop->setAccessible(true);

        StarLogger::setCorrelationId();
        $generated = $prop->getValue(null);

        $this->assertNotNull($generated);
        $this->assertNotEmpty($generated);
    }

    // -----------------------------------------------------------------------
    // Convenience wrappers
    // -----------------------------------------------------------------------

    /**
     * Each convenience wrapper should fire the 'star_log_event' action with
     * the expected level name.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('convenience_wrapper_provider')]
    public function test_convenience_wrapper_fires_action_with_correct_level(
        string $method,
        string $expected_level
    ): void {
        $GLOBALS['fired_actions'] = [];

        StarLogger::$method('WrapperCtx', 'wrapper test message');

        $events = $GLOBALS['fired_actions']['star_log_event'] ?? [];
        $this->assertNotEmpty($events, "Expected '{$method}' to fire 'star_log_event'.");
        // First arg = level name (uppercase).
        $this->assertSame($expected_level, $events[0][0]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function convenience_wrapper_provider(): array
    {
        return [
            'info'     => ['info',     'INFO'],
            'notice'   => ['notice',   'NOTICE'],
            'warning'  => ['warning',  'WARNING'],
            'warn'     => ['warn',     'WARNING'],
            'error'    => ['error',    'ERROR'],
            'critical' => ['critical', 'CRITICAL'],
            'alert'    => ['alert',    'ALERT'],
            'emergency' => ['emergency', 'EMERGENCY'],
        ];
    }

    // -----------------------------------------------------------------------
    // Throwable formatting
    // -----------------------------------------------------------------------

    /**
     * When a Throwable is passed as the message it should be formatted as a
     * class-name, message, file, and line string.
     */
    public function test_log_formats_throwable_as_readable_string(): void
    {
        $GLOBALS['fired_actions'] = [];

        $ex = new \RuntimeException('Something went wrong');
        StarLogger::error('ExTest', $ex);

        $events = $GLOBALS['fired_actions']['star_log_event'] ?? [];
        $this->assertNotEmpty($events);

        // The message argument (index 2) should be the original Throwable.
        $this->assertInstanceOf(\RuntimeException::class, $events[0][2]);
    }

    // -----------------------------------------------------------------------
    // Timer utilities
    // -----------------------------------------------------------------------

    /**
     * timeStart / timeEnd should execute without errors.
     */
    public function test_timer_start_and_end_complete_without_error(): void
    {
        StarLogger::timeStart('unit_test_timer');
        usleep(1000); // 1 ms
        StarLogger::timeEnd('unit_test_timer', 'TimerCtx');

        $ref    = new ReflectionClass(StarLogger::class);
        $timers = $ref->getProperty('timers');
        $timers->setAccessible(true);

        // The timer entry should be removed after timeEnd().
        $this->assertArrayNotHasKey('unit_test_timer', $timers->getValue(null));
    }

    /**
     * Calling timeEnd() for a label that was never started should not throw.
     */
    public function test_time_end_with_no_start_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        StarLogger::timeEnd('nonexistent_timer');
    }

    // -----------------------------------------------------------------------
    // boot()
    // -----------------------------------------------------------------------

    /**
     * boot() is a no-op and should not throw.
     */
    public function test_boot_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        StarLogger::boot();
    }
}

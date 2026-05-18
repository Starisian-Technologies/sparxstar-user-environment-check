<?php

/**
 * SPARXSTAR User Environment Check
 *
 * Internal logger wrapper that standardizes plugin logs and redacts sensitive
 * context fields before output.
 *
 * @package Starisian\SparxstarUEC\helpers
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized logger for SPARXSTAR UEC.
 */
class StarLogger
{
    public const DEBUG = 100;

    public const INFO = 200;

    public const NOTICE = 250;

    public const WARNING = 300;

    public const ERROR = 400;

    public const CRITICAL = 500;

    public const ALERT = 550;

    public const EMERGENCY = 600;

    /**
     * Minimum log level to record.
     */
    protected static int $min_log_level = self::INFO;

    protected static array $levels = [
        'debug'     => self::DEBUG,
        'info'      => self::INFO,
        'notice'    => self::NOTICE,
        'warning'   => self::WARNING,
        'error'     => self::ERROR,
        'critical'  => self::CRITICAL,
        'alert'     => self::ALERT,
        'emergency' => self::EMERGENCY,
    ];

    protected static bool $json_mode = false;

    protected static ?string $correlation_id = null;

    protected static array $timers = [];

    /*==============================================================
     * CONFIGURATION
     *=============================================================*/

    /**
     * Configure minimum accepted log level by level name.
     *
     * @param string $level_name PSR-3-like level string.
     */
    public static function setMinLogLevel(string $level_name): void
    {
        $level_name = strtolower($level_name);
        if (isset(self::$levels[$level_name])) {
            self::$min_log_level = self::$levels[$level_name];
        }
    }

    /**
     * Legacy method kept for backward compatibility.
     * Does nothing as we now rely on standard WP debug.log.
     *
     * @param string $path Deprecated custom path argument.
     */
    public static function setLogFilePath(string $path): void
    {
        // No-op
    }

    /**
     * Enable or disable JSON log output mode.
     *
     * @param bool $enabled True to emit JSON log entries.
     */
    public static function enableJsonMode(bool $enabled = true): void
    {
        self::$json_mode = $enabled;
    }

    /**
     * Assign a correlation ID used to link related log lines.
     *
     * @param string|null $id Correlation ID, auto-generated when null.
     */
    public static function setCorrelationId(?string $id = null): void
    {
        self::$correlation_id = $id ?? wp_generate_uuid4();
    }

    /*==============================================================
     * CORE LOGGING
     *=============================================================*/

    /**
     * Resolve a textual log level to its numeric severity.
     *
     * @param string $level_name Log level name.
     * @return int Numeric severity constant.
     */
    protected static function getLevelInt(string $level_name): int
    {
        return self::$levels[strtolower($level_name)] ?? self::ERROR;
    }

    /**
     * Recursively redact sensitive fields in structured log context.
     *
     * @param array<string, mixed> $data Context array.
     * @return array<string, mixed> Sanitized context.
     */
    protected static function sanitizeData(array $data): array
    {
        foreach ($data as $k => &$v) {
            if (is_string($v) && preg_match('/(ip|email|user|token|auth|fingerprint)/i', (string) $k)) {
                $v = '[REDACTED]';
            } elseif (is_array($v)) {
                $v = self::sanitizeData($v);
            }
        }

        return $data;
    }

    /**
     * Main logging method.
     * Writes directly to PHP error_log (standard WP debug.log).
     *
     * @param string $context Logical component name.
     * @param mixed $msg Throwable, scalar, or structured message.
     * @param string $level Severity label.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function log(string $context, mixed $msg, string $level = 'error', array $extra = []): void
    {
        $current_level_int = self::getLevelInt($level);

        // Check internal minimum level setting
        if ($current_level_int < self::$min_log_level) {
            return;
        }

        $level_name      = strtoupper($level);
        $message_content = self::formatMessageContent($msg);

        // Prepare context data
        $extra_clean = self::sanitizeData($extra);
        $extra_str   = $extra_clean === [] ? '' : ' | Data: ' . json_encode($extra_clean, JSON_UNESCAPED_SLASHES);

        $prefix = self::$correlation_id ? '[' . self::$correlation_id . '] ' : '';

        // Construct the log line
        if (self::$json_mode) {
            $log_entry = json_encode([
                'level'   => $level_name,
                'context' => $context,
                'message' => $message_content,
                'extra'   => $extra_clean,
                'cid'     => self::$correlation_id
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // Format: [SPARXSTAR UEC] [LEVEL] [Context] Message | Data: {...}
            // Note: error_log automatically adds the Timestamp.
            $log_entry = sprintf(
                '%s[SPARXSTAR UEC] [%s] [%s] %s%s',
                $prefix,
                $level_name,
                $context,
                $message_content,
                $extra_str
            );
        }

        // Send to standard WordPress debug.log
        error_log($log_entry);

        // Fire hooks for external integrations
        do_action('star_log_event', $level_name, $context, $msg, $extra);
    }

    /**
     * Convert mixed message payload into printable string output.
     *
     * @param mixed $msg Message payload.
     * @return string Formatted message.
     */
    protected static function formatMessageContent(mixed $msg): string
    {
        if ($msg instanceof \Throwable) {
            return sprintf(
                '%s: %s in %s:%d',
                $msg::class,
                $msg->getMessage(),
                $msg->getFile(),
                $msg->getLine()
            );
        }

        return is_array($msg) || is_object($msg) ? print_r($msg, true) : (string) $msg;
    }

    /*==============================================================
     * TIMER UTILITIES
     *=============================================================*/

    /**
     * Start a named high-resolution timer.
     *
     * @param string $label Timer identifier.
     */
    public static function timeStart(string $label): void
    {
        self::$timers[$label] = microtime(true);
    }

    /**
     * Stop a named timer and write its duration as a debug message.
     *
     * @param string $label Timer identifier.
     * @param string $context Log context for timer output.
     */
    public static function timeEnd(string $label, string $context = 'Timer'): void
    {
        if (!isset(self::$timers[$label])) {
            return;
        }

        $duration = round((microtime(true) - self::$timers[$label]) * 1000, 2);
        unset(self::$timers[$label]);
        // Log timer results as debug
        self::debug($context, sprintf('%s completed in %sms', $label, $duration));
    }

    /*==============================================================
     * CONVENIENCE WRAPPERS
     *=============================================================*/
    /**
     * Write debug-level message.
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function debug(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'debug', $extra);
    }

    /**
     * Write info-level message.
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function info(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'info', $extra);
    }

    /**
     * Write notice-level message.
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function notice(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'notice', $extra);
    }

    /**
     * Write warning-level message.
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function warning(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'warning', $extra);
    }

    /**
     * Backward-compatible alias for warning().
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function warn(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'warning', $extra);
    }

    /**
     * Write error-level message.
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function error(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'error', $extra);
    }

    /**
     * Write critical-level message.
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function critical(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'critical', $extra);
    }

    /**
     * Write alert-level message.
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function alert(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'alert', $extra);
    }

    /**
     * Write emergency-level message.
     *
     * @param string $context Logical component name.
     * @param mixed $msg Message payload.
     * @param array<string, mixed> $extra Structured metadata.
     */
    public static function emergency(string $context, mixed $msg, array $extra = []): void
    {
        self::log($context, $msg, 'emergency', $extra);
    }

    /*==============================================================
     * BOOTSTRAP
     *=============================================================*/
    /**
     * Optional bootstrap hook for logger initialization.
     */
    public static function boot(): void
    {
        // No special boot logic needed for standard error_log
    }
}

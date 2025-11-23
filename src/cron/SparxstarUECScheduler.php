<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Unified scheduler.
 * Version 3.1: 
 * - Fixes Static Analysis errors regarding Action Scheduler.
 * - Prevents "Phantom Schedule" bugs by mapping to standard WP-Cron keys.
 *
 * @package Starisian\SparxstarUEC\cron
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\cron;

use Starisian\SparxstarUEC\helpers\StarLogger;

if (! defined('ABSPATH')) {
    exit;
}

final class SparxstarUECScheduler
{
    /**
     * Schedule a recurring event safely.
     *
     * @param string $hook              The action hook to execute.
     * @param int    $interval_in_seconds How often to run (e.g., 3600, 86400).
     * @param array  $args              Arguments to pass to the hook.
     */
    public static function schedule_recurring(string $hook, int $interval_in_seconds, array $args = []): void
    {
        try {
            // 1. Action Scheduler (Preferred)
            // We use call_user_func to prevent Static Analysis/Linter errors
            // when Action Scheduler is not installed in the dev environment.
            if (function_exists('as_schedule_recurring_action')) {
                // Check if already scheduled
                $is_scheduled = call_user_func(\as_next_scheduled_action(...), $hook, $args);

                if (! $is_scheduled) {
                    call_user_func(\as_schedule_recurring_action(...), time(), $interval_in_seconds, $hook, $args);
                }

                return;
            }

            // 2. Fallback to WP-Cron
            // We map the seconds to a standard WP Key (e.g., 'hourly', 'daily').
            $schedule_key = self::get_wp_schedule_key($interval_in_seconds);

            if (! $schedule_key) {
                // If the interval doesn't match a standard WP schedule, default to 'daily'
                // to ensure the event runs safely without needing dynamic registration.
                StarLogger::warning(
                    'SparxstarUECScheduler',
                    sprintf("Could not map %ds to a standard WP-Cron key. Defaulting to 'daily'.", $interval_in_seconds),
                    ['hook' => $hook]
                );
                $schedule_key = 'daily';
            }

            if (! \wp_next_scheduled($hook, $args)) {
                \wp_schedule_event(time(), $schedule_key, $hook, $args);
            }

        } catch (\Throwable $throwable) {
            StarLogger::error('SparxstarUECScheduler', $throwable, [
                'method' => 'schedule_recurring',
                'hook'   => $hook
            ]);
        }
    }

    /**
     * Clear all queued instances of a hook.
     */
    public static function clear(string $hook, array $args = []): void
    {
        try {
            // Clear Action Scheduler (safely)
            if (function_exists('as_unschedule_all_actions')) {
                call_user_func(\as_unschedule_all_actions(...), $hook, $args);
            }

            // Clear WP-Cron
            $timestamp = \wp_next_scheduled($hook, $args);
            while ($timestamp) {
                \wp_unschedule_event($timestamp, $hook, $args);
                $timestamp = \wp_next_scheduled($hook, $args);
            }
        } catch (\Throwable $throwable) {
            StarLogger::error('SparxstarUECScheduler', $throwable, [ 'method' => 'clear', 'hook' => $hook ]);
        }
    }

    /**
     * Helper: Maps raw seconds to standard WordPress schedule keys.
     * This avoids the need to dynamically register custom intervals.
     */
    private static function get_wp_schedule_key(int $seconds): ?string
    {
        // 1. Check standard WP defaults
        $defaults = [
            'hourly'     => 3600,
            'twicedaily' => 43200,
            'daily'      => 86400,
            'weekly'     => 604800,
        ];

        // Exact match check
        if ($key = array_search($seconds, $defaults, true)) {
            return $key;
        }

        // 2. Check any custom schedules added by other plugins/themes
        $schedules = \wp_get_schedules();
        foreach ($schedules as $key => $data) {
            if (isset($data['interval']) && (int)$data['interval'] === $seconds) {
                return $key;
            }
        }

        return null;
    }
}
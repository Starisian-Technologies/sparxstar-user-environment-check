<?php
/**
 * Handles scheduled events with a fallback system.
 *
 * Prioritizes Action Scheduler if available, otherwise falls back to WP-Cron.
 */
declare(strict_types=1);

namespace Starisian\SparxstarUEC\cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An environment-aware scheduler that uses the best available system.
 */
final class SparxstarUECScheduler {

	/**
	 * A unique key for our custom cron schedule.
	 */
	private const CRON_SCHEDULE_KEY = 'sparxstar_uec_custom_interval';

	/**
	 * Schedule a recurring event.
	 *
	 * @param string $hook The hook to trigger.
	 * @param int    $interval_in_seconds The recurrence interval in seconds.
	 * @param array  $args Arguments to pass to the hook's callback function.
	 */
	public static function schedule_recurring( string $hook, int $interval_in_seconds, array $args = array() ): void {
		// 1. Prioritize Action Scheduler
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! \as_next_scheduled_action( $hook, $args ) ) {
				// Action Scheduler uses the interval directly.
				\as_schedule_recurring_action( time(), $interval_in_seconds, $hook, $args );
			}
			return;
		}

		// 2. Fallback to WP-Cron
		if ( function_exists( 'wp_schedule_event' ) ) {
			// WP-Cron needs a named schedule. We'll create one if our interval doesn't match an existing one.
			self::add_custom_cron_schedule( $interval_in_seconds );

			if ( ! \wp_next_scheduled( $hook, $args ) ) {
				\wp_schedule_event( time(), self::CRON_SCHEDULE_KEY, $hook, $args );
			}
			return;
		}

		// 3. No scheduler available. Do nothing.
		// It's better not to schedule than to use an unreliable fallback.
	}

	/**
	 * Clear any scheduled actions for a given hook.
	 *
	 * @param string $hook The hook to clear.
	 * @param array  $args Optional. Arguments to match for clearing.
	 */
	public static function clear( string $hook, array $args = array() ): void {
		// 1. Try to clear from Action Scheduler
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// as_unschedule_all_actions is the most reliable way to clear all instances.
			\as_unschedule_all_actions( $hook, $args );
		}

		// 2. Also clear from WP-Cron to be safe
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			// We get the timestamp of the next event and unschedule it.
			// This is more reliable for clearing all instances.
			$timestamp = wp_next_scheduled( $hook, $args );
			while ( $timestamp ) {
				\wp_unschedule_event( $timestamp, $hook, $args );
				$timestamp = \wp_next_scheduled( $hook, $args );
			}
		}
	}

	/**
	 * Adds a custom interval to WP-Cron's schedule list if it doesn't exist.
	 *
	 * @param int $interval_in_seconds The interval in seconds.
	 */
	private static function add_custom_cron_schedule( int $interval_in_seconds ): void {
		add_filter(
			'cron_schedules',
			function ( $schedules ) use ( $interval_in_seconds ) {
				// Only add our custom schedule if it's not already there.
				if ( ! isset( $schedules[ self::CRON_SCHEDULE_KEY ] ) ) {
					$schedules[ self::CRON_SCHEDULE_KEY ] = array(
						'interval' => $interval_in_seconds,
						'display'  => sprintf( 'Every %d seconds', $interval_in_seconds ),
					);
				}
				return $schedules;
			}
		);
	}
}

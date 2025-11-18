<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Unified scheduler that prioritizes Action Scheduler and falls back to WP-Cron.
 *
 * @package Starisian\SparxstarUEC\cron
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SparxstarUECScheduler {

	private const CRON_SCHEDULE_KEY = 'sparxstar_uec_custom_interval';

	/**
	 * Schedule a recurring event safely.
	 */
	public static function schedule_recurring( string $hook, int $interval_in_seconds, array $args = [] ): void {
		try {
			// 1. Action Scheduler (preferred)
			if ( function_exists( 'as_schedule_recurring_action' ) ) {
				if ( ! \as_next_scheduled_action( $hook, $args ) ) {
					\as_schedule_recurring_action( time(), $interval_in_seconds, $hook, $args );
				}
				return;
			}

			// 2. Fallback to WP-Cron
			self::register_custom_interval( $interval_in_seconds );

			if ( ! \wp_next_scheduled( $hook, $args ) ) {
				\wp_schedule_event( time(), self::CRON_SCHEDULE_KEY, $hook, $args );
			}
		} catch ( \Throwable $e ) {
			StarLogger::error( 'SparxstarUECScheduler', $e, array( 'method' => 'schedule_recurring', 'hook' => $hook ) );
		}
	}

	/**
	 * Clear all queued instances of a hook.
	 */
	public static function clear( string $hook, array $args = [] ): void {
		try {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				\as_unschedule_all_actions( $hook, $args );
			}

			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				$timestamp = \wp_next_scheduled( $hook, $args );
				while ( $timestamp ) {
					\wp_unschedule_event( $timestamp, $hook, $args );
					$timestamp = \wp_next_scheduled( $hook, $args );
				}
			}
		} catch ( \Throwable $e ) {
			StarLogger::error( 'SparxstarUECScheduler', $e, array( 'method' => 'clear', 'hook' => $hook ) );
		}
	}

	/**
	 * Register a custom WP-Cron interval globally so it’s available before scheduling.
	 */
	private static function register_custom_interval( int $interval_in_seconds ): void {
		try {
			add_filter(
				'cron_schedules',
				static function ( array $schedules ) use ( $interval_in_seconds ): array {
					if ( ! isset( $schedules[ self::CRON_SCHEDULE_KEY ] ) ) {
						$schedules[ self::CRON_SCHEDULE_KEY ] = [
							'interval' => max( 60, $interval_in_seconds ), // never less than a minute
							'display'  => sprintf( 'Every %d seconds (Sparxstar UEC)', $interval_in_seconds ),
						];
					}
					return $schedules;
				},
				10,
				1
			);

			// Force WordPress to refresh its cached cron schedules immediately.
			wp_get_schedules();
		} catch ( \Throwable $e ) {
			StarLogger::error( 'SparxstarUECScheduler', $e, array( 'method' => 'register_custom_interval', 'interval' => $interval_in_seconds ) );
		}
	}
}

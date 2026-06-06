<?php

namespace ActiveLayer\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;

/**
 * Automatically deletes submissions older than the configured retention period.
 *
 * Schedules a daily Action Scheduler job to prune the submissions table based
 * on the retention_days setting. When retention is set to 0 (Never), the job
 * runs but performs no deletions.
 *
 * @since 1.1.0
 */
class SubmissionCleanup {

	/**
	 * Action Scheduler hook name.
	 *
	 * @since 1.1.0
	 */
	private const HOOK = 'activelayer_cleanup_submissions';

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 */
	public static function init(): void {

		self::hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.1.0
	 */
	private static function hooks(): void {

		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * Ensure the recurring cleanup action is scheduled.
	 *
	 * @since 1.1.0
	 */
	public static function maybe_schedule(): void {

		if ( ! QueueManager::is_available() ) {
			return;
		}

		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::HOOK, [], 'activelayer' ) ) {
			return;
		}

		as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, [], 'activelayer' );
	}

	/**
	 * Delete submissions older than the configured retention period.
	 *
	 * Reads the retention_days setting and prunes records accordingly. When
	 * retention is set to 0 (Never), this method returns early without
	 * performing any deletions.
	 *
	 * @since 1.1.0
	 */
	public static function run(): void {

		$days = SettingsHelper::get_retention_days();

		if ( $days <= 0 ) {
			return;
		}

		$deleted = Storage::get_instance()->delete_older_than( $days );

		if ( $deleted > 0 ) {
			Logger::log(
				"Cleanup deleted {$deleted} submissions older than {$days} days",
				[
					'deleted_count'  => $deleted,
					'retention_days' => $days,
				]
			);
		}
	}

	/**
	 * Cancel the scheduled recurring cleanup action.
	 *
	 * @since 1.1.0
	 */
	public static function unschedule(): void {

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, [], 'activelayer' );
	}
}

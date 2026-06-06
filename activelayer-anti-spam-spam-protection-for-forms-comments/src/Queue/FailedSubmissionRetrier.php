<?php

namespace ActiveLayer\Queue;

use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retry failed submissions via Action Scheduler.
 *
 * @since 1.0.0
 */
class FailedSubmissionRetrier {

	/**
	 * Action Scheduler hook name.
	 *
	 * @since 1.0.0
	 */
	private const HOOK = 'activelayer_retry_failed_submissions';

	/**
	 * Default batch size for retries.
	 *
	 * @since 1.0.0
	 */
	private const BATCH_LIMIT = 50;

	/**
	 * Maximum retry attempts per submission.
	 *
	 * @since 1.0.0
	 */
	private const MAX_RETRIES = 5;

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init(): void {

		self::hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private static function hooks(): void {

		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( self::HOOK, [ __CLASS__, 'retry_failed_submissions' ] );
	}

	/**
	 * Ensure the recurring retry action is scheduled.
	 *
	 * @since 1.0.0
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

		/**
		 * Filter the retry interval.
		 *
		 * @since 1.0.0
		 *
		 * @param int $interval Interval in seconds.
		 */
		$interval = (int) apply_filters( 'activelayer_retry_failed_interval', DAY_IN_SECONDS );

		if ( $interval <= 0 ) {
			$interval = DAY_IN_SECONDS;
		}

		/**
		 * Filter the initial delay before the first retry sweep.
		 *
		 * @since 1.0.0
		 *
		 * @param int $delay Delay in seconds.
		 */
		$start_delay = (int) apply_filters( 'activelayer_retry_failed_start_delay', HOUR_IN_SECONDS );

		as_schedule_recurring_action(
			time() + max( 300, $start_delay ),
			$interval,
			self::HOOK,
			[],
			'activelayer'
		);
	}

	/**
	 * Handle retrying failed submissions.
	 *
	 * @since 1.0.0
	 */
	public static function retry_failed_submissions(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! QueueManager::is_available() ) {
			return;
		}

		// Skip retries when API quota is exhausted.
		if ( UpgradeHelper::is_quota_exhausted_cached() ) {
			Logger::log( 'Quota exhausted - skipping retry sweep' );

			return;
		}

		/**
		 * Filter the maximum allowed retries.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_retries Maximum retries.
		 */
		$max_retries = (int) apply_filters( 'activelayer_retry_failed_max_attempts', self::MAX_RETRIES );

		/**
		 * Filter the batch size for retry processing.
		 *
		 * @since 1.0.0
		 *
		 * @param int $batch_limit Batch size.
		 */
		$batch_limit = (int) apply_filters( 'activelayer_retry_failed_batch_limit', self::BATCH_LIMIT );

		$storage     = Storage::get_instance();
		$submissions = $storage->get_failed_submissions_for_retry( $batch_limit, $max_retries );

		if ( empty( $submissions ) ) {
			return;
		}

		foreach ( $submissions as $submission ) {
			self::maybe_retry_submission( $submission, $max_retries );
		}
	}

	/**
	 * Retry a single submission when eligible.
	 *
	 * @since 1.0.0
	 *
	 * @param array $submission  Submission data.
	 * @param int   $max_retries Maximum retries allowed.
	 */
	private static function maybe_retry_submission( array $submission, int $max_retries ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$retry_count = (int) ( $submission['retry_count'] ?? 0 );

		if ( $retry_count >= $max_retries ) {
			return;
		}

		$submission_id = (string) ( $submission['id'] ?? '' );

		if ( empty( $submission_id ) ) {
			return;
		}

		$storage = Storage::get_instance();
		$reset   = $storage->reset_for_retry( $submission_id );

		if ( ! $reset ) {
			Logger::log(
				'Retry skipped - could not reset submission status',
				[
					'submission_id' => $submission_id,
				]
			);

			return;
		}

		$queued = QueueManager::queue( $submission_id );

		if ( $queued ) {
			Logger::log(
				'Retry queued for failed submission',
				[
					'submission_id' => $submission_id,
					'retry_count'   => $retry_count + 1,
				]
			);

			return;
		}

		// If enqueue failed, set status back to failed without incrementing.
		$storage->update_status(
			$submission_id,
			'failed',
			[
				'retry_count' => $retry_count,
			]
		);

		Logger::log(
			'Retry enqueue failed - submission left as failed',
			[
				'submission_id' => $submission_id,
			]
		);
	}
}

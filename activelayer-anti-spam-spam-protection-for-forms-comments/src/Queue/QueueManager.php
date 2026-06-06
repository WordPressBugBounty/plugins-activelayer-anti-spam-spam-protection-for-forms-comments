<?php

namespace ActiveLayer\Queue;

use ActiveLayer\ActionScheduler\ActionSchedulerLoader;
use ActiveLayer\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple Queue Manager for Anti-Spam Plugin.
 *
 * Simplified queue implementation using Action Scheduler - MVP approach.
 *
 * @since 1.0.0
 */
class QueueManager {

	/**
	 * Action hook for processing submissions.
	 *
	 * @since 1.0.0
	 */
	public const HOOK_PROCESS = 'activelayer_process_submission';

	/**
	 * Action hook for processing feedback.
	 *
	 * @since 1.1.0
	 */
	public const HOOK_FEEDBACK = 'activelayer_send_feedback';

	/**
	 * Delay in seconds before sending feedback to API.
	 *
	 * Allows time for the local status update to settle before notifying the API.
	 *
	 * @since 1.1.0
	 */
	private const FEEDBACK_DELAY_SECONDS = 30;

	/**
	 * Initialize queue manager.
	 *
	 * @since 1.0.0
	 */
	public static function init(): void {

		self::hooks();
		QueueWatchdog::init();
		FailedSubmissionRetrier::init();
		SubmissionCleanup::init();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private static function hooks(): void {

		add_action( self::HOOK_PROCESS, [ __CLASS__, 'process_submission' ], 10, 1 );
		add_action( self::HOOK_FEEDBACK, [ __CLASS__, 'process_feedback' ], 10, 1 );
	}

	/**
	 * Queue a form submission for processing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID from storage.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function queue( string $submission_id ): bool {

		if ( ! self::is_available() ) {
			Logger::log( 'Queue unavailable - Action Scheduler not ready', [ 'submission_id' => $submission_id ] );

			return false;
		}

		$job_id = as_enqueue_async_action(
			self::HOOK_PROCESS,
			[ $submission_id ],
			'activelayer'
		);

		$success = ! is_wp_error( $job_id ) && ! empty( $job_id );

		if ( $success ) {
			Logger::log(
				'Submission queued',
				[
					'submission_id' => $submission_id,
					'job_id'        => $job_id,
				]
			);
		} else {
			$log_context = [ 'submission_id' => $submission_id ];

			if ( is_wp_error( $job_id ) ) {
				$log_context['error'] = $job_id->get_error_message();
			}

			Logger::log( 'Failed to queue submission', $log_context );
		}

		return $success;
	}

	/**
	 * Process submission job (called by Action Scheduler).
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID from storage.
	 */
	public static function process_submission( string $submission_id ): void {

		$worker = new SubmissionWorker();

		$worker->process( $submission_id );
	}

	/**
	 * Queue feedback to API for user correction.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id  Submission ID.
	 * @param string $correct_status Correct status according to user.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function queue_feedback( string $submission_id, string $correct_status ): bool {

		if ( ! self::is_available() ) {
			Logger::log( 'Queue unavailable - cannot queue feedback', [ 'submission_id' => $submission_id ] );

			return false;
		}

		$job_id = as_schedule_single_action(
			time() + self::FEEDBACK_DELAY_SECONDS,
			self::HOOK_FEEDBACK,
			[
				[
					'submission_id'  => $submission_id,
					'correct_status' => $correct_status,
				],
			],
			'activelayer'
		);

		$success = ! is_wp_error( $job_id ) && ! empty( $job_id );

		if ( $success ) {
			Logger::log(
				'Feedback queued',
				[
					'submission_id'  => $submission_id,
					'correct_status' => $correct_status,
					'job_id'         => $job_id,
				]
			);
		} else {
			$log_context = [ 'submission_id' => $submission_id ];

			if ( is_wp_error( $job_id ) ) {
				$log_context['error'] = $job_id->get_error_message();
			}

			Logger::log( 'Failed to queue feedback', $log_context );
		}

		return $success;
	}

	/**
	 * Process feedback job (called by Action Scheduler).
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Feedback arguments (submission_id, correct_status).
	 */
	public static function process_feedback( array $args ): void {

		$worker = new FeedbackWorker();

		$worker->process( $args );
	}

	/**
	 * Check if Action Scheduler is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if available, false otherwise.
	 */
	public static function is_available(): bool {

		return ActionSchedulerLoader::is_available();
	}
}

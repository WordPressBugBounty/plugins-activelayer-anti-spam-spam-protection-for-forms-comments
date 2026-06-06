<?php

namespace ActiveLayer\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Api\ApiClient;
use ActiveLayer\Helpers\DetectionIdResolver;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;
use Exception;

/**
 * Feedback Worker.
 *
 * Processes queued feedback corrections by sending them to the API.
 *
 * @since 1.1.0
 */
class FeedbackWorker {

	/**
	 * Process feedback from queue.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Feedback arguments (submission_id, correct_status).
	 *
	 * @throws Exception If submission not found or feedback processing fails.
	 */
	public function process( array $args ): void {

		QueueWatchdog::mark_queue_healthy();

		if ( ! isset( $args['submission_id'], $args['correct_status'] ) ) {
			Logger::log( 'Feedback processing skipped - missing arguments', [ 'args' => $args ] );

			return;
		}

		try {
			Logger::log(
				'Processing feedback',
				[
					'submission_id'  => $args['submission_id'],
					'correct_status' => $args['correct_status'],
				]
			);
			$this->handle( $args['submission_id'], $args['correct_status'] );
		} catch ( Exception $e ) {
			Logger::log(
				'Feedback processing failed',
				[
					'submission_id' => $args['submission_id'],
					'error'         => $e->getMessage(),
				]
			);

			// Re-throw so Action Scheduler retries the job.
			throw $e;
		}
	}

	/**
	 * Handle feedback processing.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id  Submission ID.
	 * @param string $correct_status Correct status (clean|spam).
	 *
	 * @throws Exception If submission not found or processing fails.
	 */
	private function handle( string $submission_id, string $correct_status ): void {

		$storage    = Storage::get_instance();
		$submission = $storage->find( $submission_id );

		if ( ! $submission ) {
			throw new Exception( sprintf( 'Submission %s not found', esc_html( $submission_id ) ) );
		}

		$api_client = new ApiClient();

		$detection_id = $this->resolve_detection_id( $api_client, $submission );

		if ( $detection_id === '' ) {
			return;
		}

		$this->send_and_log( $api_client, $submission_id, $correct_status, $detection_id );
	}

	/**
	 * Resolve the detection ID for feedback, checking API config.
	 *
	 * @since 1.1.0
	 *
	 * @param ApiClient $api_client API client instance.
	 * @param array     $submission Submission data.
	 *
	 * @return string Detection ID or empty string when feedback cannot be sent.
	 */
	private function resolve_detection_id( ApiClient $api_client, array $submission ): string {

		$config = $api_client->get_config_status();

		if ( ! ( $config['configured'] ?? false ) ) {
			return '';
		}

		return DetectionIdResolver::resolve( $submission['api_response'] ?? null );
	}

	/**
	 * Send feedback to the API and log the result.
	 *
	 * @since 1.1.0
	 *
	 * @param ApiClient $api_client     API client instance.
	 * @param string    $submission_id  Submission ID.
	 * @param string    $correct_status Correct status (clean|spam).
	 * @param string    $detection_id   Detection ID from original API response.
	 */
	private function send_and_log( ApiClient $api_client, string $submission_id, string $correct_status, string $detection_id ): void {

		$result = $api_client->send_feedback( $correct_status, $detection_id );

		if ( ! $result['success'] ) {
			Logger::log(
				'Feedback API call failed',
				[
					'submission_id' => $submission_id,
					'error'         => $result['error'] ?? 'unknown',
				]
			);

			return;
		}

		Logger::log(
			'Feedback sent successfully',
			[
				'submission_id'  => $submission_id,
				'correct_status' => $correct_status,
				'detection_id'   => $detection_id,
			]
		);
	}
}

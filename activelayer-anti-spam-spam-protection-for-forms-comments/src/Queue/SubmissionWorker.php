<?php

namespace ActiveLayer\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Api\ApiClient;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;
use ActiveLayer\Subscription\SubscriptionStats;
use Exception;

/**
 * Submission Worker.
 *
 * Processes queued form submissions by calling the API and updating storage.
 *
 * @since 1.1.0
 */
class SubmissionWorker {

	/**
	 * Process submission from queue.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID from storage.
	 */
	public function process( string $submission_id ): void {

		QueueWatchdog::mark_queue_healthy();

		try {
			Logger::log( 'Processing submission', [ 'submission_id' => $submission_id ] );
			$this->handle( $submission_id );
		} catch ( Exception $e ) {
			Logger::log(
				'Submission processing failed',
				[
					'submission_id' => $submission_id,
					'error'         => $e->getMessage(),
				]
			);
			$this->handle_error( $submission_id, $e->getMessage() );
		}
	}

	/**
	 * Handle submission processing.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @throws Exception If API is not configured or processing fails.
	 */
	private function handle( string $submission_id ): void {

		$storage    = Storage::get_instance();
		$api_client = new ApiClient();

		// 1. Atomically claim this submission to prevent duplicate processing.
		if ( ! $storage->claim_for_processing( $submission_id ) ) {
			Logger::log(
				'Submission already claimed or processed',
				[
					'submission_id' => $submission_id,
				]
			);

			return;
		}

		// 2. Fetch submission data for processing.
		$submission = $storage->find( $submission_id );

		if ( ! $submission ) {
			Logger::log(
				'Submission not found after claim — likely deleted concurrently',
				[
					'submission_id' => $submission_id,
				]
			);

			return;
		}

		// 3. Check API configuration.
		$config = $api_client->get_config_status();

		if ( ! ( $config['configured'] ?? false ) ) {
			throw new Exception( 'API not configured' );
		}

		// 4. Call appropriate API endpoint.
		$api_result = $this->call_api( $submission, $api_client );

		// 5. Update submission with results.
		$this->update_status( $submission_id, $submission, $api_result, $storage );

		Logger::log(
			'Submission processed successfully',
			[
				'submission_id' => $submission_id,
				'verdict'       => $api_result['verdict'] ?? 'unknown',
			]
		);

		// Schedule subscription stats refresh.
		SubscriptionStats::get_instance()->schedule_refresh();
	}

	/**
	 * Call appropriate API endpoint based on submission type.
	 *
	 * @since 1.1.0
	 * @since 1.5.0 Route EDD reviews through the comment-specific endpoint.
	 *
	 * @param array     $submission Submission data.
	 * @param ApiClient $api_client API client instance.
	 *
	 * @return array API response.
	 */
	private function call_api( array $submission, ApiClient $api_client ): array {

		$submission_data = $submission['form_data'];

		// Pass original submission time for accurate rate/burst analysis.
		if ( ! empty( $submission['created_at'] ) ) {
			$submission_data['created_at'] = $submission['created_at'];
		}

		// Use comment-specific endpoint for WordPress comments and product reviews
		// (WooCommerce + EDD), all of which are stored as WordPress comments.
		$comment_providers = [ 'wp_comments', 'wc_reviews', 'edd_reviews' ];

		if ( isset( $submission['provider'] ) && in_array( $submission['provider'], $comment_providers, true ) ) {
			return $api_client->check_comment( $submission_data );
		}

		// Use default endpoint for all other forms.
		return $api_client->check_submission( $submission_data );
	}

	/**
	 * Update submission status based on API result.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $submission_id Submission ID.
	 * @param array   $submission    Current submission data.
	 * @param array   $api_result    API response.
	 * @param Storage $storage       Storage instance.
	 */
	private function update_status( string $submission_id, array $submission, array $api_result, Storage $storage ): void {

		if ( $api_result['success'] ) {
			// API call successful.
			$new_status = $api_result['verdict'] === 'spam' ? 'spam' : 'clean';

			$update_data = [
				'verdict'      => $api_result['verdict'],
				'api_response' => $api_result['raw_response'],
				'processed_at' => current_time( 'mysql' ),
			];

			$storage->update_status( $submission_id, $new_status, $update_data );

			/**
			 * Fires when a verdict is received from the API.
			 *
			 * @since 1.0.0
			 *
			 * @param string $submission_id Submission ID.
			 * @param string $verdict       Verdict (spam|clean).
			 * @param array  $submission    Submission data.
			 */
			do_action( 'activelayer_queue_worker_verdict_received', $submission_id, $api_result['verdict'], $submission );

			return;
		}

		// API call failed - notify integrations before marking failure.
		$error_message       = $api_result['error'] ?? ( $api_result['message'] ?? 'api_request_failed' );
		$submission['error'] = $error_message;

		/**
		 * Fires when a submission processing fails.
		 *
		 * @since 1.0.0
		 *
		 * @param string $submission_id Submission ID.
		 * @param array  $submission    Submission data with error.
		 */
		do_action( 'activelayer_queue_worker_submission_failed', $submission_id, $submission );

		$storage->mark_failed(
			$submission_id,
			[
				'api_response' => [
					'success' => false,
					'error'   => $error_message,
				],
			]
		);

		// Refresh stats so quota guards activate sooner.
		SubscriptionStats::get_instance()->schedule_refresh();
	}

	/**
	 * Handle processing error.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $error_message Error message.
	 */
	private function handle_error( string $submission_id, string $error_message ): void {

		$storage    = Storage::get_instance();
		$submission = $storage->find( $submission_id );

		// Submission may have been deleted concurrently.
		if ( ! $submission ) {
			Logger::log(
				'Cannot mark failed — submission not found',
				[
					'submission_id' => $submission_id,
					'error'         => $error_message,
				]
			);

			return;
		}

		$submission['error'] = $error_message;

		/**
		 * Fires when a submission processing fails.
		 *
		 * @since 1.1.0
		 *
		 * @param string $submission_id Submission ID.
		 * @param array  $submission    Submission data with error.
		 */
		do_action( 'activelayer_queue_worker_submission_failed', $submission_id, $submission );

		$storage->mark_failed(
			$submission_id,
			[
				'api_response' => [
					'success' => false,
					'error'   => $error_message,
				],
			]
		);
	}
}

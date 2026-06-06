<?php

namespace ActiveLayer\Integrations\SureForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use Exception;

/**
 * Synchronous submission handler for SureForms.
 *
 * Hooked to srfm_before_submission action. Runs the sync spam check
 * and blocks spam via wp_send_json_error().
 *
 * @since 1.1.0
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.1.0
	 *
	 * @var SureFormsIntegration
	 */
	private $integration;

	/**
	 * Set up the submission handler.
	 *
	 * @since 1.1.0
	 *
	 * @param SureFormsIntegration $integration Parent integration reference.
	 */
	public function __construct( SureFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Entry point from SureForms before submission.
	 *
	 * Hooked to srfm_before_submission at priority 5. This is an action
	 * hook — no return value needed.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form_before_submission_data Submission data with form_id and data keys.
	 */
	public function handle_submission( array $form_before_submission_data ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$should_abort = false;

		try {
			if ( ! $this->integration->is_enabled() ) {
				return;
			}

			$form_id = (int) ( $form_before_submission_data['form_id'] ?? 0 );

			if ( $form_id <= 0 ) {
				return;
			}

			if ( ! $this->is_form_protected( $form_id ) ) {
				return;
			}

			if ( ! SettingsHelper::has_api_key() ) {
				return;
			}

			$should_abort = $this->should_block_submission( $form_id );
		} catch ( Exception $exception ) {
			Logger::log(
				'SureForms sync mode: unexpected error',
				[
					'form_id' => (int) ( $form_before_submission_data['form_id'] ?? 0 ),
					'error'   => $exception->getMessage(),
				]
			);
		} finally {
			// Always clear cached field data to prevent stale state.
			$this->integration->clear_cached_field_data();
		}

		// Abort outside try/catch — in production wp_send_json_error() calls die().
		if ( $should_abort ) {
			$this->abort_submission();
		}
	}

	/**
	 * Run synchronous verification and determine if submission should be blocked.
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return bool True if the submission should be aborted (spam detected).
	 */
	private function should_block_submission( int $form_id ): bool {

		$meta = $this->integration->get_form_meta( $form_id );

		// Pass cached field data as raw_data. normalize_form_data() reads from
		// $this->cached_field_data (populated by the filter hook) and ignores
		// $raw_data when cache is set. The array is passed here only as a
		// fallback if the cache were ever null at this point.
		$cached_data = $this->integration->get_cached_field_data() ?? [];

		$result = $this->integration->process_submission_synchronously( $cached_data, $meta );

		// Safe-by-default: on any failure, allow submission through.
		if ( empty( $result['success'] ) ) {
			return false;
		}

		$verdict = $result['verdict'] ?? 'clean';

		return $verdict === 'spam';
	}

	/**
	 * Abort the submission by sending a JSON error response.
	 *
	 * Extracted to a separate method to enable test overriding,
	 * since wp_send_json_error() calls die() and terminates PHP execution.
	 *
	 * @since 1.1.0
	 */
	protected function abort_submission(): void {

		/**
		 * Filter the spam block message shown to SureForms users.
		 *
		 * @since 1.1.0
		 *
		 * @param string $message Default spam block message.
		 *
		 * @return string
		 */
		$message = apply_filters(
			'activelayer_integrations_sureforms_submission_handler_abort_submission_message',
			$this->integration->get_sync_block_message()
		);

		wp_send_json_error( [ 'message' => wp_kses_post( $message ) ] );
	}

	/**
	 * Check whether ActiveLayer is enabled for the given form.
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return bool True when protection is active.
	 */
	private function is_form_protected( int $form_id ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_id );

		return ! empty( $form_settings['enabled'] );
	}
}

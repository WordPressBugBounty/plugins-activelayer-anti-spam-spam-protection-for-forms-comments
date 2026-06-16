<?php

namespace ActiveLayer\Integrations\WSForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use Exception;

/**
 * Synchronous submission handler for WS Form.
 *
 * Hooked to the wsf_submit_validate filter. Runs the sync spam check and
 * blocks spam by pushing a `message` error action into the returned
 * validation-actions array (WS Form then aborts before writing the entry
 * or running actions such as Send Email).
 *
 * @since 1.4.0
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.4.0
	 *
	 * @var WSFormIntegration
	 */
	private $integration;

	/**
	 * Set up the submission handler.
	 *
	 * @since 1.4.0
	 *
	 * @param WSFormIntegration $integration Parent integration reference.
	 */
	public function __construct( WSFormIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Filter callback for wsf_submit_validate.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed  $errors    Existing validation error actions (array).
	 * @param string $post_mode WS Form post mode ('submit' | 'save' | 'action').
	 * @param mixed  $submit    WS_Form_Submit instance.
	 *
	 * @return array Validation error actions, possibly with a block action appended.
	 */
	public function handle_validate( $errors, $post_mode, $submit ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ! is_array( $errors ) ) {
			$errors = [];
		}

		$form_id = 0;

		try {
			// Guard real submissions and direct action runs, never draft saves.
			// 'action' is a spoofable public post mode that bypasses conditional
			// logic and still runs Send Email and the entry save, so it must be
			// spam-checked like 'submit'.
			if ( ! in_array( $post_mode, [ 'submit', 'action' ], true ) ) {
				return $errors;
			}

			if ( ! $this->integration->is_enabled() ) {
				return $errors;
			}

			if ( ! SettingsHelper::has_api_key() ) {
				return $errors;
			}

			$form_id = ( is_object( $submit ) && isset( $submit->form_id ) ) ? (int) $submit->form_id : 0;

			if ( $form_id <= 0 || ! $this->is_form_protected( $form_id ) ) {
				return $errors;
			}

			$meta     = $this->integration->get_form_meta( $submit );
			$raw_data = $this->integration->extract_fields_by_type( $submit );

			$result = $this->integration->process_submission_synchronously( $raw_data, $meta );

			// Safe-by-default: on any failure, allow the submission through.
			if ( empty( $result['success'] ) ) {
				return $errors;
			}

			if ( ( $result['verdict'] ?? 'clean' ) === 'spam' ) {
				$errors[] = $this->build_block_error();
			}
		} catch ( Exception $exception ) {
			Logger::log(
				'WS Form sync validate: unexpected error',
				[
					'form_id' => $form_id,
					'error'   => $exception->getMessage(),
				]
			);
		}

		return $errors;
	}

	/**
	 * Build the WS Form `message` error action that blocks the submission.
	 *
	 * @since 1.4.0
	 *
	 * @return array WS Form validation action.
	 */
	private function build_block_error(): array {

		/**
		 * Filter the spam block message shown to WS Form users.
		 *
		 * @since 1.4.0
		 *
		 * @param string $message Default spam block message.
		 *
		 * @return string
		 */
		$message = apply_filters(
			'activelayer_integrations_wsform_submission_handler_block_message',
			$this->integration->get_sync_block_message()
		);

		return [
			'action'  => 'message',
			'message' => wp_kses_post( $message ),
			'type'    => 'danger',
		];
	}

	/**
	 * Check whether ActiveLayer protection is enabled for the given form.
	 *
	 * @since 1.4.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return bool
	 */
	private function is_form_protected( int $form_id ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_id );

		return ! empty( $form_settings['enabled'] );
	}
}

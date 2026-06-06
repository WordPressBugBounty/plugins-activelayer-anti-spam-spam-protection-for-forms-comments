<?php

namespace ActiveLayer\Integrations\ElementorForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Logger\Logger;
use ElementorPro\Modules\Forms\Classes\Ajax_Handler;
use ElementorPro\Modules\Forms\Classes\Form_Record;
use Exception;

/**
 * Synchronous submission handler for Elementor Forms.
 *
 * Hooks into elementor_pro/forms/validation to check submissions
 * before form actions execute. Spam is rejected via the AJAX handler.
 *
 * @since 1.1.0
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.1.0
	 *
	 * @var ElementorFormsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param ElementorFormsIntegration $integration Parent integration reference.
	 */
	public function __construct( ElementorFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Handle Elementor Forms validation hook.
	 *
	 * Called on `elementor_pro/forms/validation`. Runs a synchronous spam
	 * check via the ActiveLayer API. On spam verdict in blocking mode,
	 * adds an error message to the AJAX handler which prevents form
	 * actions from executing.
	 *
	 * @since 1.1.0
	 *
	 * @param Form_Record  $record       Elementor form record.
	 * @param Ajax_Handler $ajax_handler Elementor AJAX handler.
	 */
	public function handle_validation( $record, $ajax_handler ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		try {
			$this->process_validation( $record, $ajax_handler );
		} catch ( Exception $exception ) {
			Logger::log(
				'Elementor Forms sync mode: unexpected error',
				[
					'error' => $exception->getMessage(),
				]
			);
		}
	}

	/**
	 * Run the validation check logic.
	 *
	 * Extracted from handle_validation() to keep the try/catch wrapper clean.
	 *
	 * @since 1.1.0
	 *
	 * @param Form_Record  $record       Elementor form record.
	 * @param Ajax_Handler $ajax_handler Elementor AJAX handler.
	 */
	private function process_validation( $record, $ajax_handler ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		$element_id = $record->get_form_settings( 'id' );
		$form_id    = $this->integration->get_admin_settings()->element_id_to_int( (string) $element_id );

		if ( $form_id <= 0 ) {
			return;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		if ( UpgradeHelper::is_quota_exhausted_cached() ) {
			Logger::log(
				'Quota exhausted - skipping Elementor Forms submission',
				[ 'form_id' => $form_id ]
			);

			return;
		}

		$meta = $this->integration->get_form_meta( $record );

		$raw_data = [
			'record' => $record,
			'form'   => [],
		];

		$result = $this->integration->process_submission_synchronously( $raw_data, $meta );

		if ( ! empty( $result['success'] ) && ( $result['verdict'] ?? 'clean' ) === 'spam' ) {
			/**
			 * Filter the spam block message shown to Elementor Forms users.
			 *
			 * @since 1.1.0
			 *
			 * @param string $message Default spam block message.
			 */
			$message = apply_filters(
				'activelayer_integrations_elementor_forms_submission_handler_block_message',
				$this->integration->get_sync_block_message()
			);

			$ajax_handler->add_error_message( $message );

			// Field-level error is required to make validate() return false,
			// which prevents form actions (save-to-database, email) from running.
			$ajax_handler->add_error( 'activelayer_spam', $message );
		}
	}
}

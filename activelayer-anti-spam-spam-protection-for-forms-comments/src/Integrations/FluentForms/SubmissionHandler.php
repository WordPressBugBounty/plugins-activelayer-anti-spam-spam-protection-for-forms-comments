<?php

namespace ActiveLayer\Integrations\FluentForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use Exception;

/**
 * Synchronous submission handler for Fluent Forms.
 *
 * Behaviour depends on the form's entry storage setting:
 *
 * - Storage ON:  spam is allowed through so Fluent Forms saves the entry
 *                (marked as spam), but email notifications are suppressed.
 * - Storage OFF: spam is rejected (form blocked with 422).
 *
 * @since 1.1.0
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.1.0
	 *
	 * @var FluentFormsIntegration
	 */
	private $integration;

	/**
	 * Form ID that received a spam verdict in save mode.
	 *
	 * When set, the integration suppresses email notifications
	 * for this form via the `pre_wp_mail` filter.
	 *
	 * @since 1.1.0
	 *
	 * @var int|null
	 */
	private $spam_save_form_id = null;

	/**
	 * Set up the submission handler.
	 *
	 * @since 1.1.0
	 *
	 * @param FluentFormsIntegration $integration Parent integration reference.
	 */
	public function __construct( FluentFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Entry point from Fluent Forms before submission insert.
	 *
	 * Hooked to fluentform/before_insert_submission at priority 5.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $insert_data Data about to be inserted.
	 * @param array  $data        Raw form submission data.
	 * @param object $form        Fluent Forms form object.
	 */
	public function handle_submission( array $insert_data, array $data, $form ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed

		$action = 'allow';

		try {
			if ( ! $this->integration->is_enabled() ) {
				return;
			}

			$form_id = isset( $form->id ) ? (int) $form->id : 0;

			if ( ! $this->is_form_protected( $form_id ) ) {
				return;
			}

			if ( ! SettingsHelper::has_api_key() ) {
				return;
			}

			$action = $this->determine_spam_action( $data, $form, $form_id );
		} catch ( Exception $exception ) {
			Logger::log(
				'Fluent Forms sync mode: unexpected error',
				[
					'form_id' => isset( $form->id ) ? (int) $form->id : 0,
					'error'   => $exception->getMessage(),
				]
			);
		}

		if ( $action === 'block' ) {
			// Abort outside try/catch — in production wp_send_json() calls die().
			$this->abort_submission();
		}

		if ( $action === 'save' ) {
			$this->activate_email_suppression();
		}
	}

	/**
	 * Run synchronous verification and decide the spam action.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $data    Raw form submission data.
	 * @param object $form    Fluent Forms form object.
	 * @param int    $form_id Form ID.
	 *
	 * @return string 'allow', 'block', or 'save'.
	 */
	private function determine_spam_action( array $data, $form, int $form_id ): string {

		$meta = $this->integration->get_form_meta( $form );

		// Store form reference so normalize_form_data() can access field definitions.
		$this->integration->set_current_form( $form );

		try {
			$result = $this->integration->process_submission_synchronously( $data, $meta );
		} finally {
			// Always clear form reference, even on exception.
			$this->integration->set_current_form( null );
		}

		// Safe-by-default: on any failure, allow submission through.
		if ( empty( $result['success'] ) ) {
			return 'allow';
		}

		$verdict = $result['verdict'] ?? 'clean';

		if ( $verdict !== 'spam' ) {
			return 'allow';
		}

		// Spam verdict: decide based on entry storage setting.
		if ( $this->integration->has_entry_storage( $form_id ) ) {
			$this->spam_save_form_id = $form_id;

			Logger::log(
				'Fluent Forms sync: spam in save mode — allowing entry, suppressing email',
				[
					'provider'      => $this->integration->get_slug(),
					'form_id'       => $form_id,
					'submission_id' => $result['submission_id'] ?? '',
				]
			);

			return 'save';
		}

		return 'block';
	}

	/**
	 * Abort the submission by sending a JSON error response.
	 *
	 * Extracted to a separate method to enable test overriding,
	 * since wp_send_json() calls die() and terminates PHP execution.
	 *
	 * Sends `errors` as a plain string so the Fluent Forms frontend
	 * routes it through `j({error:[msg]})` and does not touch
	 * `settings.layout.errorMessagePlacement` — which can be missing
	 * on imported/legacy forms and throws a TypeError otherwise.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Send `errors` as a string to avoid
	 *        a Fluent Forms frontend TypeError on forms without a
	 *        `formSettings.layout` definition.
	 */
	protected function abort_submission(): void {

		/**
		 * Filter the spam block message shown to Fluent Forms users.
		 *
		 * @since 1.1.0
		 *
		 * @param string $message Default spam block message.
		 *
		 * @return string
		 */
		$message = apply_filters(
			'activelayer_integrations_fluent_forms_submission_handler_abort_submission_message',
			$this->integration->get_sync_block_message()
		);

		wp_send_json(
			[
				'errors' => (string) $message,
			],
			422
		);
	}

	/**
	 * Activate email suppression via the pre_wp_mail filter.
	 *
	 * @since 1.1.0
	 */
	protected function activate_email_suppression(): void { // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Runtime email suppression for sync-save mode.

		add_filter( 'pre_wp_mail', '__return_false' );
	}

	/**
	 * Get the form ID that has a pending spam-save verdict.
	 *
	 * @since 1.1.0
	 *
	 * @return int|null Form ID or null if no spam-save pending.
	 */
	public function get_spam_save_form_id(): ?int {

		return $this->spam_save_form_id;
	}

	/**
	 * Reset synchronous state.
	 *
	 * @since 1.1.0
	 */
	public function reset(): void {

		$this->spam_save_form_id = null;
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

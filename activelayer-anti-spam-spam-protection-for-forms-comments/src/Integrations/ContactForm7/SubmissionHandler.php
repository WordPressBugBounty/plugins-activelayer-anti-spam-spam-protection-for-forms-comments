<?php

namespace ActiveLayer\Integrations\ContactForm7;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use Exception;
use WPCF7_ContactForm;
use WPCF7_Submission;

/**
 * Synchronous submission handler for Contact Form 7.
 *
 * @since 1.0.0
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.0.0
	 *
	 * @var ContactForm7Integration
	 */
	private $integration;

	/**
	 * Set up the submission handler.
	 *
	 * @since 1.0.0
	 *
	 * @param ContactForm7Integration $integration Parent integration reference.
	 */
	public function __construct( ContactForm7Integration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Entry point from CF7 prior to email delivery.
	 *
	 * @since 1.0.0
	 *
	 * @param WPCF7_ContactForm $contact_form Contact form instance.
	 * @param bool              $abort        Abort flag passed by reference.
	 * @param WPCF7_Submission  $submission   Submission wrapper.
	 */
	public function handle_submission( WPCF7_ContactForm $contact_form, bool &$abort, WPCF7_Submission $submission ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		if ( ! $this->is_form_protected( $contact_form ) ) {
			return;
		}

		$global_settings = SettingsHelper::get_global_settings();

		if ( ! SettingsHelper::has_api_key( $global_settings ) ) {
			return;
		}

		try {
			$posted_data = $submission->get_posted_data();

			if ( empty( $posted_data ) ) {
				return;
			}

			$this->handle_sync_submission( $contact_form, $submission, $posted_data, $abort );
		} catch ( Exception $exception ) {
			Logger::log(
				'ContactForm7 sync mode: unexpected error',
				[
					'form_id' => $contact_form->id(),
					'error'   => $exception->getMessage(),
				]
			);
		}
	}

	/**
	 * Execute synchronous verification and update storage/logs.
	 *
	 * @since 1.0.0
	 *
	 * @param WPCF7_ContactForm $contact_form Contact form instance.
	 * @param WPCF7_Submission  $submission   Submission wrapper.
	 * @param array             $posted_data  Raw form payload.
	 * @param bool              $abort        Abort flag.
	 */
	private function handle_sync_submission( WPCF7_ContactForm $contact_form, WPCF7_Submission $submission, array $posted_data, bool &$abort ): void {

		$meta = $this->integration->get_form_meta( $contact_form );

		$raw_data = [
			'posted_data'  => $posted_data,
			'contact_form' => $contact_form,
		];

		$result = $this->integration->process_submission_synchronously( $raw_data, $meta );

		if ( empty( $result['success'] ) || ( $result['verdict'] ?? 'clean' ) !== 'spam' ) {
			return;
		}

		$abort = true;

		$submission->set_status( 'spam' );
		$submission->set_response( $contact_form->message( 'spam' ) );
	}

	/**
	 * Check whether ActiveLayer is enabled for the given form.
	 *
	 * @since 1.0.0
	 *
	 * @param WPCF7_ContactForm $contact_form Contact form instance.
	 *
	 * @return bool True when protection is active.
	 */
	private function is_form_protected( WPCF7_ContactForm $contact_form ): bool {

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $contact_form->id() );

		return ! empty( $form_settings['enabled'] );
	}
}

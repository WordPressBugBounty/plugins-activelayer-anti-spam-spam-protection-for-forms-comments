<?php

namespace ActiveLayer\Integrations\WPForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;

/**
 * WPForms Submission Handler.
 *
 * Handles form submissions and API verdict processing for WPForms.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\WPForms
 */
class SubmissionHandler {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.0.0
	 *
	 * @var WPFormsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param WPFormsIntegration $integration Parent integration instance.
	 */
	public function __construct( WPFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Handle WPForms submission.
	 *
	 * @since 1.0.0
	 *
	 * @param array     $fields    Form fields data.
	 * @param array     $entry     Entry data (unused for wpforms_process_complete).
	 * @param array     $form_data Form configuration.
	 * @param int|mixed $entry_id  WPForms entry ID (can be int or string depending on WPForms version).
	 */
	public function handle_submission( array $fields, array $entry, array $form_data, $entry_id ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Ensure entry_id is an integer.
		$entry_id = (int) $entry_id;

		// Skip if integration is disabled.
		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		// Check if anti-spam is enabled for this form.
		if ( ! $this->integration->is_form_protected( $form_data ) ) {
			return;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_data );
		$tracking_mode = $this->integration->is_tracking_mode_for_form( $form_data );

		$strategy = $this->integration->get_processing_strategy( $form_data );

		if ( $strategy === WPFormsIntegration::STRATEGY_SYNC_SAVE ) {
			$this->handle_sync_with_entries( $fields, $form_data, $entry_id );

			return;
		}

		if ( $strategy === WPFormsIntegration::STRATEGY_SYNC_BLOCK ) {
			// Already handled by maybe_handle_sync_submission().
			return;
		}

		// Check if API key is configured.
		if ( ! SettingsHelper::has_api_key() ) {
			// No API key configured - skip tracking and API calls.
			return;
		}

		try {
			// Get form metadata.
			$meta                  = $this->integration->get_form_meta( $form_data );
			$meta['entry_id']      = $entry_id;
			$meta['tracking_mode'] = $tracking_mode;

			// Process submission.
			$submission_id = $this->integration->process_submission( $fields, $meta );

			if ( ! empty( $meta['queue_failed'] ) ) {
				$this->integration->register_queue_failure( (int) $form_data['id'], $entry_id );

				if ( $entry_id ) {
					wpforms()->obj( 'entry_meta' )->add(
						[
							'entry_id' => $entry_id,
							'form_id'  => $form_data['id'],
							'type'     => 'spam_status',
							'data'     => 'failed',
						]
					);
				}

				return;
			}

			// Store submission ID in entry meta for later reference.
			if ( $submission_id && $entry_id ) {
				wpforms()->obj( 'entry_meta' )->add(
					[
						'entry_id' => $entry_id,
						'form_id'  => $form_data['id'],
						'type'     => 'activelayer_submission_id',
						'data'     => $submission_id,
					]
				);
			}
		} catch ( \Exception $e ) {
			// Silently fail - don't interrupt form submission.
			Logger::log(
				'Failed to queue submission',
				[
					'error'    => $e->getMessage(),
					'form_id'  => $form_data['id'] ?? 0,
					'entry_id' => $entry_id,
				]
			);
		}
	}

	/**
	 * Handle synchronous processing when entries are available.
	 *
	 * Runs a blocking API check after the entry is created, then marks
	 * the entry as spam or allows emails based on the verdict.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields    Form fields data.
	 * @param array $form_data Form configuration.
	 * @param int   $entry_id  WPForms entry ID.
	 */
	private function handle_sync_with_entries( array $fields, array $form_data, int $entry_id ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$tracking_mode = $this->integration->is_tracking_mode_for_form( $form_data );
		$meta          = $this->integration->get_form_meta( $form_data );

		$meta['entry_id']      = $entry_id;
		$meta['tracking_mode'] = $tracking_mode;

		// Tracking mode: queue async, emails already flowing normally.
		if ( $tracking_mode ) {
			try {
				$submission_id = $this->integration->process_submission( $fields, $meta );

				if ( $submission_id && $entry_id ) {
					wpforms()->obj( 'entry_meta' )->add(
						[
							'entry_id' => $entry_id,
							'form_id'  => $form_data['id'],
							'type'     => 'activelayer_submission_id',
							'data'     => $submission_id,
						]
					);
				}
			} catch ( \Exception $e ) {
				Logger::log(
					'WPForms sync+entries tracking error',
					[
						'form_id' => $meta['form_id'] ?? 0,
						'error'   => $e->getMessage(),
					]
				);
			}

			return;
		}

		// Non-tracking: synchronous API check.
		$result        = $this->integration->process_submission_synchronously( $fields, $meta );
		$submission_id = $result['submission_id'] ?? '';
		$verdict       = $result['verdict'] ?? '';

		// Link submission to entry.
		if ( $submission_id && $entry_id ) {
			wpforms()->obj( 'entry_meta' )->add(
				[
					'entry_id' => $entry_id,
					'form_id'  => $form_data['id'],
					'type'     => 'activelayer_submission_id',
					'data'     => $submission_id,
				]
			);
		}

		// Handle API failure: safe-by-default, re-send emails.
		if ( empty( $result['success'] ) ) {
			if ( $submission_id ) {
				$this->integration->resend_clean_emails( $submission_id );
			} else {
				$this->integration->allow_sync_emails();
			}

			if ( $entry_id ) {
				wpforms()->obj( 'entry_meta' )->add(
					[
						'entry_id' => $entry_id,
						'form_id'  => $form_data['id'],
						'type'     => 'spam_status',
						'data'     => 'failed',
					]
				);
			}

			return;
		}

		// Write spam_status meta.
		if ( $entry_id ) {
			wpforms()->obj( 'entry_meta' )->add(
				[
					'entry_id' => $entry_id,
					'form_id'  => $form_data['id'],
					'type'     => 'spam_status',
					'data'     => $verdict,
				]
			);
		}

		if ( $verdict === 'spam' ) {
			// Mark entry as spam. Emails remain blocked.
			$this->integration->mark_entry_spam( $entry_id, $form_data );
		} else {
			// Clean: re-send emails via EmailReconstructor.
			$this->integration->resend_clean_emails( $submission_id );
		}
	}
}

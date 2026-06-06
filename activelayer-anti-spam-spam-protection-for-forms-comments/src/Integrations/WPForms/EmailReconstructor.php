<?php

namespace ActiveLayer\Integrations\WPForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Logger\Logger;
use Exception;

/**
 * WPForms Email Reconstructor.
 *
 * Manages email sending and blocking for WPForms submissions.
 * Handles email reconstruction for clean submissions.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\WPForms
 */
class EmailReconstructor {

	/**
	 * Entry meta key used to mark notifications already released for an entry.
	 *
	 * @since 1.2.0
	 */
	private const NOTIFICATIONS_META_KEY = 'activelayer_notifications_released';

	/**
	 * Parent integration instance.
	 *
	 * @since 1.0.0
	 *
	 * @var WPFormsIntegration
	 */
	private $integration;

	/**
	 * Flag to temporarily allow emails during reconstruction.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $allow_emails_temporarily = false;

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
	 * Set the temporary email allow flag.
	 *
	 * Used by sync+entries flow to control email delivery based on verdict.
	 *
	 * @since 1.1.0
	 *
	 * @param bool $allow Whether to allow emails.
	 */
	public function set_allow_emails_temporarily( bool $allow ): void {

		$this->allow_emails_temporarily = $allow;
	}

	/**
	 * Check if emails should be disabled for anti-spam forms.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $disable       Current disable status.
	 * @param object $notifications Notifications object.
	 *
	 * @return bool True to disable emails.
	 */
	public function should_disable_emails( bool $disable, object $notifications ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Skip if integration is disabled globally.
		if ( ! $this->integration->is_enabled() ) {
			return $disable;
		}

		// Extract form data from WPForms Notifications object.
		$form_data = $notifications->form_data ?? null;

		if ( ! is_array( $form_data ) ) {
			return $disable;
		}

		// Check if anti-spam is enabled for THIS SPECIFIC form.
		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_data );

		if ( ! $form_settings['enabled'] ) {
			// ActiveLayer is NOT enabled for this form - don't interfere.
			return $disable;
		}

		$tracking_mode = $this->integration->is_tracking_mode_for_form( $form_data );
		$entry_id      = property_exists( $notifications, 'entry_id' ) ? (int) $notifications->entry_id : null;

		if ( $this->integration->should_bypass_email_interception( $form_data ) ) {
			return false;
		}

		if ( $this->integration->has_queue_failure( $form_data, $entry_id ) ) {
			return false;
		}

		// In tracking mode, allow emails to be sent immediately.
		if ( $tracking_mode ) {
			return false;
		}

		// Allow emails if we're temporarily allowing them (during reconstruction).
		// Only check this AFTER we verified ActiveLayer is enabled for this form.
		if ( $this->allow_emails_temporarily ) {
			return false;
		}

		// ActiveLayer is enabled for this form - disable emails until API verification.
		return true;
	}

	/**
	 * Allow clean submission - send emails.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Make method idempotent via wpforms_entry_meta marker.
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True on success.
	 */
	public function allow_submission( string $submission_id ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ! function_exists( 'wpforms' ) ) {
			return false;
		}

		// Get submission data.
		$submission = $this->integration->get_storage()->find( $submission_id );

		if ( ! $submission || ! $submission['entry_id'] ) {
			return false;
		}

		// Get WPForms entry.
		$entry = wpforms()->obj( 'entry' )->get( $submission['entry_id'] );

		if ( ! $entry ) {
			return false;
		}

		if ( $this->notifications_already_released( (int) $entry->entry_id ) ) {
			Logger::log(
				'WPForms: notifications already released - skipping resend',
				[
					'submission_id' => $submission_id,
					'entry_id'      => (int) $entry->entry_id,
				]
			);

			return true;
		}

		// Get form data - same way as WPForms "Resend Notifications" functionality.
		$form = wpforms()->obj( 'form' )->get( $entry->form_id );

		if ( ! $form ) {
			return false;
		}

		// Decode form data from post_content (same as WPForms resend functionality).
		$form_data = wpforms_decode( $form->post_content );

		if ( ! $form_data ) {
			return false;
		}

		// Decode fields data from entry (same as WPForms resend functionality).
		$fields = wpforms_decode( $entry->fields );

		if ( ! $fields ) {
			return false;
		}

		/**
		 * Filters the form data for the entry before processing notifications.
		 * Same filter as used in WPForms resend notifications.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $form_data Form data.
		 * @param object $entry     Entry object.
		 *
		 * @return array
		 */
		$form_data = apply_filters( 'activelayer_integrations_wpforms_email_reconstructor_form_data', $form_data, $entry );

		// Temporarily allow emails by disabling our filter.
		$this->allow_emails_temporarily = true;

		try {
			// Send notifications using WPForms process object (same as resend functionality).
			// Pass empty array as second parameter (entry data) since it's not needed for notifications.
			wpforms()->obj( 'process' )->entry_email( $fields, [], $form_data, $entry->entry_id );

			$success = true;
		} catch ( Exception $e ) {
			$success = false;
		} finally {
			// Always reset the flag.
			$this->allow_emails_temporarily = false;
		}

		if ( $success ) {
			$this->mark_notifications_released( (int) $entry->entry_id, (int) $entry->form_id );
		}

		return $success;
	}

	/**
	 * Check whether notifications have already been released for an entry.
	 *
	 * Uses the WPForms entry_meta store to look up a marker row written after a
	 * successful email release, making `allow_submission()` self-idempotent.
	 *
	 * @since 1.2.0
	 *
	 * @param int $entry_id WPForms entry ID.
	 *
	 * @return bool True when at least one marker row exists for the entry.
	 */
	private function notifications_already_released( int $entry_id ): bool {

		if ( $entry_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );

		if ( ! $entry_meta ) {
			return false;
		}

		$count = $entry_meta->get_meta(
			[
				'entry_id' => $entry_id,
				'type'     => self::NOTIFICATIONS_META_KEY,
				'number'   => 1,
			],
			true
		);

		return (int) $count > 0;
	}

	/**
	 * Mark notifications as released for an entry.
	 *
	 * Writes a marker row into the WPForms entry_meta store so subsequent
	 * `allow_submission()` calls for the same entry short-circuit.
	 *
	 * @since 1.2.0
	 *
	 * @param int $entry_id WPForms entry ID.
	 * @param int $form_id  WPForms form ID.
	 *
	 * @return void
	 */
	private function mark_notifications_released( int $entry_id, int $form_id ): void {

		if ( $entry_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return;
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );

		if ( ! $entry_meta ) {
			return;
		}

		$entry_meta->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $form_id,
				'type'     => self::NOTIFICATIONS_META_KEY,
				'data'     => current_time( 'mysql' ),
			]
		);
	}
}

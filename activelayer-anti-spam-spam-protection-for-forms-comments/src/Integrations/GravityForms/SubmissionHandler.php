<?php

namespace ActiveLayer\Integrations\GravityForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use Exception;
use GFFormsModel;
use GFAPI;

/**
 * Synchronous submission handler for Gravity Forms.
 *
 * Checks submissions at gform_validation, then marks spam entries and
 * suppresses notifications via post-save hooks. Entries are always created;
 * spam entries receive GF status 'spam'.
 *
 * @since 1.1.0
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.1.0
	 *
	 * @var GravityFormsIntegration
	 */
	private $integration;

	/**
	 * Verdicts collected during validation, keyed by form ID.
	 *
	 * Each value is an array with keys: verdict, submission_id.
	 *
	 * @since 1.1.0
	 *
	 * @var array<int, array{verdict: string, submission_id: string}>
	 */
	private $verdicts = [];

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param GravityFormsIntegration $integration Parent integration reference.
	 */
	public function __construct( GravityFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Handle Gravity Forms validation filter.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $validation_result Validation result with 'is_valid', 'form', 'failed_validation_page'.
	 * @param string $context           Submission context (form-submit, api-submit, api-validate).
	 *
	 * @return array Modified validation result.
	 */
	public function handle_validation( $validation_result, $context = '' ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Only process actual form submissions.
		if ( $context !== '' && $context !== 'form-submit' ) {
			return $validation_result;
		}

		// Do not interfere if validation already failed.
		if ( empty( $validation_result['is_valid'] ) ) {
			return $validation_result;
		}

		$form    = $validation_result['form'] ?? [];
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		if ( $form_id <= 0 ) {
			return $validation_result;
		}

		// Multi-page guard: only check on final submission.
		$post_key = 'gform_target_page_number_' . $form_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- GF internal page number, read-only.
		$target_page = isset( $_POST[ $post_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) : '';

		if ( $target_page !== '0' && $target_page !== '' ) {
			return $validation_result;
		}

		if ( ! $this->integration->is_enabled() ) {
			return $validation_result;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return $validation_result;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return $validation_result;
		}

		try {
			return $this->check_submission( $validation_result, $form, $form_id );
		} catch ( Exception $exception ) {
			Logger::log(
				'GravityForms sync mode: unexpected error',
				[
					'form_id' => $form_id,
					'error'   => $exception->getMessage(),
				]
			);

			return $validation_result;
		}
	}

	/**
	 * Run synchronous API check and update validation result.
	 *
	 * @since 1.1.0
	 *
	 * @param array $validation_result Validation result.
	 * @param array $form              Form object.
	 * @param int   $form_id           Form ID.
	 *
	 * @return array Modified validation result.
	 */
	private function check_submission( array $validation_result, array $form, int $form_id ): array {

		$meta = $this->integration->get_form_meta( $form );

		// Build entry-like array from current POST data.
		$entry = class_exists( 'GFFormsModel' ) ? GFFormsModel::get_current_lead() : [];

		if ( empty( $entry ) ) {
			$entry = [];
		}

		$raw_data = [
			'entry' => $entry,
			'form'  => $form,
		];

		$result = $this->integration->process_submission_synchronously( $raw_data, $meta );

		if ( ! empty( $result['success'] ) ) {
			$this->verdicts[ $form_id ] = [
				'verdict'       => $result['verdict'] ?? 'clean',
				'submission_id' => $result['submission_id'] ?? '',
			];
		}

		return $validation_result;
	}

	/**
	 * Mark spam entries after Gravity Forms saves them.
	 *
	 * Hooked to gform_entry_post_save (filter; fires after INSERT, before
	 * notifications). The hook is a filter — GF assigns the return value
	 * back to $entry for the rest of the submission pipeline (notifications,
	 * confirmations). Returning void here would null out $entry and produce
	 * empty `{all_fields}` merge tags.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Return $entry so downstream notifications/confirmations receive the saved entry instead of null. Previously registered as an action and returned void, which broke `{all_fields}` and other entry-aware merge tags.
	 *
	 * @param array $entry Saved entry array.
	 * @param array $form  Form object.
	 *
	 * @return array Entry array (unchanged; mutations happen via GFAPI in the DB).
	 */
	public function handle_entry_post_save( $entry, $form ): array {

		$form_id  = isset( $form['id'] ) ? (int) $form['id'] : 0;
		$entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;

		if ( ! isset( $this->verdicts[ $form_id ] ) || $entry_id <= 0 ) {
			return $entry;
		}

		$data = $this->verdicts[ $form_id ];

		// Mark GF entry as spam.
		if ( $data['verdict'] === 'spam' && class_exists( 'GFAPI' ) ) {
			GFAPI::update_entry_property( $entry_id, 'status', 'spam' );

			Logger::log(
				'GravityForms: marked entry as spam',
				[
					'form_id'       => $form_id,
					'entry_id'      => $entry_id,
					'submission_id' => $data['submission_id'],
				]
			);
		}

		// Store ActiveLayer submission ID in entry meta for cross-reference.
		if ( function_exists( 'gform_update_meta' ) && ! empty( $data['submission_id'] ) ) {
			gform_update_meta( $entry_id, 'activelayer_submission_id', $data['submission_id'] );
		}

		return $entry;
	}

	/**
	 * Suppress email notifications for spam entries.
	 *
	 * Hooked to gform_notification (fires per notification, after entry save).
	 *
	 * @since 1.1.0
	 *
	 * @param array $notification Notification configuration.
	 * @param array $form         Form object.
	 * @param array $entry        Entry array.
	 *
	 * @return array|false Notification array to send, or false to suppress.
	 */
	public function maybe_suppress_notification( $notification, $form, $entry ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by gform_notification filter signature.

		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		if ( ! isset( $this->verdicts[ $form_id ] ) ) {
			return $notification;
		}

		$data = $this->verdicts[ $form_id ];

		// Suppress notifications for spam.
		if ( $data['verdict'] === 'spam' ) {
			return false;
		}

		return $notification;
	}

	/**
	 * Get the stored verdict for a form (used by tests).
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array|null Verdict data or null if not set.
	 */
	public function get_verdict( int $form_id ): ?array {

		return $this->verdicts[ $form_id ] ?? null;
	}
}

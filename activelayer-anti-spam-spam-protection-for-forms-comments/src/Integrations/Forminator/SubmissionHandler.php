<?php

namespace ActiveLayer\Integrations\Forminator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Logger\Logger;
use Exception;

/**
 * Synchronous submission handler for Forminator.
 *
 * Hooks into `forminator_spam_protection` to check submissions before entry
 * creation. Behaviour depends on the form's Store Submissions setting:
 *
 * - Store OFF: spam is rejected (form blocked).
 * - Store ON:  spam is allowed through so Forminator saves the entry,
 *              but email notifications are suppressed via `pre_wp_mail`.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Integrations\Forminator
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.1.0
	 *
	 * @var ForminatorIntegration
	 */
	private $integration;

	/**
	 * Track forms already processed synchronously during the current request.
	 *
	 * @since 1.1.0
	 *
	 * @var array<int,bool>
	 */
	private $sync_validated_forms = [];

	/**
	 * Form ID that received a spam verdict in save mode.
	 *
	 * When set, the integration suppresses Forminator email notifications
	 * for this form via the `pre_wp_mail` filter.
	 *
	 * @since 1.1.0
	 *
	 * @var int|null
	 */
	private $spam_save_form_id = null;

	/**
	 * Form ID pending spam entry marking via the addon hook.
	 *
	 * Set when a spam verdict is received in save mode so that the
	 * Forminator addon `after_entry_saved()` hook can mark the entry
	 * as spam in the Forminator database.
	 *
	 * Static because Forminator instantiates the addon hooks class
	 * independently — we cannot inject dependencies.
	 *
	 * @since 1.1.0
	 *
	 * @var int|null
	 */
	private static $pending_spam_form_id = null;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param ForminatorIntegration $integration Parent integration reference.
	 */
	public function __construct( ForminatorIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Handle Forminator spam protection filter.
	 *
	 * Runs synchronous API check before entry creation.
	 * Clean and API-failure submissions always pass through (safe-by-default).
	 *
	 * Spam handling depends on Store Submissions:
	 * - OFF: returns true (Forminator blocks the form).
	 * - ON:  returns false (entry saved), emails suppressed separately.
	 *
	 * @since 1.1.0
	 *
	 * @param bool   $is_spam     Current spam status.
	 * @param array  $field_data  Field data array.
	 * @param int    $form_id     Form identifier.
	 * @param string $module_slug Module slug.
	 *
	 * @return bool
	 */
	public function handle_spam_protection( $is_spam, $field_data, $form_id, $module_slug ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( $is_spam ) {
			return $is_spam;
		}

		if ( $module_slug !== 'form' ) {
			return $is_spam;
		}

		if ( ! $this->integration->is_enabled() ) {
			return $is_spam;
		}

		$form_id = (int) $form_id;

		if ( $form_id <= 0 ) {
			return $is_spam;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return $is_spam;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return $is_spam;
		}

		if ( UpgradeHelper::is_quota_exhausted_cached() ) {
			Logger::log(
				'Quota exhausted - skipping Forminator submission',
				[ 'form_id' => $form_id ]
			);

			return $is_spam;
		}

		if ( ! empty( $this->sync_validated_forms[ $form_id ] ) ) {
			return $is_spam;
		}

		$this->sync_validated_forms[ $form_id ] = true;

		$fields = $this->integration->build_fields_from_field_data( $field_data );

		$raw_data = [
			'fields' => $fields,
		];

		$meta             = $this->integration->get_form_meta( $form_id );
		$meta['entry_id'] = null;

		try {
			$result = $this->integration->process_submission_synchronously( $raw_data, $meta );
		} catch ( Exception $exception ) {
			Logger::log(
				'Forminator sync: unhandled exception — allowing submission',
				[
					'provider' => $this->integration->get_slug(),
					'form_id'  => $form_id,
					'error'    => $exception->getMessage(),
				]
			);

			return $is_spam;
		}

		if ( empty( $result['success'] ) ) {
			return $is_spam;
		}

		if ( 'spam' !== ( $result['verdict'] ?? 'clean' ) ) {
			return $is_spam;
		}

		// Spam verdict: decide based on Store Submissions setting.
		if ( $this->integration->has_store_submissions( $form_id ) ) {
			$this->spam_save_form_id    = $form_id;
			self::$pending_spam_form_id = $form_id;

			Logger::log(
				'Forminator sync: spam in save mode — allowing entry, suppressing email',
				[
					'provider'      => $this->integration->get_slug(),
					'form_id'       => $form_id,
					'submission_id' => $result['submission_id'] ?? '',
				]
			);

			return $is_spam;
		}

		return true;
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
	 * Get the form ID pending spam entry marking.
	 *
	 * Used by the Forminator addon `after_entry_saved()` hook to
	 * determine if the saved entry should be marked as spam.
	 *
	 * @since 1.1.0
	 *
	 * @return int|null Form ID or null if no spam-save pending.
	 */
	public static function get_pending_spam_form_id(): ?int {

		return self::$pending_spam_form_id;
	}

	/**
	 * Clear the pending spam form ID.
	 *
	 * Called after the entry has been marked as spam in the Forminator database.
	 *
	 * @since 1.1.0
	 */
	public static function clear_pending_spam(): void {

		self::$pending_spam_form_id = null;
	}

	/**
	 * Reset synchronous validation state.
	 *
	 * @since 1.1.0
	 */
	public function reset(): void {

		$this->sync_validated_forms = [];
		$this->spam_save_form_id    = null;
		self::$pending_spam_form_id = null;
	}
}

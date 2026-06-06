<?php

namespace ActiveLayer\Integrations\NinjaForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Logger\Logger;
use Throwable;

/**
 * Handles Ninja Forms submission lifecycle.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\NinjaForms
 */
class SubmissionHandler {

	/**
	 * Parent integration reference.
	 *
	 * @since 1.0.0
	 *
	 * @var NinjaFormsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param NinjaFormsIntegration $integration Integration instance.
	 */
	public function __construct( NinjaFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Handle completed submission.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Submission payload from Ninja Forms.
	 *
	 * @return void
	 */
	public function handle_submission( array $data ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		$form_id = $this->resolve_form_id( $data );

		if ( $form_id === null ) {
			return;
		}

		if ( ! $this->integration->is_form_protected( $form_id ) ) {
			return;
		}

		$strategy = $this->integration->get_processing_strategy( $form_id );

		if ( $strategy === NinjaFormsIntegration::STRATEGY_SYNC_SAVE ) {
			$this->handle_sync_with_entries( $data, $form_id );

			return;
		}

		if ( $strategy === NinjaFormsIntegration::STRATEGY_SYNC_BLOCK ) {
			// Already handled by maybe_handle_sync_submission().
			return;
		}

		try {
			$payload       = $this->prepare_submission_payload( $data, $form_id );
			$meta          = $payload['meta'];
			$form_data     = $payload['form_data'];
			$email_actions = $payload['email_actions'];

			if ( empty( $meta['entry_id'] ) ) {
				Logger::log(
					'Ninja Forms submission bypassed - no entry recorded',
					[
						'form_id' => $form_id,
					]
				);

				$this->integration->get_email_reconstructor()->send_captured_emails_now( $form_id, $data, $email_actions );

				return;
			}

			$submission_id = $this->integration->process_submission( $form_data, $meta );

			// When submission was skipped entirely (e.g., quota exhausted),
			// no DB record exists — replay captured emails immediately.
			if ( $submission_id === '' && ! empty( $meta['queue_failed'] ) ) {
				$this->integration->get_email_reconstructor()->send_captured_emails_now( $form_id, $data, $email_actions );

				return;
			}

			$this->integration->get_email_reconstructor()->persist_captured_emails( $submission_id, $email_actions );

			if ( ! empty( $meta['queue_failed'] ) ) {
				Logger::log(
					'Ninja Forms queue failed - allowing email delivery',
					[
						'form_id' => $form_id,
					]
				);

				$this->integration->get_email_reconstructor()->allow_submission( $submission_id );
				$this->integration->get_email_reconstructor()->cleanup_submission_assets( $submission_id );

				$this->integration->get_storage()->update_status(
					$submission_id,
					'failed',
					[
						'verdict' => 'queue_failed',
					]
				);

				return;
			}
		} catch ( Throwable $exception ) {
			Logger::log(
				'Failed to queue Ninja Forms submission',
				[
					'form_id' => $form_id,
					'error'   => $exception->getMessage(),
				]
			);
		}
	}

	/**
	 * Resolve form identifier after basic validation.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Submission payload.
	 *
	 * @return int|null Sanitized form ID or null when invalid.
	 */
	private function resolve_form_id( array $data ): ?int {

		$form_id = isset( $data['form_id'] ) ? (int) $data['form_id'] : 0;

		return $form_id > 0 ? $form_id : null;
	}

	/**
	 * Prepare submission payload for processing.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data    Submission payload.
	 * @param int   $form_id Form identifier.
	 *
	 * @return array{
	 *     meta: array,
	 *     form_data: array,
	 *     email_actions: array,
	 * }
	 */
	private function prepare_submission_payload( array $data, int $form_id ): array {

		$meta = $this->integration->get_form_meta( $form_id );

		$entry_id = $data['actions']['save']['sub_id'] ?? null;

		if ( $entry_id ) {
			$meta['entry_id'] = (int) $entry_id;
		}

		$email_actions = $this->integration->get_email_reconstructor()->drain_captured_emails();

		$form_data = [
			'fields' => $data['fields'] ?? [],
		];

		return [
			'form_data'     => $form_data,
			'meta'          => $meta,
			'email_actions' => $email_actions,
		];
	}

	/**
	 * Handle synchronous processing when entries are available.
	 *
	 * Runs a blocking API check after the entry is saved, then replays
	 * or discards captured emails based on the verdict.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data    Submission payload from Ninja Forms.
	 * @param int   $form_id Form identifier.
	 */
	private function handle_sync_with_entries( array $data, int $form_id ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$entry_id = isset( $data['actions']['save']['sub_id'] ) ? (int) $data['actions']['save']['sub_id'] : 0;

		// Drain captured emails early so they are never lost.
		$email_actions = $this->integration->get_email_reconstructor()->drain_captured_emails();

		if ( $entry_id <= 0 ) {
			Logger::log(
				'Ninja Forms sync+entries: no entry recorded',
				[
					'form_id' => $form_id,
				]
			);

			// Safe-by-default: replay captured emails.
			if ( ! empty( $email_actions ) ) {
				$this->integration->get_email_reconstructor()->send_captured_emails_now( $form_id, $data, $email_actions );
			}

			return;
		}

		$meta             = $this->integration->get_form_meta( $form_id );
		$meta['entry_id'] = $entry_id;

		$form_data = [
			'fields' => $data['fields'] ?? [],
		];

		// Synchronous API check.
		$result  = $this->integration->process_submission_synchronously( $form_data, $meta );
		$verdict = $result['verdict'] ?? '';

		if ( empty( $result['success'] ) || $verdict !== 'spam' ) {
			// Clean or API error: replay captured emails (safe-by-default).
			$this->integration->get_email_reconstructor()->send_captured_emails_now(
				$form_id,
				$data,
				$email_actions
			);
		}

		// Spam: emails discarded (not replayed).
	}
}

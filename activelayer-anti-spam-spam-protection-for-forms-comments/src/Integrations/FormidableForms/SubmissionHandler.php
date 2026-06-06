<?php

namespace ActiveLayer\Integrations\FormidableForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SafeUnserializer;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use FrmEntry;
use FrmEntryMeta;
use FrmField;
use Throwable;

/**
 * Handles Formidable Forms submission events.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\FormidableForms
 */
class SubmissionHandler {

	/**
	 * Parent integration reference.
	 *
	 * @since 1.0.0
	 *
	 * @var FormidableFormsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param FormidableFormsIntegration $integration Integration reference.
	 */
	public function __construct( FormidableFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Handle entry creation.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $entry_id Entry identifier.
	 * @param int   $form_id  Form identifier.
	 * @param array $args     Additional arguments.
	 *
	 * @return void
	 */
	public function handle_submission( int $entry_id, int $form_id, array $args ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		if ( $form_id <= 0 || $entry_id <= 0 ) {
			return;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		$strategy = $this->integration->get_processing_strategy( $form_id );

		if ( $strategy === FormidableFormsIntegration::STRATEGY_SYNC_SAVE ) {
			$this->handle_sync_with_entries( $entry_id, $form_id, $args );

			return;
		}

		if ( $strategy === FormidableFormsIntegration::STRATEGY_SYNC_BLOCK ) {
			// Already handled by maybe_handle_sync_submission().
			return;
		}

		try {
			$entry = FrmEntry::getOne( $entry_id, true );

			if ( ! $entry ) {
				return;
			}

			$fields = $this->prepare_fields( $entry_id );

			$meta             = $this->integration->get_form_meta( $form_id );
			$meta['entry_id'] = $entry_id;
			$email_contexts   = $this->integration->get_email_reconstructor()->drain_captured_emails( $entry_id );
			$event            = isset( $args['event'] ) && is_string( $args['event'] ) ? $args['event'] : 'create';

			$form_data = [
				'fields' => $fields,
			];

			$submission_id = $this->integration->process_submission( $form_data, $meta );

			$this->integration->get_email_reconstructor()->persist_captured_emails( $submission_id, $form_id, $entry_id, $event, $email_contexts );

			if ( ! empty( $meta['queue_failed'] ) ) {
				Logger::log(
					'Formidable Forms queue failed - allowing email delivery',
					[
						'form_id'  => $form_id,
						'entry_id' => $entry_id,
					]
				);

				$this->integration->get_email_reconstructor()->allow_submission( $submission_id );
				$this->integration->get_email_reconstructor()->cleanup_submission_assets( $submission_id );

				return;
			}
		} catch ( Throwable $exception ) {
			Logger::log(
				'Failed to queue Formidable Forms submission',
				[
					'form_id'  => $form_id,
					'entry_id' => $entry_id,
					'error'    => $exception->getMessage(),
				]
			);
		}
	}

	/**
	 * Build simplified fields array.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Replaced maybe_unserialize with allowed_classes=>false to block PHP object injection via meta_value.
	 *
	 * @param int $entry_id Entry identifier.
	 *
	 * @return array
	 */
	private function prepare_fields( int $entry_id ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$metas  = FrmEntryMeta::get_entry_meta_info( $entry_id );
		$fields = [];

		foreach ( $metas as $meta ) {
			$field = FrmField::getOne( $meta->field_id );

			$value = SafeUnserializer::unserialize( $meta->meta_value );

			if ( is_array( $value ) ) {
				$value = array_filter(
					$value,
					static function ( $item ) {

						return $item !== '';
					}
				);
			}

			$fields[] = [
				'id'    => $meta->field_id,
				'key'   => $field ? ( $field->field_key ?? '' ) : '',
				'type'  => $field ? ( $field->type ?? '' ) : '',
				'label' => $field ? ( $field->name ?? '' ) : '',
				'value' => $value,
			];
		}

		return $fields;
	}

	/**
	 * Handle synchronous processing when entries are available.
	 *
	 * Runs a blocking API check after the entry is created, then replays
	 * or discards captured email actions based on the verdict.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $entry_id Entry identifier.
	 * @param int   $form_id  Form identifier.
	 * @param array $args     Additional arguments.
	 */
	private function handle_sync_with_entries( int $entry_id, int $form_id, array $args ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$email_contexts = $this->integration->get_email_reconstructor()->drain_captured_emails( $entry_id );
		$event          = isset( $args['event'] ) && is_string( $args['event'] ) ? $args['event'] : 'create';

		$fields = $this->prepare_fields( $entry_id );

		$meta             = $this->integration->get_form_meta( $form_id );
		$meta['entry_id'] = $entry_id;

		$form_data = [
			'fields' => $fields,
		];

		// Synchronous API check.
		$result        = $this->integration->process_submission_synchronously( $form_data, $meta );
		$submission_id = $result['submission_id'] ?? '';
		$verdict       = $result['verdict'] ?? '';

		if ( empty( $result['success'] ) || $verdict !== 'spam' ) {
			// Clean or API error: replay captured email actions (safe-by-default).
			if ( ! empty( $email_contexts ) ) {
				if ( $submission_id ) {
					$this->integration->get_email_reconstructor()->persist_captured_emails(
						$submission_id,
						$form_id,
						$entry_id,
						$event,
						$email_contexts
					);

					$this->integration->get_email_reconstructor()->allow_submission( $submission_id );
					$this->integration->get_email_reconstructor()->cleanup_submission_assets( $submission_id );
				} else {
					// Storage error: replay directly without persist.
					$this->integration->get_email_reconstructor()->replay_emails_now( $email_contexts, $form_id, $entry_id, $event );
				}
			}
		}

		// Spam: emails discarded (not replayed).
	}
}

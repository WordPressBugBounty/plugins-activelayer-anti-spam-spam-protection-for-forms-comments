<?php

namespace ActiveLayer\Integrations\FormidableForms;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use FrmAppHelper;
use FrmField;
use FrmForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formidable Forms Integration.
 *
 * Orchestrates ActiveLayer workflow for Formidable Forms submissions.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\FormidableForms
 */
class FormidableFormsIntegration extends BaseFormIntegration {

	const STRATEGY_ASYNC      = 'async';
	const STRATEGY_SYNC_BLOCK = 'sync_block';
	const STRATEGY_SYNC_SAVE  = 'sync_save';

	/**
	 * Submission handler.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Email reconstructor.
	 *
	 * @since 1.0.0
	 *
	 * @var EmailReconstructor
	 */
	private $email_reconstructor;

	/**
	 * Admin settings helper.
	 *
	 * @since 1.0.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Tracks whether email interception is temporarily suppressed while replaying notifications.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $email_interception_suspended = false;

	/**
	 * Track forms that disable entry storage.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,bool>
	 */
	private $forms_without_storage = [];

	/**
	 * Track forms already processed synchronously during the current request.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,bool>
	 */
	private $sync_validated_forms = [];

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		parent::__construct( 'Formidable Forms' );

		$this->submission_handler  = new SubmissionHandler( $this );
		$this->email_reconstructor = new EmailReconstructor( $this );
		$this->admin_settings      = new AdminSettings();
	}

	/**
	 * Boot integration hooks.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		$this->hooks();
		$this->admin_settings->init();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function hooks(): void {

		add_action(
			'frm_after_create_entry',
			[ $this->submission_handler, 'handle_submission' ],
			30,
			3
		);

		add_filter(
			'frm_entries_before_create',
			[ $this, 'maybe_handle_sync_submission' ],
			20,
			2
		);

		add_filter(
			'frm_custom_trigger_action',
			[ $this->email_reconstructor, 'intercept_action' ],
			5,
			5
		);

		add_action(
			'activelayer_queue_worker_verdict_received',
			[ $this, 'handle_verdict_action' ],
			10,
			3
		);

		add_action(
			'activelayer_queue_worker_submission_failed',
			[ $this, 'handle_submission_failed' ],
			10,
			2
		);

		// Add hidden field for environment signals to protected forms.
		add_action(
			'frm_entry_form',
			[ $this, 'output_environment_signals_field' ],
			10,
			3
		);
	}

	/**
	 * Output the hidden environment signals field in the form.
	 *
	 * Only outputs for forms that have ActiveLayer protection enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed  $form      Form object.
	 * @param string $action    Form action URL.
	 * @param array  $form_args Form arguments.
	 *
	 * @return void
	 */
	public function output_environment_signals_field( $form, string $action, array $form_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		$form_id = 0;

		if ( is_object( $form ) && isset( $form->id ) ) {
			$form_id = (int) $form->id;
		}

		if ( $form_id <= 0 ) {
			return;
		}

		$form_settings = $this->admin_settings->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return;
		}

		FieldRenderer::output_all();
	}

	/**
	 * Check if Formidable Forms is active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return class_exists( 'FrmFormActionsController' );
	}

	/**
	 * Handle queue verdict.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $verdict       Verdict result.
	 * @param array  $submission    Stored submission data.
	 */
	public function handle_verdict_action( string $submission_id, string $verdict, array $submission ): void {

		$provider = $submission['provider'] ?? '';

		if ( $provider !== $this->get_slug() ) {
			return;
		}

		$this->handle_verdict(
			$submission_id,
			$verdict,
			[]
		);
	}

	/**
	 * Handle submission failure fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param array  $submission    Stored submission data.
	 */
	public function handle_submission_failed( string $submission_id, array $submission ): void {

		if ( $this->get_slug() !== ( $submission['provider'] ?? '' ) ) {
			return;
		}

		$this->email_reconstructor->allow_submission( $submission_id );
		$this->email_reconstructor->cleanup_submission_assets( $submission_id );
	}

	/**
	 * Run synchronous verification when entry storage is disabled.
	 *
	 * @since 1.0.0
	 *
	 * @param array|string $errors Existing validation errors.
	 * @param mixed        $form   Form object or identifier.
	 *
	 * @return array|string
	 */
	public function maybe_handle_sync_submission( $errors, $form ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ! $this->is_enabled() ) {
			return $errors;
		}

		if ( is_object( $form ) && isset( $form->id ) ) {
			$form_id = (int) $form->id;
		} elseif ( is_numeric( $form ) ) {
			$form_id = (int) $form;
		} else {
			$form_id = 0;
		}

		if ( $form_id <= 0 ) {
			return $errors;
		}

		$form_settings = $this->admin_settings->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return $errors;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return $errors;
		}

		if ( $this->get_processing_strategy( $form_id ) !== self::STRATEGY_SYNC_BLOCK ) {
			return $errors;
		}

		if ( ! empty( $this->sync_validated_forms[ $form_id ] ) ) {
			return $errors;
		}

		$this->sync_validated_forms[ $form_id ] = true;

		$raw_data = [
			'fields' => $this->build_request_fields( $form_id ),
		];

		$meta             = $this->get_form_meta( $form_id );
		$meta['entry_id'] = null;

		$result = $this->process_submission_synchronously( $raw_data, $meta );

		if ( empty( $result['success'] ) ) {
			return $errors;
		}

		if ( 'spam' !== ( $result['verdict'] ?? 'clean' ) ) {
			return $errors;
		}

		if ( ! is_array( $errors ) ) {
			$errors = [];
		}

		$errors['activelayer'] = $this->get_sync_block_message();

		return $errors;
	}

	/**
	 * Normalize submission data for API payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_data Raw data bundle.
	 *
	 * @return array
	 */
	protected function normalize_form_data( array $raw_data ): array {

		$fields = $raw_data['fields'] ?? [];

		return [
			'name'        => $this->find_field_value( $fields, [ 'name', 'text' ] ),
			'email'       => $this->find_field_value( $fields, [ 'email' ] ),
			'website_url' => $this->find_field_value( $fields, [ 'url' ] ),
			'message'     => $this->find_field_value( $fields, [ 'textarea' ] ),
			'ip'          => RequestHelper::get_user_ip(),
			'user_agent'  => RequestHelper::get_user_agent(),
		];
	}

	/**
	 * Retrieve form metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $form_instance Form ID.
	 *
	 * @return array
	 */
	public function get_form_meta( $form_instance ): array {

		$form_id = (int) $form_instance;

		$form = FrmForm::getOne( $form_id );

		return [
			'form_id'    => $form_id,
			'form_title' => $form && isset( $form->name ) ? $form->name : 'Formidable Form',
		];
	}

	/**
	 * Check whether the form stores entries (no_save is not set).
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return bool
	 */
	public function can_store_entries( int $form_id ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		if ( isset( $this->forms_without_storage[ $form_id ] ) ) {
			return ! $this->forms_without_storage[ $form_id ];
		}

		$form = FrmForm::getOne( $form_id );

		if ( ! $form ) {
			$this->forms_without_storage[ $form_id ] = true;

			return false;
		}

		if ( empty( $form->options ) ) {
			$this->forms_without_storage[ $form_id ] = false;

			return true;
		}

		FrmAppHelper::unserialize_or_decode( $form->options );

		$no_save = ! empty( $form->options['no_save'] );

		$this->forms_without_storage[ $form_id ] = $no_save;

		return ! $no_save;
	}

	/**
	 * Determine the processing strategy for the form.
	 *
	 * Returns the strategy based on sync mode and entry storage capability:
	 * - 'async': background processing via Action Scheduler.
	 * - 'sync_block': synchronous API check, block spam before entry creation.
	 * - 'sync_save': synchronous API check after entry is saved.
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return string One of self::STRATEGY_ASYNC, self::STRATEGY_SYNC_BLOCK, or self::STRATEGY_SYNC_SAVE.
	 */
	public function get_processing_strategy( int $form_id ): string {

		if ( $this->is_sync_mode() ) {
			return $this->can_store_entries( $form_id ) ? self::STRATEGY_SYNC_SAVE : self::STRATEGY_SYNC_BLOCK;
		}

		return $this->can_store_entries( $form_id ) ? self::STRATEGY_ASYNC : self::STRATEGY_SYNC_BLOCK;
	}

	/**
	 * Whether email interception should be bypassed for the form.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return bool
	 */
	public function should_bypass_email_interception( int $form_id ): bool {

		return $this->get_processing_strategy( $form_id ) === self::STRATEGY_SYNC_BLOCK;
	}

	/**
	 * Get admin settings helper.
	 *
	 * @since 1.0.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Temporarily suspend email interception so replayed notifications are not captured again.
	 *
	 * @since 1.0.0
	 */
	public function suspend_email_interception(): void {

		$this->email_interception_suspended = true;
	}

	/**
	 * Resume email interception after replay has finished.
	 *
	 * @since 1.0.0
	 */
	public function resume_email_interception(): void {

		$this->email_interception_suspended = false;
	}

	/**
	 * Check whether interception is currently suppressed to prevent double-processing.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_email_interception_suspended(): bool {

		return $this->email_interception_suspended;
	}

	/**
	 * Expose reconstructor.
	 *
	 * @since 1.0.0
	 *
	 * @return EmailReconstructor
	 */
	public function get_email_reconstructor(): EmailReconstructor {

		return $this->email_reconstructor;
	}

	/**
	 * Reset cached settings and synchronous state.
	 *
	 * @since 1.0.0
	 */
	public function reload_settings(): void {

		parent::reload_settings();

		$this->forms_without_storage        = [];
		$this->sync_validated_forms         = [];
		$this->email_interception_suspended = false;
	}

	/**
	 * Allow submission (send queued emails).
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool
	 */
	protected function allow_submission( string $submission_id ): bool {

		$allowed = $this->email_reconstructor->allow_submission( $submission_id );

		$this->email_reconstructor->cleanup_submission_assets( $submission_id );

		return $allowed;
	}

	/**
	 * Build a simplified field set from the current request payload.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_request_fields( int $form_id ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Nonce is verified by Formidable Forms during form submission processing.
		// FrmEntriesController::process_entry() validates 'frm_submit_entry' nonce before this code runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by Formidable Forms core.
		if ( empty( $_POST['item_meta'] ) || ! is_array( $_POST['item_meta'] ) ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by Formidable; sanitized below.
		$submitted_raw = wp_unslash( $_POST['item_meta'] );
		$submitted     = is_array( $submitted_raw ) ? RequestHelper::sanitize_submission_data( $submitted_raw ) : [];

		$fields = FrmField::get_all_for_form( $form_id );
		$mapped = [];

		foreach ( $fields as $field ) {
			$field_id = isset( $field->id ) ? (int) $field->id : 0;

			if ( $field_id <= 0 ) {
				continue;
			}

			$value = $submitted[ $field_id ] ?? '';

			$mapped[] = [
				'id'    => $field_id,
				'key'   => isset( $field->field_key ) ? (string) $field->field_key : '',
				'type'  => isset( $field->type ) ? (string) $field->type : '',
				'label' => isset( $field->name ) ? (string) $field->name : '',
				'value' => $this->sanitize_submission_value( $value ),
			];
		}

		return $mapped;
	}

	/**
	 * Sanitize submission value recursively for synchronous payloads.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw submission value.
	 *
	 * @return mixed
	 */
	private function sanitize_submission_value( $value ) {

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->sanitize_submission_value( $item );
			}

			return $value;
		}

		if ( is_scalar( $value ) || $value === null ) {
			return wp_kses_post( (string) ( $value ?? '' ) );
		}

		return '';
	}

	/**
	 * Find field value by key/type hints.
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields  Field structures.
	 * @param array $needles Keywords.
	 *
	 * @return string
	 */
	private function find_field_value( array $fields, array $needles ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded, Generic.Metrics.NestingLevel.MaxExceeded

		foreach ( $fields as $field ) {
			$key   = strtolower( (string) ( $field['key'] ?? '' ) );
			$type  = strtolower( (string) ( $field['type'] ?? '' ) );
			$value = $field['value'] ?? '';

			if ( $value === '' || $value === null ) {
				continue;
			}

			if ( in_array( $key, $needles, true ) || in_array( $type, $needles, true ) ) {
				if ( is_array( $value ) ) {
					$filtered = array_filter(
						$value,
						static function ( $item ): bool {

							return $item !== '' && $item !== null;
						}
					);

					$sanitized = array_map(
						static function ( $item ): string {

							if ( is_scalar( $item ) || $item === null ) {
								return sanitize_text_field( (string) $item );
							}

							return '';
						},
						$filtered
					);

					$sanitized = array_filter(
						$sanitized,
						static function ( $item ): bool {

							return $item !== '';
						}
					);

					if ( $type === 'name' || $key === 'name' ) {
						return trim( implode( ' ', $sanitized ) );
					}

					return implode( ', ', $sanitized );
				}

				return sanitize_text_field( (string) $value );
			}
		}

		return '';
	}
}

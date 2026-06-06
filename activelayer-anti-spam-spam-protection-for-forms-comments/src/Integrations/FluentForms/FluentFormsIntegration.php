<?php

namespace ActiveLayer\Integrations\FluentForms;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Logger\Logger;
use FluentForm\App\Helpers\Helper as FluentFormHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fluent Forms Integration (synchronous only).
 *
 * @since 1.1.0
 */
class FluentFormsIntegration extends BaseFormIntegration {

	/**
	 * Submission handler.
	 *
	 * @since 1.1.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings helper.
	 *
	 * @since 1.1.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Current form being processed.
	 *
	 * Temporarily set during submission processing so that
	 * normalize_form_data() can access the form definition.
	 *
	 * @since 1.1.0
	 *
	 * @var object|null
	 */
	private $current_form;

	/**
	 * Wire up Fluent Forms integration services.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {

		parent::__construct( 'Fluent Forms' );

		$this->submission_handler = new SubmissionHandler( $this );
		$this->admin_settings     = new AdminSettings( $this );
	}

	/**
	 * Bootstrap integration hooks.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.1.0
	 */
	private function hooks(): void {

		// Sync check — runs API check before entry creation.
		add_action( 'fluentform/before_insert_submission', [ $this->submission_handler, 'handle_submission' ], 5, 3 );

		// Post-save: mark spam entries after creation (before notifications at priority 10).
		add_action( 'fluentform/submission_inserted', [ $this, 'maybe_mark_entry_spam' ], 5, 3 );

		// Restore email sending after notification processing completes.
		add_action( 'fluentform/global_notify_completed', [ $this, 'restore_email_sending' ], 99, 2 );

		// Clean up per-form options when a Fluent Form is deleted.
		add_action( 'fluentform/before_form_delete', [ $this->admin_settings, 'cleanup_form_settings' ] );

		// Add hidden fields for client signals to protected forms.
		add_action( 'fluentform/form_element_start', [ $this, 'output_client_signals' ] );
	}

	/**
	 * Check if Fluent Forms is installed.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return defined( 'FLUENTFORM' );
	}

	/**
	 * The integration only supports synchronous mode.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_sync_mode_enabled(): bool {

		return true;
	}

	/**
	 * Map raw Fluent Forms submission to ActiveLayer expected payload.
	 *
	 * @since 1.1.0
	 *
	 * @param array       $raw_data Raw submitted form data.
	 * @param object|null $form     Optional Fluent Forms form object.
	 *
	 * @return array Normalized submission payload.
	 */
	public function normalize_form_data( array $raw_data, $form = null ): array {

		// Use stored current form if not passed directly.
		if ( $form === null ) {
			$form = $this->current_form;
		}

		$fields = $this->extract_field_values( $raw_data, $form );

		return [
			'name'        => $fields['name'],
			'email'       => $fields['email'],
			'website_url' => $fields['website_url'],
			'message'     => $fields['message'],
			'ip'          => RequestHelper::get_user_ip(),
			'user_agent'  => RequestHelper::get_user_agent(),
		];
	}

	/**
	 * Extract field values from Fluent Forms submission data using field definitions.
	 *
	 * @since 1.1.0
	 *
	 * @param array       $raw_data Raw submitted form data.
	 * @param object|null $form     Fluent Forms form object.
	 *
	 * @return array<string,string> Extracted values keyed by slot.
	 */
	private function extract_field_values( array $raw_data, $form ): array {

		$values = [
			'name'        => '',
			'email'       => '',
			'website_url' => '',
			'message'     => '',
		];

		$fields_list = $this->parse_form_fields( $form );

		if ( $fields_list === null ) {
			return $values;
		}

		foreach ( $fields_list as $field ) {
			if ( ! is_array( $field ) || empty( $field['element'] ) ) {
				continue;
			}

			$this->map_field_to_slot( $field, $raw_data, $values );
		}

		return $values;
	}

	/**
	 * Parse form field definitions from a Fluent Forms form object.
	 *
	 * @since 1.1.0
	 *
	 * @param object|null $form Fluent Forms form object.
	 *
	 * @return array|null Parsed fields list, or null on failure.
	 */
	private function parse_form_fields( $form ) {

		if ( ! $form || ! isset( $form->form_fields ) ) {
			return null;
		}

		$form_fields = $form->form_fields;

		// Handle both JSON string and already-decoded array.
		if ( is_string( $form_fields ) ) {
			$form_fields = json_decode( $form_fields, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $form_fields ) ) {
				return null;
			}
		}

		if ( ! is_array( $form_fields ) ) {
			return null;
		}

		return $form_fields['fields'] ?? $form_fields;
	}

	/**
	 * Map a single Fluent Forms field definition to a normalized slot.
	 *
	 * Populates the first empty matching slot in `$values` by reference.
	 *
	 * @since 1.1.0
	 *
	 * @param array $field    Single field definition from form_fields.
	 * @param array $raw_data Raw submitted form data.
	 * @param array $values   Normalized values array (passed by reference).
	 */
	private function map_field_to_slot( array $field, array $raw_data, array &$values ): void {

		$element = $field['element'];
		$name    = $field['attributes']['name'] ?? '';

		// Name fields require special multi-part extraction.
		if ( $element === 'input_name' && $values['name'] === '' ) {
			$values['name'] = $this->extract_name_field( $raw_data, $name );

			return;
		}

		// Map simple element types to their corresponding slot.
		$element_slot_map = [
			'input_email' => 'email',
			'textarea'    => 'message',
		];

		$slot = $element_slot_map[ $element ] ?? null;

		if ( $slot !== null && $values[ $slot ] === '' && $name !== '' && isset( $raw_data[ $name ] ) ) {
			$values[ $slot ] = RequestHelper::sanitize_field_value( $raw_data[ $name ] );

			return;
		}

		// Fall back to pattern matching for URL fields.
		if ( $slot === null ) {
			$this->map_url_field( $name, $raw_data, $values );
		}
	}

	/**
	 * Attempt to map a field to the website_url slot via name pattern matching.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name     Field attribute name.
	 * @param array  $raw_data Raw submitted form data.
	 * @param array  $values   Normalized values array (passed by reference).
	 */
	private function map_url_field( string $name, array $raw_data, array &$values ): void {

		if ( $values['website_url'] !== '' || $name === '' || ! isset( $raw_data[ $name ] ) ) {
			return;
		}

		$lower_name = strtolower( $name );

		if ( strpos( $lower_name, 'url' ) !== false || strpos( $lower_name, 'website' ) !== false ) {
			$values['website_url'] = RequestHelper::sanitize_field_value( $raw_data[ $name ] );
		}
	}

	/**
	 * Extract and concatenate name sub-fields from Fluent Forms input_name data.
	 *
	 * Fluent Forms stores name fields as sub-fields: first_name, middle_name, last_name.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $raw_data Raw form data.
	 * @param string $name     Field attribute name.
	 *
	 * @return string Concatenated name.
	 */
	private function extract_name_field( array $raw_data, string $name ): string {

		// Fluent Forms sends name sub-fields as an array keyed by the field name.
		$name_data = $raw_data[ $name ] ?? [];

		if ( ! is_array( $name_data ) ) {
			return RequestHelper::sanitize_field_value( $name_data );
		}

		$parts = [];

		foreach ( [ 'first_name', 'middle_name', 'last_name' ] as $sub_field ) {
			$value = trim( (string) ( $name_data[ $sub_field ] ?? '' ) );

			if ( $value !== '' ) {
				$parts[] = $value;
			}
		}

		return RequestHelper::sanitize_field_value( implode( ' ', $parts ) );
	}

	/**
	 * Build metadata describing the current Fluent Forms form.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $form_instance Form instance or placeholder.
	 *
	 * @return array Form metadata.
	 */
	public function get_form_meta( $form_instance ): array {

		if ( ! is_object( $form_instance ) || ! isset( $form_instance->id, $form_instance->title ) ) {
			return [
				'form_id'    => 0,
				'form_title' => 'Unknown Form',
			];
		}

		return [
			'form_id'    => (int) $form_instance->id,
			'form_title' => (string) $form_instance->title,
		];
	}

	/**
	 * Expose admin settings helper.
	 *
	 * @since 1.1.0
	 *
	 * @return AdminSettings Fluent Forms admin settings handler.
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Mark Fluent Forms entry as spam when a spam-save verdict is pending.
	 *
	 * Hooked to `fluentform/submission_inserted` at priority 5 (before
	 * GlobalNotificationHandler at priority 10).
	 *
	 * @since 1.1.0
	 *
	 * @param int    $insert_id Submission ID in Fluent Forms.
	 * @param array  $form_data Submitted form data.
	 * @param object $form      Fluent Forms form object.
	 */
	public function maybe_mark_entry_spam( $insert_id, $form_data, $form ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		$spam_form_id = $this->submission_handler->get_spam_save_form_id();

		if ( $spam_form_id === null ) {
			return;
		}

		$current_form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;

		if ( $current_form_id !== $spam_form_id ) {
			return;
		}

		$insert_id = (int) $insert_id;

		if ( $insert_id <= 0 ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_submissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			[ 'status' => 'spam' ],
			[ 'id' => $insert_id ],
			[ '%s' ],
			[ '%d' ]
		);

		Logger::log(
			'Fluent Forms: marked entry as spam',
			[
				'form_id'  => $current_form_id,
				'entry_id' => $insert_id,
			]
		);
	}

	/**
	 * Restore email sending after Fluent Forms notification processing.
	 *
	 * Hooked to `fluentform/global_notify_completed`. Always removes
	 * the `pre_wp_mail` filter to prevent leaking into other emails.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $insert_id Submission ID.
	 * @param object $form      Form object.
	 */
	public function restore_email_sending( $insert_id, $form ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Cleanup for runtime email suppression.

		remove_filter( 'pre_wp_mail', '__return_false' ); // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Cleanup for runtime email suppression.
		$this->submission_handler->reset();
	}

	/**
	 * Output client signal hidden fields inside a Fluent Forms form.
	 *
	 * Hooked to `fluentform/form_element_start` which fires inside
	 * the form tag during server-side rendering.
	 *
	 * @since 1.1.0
	 *
	 * @param object $form Fluent Forms form object.
	 */
	public function output_client_signals( $form ): void {

		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		$form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;

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
	 * Check whether Fluent Forms stores entries for the given form.
	 *
	 * Returns true when entries are persisted (the default). When the form
	 * has "Delete entry data after form submission" enabled, returns false.
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return bool True when entries are stored.
	 */
	public function has_entry_storage( int $form_id ): bool {

		if ( ! class_exists( '\FluentForm\App\Helpers\Helper' ) ) {
			return true;
		}

		return ! FluentFormHelper::isEntryAutoDeleteEnabled( $form_id );
	}

	/**
	 * Set the current form being processed.
	 *
	 * Called before process_submission_synchronously() so normalize_form_data()
	 * can access the form definition without a direct parameter.
	 *
	 * @since 1.1.0
	 *
	 * @param object|null $form Fluent Forms form object.
	 */
	public function set_current_form( $form ): void {

		$this->current_form = $form;
	}
}

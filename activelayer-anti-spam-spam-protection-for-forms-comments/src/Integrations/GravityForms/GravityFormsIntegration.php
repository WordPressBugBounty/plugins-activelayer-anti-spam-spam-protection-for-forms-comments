<?php

namespace ActiveLayer\Integrations\GravityForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use GFAPI;

/**
 * Gravity Forms integration (synchronous save).
 *
 * Checks submissions at validation time, then marks spam entries and
 * suppresses notifications via post-save hooks. Entries are always created;
 * spam entries receive GF status 'spam' and their notifications are blocked.
 *
 * @since 1.1.0
 */
class GravityFormsIntegration extends BaseFormIntegration {

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
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param AdminSettings|null $admin_settings Optional admin settings override (for testing).
	 */
	public function __construct( ?AdminSettings $admin_settings = null ) {

		parent::__construct( 'Gravity Forms' );

		$this->admin_settings     = $admin_settings ?? new AdminSettings();
		$this->submission_handler = new SubmissionHandler( $this );
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
	 * Register Gravity Forms hooks and filters.
	 *
	 * @since 1.1.0
	 */
	private function hooks(): void {

		// Sync validation — runs API check before entry creation and stores verdict.
		add_filter( 'gform_validation', [ $this->submission_handler, 'handle_validation' ], 10, 2 );

		// Post-save: mark spam entries after creation. Note: gform_entry_post_save is a filter — handler must return $entry.
		add_filter( 'gform_entry_post_save', [ $this->submission_handler, 'handle_entry_post_save' ], 10, 2 );

		// Notification filter: suppress emails for spam entries.
		add_filter( 'gform_notification', [ $this->submission_handler, 'maybe_suppress_notification' ], 10, 3 );

		// Per-form settings in GF form editor.
		add_filter( 'gform_form_settings_fields', [ $this, 'add_form_settings_fields' ], 10, 2 );
		add_filter( 'gform_pre_form_settings_save', [ $this, 'save_form_settings' ] );

		// Inject client signals hidden fields.
		add_filter( 'gform_get_form_filter', [ $this, 'append_client_signals' ], 10, 2 );
	}

	/**
	 * Check if Gravity Forms is installed and active.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return class_exists( 'GFForms' );
	}

	/**
	 * This integration uses synchronous save mode (always sync).
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_sync_mode_enabled(): bool {

		return true;
	}

	/**
	 * Normalize Gravity Forms submission data to ActiveLayer payload.
	 *
	 * @since 1.1.0
	 *
	 * @param array $raw_data Raw form data with 'entry' and 'form' keys.
	 *
	 * @return array Normalized payload.
	 */
	public function normalize_form_data( array $raw_data ): array {

		$entry = $raw_data['entry'] ?? [];
		$form  = $raw_data['form'] ?? [];

		return [
			'name'        => $this->extract_name( $entry, $form ),
			'email'       => $this->extract_field_value_by_type( $entry, $form, 'email' ),
			'website_url' => $this->extract_field_value_by_type( $entry, $form, 'website' ),
			'message'     => $this->extract_field_value_by_type( $entry, $form, 'textarea' ),
			'ip'          => RequestHelper::get_user_ip(),
			'user_agent'  => RequestHelper::get_user_agent(),
		];
	}

	/**
	 * Build form metadata.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $form_instance Form array or form ID.
	 *
	 * @return array Form metadata with 'form_id' and 'form_title'.
	 */
	public function get_form_meta( $form_instance ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( is_array( $form_instance ) ) {
			return $this->build_meta_from_form_array( $form_instance );
		}

		$form_id = (int) $form_instance;

		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return $this->build_default_meta( $form_id );
		}

		$form = GFAPI::get_form( $form_id );

		return $form ? $this->build_meta_from_form_array( $form ) : $this->build_default_meta( $form_id );
	}

	/**
	 * Build metadata from a form array.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form Form array.
	 *
	 * @return array
	 */
	private function build_meta_from_form_array( array $form ): array {

		return [
			'form_id'    => isset( $form['id'] ) ? (int) $form['id'] : 0,
			'form_title' => ! empty( $form['title'] ) ? $form['title'] : 'Gravity Form',
		];
	}

	/**
	 * Build default metadata for a form ID.
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array
	 */
	private function build_default_meta( int $form_id ): array {

		return [
			'form_id'    => $form_id,
			'form_title' => 'Gravity Form',
		];
	}

	/**
	 * Expose admin settings helper.
	 *
	 * @since 1.1.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Add ActiveLayer toggle to Gravity Forms form settings.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields Existing form settings fields.
	 * @param array $form   Current form object.
	 *
	 * @return array Modified settings fields.
	 */
	public function add_form_settings_fields( array $fields, $form ): array {

		$form_id       = isset( $form['id'] ) ? (int) $form['id'] : 0;
		$form_settings = $this->admin_settings->get_form_settings( $form_id );

		$fields['form_options']['fields'][] = [
			'type'          => 'toggle',
			'name'          => 'activelayer_enabled',
			'label'         => esc_html__( 'ActiveLayer Spam Protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'description'   => esc_html__( 'Check submissions for spam using ActiveLayer AI before creating entries.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'default_value' => ! empty( $form_settings['enabled'] ),
		];

		return $fields;
	}

	/**
	 * Sync per-form settings from GF form editor save to our option.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form Form object being saved.
	 *
	 * @return array Unmodified form object.
	 */
	public function save_form_settings( $form ) {

		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		if ( $form_id <= 0 ) {
			return $form;
		}

		$enabled = ! empty( $form['activelayer_enabled'] );

		$settings = [
			'enabled' => $enabled,
		];

		$this->admin_settings->save_form_settings_full( $form_id, $settings );

		return $form;
	}

	/**
	 * Append client signal hidden fields to protected forms.
	 *
	 * @since 1.1.0
	 *
	 * @param string $form_string Complete form HTML.
	 * @param array  $form        Form object.
	 *
	 * @return string Modified form HTML.
	 */
	public function append_client_signals( string $form_string, $form ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->is_enabled() || ! SettingsHelper::has_api_key() ) {
			return $form_string;
		}

		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		if ( $form_id <= 0 ) {
			return $form_string;
		}

		$form_settings = $this->admin_settings->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return $form_string;
		}

		$signals_html = FieldRenderer::render_all();

		if ( empty( $signals_html ) ) {
			return $form_string;
		}

		// Insert before the closing </form> tag.
		$pos = strrpos( $form_string, '</form>' );

		if ( $pos !== false ) {
			$form_string = substr_replace( $form_string, $signals_html, $pos, 0 );
		}

		return $form_string;
	}

	/**
	 * Extract name value from entry using the first Name field.
	 *
	 * Handles both compound (first.3 + last.6) and simple name fields.
	 *
	 * @since 1.1.0
	 *
	 * @param array $entry Entry-like array.
	 * @param array $form  Form object.
	 *
	 * @return string Extracted name or empty string.
	 */
	private function extract_name( array $entry, array $form ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( empty( $form['fields'] ) ) {
			return '';
		}

		foreach ( $form['fields'] as $field ) {
			if ( $this->get_field_prop( $field, 'type' ) !== 'name' ) {
				continue;
			}

			$field_id = $this->get_field_prop( $field, 'id' );

			// Compound name field: first (.3) + last (.6).
			$first    = isset( $entry[ $field_id . '.3' ] ) ? trim( $entry[ $field_id . '.3' ] ) : '';
			$last     = isset( $entry[ $field_id . '.6' ] ) ? trim( $entry[ $field_id . '.6' ] ) : '';
			$compound = trim( $first . ' ' . $last );

			if ( $compound !== '' ) {
				return $compound;
			}

			// Simple name format (single input).
			$simple = $this->get_entry_value( $entry, $field_id );

			if ( $simple !== '' ) {
				return $simple;
			}
		}

		// Fallback: look for a text field with name-like label.
		return $this->extract_field_value_by_label_hint( $entry, $form, [ 'name', 'full name', 'your name' ] );
	}

	/**
	 * Extract the first field value matching a Gravity Forms field type.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $entry Entry-like array.
	 * @param array  $form  Form object.
	 * @param string $type  GF field type (email, website, textarea).
	 *
	 * @return string Field value or empty string.
	 */
	private function extract_field_value_by_type( array $entry, array $form, string $type ): string {

		if ( empty( $form['fields'] ) ) {
			return '';
		}

		foreach ( $form['fields'] as $field ) {
			if ( $this->get_field_prop( $field, 'type' ) !== $type ) {
				continue;
			}

			$value = $this->get_entry_value( $entry, $this->get_field_prop( $field, 'id' ) );

			if ( $value !== '' ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Fallback: find a text field whose label matches common hints.
	 *
	 * @since 1.1.0
	 *
	 * @param array    $entry Entry-like array.
	 * @param array    $form  Form object.
	 * @param string[] $hints Lowercase label substrings to match.
	 *
	 * @return string Field value or empty string.
	 */
	private function extract_field_value_by_label_hint( array $entry, array $form, array $hints ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( empty( $form['fields'] ) ) {
			return '';
		}

		foreach ( $form['fields'] as $field ) {
			$lower = strtolower( (string) $this->get_field_prop( $field, 'label' ) );

			foreach ( $hints as $hint ) {
				if ( strpos( $lower, $hint ) === false ) {
					continue;
				}

				$value = $this->get_entry_value( $entry, $this->get_field_prop( $field, 'id' ) );

				if ( $value !== '' ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Get a property from a GF field (object or array).
	 *
	 * @since 1.1.0
	 *
	 * @param mixed  $field    GF_Field object or array.
	 * @param string $property Property name.
	 *
	 * @return mixed Property value or empty string.
	 */
	private function get_field_prop( $field, string $property ) {

		if ( is_object( $field ) ) {
			return $field->{$property} ?? '';
		}

		if ( is_array( $field ) ) {
			return $field[ $property ] ?? '';
		}

		return '';
	}

	/**
	 * Get a trimmed entry value by field ID.
	 *
	 * @since 1.1.0
	 *
	 * @param array $entry    Entry-like array.
	 * @param mixed $field_id Field ID.
	 *
	 * @return string Trimmed value or empty string.
	 */
	private function get_entry_value( array $entry, $field_id ): string {

		$key = (string) $field_id;

		return isset( $entry[ $key ] ) && $entry[ $key ] !== '' ? trim( $entry[ $key ] ) : '';
	}
}

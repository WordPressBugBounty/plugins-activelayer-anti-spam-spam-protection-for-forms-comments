<?php

namespace ActiveLayer\Integrations\ElementorForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;

/**
 * Elementor Forms integration (synchronous only).
 *
 * Hooks into Elementor Pro's form validation to check submissions
 * for spam via the ActiveLayer API before they are processed.
 *
 * @since 1.1.0
 */
class ElementorFormsIntegration extends BaseFormIntegration {

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

		parent::__construct( 'Elementor Forms' );

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
	 * Register Elementor Pro Forms hooks and filters.
	 *
	 * @since 1.1.0
	 */
	private function hooks(): void {

		// Sync validation - blocks spam before form actions run.
		add_action( 'elementor_pro/forms/validation', [ $this->submission_handler, 'handle_validation' ], 10, 2 );

		// Inject client signal hidden fields into Elementor form widgets.
		add_filter( 'elementor/widget/render_content', [ $this, 'append_client_signals' ], 10, 2 );
	}

	/**
	 * Check if Elementor Pro Forms is installed and active.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return defined( 'ELEMENTOR_PRO_VERSION' ) && class_exists( '\ElementorPro\Modules\Forms\Module' );
	}

	/**
	 * This integration only supports synchronous mode.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_sync_mode_enabled(): bool {

		return true;
	}

	/**
	 * Normalize Elementor Forms submission data to ActiveLayer payload.
	 *
	 * Extracts field values by their Elementor field type:
	 * - First `text` field maps to name.
	 * - First `email` field maps to email.
	 * - First `url` field maps to website_url.
	 * - First `textarea` field maps to message.
	 *
	 * @since 1.1.0
	 *
	 * @param array $raw_data Raw form data with 'record' (Form_Record) and 'form' keys.
	 *
	 * @return array Normalized payload.
	 */
	public function normalize_form_data( array $raw_data ): array {

		$record = isset( $raw_data['record'] ) ? $raw_data['record'] : null;

		$values = [
			'name'        => '',
			'email'       => '',
			'website_url' => '',
			'message'     => '',
		];

		if ( $record && is_object( $record ) && method_exists( $record, 'get' ) ) {
			$fields = $record->get( 'fields' );

			if ( is_array( $fields ) ) {
				$values = $this->extract_fields_by_type( $fields, $values );
			}
		}

		return [
			'name'        => $values['name'],
			'email'       => $values['email'],
			'website_url' => $values['website_url'],
			'message'     => $values['message'],
			'ip'          => RequestHelper::get_user_ip(),
			'user_agent'  => RequestHelper::get_user_agent(),
		];
	}

	/**
	 * Build form metadata from a form instance.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $form_instance Form_Record instance or other form reference.
	 *
	 * @return array Form metadata with 'form_id' and 'form_title'.
	 */
	public function get_form_meta( $form_instance ): array {

		if ( is_object( $form_instance ) && method_exists( $form_instance, 'get_form_settings' ) ) {
			$form_name  = $form_instance->get_form_settings( 'form_name' );
			$element_id = $form_instance->get_form_settings( 'id' );

			$meta = [
				'form_id'    => $element_id ? $this->admin_settings->element_id_to_int( (string) $element_id ) : 0,
				'form_title' => ! empty( $form_name ) ? (string) $form_name : 'Elementor Form',
			];

			// Store the page post ID for edit link generation.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$page_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

			if ( $page_id > 0 ) {
				$meta['page_id'] = $page_id;
			}

			return $meta;
		}

		return [
			'form_id'    => 0,
			'form_title' => 'Elementor Form',
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
	 * Append client signal hidden fields to Elementor form widgets.
	 *
	 * Only modifies the output of Elementor form widgets. Injects
	 * FieldRenderer::render_all() before the closing </form> tag.
	 *
	 * @since 1.1.0
	 *
	 * @param string $content Rendered widget HTML.
	 * @param mixed  $widget  Elementor widget instance.
	 *
	 * @return string Modified widget HTML.
	 */
	public function append_client_signals( string $content, $widget ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		// Only process Elementor form widgets.
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || $widget->get_name() !== 'form' ) {
			return $content;
		}

		if ( ! $this->is_enabled() || ! SettingsHelper::has_api_key() ) {
			return $content;
		}

		// Extract element ID from the widget to check per-form protection.
		$form_id = 0;

		if ( method_exists( $widget, 'get_id' ) ) {
			$element_id = $widget->get_id();

			if ( ! empty( $element_id ) ) {
				$form_id = $this->admin_settings->element_id_to_int( (string) $element_id );
			}
		}

		if ( $form_id > 0 ) {
			$form_settings = $this->admin_settings->get_form_settings( $form_id );

			if ( empty( $form_settings['enabled'] ) ) {
				return $content;
			}
		}

		$signals_html = FieldRenderer::render_all();

		if ( empty( $signals_html ) ) {
			return $content;
		}

		// Insert before the closing </form> tag.
		$pos = strrpos( $content, '</form>' );

		if ( $pos !== false ) {
			$content = substr_replace( $content, $signals_html, $pos, 0 );
		}

		return $content;
	}

	/**
	 * Extract field values from Elementor form fields by type.
	 *
	 * Maps the first field of each type to the corresponding slot:
	 * - text    -> name
	 * - email   -> email
	 * - url     -> website_url
	 * - textarea -> message
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields Elementor fields array keyed by custom_id.
	 * @param array $values Current extracted values.
	 *
	 * @return array Updated values.
	 */
	private function extract_fields_by_type( array $fields, array $values ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$type_slot_map = [
			'text'     => 'name',
			'email'    => 'email',
			'url'      => 'website_url',
			'textarea' => 'message',
		];

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['type'] ) ) {
				continue;
			}

			$type = $field['type'];

			if ( ! isset( $type_slot_map[ $type ] ) ) {
				continue;
			}

			$slot = $type_slot_map[ $type ];

			// Only fill the first matching field for each slot.
			if ( $values[ $slot ] !== '' ) {
				continue;
			}

			$value = isset( $field['value'] ) ? trim( (string) $field['value'] ) : '';

			if ( $value !== '' ) {
				$values[ $slot ] = $value;
			}
		}

		return $values;
	}
}

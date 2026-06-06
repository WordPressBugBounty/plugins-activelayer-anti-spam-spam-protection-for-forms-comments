<?php

namespace ActiveLayer\Integrations\SureForms;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SureForms Integration (synchronous only).
 *
 * Uses a two-hook strategy: srfm_before_fields_processing (filter) to cache
 * field-type-to-value mapping, then srfm_before_submission (action) to run
 * the sync spam check and block via wp_send_json_error().
 *
 * @since 1.1.0
 */
class SureFormsIntegration extends BaseFormIntegration {

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
	 * Cached field data from the filter hook.
	 *
	 * Populated by cache_field_data() during srfm_before_fields_processing,
	 * consumed by normalize_form_data() during srfm_before_submission.
	 *
	 * @since 1.1.0
	 *
	 * @var array|null
	 */
	private $cached_field_data;

	/**
	 * Wire up SureForms integration services.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {

		parent::__construct( 'SureForms' );

		$this->submission_handler = new SubmissionHandler( $this );
		$this->admin_settings     = new AdminSettings( $this );
		$this->cached_field_data  = null;
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
	 * Hook 1 (filter): srfm_before_fields_processing caches field-type-to-value mapping.
	 * Hook 2 (action): srfm_before_submission runs the spam check and blocks if needed.
	 *
	 * @since 1.1.0
	 */
	private function hooks(): void {

		add_filter( 'srfm_before_fields_processing', [ $this, 'cache_field_data' ], 5 );
		add_action( 'srfm_before_submission', [ $this->submission_handler, 'handle_submission' ], 5 );
		add_action( 'srfm_after_field_content', [ $this, 'output_client_signal_fields' ], 10, 2 );

		// Clean up per-form options when a SureForms form is deleted.
		add_action( 'before_delete_post', [ $this->admin_settings, 'cleanup_form_settings' ] );
	}

	/**
	 * Check if SureForms is installed.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return defined( 'SRFM_VER' );
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
	 * Cache field-type-to-value mapping from the filter hook.
	 *
	 * Hooked to srfm_before_fields_processing. Iterates $form_data keys,
	 * skips non-field entries (keys without -lbl-), and extracts field type
	 * via resilient parsing. MUST return $form_data unmodified.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form_data Raw form data with field keys.
	 *
	 * @return array Unmodified form data.
	 */
	public function cache_field_data( array $form_data ): array {

		$this->cached_field_data = [];

		foreach ( $form_data as $key => $value ) {
			// Skip non-field entries (no -lbl- delimiter).
			if ( strpos( $key, '-lbl-' ) === false ) {
				continue;
			}

			$field_type = $this->extract_field_type( $key );

			if ( $field_type !== '' ) {
				$this->cached_field_data[ $field_type ][] = $value;
			}
		}

		return $form_data;
	}

	/**
	 * Extract field type from a SureForms field key.
	 *
	 * Canonical key format in SureForms is:
	 * srfm-{type}-{block_id}-lbl-{encrypted_label}-{slug}.
	 * For compatibility, this method also handles legacy/non-canonical
	 * keys by falling back to the last prefix segment.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key SureForms field key.
	 *
	 * @return string Extracted field type or empty string.
	 */
	private function extract_field_type( string $key ): string {

		$parts = explode( '-lbl-', $key, 2 );

		if ( empty( $parts[0] ) ) {
			return '';
		}

		$prefix_parts = explode( '-', $parts[0] );

		// Canonical SureForms keys: srfm-{type}-{block_id}-lbl-{...}.
		if ( ! empty( $prefix_parts[0] ) && $prefix_parts[0] === 'srfm' && ! empty( $prefix_parts[1] ) ) {
			return $prefix_parts[1];
		}

		// Legacy/non-canonical fallback used in tests and defensive parsing.
		$field_type = end( $prefix_parts );

		return is_string( $field_type ) ? $field_type : '';
	}

	/**
	 * Get cached field data from the filter hook.
	 *
	 * @since 1.1.0
	 *
	 * @return array|null Cached field data or null if filter didn't fire.
	 */
	public function get_cached_field_data(): ?array {

		return $this->cached_field_data;
	}

	/**
	 * Clear cached field data.
	 *
	 * Called after processing to prevent stale state between requests.
	 *
	 * @since 1.1.0
	 */
	public function clear_cached_field_data(): void {

		$this->cached_field_data = null;
	}

	/**
	 * Map raw SureForms submission to ActiveLayer expected payload.
	 *
	 * Uses cached field data (populated by filter hook) to map fields to slots.
	 * Falls back to parsing $raw_data directly if cached data is null.
	 *
	 * @since 1.1.0
	 *
	 * @param array $raw_data Raw submitted form data.
	 *
	 * @return array Normalized submission payload.
	 */
	protected function normalize_form_data( array $raw_data ): array {

		$field_data = $this->cached_field_data;

		// Fall back to parsing raw_data directly if cache is empty.
		if ( $field_data === null ) {
			$this->cache_field_data( $raw_data );
			$field_data = $this->cached_field_data;
		}

		$values = [
			'name'        => '',
			'email'       => '',
			'website_url' => '',
			'message'     => '',
		];

		if ( is_array( $field_data ) ) {
			foreach ( $field_data as $type => $type_values ) {
				$slot = $this->map_field_to_slot( $type );

				if ( $slot === null ) {
					continue;
				}

				// Only fill the first match for each slot.
				if ( $values[ $slot ] === '' && ! empty( $type_values[0] ) ) {
					$values[ $slot ] = RequestHelper::sanitize_field_value( $type_values[0] );
				}
			}
		}

		$values['ip']         = RequestHelper::get_user_ip();
		$values['user_agent'] = RequestHelper::get_user_agent();

		return $values;
	}

	/**
	 * Map a SureForms field type to a normalized slot name.
	 *
	 * @since 1.1.0
	 *
	 * @param string $field_type SureForms field type.
	 *
	 * @return string|null Slot name or null if no mapping.
	 */
	private function map_field_to_slot( string $field_type ): ?string {

		$slot_map = [
			'email'    => 'email',
			'textarea' => 'message',
			'url'      => 'website_url',
			'input'    => 'name',
		];

		return $slot_map[ $field_type ] ?? null;
	}

	/**
	 * Build metadata describing the current SureForms form.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $form_instance Form ID (int).
	 *
	 * @return array Form metadata.
	 */
	public function get_form_meta( $form_instance ): array {

		$form_id = (int) $form_instance;

		return [
			'form_id'    => $form_id,
			'form_title' => $form_id > 0 ? (string) get_the_title( $form_id ) : 'Unknown Form',
		];
	}

	/**
	 * Expose admin settings helper.
	 *
	 * @since 1.1.0
	 *
	 * @return AdminSettings SureForms admin settings handler.
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Output hidden client-signal fields in SureForms markup.
	 *
	 * Runs during form rendering after field content and injects
	 * environment, behavioral, and honeypot hidden inputs for forms
	 * that have ActiveLayer protection enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $post    Current SureForms form post object (unused).
	 * @param int   $form_id Current SureForms form ID.
	 */
	public function output_client_signal_fields( $post, int $form_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( $form_id <= 0 ) {
			return;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		$form_settings = $this->admin_settings->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return;
		}

		FieldRenderer::output_all();
	}
}

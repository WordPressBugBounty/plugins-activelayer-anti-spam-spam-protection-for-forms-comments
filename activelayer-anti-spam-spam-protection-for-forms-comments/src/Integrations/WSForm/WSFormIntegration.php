<?php

namespace ActiveLayer\Integrations\WSForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use WS_Form_Action;

/**
 * WS Form integration (synchronous block only).
 *
 * Blocks spam at the wsf_submit_validate filter (before WS Form writes the
 * entry or runs actions such as Send Email) and injects client-signal hidden
 * inputs into the rendered form via the wsf_shortcode HTML filter.
 *
 * @since 1.4.0
 */
class WSFormIntegration extends BaseFormIntegration {

	/**
	 * Submission handler.
	 *
	 * @since 1.4.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings helper.
	 *
	 * @since 1.4.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Form-editor Spam-tab panel.
	 *
	 * @since 1.4.0
	 *
	 * @var EditorPanel
	 */
	private $editor_panel;

	/**
	 * Wire up WS Form integration services.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {

		parent::__construct( 'WS Form', 'ws_form' );

		$this->submission_handler = new SubmissionHandler( $this );
		$this->admin_settings     = new AdminSettings( $this );
		$this->editor_panel       = new EditorPanel( $this );
	}

	/**
	 * Bootstrap integration hooks.
	 *
	 * @since 1.4.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Hook 1 (filter): wsf_submit_validate runs the sync spam check and blocks spam.
	 * Hook 2 (filter): wsf_shortcode appends client-signal hidden inputs.
	 * Hook 3 (action): wsf_form_delete cleans up per-form options.
	 * Hook 4 (panel): EditorPanel adds the protection toggle to the Spam tab.
	 *
	 * @since 1.4.0
	 */
	private function hooks(): void {

		// Primary intercept: form-level validation filter (before entry + actions).
		add_filter( 'wsf_submit_validate', [ $this->submission_handler, 'handle_validate' ], 10, 3 );

		// Client-signal hidden inputs: rendered into a sibling box and cloned
		// into the form by the bridge script after WS Form's client-side render.
		add_filter( 'wsf_shortcode', [ $this, 'append_client_signals' ], 10, 3 );

		// Remove per-form options when a WS Form form is deleted.
		add_action( 'wsf_form_delete', [ $this->admin_settings, 'cleanup_form_settings' ] );

		// Surface the protection toggle inside WS Form's per-form Spam tab.
		$this->editor_panel->hooks();
	}

	/**
	 * Check if WS Form is installed.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return defined( 'WS_FORM_VERSION' );
	}

	/**
	 * WS Form supports synchronous mode only.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public function is_sync_mode_enabled(): bool {

		return true;
	}

	/**
	 * Expose the admin settings helper (used by IntegrationRegistry + handler).
	 *
	 * @since 1.4.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Extract submitted field values grouped by WS Form field type.
	 *
	 * Iterates the typed $submit->form_object structure
	 * (groups -> sections -> fields) and reads each field's submitted value
	 * via WS_Form_Action::get_submit_value(). Empty values are skipped.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $submit WS_Form_Submit instance.
	 *
	 * @return array Map of field type => list of submitted values.
	 */
	public function extract_fields_by_type( $submit ): array {

		$by_type = [];

		if (
			! is_object( $submit ) ||
			! isset( $submit->form_object->groups ) ||
			! is_array( $submit->form_object->groups )
		) {
			return $by_type;
		}

		foreach ( $submit->form_object->groups as $group ) {
			$this->extract_group_fields( $submit, $group, $by_type );
		}

		return $by_type;
	}

	/**
	 * Extract field values from a single WS Form group.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $submit  WS_Form_Submit instance.
	 * @param mixed $group   WS Form group object.
	 * @param array $by_type Running map of type => values (modified by reference).
	 */
	private function extract_group_fields( $submit, $group, array &$by_type ): void {

		if ( ! isset( $group->sections ) || ! is_array( $group->sections ) ) {
			return;
		}

		foreach ( $group->sections as $section ) {
			$this->extract_section_fields( $submit, $section, $by_type );
		}
	}

	/**
	 * Extract field values from a single WS Form section.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $submit  WS_Form_Submit instance.
	 * @param mixed $section WS Form section object.
	 * @param array $by_type Running map of type => values (modified by reference).
	 */
	private function extract_section_fields( $submit, $section, array &$by_type ): void {

		if ( ! isset( $section->fields ) || ! is_array( $section->fields ) ) {
			return;
		}

		foreach ( $section->fields as $field ) {
			if ( ! isset( $field->type, $field->id ) ) {
				continue;
			}

			$value = WS_Form_Action::get_submit_value( $submit, 'field_' . (int) $field->id, '' );

			if ( $value === '' || $value === null ) {
				continue;
			}

			$by_type[ (string) $field->type ][] = $value;
		}
	}

	/**
	 * Map a WS Form field-type map to the normalized ActiveLayer payload.
	 *
	 * @since 1.4.0
	 *
	 * @param array $raw_data Map of field type => list of values (from extract_fields_by_type()).
	 *
	 * @return array Normalized submission payload.
	 */
	protected function normalize_form_data( array $raw_data ): array {

		$values = [
			'name'        => '',
			'email'       => '',
			'website_url' => '',
			'message'     => '',
		];

		$slot_map = [
			'email'    => 'email',
			'textarea' => 'message',
			'url'      => 'website_url',
			'text'     => 'name',
		];

		foreach ( $raw_data as $type => $type_values ) {
			$slot = $slot_map[ $type ] ?? null;

			if ( $slot === null || ! is_array( $type_values ) ) {
				continue;
			}

			if ( $values[ $slot ] === '' && ! empty( $type_values[0] ) ) {
				$values[ $slot ] = RequestHelper::sanitize_field_value( $type_values[0] );
			}
		}

		$values['ip']         = RequestHelper::get_user_ip();
		$values['user_agent'] = RequestHelper::get_user_agent();

		return $values;
	}

	/**
	 * Build metadata for the current WS Form submission.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $form_instance WS_Form_Submit instance.
	 *
	 * @return array Form metadata.
	 */
	public function get_form_meta( $form_instance ): array {

		$form_id = 0;
		$title   = 'Unknown Form';

		if ( is_object( $form_instance ) ) {
			$form_id = isset( $form_instance->form_id ) ? (int) $form_instance->form_id : 0;

			if ( isset( $form_instance->form_object->label ) && $form_instance->form_object->label !== '' ) {
				$title = (string) $form_instance->form_object->label;
			}
		}

		return [
			'form_id'    => $form_id,
			'form_title' => $title,
		];
	}

	/**
	 * Append the client-signal box after the rendered form HTML.
	 *
	 * Hooked to wsf_shortcode. The hidden box is cloned into the <form> by the
	 * bridge script after WS Form's client-side render (see inject_signals());
	 * WS Form then serializes the entire <form> DOM via new FormData() at
	 * submit, so the cloned inputs are submitted in $_POST and read by the
	 * signal enrichers.
	 *
	 * WS Form fires this filter with a varying argument count: 3 args from the
	 * shortcode handler (form_html, atts, content) and 1 arg from an inner
	 * render pass inside form_html(). Defaults on $atts/$content prevent an
	 * ArgumentCountError on the 1-arg fire; without shortcode atts the form id
	 * resolves to 0 and the HTML is returned unchanged.
	 *
	 * @since 1.4.0
	 *
	 * @param string $form_html Rendered form HTML.
	 * @param array  $atts      Shortcode attributes (contains 'id'). Optional.
	 * @param string $content   Shortcode content (unused). Optional.
	 *
	 * @return string Possibly modified form HTML.
	 */
	public function append_client_signals( string $form_html, $atts = [], $content = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		if ( ! $this->is_enabled() || ! SettingsHelper::has_api_key() ) {
			return $form_html;
		}

		$form_id = ( is_array( $atts ) && isset( $atts['id'] ) ) ? (int) $atts['id'] : 0;

		if ( $form_id <= 0 ) {
			return $form_html;
		}

		if ( empty( $this->admin_settings->get_form_settings( $form_id )['enabled'] ) ) {
			return $form_html;
		}

		return $this->inject_signals( $form_html, $form_id );
	}

	/**
	 * Attach client-signal fields so they survive WS Form's client-side render.
	 *
	 * WS Form rebuilds the form body in JavaScript and clears the <form>
	 * element's children during form_build(), so signals injected directly
	 * inside the form are wiped before submit and never reach $_POST. Instead
	 * we render them into a hidden sibling box placed after the form and enqueue
	 * a small bridge script that clones them into the form once WS Form fires
	 * its `wsf-rendered` event. The existing collectors then populate and submit
	 * them.
	 *
	 * @since 1.4.0
	 *
	 * @param string $form_html Form HTML.
	 * @param int    $form_id   WS Form form id.
	 *
	 * @return string Modified HTML, or original if no signals are rendered.
	 */
	private function inject_signals( string $form_html, int $form_id ): string {

		$signals_html = FieldRenderer::render_all();

		if ( $signals_html === '' ) {
			return $form_html;
		}

		$this->enqueue_signal_bridge();

		$box = sprintf(
			'<div class="activelayer-wsform-signals" data-wsf-form-id="%d" hidden>%s</div>',
			$form_id,
			$signals_html
		);

		return $form_html . $box;
	}

	/**
	 * Enqueue the WS Form client-signal bridge script (once per request).
	 *
	 * @since 1.4.0
	 */
	private function enqueue_signal_bridge(): void {

		$handle = 'activelayer-wsform-client-signals';

		if ( wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			ACTIVELAYER_PLUGIN_URL . 'assets/js/wsform-client-signals.js',
			[ 'jquery' ],
			ACTIVELAYER_PLUGIN_VERSION,
			[ 'in_footer' => true ]
		);
	}
}

<?php

namespace ActiveLayer\Integrations\CubeWP;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\Submission\SubmissionBodySanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protect CubeWP Forms before leads, uploads and notifications are created.
 *
 * @since 1.8.0
 */
class CubeWPIntegration extends BaseFormIntegration {

	/**
	 * Per-form settings.
	 *
	 * @since 1.8.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Submission handler.
	 *
	 * @since 1.8.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Native form editor panel.
	 *
	 * @since 1.8.0
	 *
	 * @var EditorPanel
	 */
	private $editor_panel;

	/**
	 * Construct the provider and its services.
	 *
	 * @since 1.8.0
	 */
	public function __construct() {

		parent::__construct( 'CubeWP Forms', 'cubewp_forms' );

		$this->admin_settings     = new AdminSettings( $this );
		$this->submission_handler = new SubmissionHandler( $this );
		$this->editor_panel       = new EditorPanel( $this );
	}

	/**
	 * Register both AJAX paths and the separate CubeWP REST submission path.
	 *
	 * @since 1.8.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register the provider hooks.
	 *
	 * @since 1.8.0
	 */
	private function hooks(): void {

		add_action( 'wp_ajax_cubewp_submit_custom_form', [ $this->submission_handler, 'handle_submission' ], 5 );
		add_action( 'wp_ajax_nopriv_cubewp_submit_custom_form', [ $this->submission_handler, 'handle_submission' ], 5 );
		add_filter( 'rest_request_before_callbacks', [ $this->submission_handler, 'handle_rest_submission' ], 10, 3 );
		add_filter( 'do_shortcode_tag', [ $this, 'add_client_signal_fields' ], 10, 3 );
		add_action( 'before_delete_post', [ $this->admin_settings, 'cleanup_form_settings' ] );

		$this->editor_panel->hooks();
	}

	/**
	 * Detect CubeWP Forms rather than the standalone framework.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Whether CubeWP Forms is loaded.
	 */
	public function is_active(): bool {

		return defined( 'CUBEWP_FORMS_VERSION' );
	}

	/**
	 * Check synchronously before CubeWP performs submission side effects.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Always true.
	 */
	public function is_sync_mode_enabled(): bool {

		return true;
	}

	/**
	 * Expose per-form settings to the central Integrations screen.
	 *
	 * @since 1.8.0
	 *
	 * @return AdminSettings Settings service.
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Insert shared behavioral, environment and honeypot fields inside the form.
	 *
	 * CubeWP's Elementor widget uses this same shortcode.
	 *
	 * @since 1.8.0
	 *
	 * @param string       $output Rendered shortcode HTML.
	 * @param string       $tag    Shortcode tag.
	 * @param array|string $attrs  Shortcode attributes.
	 *
	 * @return string Form HTML with signals when protection is enabled.
	 */
	public function add_client_signal_fields( string $output, string $tag, $attrs ): string {

		if ( $tag !== 'cwpCustomForm' || ! is_array( $attrs ) || ! $this->is_enabled() ) {
			return $output;
		}

		$form_id = isset( $attrs['form_id'] ) && is_scalar( $attrs['form_id'] ) ? absint( $attrs['form_id'] ) : 0;

		if ( $form_id <= 0 || get_post_type( $form_id ) !== 'cwp_forms' || empty( $this->admin_settings->get_form_settings( $form_id )['enabled'] ) || strpos( $output, '</form>' ) === false ) {
			return $output;
		}

		return str_replace( '</form>', FieldRenderer::render_all() . '</form>', $output );
	}

	/**
	 * Map server-defined field types to the standard ActiveLayer payload.
	 *
	 * Passwords, uploads and unknown field names are never included.
	 *
	 * @since 1.8.0
	 *
	 * @param array $raw_data Unslashed fields and server-side field definitions.
	 *
	 * @return array Normalized submission.
	 */
	protected function normalize_form_data( array $raw_data ): array {

		$values = [
			'name'        => '',
			'email'       => '',
			'website_url' => '',
			'message'     => '',
		];
		$values = $this->map_field_values( $raw_data['fields'] ?? [], $raw_data['field_options'] ?? [], $values );

		$values['ip']         = RequestHelper::get_user_ip();
		$values['user_agent'] = RequestHelper::get_user_agent();

		return $values;
	}

	/**
	 * Map configured text and repeaters, preserving URL encoding and excluding secrets.
	 *
	 * @since 1.8.0
	 *
	 * @param array $fields  Submitted field values.
	 * @param array $options Resolved server-side field definitions.
	 * @param array $values  Standard payload values collected so far.
	 *
	 * @return array Standard payload containing every supported text value.
	 */
	private function map_field_values( array $fields, array $options, array $values ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Handles native field shapes and sensitive field exclusions.

		$slots = [
			'text'  => 'name',
			'email' => 'email',
			'url'   => 'website_url',
		];

		foreach ( $options as $name => $field ) {
			$type  = $field['type'] ?? '';
			$value = $fields[ $name ] ?? '';

			if ( in_array( $type, [ 'password', 'file', 'image', 'gallery' ], true ) ) {
				continue;
			}

			if ( $type === 'repeating_field' ) {
				$values = $this->map_field_values( is_array( $value ) ? $value : [], $field['sub_fields'] ?? [], $values );

				continue;
			}

			$slot = is_array( $value ) ? 'message' : ( $slots[ $type ] ?? 'message' );

			if ( $slot !== 'message' && $values[ $slot ] === '' ) {
				$values[ $slot ] = $slot === 'website_url' ? esc_url_raw( (string) $value ) : RequestHelper::sanitize_field_value( $value );

				continue;
			}

			$value = SubmissionBodySanitizer::sanitize( $value );

			if ( $value !== '' ) {
				$values['message'] .= ( $values['message'] !== '' ? "\n" : '' ) . $value;
			}
		}

		return $values;
	}

	/**
	 * Build tracking metadata for a CubeWP form.
	 *
	 * @since 1.8.0
	 *
	 * @param mixed $form_instance CubeWP form ID.
	 *
	 * @return array Form identity and title.
	 */
	public function get_form_meta( $form_instance ): array {

		$form_id = (int) $form_instance;

		return [
			'form_id'    => $form_id,
			'form_title' => (string) get_the_title( $form_id ),
		];
	}
}

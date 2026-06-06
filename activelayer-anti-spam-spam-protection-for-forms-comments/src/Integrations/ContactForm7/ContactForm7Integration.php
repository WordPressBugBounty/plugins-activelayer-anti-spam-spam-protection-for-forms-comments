<?php

namespace ActiveLayer\Integrations\ContactForm7;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use WPCF7_ContactForm;
use WPCF7_FormTag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact Form 7 Integration (synchronous only).
 *
 * @since 1.0.0
 */
class ContactForm7Integration extends BaseFormIntegration {

	private const SUPPORTED_TAGS = [
		'activelayer:name'    => 'name',
		'activelayer:email'   => 'email',
		'activelayer:url'     => 'website_url',
		'activelayer:message' => 'message',
	];

	/**
	 * Submission handler.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings helper.
	 *
	 * @since 1.0.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Wire up CF7 integration services.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		parent::__construct( 'Contact Form 7' );

		$this->submission_handler = new SubmissionHandler( $this );
		$this->admin_settings     = new AdminSettings( $this );
	}

	/**
	 * Bootstrap integration hooks.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register CF7 related hooks and filters.
	 *
	 * @since 1.0.0
	 */
	private function hooks(): void {

		add_action( 'wpcf7_before_send_mail', [ $this->submission_handler, 'handle_submission' ], 5, 3 );

		add_filter( 'wpcf7_editor_panels', [ $this->admin_settings, 'add_editor_panel' ], 10, 1 );
		add_action( 'wpcf7_save_contact_form', [ $this->admin_settings, 'save_form_settings' ], 10, 1 );

		// Add hidden field for environment signals to protected forms.
		add_filter( 'wpcf7_form_elements', [ $this, 'add_environment_signals_field' ], 10, 1 );
	}

	/**
	 * Add the hidden environment signals field to CF7 form HTML.
	 *
	 * Only adds for forms that have ActiveLayer protection enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param string $form_elements Form HTML elements.
	 *
	 * @return string Modified form HTML.
	 */
	public function add_environment_signals_field( string $form_elements ): string {

		if ( ! $this->is_enabled() ) {
			return $form_elements;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return $form_elements;
		}

		// Get current form to check if protected.
		$contact_form = wpcf7_get_current_contact_form();

		if ( ! $contact_form ) {
			return $form_elements;
		}

		$form_settings = $this->admin_settings->get_form_settings( $contact_form->id() );

		if ( empty( $form_settings['enabled'] ) ) {
			return $form_elements;
		}

		return $form_elements . FieldRenderer::render_all();
	}

	/**
	 * Check if Contact Form 7 is installed.
	 *
	 * @since 1.0.0
	 */
	public function is_active(): bool {

		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * The integration only supports synchronous mode for CF7.
	 *
	 * @since 1.0.0
	 */
	public function is_sync_mode_enabled(): bool {

		return true;
	}

	/**
	 * Map raw CF7 submission to ActiveLayer expected payload.
	 *
	 * @param array                  $raw_data     Raw submitted form data.
	 * @param WPCF7_ContactForm|null $contact_form Optional contact form context.
	 *
	 * @return array Normalized submission payload.
	 *
	 * @since 1.0.0
	 */
	public function normalize_form_data( array $raw_data, ?WPCF7_ContactForm $contact_form = null ): array {

		// Support base class calling with packaged raw_data (single-arg).
		if ( $contact_form === null && isset( $raw_data['contact_form'] ) ) {
			$contact_form = $raw_data['contact_form'];
			$raw_data     = $raw_data['posted_data'] ?? [];
		}

		unset( $raw_data['activelayer_cf7'] );

		$fields = $this->get_tagged_field_values( $raw_data, $contact_form );

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
	 * Extract values explicitly tagged via activelayer:* options.
	 *
	 * @since 1.0.0
	 *
	 * @param array                  $raw_data     Submission payload.
	 * @param WPCF7_ContactForm|null $contact_form Contact form instance.
	 *
	 * @return array<string,string> Tagged values keyed by slot.
	 */
	private function get_tagged_field_values( array $raw_data, ?WPCF7_ContactForm $contact_form ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$values = [
			'name'        => '',
			'email'       => '',
			'website_url' => '',
			'message'     => '',
		];

		if ( ! $contact_form instanceof WPCF7_ContactForm || ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return $values;
		}

		foreach ( $contact_form->scan_form_tags() as $tag ) {
			if ( ! $tag instanceof WPCF7_FormTag ) {
				continue;
			}

			$slot = $this->resolve_slot_from_tag( $tag );

			if ( ! $slot || $values[ $slot ] !== '' ) {
				continue;
			}

			$field_name = trim( (string) ( $tag->name ?? '' ) );

			if ( $field_name === '' || ! array_key_exists( $field_name, $raw_data ) ) {
				continue;
			}

			$values[ $slot ] = RequestHelper::sanitize_field_value( $raw_data[ $field_name ] );

			if ( ! in_array( '', $values, true ) ) {
				break;
			}
		}

		return $values;
	}

	/**
	 * Resolve supported slot for a given CF7 form tag.
	 *
	 * @since 1.0.0
	 *
	 * @param WPCF7_FormTag $tag Contact Form 7 tag instance.
	 *
	 * @return string|null Slot identifier or null when not tagged.
	 */
	private function resolve_slot_from_tag( WPCF7_FormTag $tag ): ?string {

		$options = (array) ( $tag->options ?? [] );

		foreach ( $options as $option ) {
			$normalized = strtolower( trim( (string) $option ) );

			if ( isset( self::SUPPORTED_TAGS[ $normalized ] ) ) {
				return self::SUPPORTED_TAGS[ $normalized ];
			}
		}

		return null;
	}

	/**
	 * Build metadata describing the current CF7 form.
	 *
	 * @param mixed $form_instance Form instance or placeholder.
	 *
	 * @return array Form metadata.
	 *
	 * @since 1.0.0
	 */
	public function get_form_meta( $form_instance ): array {

		if ( ! $form_instance instanceof \WPCF7_ContactForm ) {
			return [
				'form_id'    => 0,
				'form_title' => 'Unknown Form',
			];
		}

		return [
			'form_id'    => $form_instance->id(),
			'form_title' => $form_instance->title(),
		];
	}

	/**
	 * Expose admin settings helper.
	 *
	 * @since 1.0.0
	 *
	 * @return AdminSettings CF7 admin settings handler.
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}
}

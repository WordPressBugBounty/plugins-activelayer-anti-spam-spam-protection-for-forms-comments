<?php

namespace ActiveLayer\Integrations\FunnelKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use WFFN_Optin_Form_Controller_Custom_Form;

/**
 * FunnelKit Funnel Builder integration (synchronous block only).
 *
 * Protects FunnelKit opt-in forms (lead capture funnel steps). Spam is blocked
 * by intercepting the wffn_submit_custom_optin_form AJAX request before
 * FunnelKit's handler runs its actions (admin email, user email, CRM contact,
 * webhook). Sync-only: those actions execute inside the same request, so an
 * async queue could not prevent them.
 *
 * @since 1.5.0
 */
class FunnelKitIntegration extends BaseFormIntegration {

	/**
	 * Submission handler.
	 *
	 * @since 1.5.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings helper.
	 *
	 * @since 1.5.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Wire up FunnelKit integration services.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {

		parent::__construct( 'FunnelKit', 'funnelkit' );

		$this->submission_handler = new SubmissionHandler( $this );
		$this->admin_settings     = new AdminSettings( $this );
	}

	/**
	 * Bootstrap integration hooks.
	 *
	 * @since 1.5.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Hook 1 (action): AJAX intercept at priority 5 — FunnelKit's own handler
	 * runs at 10, so on a spam verdict we emit a next_url redirect and exit
	 * before it executes.
	 * Hook 2 (action): wfopp_output_form_tag_before renders the client-signal
	 * box next to the opt-in form (the bridge script moves it inside).
	 * Hook 3 (action): wfopp_output_form_before renders the server-side block
	 * notice on the reloaded opt-in page (?al_blocked=1).
	 * Hook 4 (action): deleted_post cleans up per-form options.
	 *
	 * @since 1.5.0
	 */
	private function hooks(): void {

		add_action( 'wp_ajax_wffn_submit_custom_optin_form', [ $this->submission_handler, 'maybe_intercept' ], 5 );
		add_action( 'wp_ajax_nopriv_wffn_submit_custom_optin_form', [ $this->submission_handler, 'maybe_intercept' ], 5 );

		add_action( 'wfopp_output_form_tag_before', [ $this, 'output_client_signals' ] );

		add_action( 'wfopp_output_form_before', [ $this, 'output_block_notice' ] );

		add_action( 'deleted_post', [ $this->admin_settings, 'cleanup_form_settings' ], 10, 2 );
	}

	/**
	 * Check if FunnelKit Funnel Builder is installed.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return defined( 'WFFN_VERSION' );
	}

	/**
	 * FunnelKit supports synchronous mode only.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_sync_mode_enabled(): bool {

		return true;
	}

	/**
	 * Expose the admin settings helper (used by IntegrationRegistry + handler).
	 *
	 * @since 1.5.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Read the submitted opt-in fields via FunnelKit's own parser.
	 *
	 * Reuses WFFN_Optin_Form_Controller_Custom_Form::get_posted_data() so field
	 * sanitization stays identical to FunnelKit's. Returns an empty array when
	 * the controller class is unavailable (fail-open).
	 *
	 * @since 1.5.0
	 *
	 * @param int $optin_page_id Opt-in page post ID.
	 *
	 * @return array Sanitized field map keyed by FunnelKit field slug.
	 */
	public function get_posted_data( int $optin_page_id ): array {

		if ( ! class_exists( 'WFFN_Optin_Form_Controller_Custom_Form' ) ) {
			return [];
		}

		$posted = WFFN_Optin_Form_Controller_Custom_Form::get_instance()->get_posted_data( $optin_page_id );

		return is_array( $posted ) ? $posted : [];
	}

	/**
	 * Map FunnelKit opt-in field slugs to the normalized ActiveLayer payload.
	 *
	 * Known slugs: optin_email, optin_first_name, optin_last_name. Any other
	 * scalar values (Pro: optin_phone, custom fields) are concatenated into the
	 * message slot so their content still feeds spam detection.
	 *
	 * @since 1.5.0
	 *
	 * @param array $raw_data Sanitized field map from get_posted_data().
	 *
	 * @return array Normalized submission payload.
	 */
	protected function normalize_form_data( array $raw_data ): array {

		$first = isset( $raw_data['optin_first_name'] ) && is_scalar( $raw_data['optin_first_name'] )
			? (string) $raw_data['optin_first_name']
			: '';
		$last  = isset( $raw_data['optin_last_name'] ) && is_scalar( $raw_data['optin_last_name'] )
			? (string) $raw_data['optin_last_name']
			: '';
		$email = isset( $raw_data['optin_email'] ) && is_scalar( $raw_data['optin_email'] )
			? (string) $raw_data['optin_email']
			: '';

		$skip   = [ 'optin_email', 'optin_first_name', 'optin_last_name', 'optin_page_id', 'wffn-captcha-response' ];
		$extras = [];

		foreach ( $raw_data as $key => $value ) {
			if ( in_array( $key, $skip, true ) || ! is_scalar( $value ) || $value === '' ) {
				continue;
			}

			$extras[] = (string) $value;
		}

		return [
			'name'        => RequestHelper::sanitize_field_value( trim( $first . ' ' . $last ) ),
			'email'       => sanitize_email( $email ),
			'website_url' => '',
			'message'     => RequestHelper::sanitize_field_value( implode( "\n", $extras ) ),
			'ip'          => RequestHelper::get_user_ip(),
			'user_agent'  => RequestHelper::get_user_agent(),
		];
	}

	/**
	 * Build metadata for the current FunnelKit opt-in submission.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $form_instance Opt-in page post ID.
	 *
	 * @return array Form metadata.
	 */
	public function get_form_meta( $form_instance ): array {

		$form_id = (int) $form_instance;
		$title   = $form_id > 0 ? get_the_title( $form_id ) : '';

		return [
			'form_id'    => $form_id,
			'form_title' => $title !== '' ? $title : 'Unknown Form',
		];
	}

	/**
	 * Render the client-signal box next to the opt-in form.
	 *
	 * Hooked to wfopp_output_form_tag_before, which fires inside the
	 * .wffn-optin-form wrapper but BEFORE the <form> tag, so the inputs would
	 * not be included in FunnelKit's jQuery serialize() submission. The bridge
	 * script moves the signal inputs inside the form element, so it is enqueued
	 * only when signal fields are actually rendered.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $optin_page_id Opt-in page post ID (first hook arg).
	 */
	public function output_client_signals( $optin_page_id ): void {

		$optin_page_id = (int) $optin_page_id;

		if ( ! $this->is_enabled() || ! SettingsHelper::has_api_key() ) {
			return;
		}

		if ( $optin_page_id <= 0 || empty( $this->admin_settings->get_form_settings( $optin_page_id )['enabled'] ) ) {
			return;
		}

		$signals_html = FieldRenderer::render_all();

		if ( $signals_html === '' ) {
			return;
		}

		// Enqueue the bridge only when there are signal fields to move into the
		// form; the bridge's sole job now is relocating those inputs.
		$this->enqueue_signal_bridge();

		printf(
			'<div class="activelayer-funnelkit-signals" data-optin-id="%d" hidden>%s</div>',
			(int) $optin_page_id,
			$signals_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldRenderer escapes internally.
		);
	}

	/**
	 * Render the server-side block notice on the reloaded opt-in page.
	 *
	 * Hooked to wfopp_output_form_before, which fires when the opt-in form
	 * renders. After a spam block the visitor is redirected by FunnelKit's
	 * native next_url handling to the opt-in page with ?al_blocked=1, and this
	 * method renders a banner. The al_blocked flag is a display-only presence
	 * marker (no state change), so no nonce is required.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $optin_page_id Opt-in page post ID (first hook arg).
	 */
	public function output_block_notice( $optin_page_id ): void {

		$optin_page_id = (int) $optin_page_id;

		if ( ! $this->is_enabled() || ! SettingsHelper::has_api_key() ) {
			return;
		}

		if ( $optin_page_id <= 0 || empty( $this->admin_settings->get_form_settings( $optin_page_id )['enabled'] ) ) {
			return;
		}

		// Display-only presence flag set by our own next_url redirect; no action
		// is taken, so nonce verification does not apply.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only presence flag, no state change.
		if ( ! isset( $_GET['al_blocked'] ) ) {
			return;
		}

		/** This filter is documented in src/Integrations/FunnelKit/SubmissionHandler.php */
		$message = apply_filters(
			'activelayer_integrations_funnelkit_submission_handler_block_message',
			$this->get_sync_block_message()
		);

		// Reuse FunnelKit's native error styling: `.bwfac_error .error` is a global
		// rule in wfopp-optin-frontend.css, so the banner matches the form's own
		// validation errors without shipping any ActiveLayer CSS. The
		// activelayer-funnelkit-notice class is kept as the hook/test target.
		printf(
			'<div class="bwfac_error activelayer-funnelkit-notice" role="alert"><span class="error">%s</span></div>',
			wp_kses_post( $message )
		);
	}

	/**
	 * Enqueue the FunnelKit bridge script (once per request).
	 *
	 * The script moves the signal fields inside the opt-in form so they are
	 * included in FunnelKit's jQuery serialize() submission, and re-runs that
	 * move when FunnelKit re-renders popup opt-ins.
	 *
	 * @since 1.5.0
	 */
	private function enqueue_signal_bridge(): void {

		$handle = 'activelayer-funnelkit-client-signals';

		if ( wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			ACTIVELAYER_PLUGIN_URL . 'assets/js/funnelkit-client-signals.js',
			[ 'jquery' ],
			ACTIVELAYER_PLUGIN_VERSION,
			[ 'in_footer' => true ]
		);
	}
}

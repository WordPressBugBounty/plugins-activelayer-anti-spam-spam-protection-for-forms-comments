<?php

namespace ActiveLayer\Integrations\EasyDigitalDownloads\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the standalone EDD registration form into the spam check.
 *
 * Binds two EDD hooks, both scoped to the standalone `[edd_register]` shortcode
 * and the `edd/register` block (which share the same handler):
 *   - `edd_register_form_fields_before_submit` (action) → renders hidden signal
 *     fields. Fires inside the <form> of both the shortcode template
 *     (templates/shortcode-register.php) and the block view
 *     (includes/blocks/views/forms/registration.php), never in the checkout form.
 *   - `edd_process_register_form` (action) → the spam gate. Fired by
 *     edd_process_register_form() (includes/users/register.php) after EDD's own
 *     validations and immediately before the `edd_get_errors()` check that
 *     gates user creation. Calling edd_set_error() here aborts the registration
 *     with EDD's native error UI — the same path EDD's honeypot uses.
 *
 * Registration during checkout is intentionally NOT reachable here:
 * edd_register_and_login_new_user() is called directly from
 * includes/process-purchase.php and never fires `edd_process_register_form`, so
 * a spam verdict can never block or delay a purchase.
 *
 * @since 1.5.0
 *
 * @package ActiveLayer\Integrations\EasyDigitalDownloads\Registration
 */
class RegistrationSubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.5.0
	 *
	 * @var RegistrationIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param RegistrationIntegration $integration Parent integration.
	 */
	public function __construct( RegistrationIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize the submission handler.
	 *
	 * @since 1.5.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.5.0
	 */
	private function hooks(): void {

		add_action( 'edd_register_form_fields_before_submit', [ $this->integration, 'output_signal_fields' ] );

		// Default priority is sufficient: EDD runs its own validations inline
		// before firing this action, and the edd_get_errors() gate that consumes
		// our error runs immediately after it (includes/users/register.php).
		add_action( 'edd_process_register_form', [ $this, 'validate_registration' ] );
	}

	/**
	 * EDD register action: validate the standalone registration and block on spam.
	 *
	 * The `edd_process_register_form` action carries no arguments — the handler
	 * reads the same $_POST EDD itself is about to validate and store. On a spam
	 * verdict it calls edd_set_error(), which leaves a non-empty error bag for
	 * the edd_get_errors() check at includes/users/register.php, so the WP user
	 * is never created and EDD re-renders the form with the message.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function validate_registration(): void {

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		// EDD already collected its own validation errors (invalid/duplicate
		// username, invalid/taken email, honeypot) before firing this action and
		// will reject the registration if any exist — skip the API call. This
		// also short-circuits obvious bots caught by EDD's honeypot. Logged-in
		// users never reach here: edd_process_register_form() returns early for
		// them before this action fires (includes/users/register.php).
		if ( edd_get_errors() ) {
			return;
		}

		list( $email, $login ) = $this->resolve_registrant_identity();

		if ( $email === '' ) {
			return;
		}

		$this->integration->check_registration_spam(
			$email,
			$login,
			[ 'form_id' => 'edd_register' ],
			static function ( string $message ): void {
				edd_set_error( 'activelayer_spam', $message );
			}
		);
	}

	/**
	 * Resolve the registrant email and login from the registration POST.
	 *
	 * Mirrors EDD's own login fallback (includes/users/register.php): when no
	 * username is supplied, the email address is used as the login. Values are
	 * guarded against non-scalar payloads (e.g. `edd_user_email[]=x`).
	 *
	 * @since 1.5.0
	 *
	 * @return string[] Two-element array: sanitized email and login (each may be '').
	 */
	private function resolve_registrant_identity(): array {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- EDD's registration processing reads the same unauthenticated $_POST without verifying a nonce (includes/users/register.php); see validate_registration().
		$email = '';

		if ( isset( $_POST['edd_user_email'] ) && is_scalar( $_POST['edd_user_email'] ) ) {
			$email = sanitize_email( wp_unslash( (string) $_POST['edd_user_email'] ) );
		}

		$login = '';

		if ( isset( $_POST['edd_user_login'] ) && is_scalar( $_POST['edd_user_login'] ) ) {
			$login = sanitize_user( wp_unslash( (string) $_POST['edd_user_login'] ), false );
		}
		// phpcs:enable

		if ( $login === '' ) {
			$login = $email;
		}

		return [ $email, $login ];
	}
}

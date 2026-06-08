<?php

namespace ActiveLayer\Integrations\BuddySignup;

use ActiveLayer\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the BuddyPress / BuddyBoss signup flow into the spam check.
 *
 * Reads `signup_username` and `signup_email` from `$_POST`, delegates to the
 * shared `RegistrationProtectionTrait::check_registration_spam()`, and on a
 * `spam` verdict assigns the block message to
 * `buddypress()->signup->errors['signup_username']`. BuddyPress already
 * verified the form nonce via `bp_verify_nonce_request( 'bp_new_signup' )`
 * before the `bp_signup_validate` action fires, so we do not re-check.
 *
 * Works against either concrete subclass of AbstractBuddySignupIntegration —
 * the runtime form-shape detection (presence of `signup_username` vs the
 * BuddyBoss `field_1` fallback) lives in `handle_signup_validate()` and does
 * not depend on which integration owns the hook.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Integrations\BuddySignup
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.3.0
	 *
	 * @var AbstractBuddySignupIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 *
	 * @param AbstractBuddySignupIntegration $integration Parent integration.
	 */
	public function __construct( AbstractBuddySignupIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize the submission handler.
	 *
	 * @since 1.3.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.3.0
	 */
	private function hooks(): void {

		// Hidden signal fields injected just above the submit buttons.
		add_action( 'bp_before_registration_submit_buttons', [ $this->integration, 'output_signal_fields' ] );

		// Primary spam gate. BP fires this action AFTER its own validation.
		add_action( 'bp_signup_validate', [ $this, 'handle_signup_validate' ] );
	}

	/**
	 * BP/BB signup hook: validate the registration and block on spam verdict.
	 *
	 * BuddyPress fires `bp_signup_validate` from `bp-members/screens/register.php`
	 * after it ran its own validation and AFTER it verified the form nonce via
	 * `bp_verify_nonce_request( 'bp_new_signup' )`. We trust that gate and only
	 * sanitize the inputs we consume.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function handle_signup_validate(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- BP verifies the form nonce via bp_verify_nonce_request before this action fires.
		$has_username_field = isset( $_POST['signup_username'] );

		$username = $has_username_field
			? sanitize_user( wp_unslash( $_POST['signup_username'] ), false )
			: '';
		$email    = isset( $_POST['signup_email'] )
			? sanitize_email( wp_unslash( $_POST['signup_email'] ) )
			: '';

		// BuddyBoss signup forms omit signup_username (username is auto-generated
		// from the email). Fall back to the xprofile field with database id 1 —
		// the default Name field on stock BP/BB installs. On sites that deleted
		// or reordered the original Name field this fallback yields an empty
		// string; the API still works with just the email. Guard against array
		// values (multi-value xprofile fields) so the (string) cast does not
		// emit a warning or send a literal "Array" to the API.
		if ( $username === '' && isset( $_POST['field_1'] ) && is_scalar( $_POST['field_1'] ) ) {
			$username = sanitize_text_field( wp_unslash( (string) $_POST['field_1'] ) );
		}
		// phpcs:enable

		if ( $email === '' ) {
			return;
		}

		$signup_bag = $this->get_signup_bag();

		if ( $signup_bag === null ) {
			// `bp_signup_validate` fired without a usable error bag — fail-safe:
			// let the signup proceed rather than running a check whose verdict
			// would be silently discarded.
			Logger::log(
				'bp_signup_validate fired without buddypress()->signup — skipping spam check (fail-safe).',
				[ 'provider' => $this->integration->get_slug() ]
			);

			return;
		}

		// Respect upstream BP validation — first error wins.
		if ( ! empty( $signup_bag->errors ) ) {
			return;
		}

		// Render the error next to the username field when BP shows one, otherwise
		// fall back to the email field (BuddyBoss).
		$error_field = $has_username_field ? 'signup_username' : 'signup_email';

		$this->integration->check_registration_spam(
			$email,
			$username,
			[ 'form_id' => 'bp_signup' ],
			static function ( string $message ) use ( $signup_bag, $error_field ): void {
				$signup_bag->errors[ $error_field ] = $message;
			}
		);
	}

	/**
	 * Resolve the BuddyPress / BuddyBoss signup error bag.
	 *
	 * Returns `buddypress()->signup` in production. In PHPUnit (where
	 * BuddyPress is not loaded) returns the `$GLOBALS['bp_signup_errors_stub']`
	 * object the test suite seeds, keeping the handler testable without
	 * depending on the BP runtime. The stub branch is gated on the
	 * `ACTIVELAYER_RUNNING_TESTS` sentinel defined by the PHPUnit bootstrap,
	 * so a third-party plugin in production cannot seed the global to capture
	 * spam verdicts.
	 *
	 * Returns null when no usable bag is available — callers must treat that
	 * as a fail-safe and skip the spam check, since assigning into a discarded
	 * object would silently lose the verdict.
	 *
	 * @since 1.3.0
	 *
	 * @return object|null Object exposing an `errors` array property, or null
	 *                     when no real bag is available.
	 */
	private function get_signup_bag() {

		if ( function_exists( 'buddypress' ) ) {
			$bp = buddypress();

			if ( isset( $bp->signup ) ) {
				return $bp->signup;
			}
		}

		if (
			defined( 'ACTIVELAYER_RUNNING_TESTS' )
			&& isset( $GLOBALS['bp_signup_errors_stub'] )
		) {
			return $GLOBALS['bp_signup_errors_stub'];
		}

		return null;
	}
}

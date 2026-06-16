<?php

namespace ActiveLayer\Integrations\AffiliateWP;

use ActiveLayer\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the AffiliateWP affiliate-registration flow into the spam check.
 *
 * Binds two AffiliateWP actions:
 *   - `affwp_register_fields_before_submit` → renders hidden signal fields
 *     (fires in both the legacy [affiliate_registration] shortcode template
 *     and the block-based registration form).
 *   - `affwp_process_register_form` → the spam gate. AffiliateWP fires this
 *     after its own validation (nonce, required fields, honeypot, CAPTCHA) and
 *     before `Affiliate_WP_Register::register_user()`; affiliate creation is
 *     gated on `empty( $this->errors )`, so adding `activelayer_spam` to the
 *     register error bag aborts the registration.
 *
 * @since 1.4.0
 *
 * @package ActiveLayer\Integrations\AffiliateWP
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.4.0
	 *
	 * @var AffiliateWPIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 *
	 * @param AffiliateWPIntegration $integration Parent integration.
	 */
	public function __construct( AffiliateWPIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize the submission handler.
	 *
	 * @since 1.4.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.4.0
	 */
	private function hooks(): void {

		// Hidden signal fields — fires in both the legacy shortcode template and
		// the block-based registration form, so one hook covers both render paths.
		add_action( 'affwp_register_fields_before_submit', [ $this->integration, 'output_signal_fields' ] );

		// Primary spam gate.
		add_action( 'affwp_process_register_form', [ $this, 'handle_register_form' ] );
	}

	/**
	 * AffiliateWP registration hook: validate and block on spam verdict.
	 *
	 * AffiliateWP verified `affwp_register_nonce` before this action fires, so
	 * we only sanitize the inputs we consume.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function handle_register_form(): void {

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		$register = $this->get_register_bag();

		if ( $register === null ) {
			// `affwp_process_register_form` fired without a usable register bag —
			// fail-safe: let the registration proceed.
			Logger::log(
				'affwp_process_register_form fired without affiliate_wp()->register — skipping spam check (fail-safe).',
				[ 'provider' => $this->integration->get_slug() ]
			);

			return;
		}

		// Respect upstream AffiliateWP validation — first error wins. There is no
		// is_error() method on Affiliate_WP_Register; get_errors() returns [].
		if ( ! empty( $register->get_errors() ) ) {
			return;
		}

		list( $email, $login ) = $this->resolve_registrant_identity();

		if ( $email === '' ) {
			return;
		}

		$this->integration->check_registration_spam(
			$email,
			$login,
			[ 'form_id' => 'affwp_register' ],
			static function ( string $message ) use ( $register ): void {
				$register->add_error( 'activelayer_spam', $message );
			}
		);
	}

	/**
	 * Resolve the registrant email and login the way AffiliateWP itself does.
	 *
	 * Logged-in users: AffiliateWP unconditionally overrides the posted values
	 * with the current account's login/email before creating the affiliate (see
	 * Affiliate_WP_Register::process_registration()) — the block form renders
	 * the email input as disabled, the legacy form as readonly. Mirroring that
	 * override means the gate checks the same identity AffiliateWP will store,
	 * and the upgrade-to-affiliate flow (no email in $_POST) is still
	 * spam-checked instead of silently bypassing the gate.
	 *
	 * Logged-out users: read the posted fields, guarded against non-scalar
	 * payloads (e.g. `affwp_user_email[]=x`), with the login fallback chain
	 * affwp_user_login → affwp_user_name.
	 *
	 * @since 1.4.0
	 *
	 * @return string[] Two-element array: sanitized email and login (each may be '').
	 */
	private function resolve_registrant_identity(): array {

		if ( is_user_logged_in() ) {
			$current = wp_get_current_user();

			return [ sanitize_email( $current->user_email ), sanitize_user( (string) $current->user_login, false ) ];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- AffiliateWP verifies affwp_register_nonce before this action fires.
		$email = '';

		if ( isset( $_POST['affwp_user_email'] ) && is_scalar( $_POST['affwp_user_email'] ) ) {
			$email = sanitize_email( wp_unslash( (string) $_POST['affwp_user_email'] ) );
		}

		// Login fallback chain: affwp_user_login → affwp_user_name.
		$login = '';

		if ( isset( $_POST['affwp_user_login'] ) && is_scalar( $_POST['affwp_user_login'] ) ) {
			$login = sanitize_user( wp_unslash( (string) $_POST['affwp_user_login'] ), false );
		}

		if ( $login === '' && isset( $_POST['affwp_user_name'] ) && is_scalar( $_POST['affwp_user_name'] ) ) {
			$login = sanitize_text_field( wp_unslash( (string) $_POST['affwp_user_name'] ) );
		}
		// phpcs:enable

		return [ $email, $login ];
	}

	/**
	 * Resolve the AffiliateWP registration error bag.
	 *
	 * Returns `affiliate_wp()->register` in production. In PHPUnit (where
	 * AffiliateWP is not loaded) returns the `$GLOBALS['affwp_register_bag_stub']`
	 * object the test suite seeds, gated on the `ACTIVELAYER_RUNNING_TESTS`
	 * sentinel so a third-party plugin in production cannot seed the global to
	 * capture spam verdicts.
	 *
	 * Returns null when no usable bag is available — callers treat that as a
	 * fail-safe and skip the spam check.
	 *
	 * @since 1.4.0
	 *
	 * @return object|null Object exposing get_errors() + add_error(), or null.
	 */
	private function get_register_bag() {

		if ( function_exists( 'affiliate_wp' ) ) {
			$affwp = affiliate_wp();

			if ( isset( $affwp->register ) ) {
				return $affwp->register;
			}
		}

		if (
			defined( 'ACTIVELAYER_RUNNING_TESTS' )
			&& isset( $GLOBALS['affwp_register_bag_stub'] )
		) {
			return $GLOBALS['affwp_register_bag_stub'];
		}

		return null;
	}
}

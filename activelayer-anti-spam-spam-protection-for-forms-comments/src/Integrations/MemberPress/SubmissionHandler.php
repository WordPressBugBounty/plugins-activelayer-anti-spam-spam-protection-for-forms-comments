<?php

namespace ActiveLayer\Integrations\MemberPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the MemberPress signup flow into the spam check.
 *
 * Binds two MemberPress hooks — underscore spelling ONLY: MeprHooks fires
 * every hook under both `mepr-` and `mepr_` names (app/lib/MeprHooks.php),
 * so registering both spellings would double-run the callbacks.
 *   - `mepr_checkout_before_submit` (action) → renders hidden signal fields.
 *     Fires inside the <form> of all three checkout templates (classic
 *     app/views/checkout/form.php, SPC spc_form.php and the ReadyLaunch
 *     variant), for free and paid memberships alike.
 *   - `mepr_validate_signup` (filter) → the spam gate. Fires after
 *     MeprUser::validate_signup() in both the classic POST path
 *     (MeprCheckoutCtrl::process_signup_form) and the AJAX/SPC gateway path
 *     (process_signup_form_ajax), in each case before the WP user is
 *     created. Returning a non-empty errors array aborts the signup with
 *     MemberPress's native error UI (page re-render or wp_send_json_error).
 *
 * @since 1.4.0
 *
 * @package ActiveLayer\Integrations\MemberPress
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.4.0
	 *
	 * @var MemberPressIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 *
	 * @param MemberPressIntegration $integration Parent integration.
	 */
	public function __construct( MemberPressIntegration $integration ) {

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
	 * Runs at plugins_loaded:10 (ActiveLayer bootstrap) — before MemberPress's
	 * init:10 standalone-request dispatcher, so the filter is always armed for
	 * the current request.
	 *
	 * @since 1.4.0
	 */
	private function hooks(): void {

		add_action( 'mepr_checkout_before_submit', [ $this, 'render_signal_fields' ] );

		// Priority 20: MemberPress registers some of its own validators (e.g.
		// MeprZxcvbnCtrl on after_setup_theme) after this handler; running late
		// lets their errors short-circuit the gate instead of wasting an API call.
		add_filter( 'mepr_validate_signup', [ $this, 'validate_signup' ], 20 );
	}

	/**
	 * Render hidden signal fields inside the checkout form.
	 *
	 * Skips logged-in users: the gate only checks anonymous registrations
	 * (mirrors MemberPress's own math captcha), so collecting signals for
	 * logged-in purchases would be dead weight.
	 *
	 * @since 1.4.0
	 */
	public function render_signal_fields(): void {

		if ( is_user_logged_in() ) {
			return;
		}

		$this->integration->output_signal_fields();
	}

	/**
	 * MemberPress signup filter: validate and block on spam verdict.
	 *
	 * MemberPress's anonymous signup flow carries no nonce (only logged-in
	 * purchases are nonce-checked in MeprAppCtrl); the filter consumes the
	 * same POST MemberPress itself is about to validate and store.
	 *
	 * @since 1.4.0
	 * @since 1.4.1 Skip real-money signups unless the admin opts
	 *        in via `block_paid_signups`; free, free-trial, and fully-discounted
	 *        signups stay gated.
	 *
	 * @param array $errors Validation errors collected by MemberPress so far.
	 *
	 * @return array Errors, with the block message appended on a spam verdict.
	 */
	public function validate_signup( $errors ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! is_array( $errors ) ) {
			$errors = [];
		}

		if ( ! $this->integration->is_enabled() ) {
			return $errors;
		}

		// Registration-only scope: the filter also fires when an existing
		// logged-in account purchases a membership. Account creation is the
		// spam vector — skip purchases by existing users.
		if ( is_user_logged_in() ) {
			return $errors;
		}

		// Respect upstream MemberPress validation (dash-spelled callbacks and
		// earlier-priority underscore callbacks run before this handler).
		if ( ! empty( $errors ) ) {
			return $errors;
		}

		list( $email, $name ) = $this->resolve_registrant_identity();

		if ( $email === '' ) {
			return $errors;
		}

		$membership_id = $this->resolve_membership_id();

		// MemberPress creates the WordPress account before taking payment, so a
		// false-positive block on a real-money signup aborts the purchase and
		// costs the sale. Skip the gate for signups that charge money at
		// checkout unless the admin opted in. Free, free-trial, and
		// fully-discounted signups have no payment at stake and stay gated.
		if (
			! $this->integration->should_block_paid_signups()
			&& $this->integration->signup_takes_payment_now( $membership_id, $this->resolve_coupon_code() )
		) {
			return $errors;
		}

		$this->integration->check_registration_spam(
			$email,
			$name,
			[ 'form_id' => $membership_id ],
			static function ( string $message ) use ( &$errors ): void {
				$errors[] = $message;
			}
		);

		return $errors;
	}

	/**
	 * Resolve the registrant email and display name from the signup POST.
	 *
	 * Name preference chain: "First Last" → user_login. user_login is absent
	 * when the MemberPress "Members must use their email address for their
	 * Username" option is on, and first/last may be absent depending on the
	 * fields configuration — each source is optional. Values are guarded
	 * against non-scalar payloads (e.g. `user_email[]=x`).
	 *
	 * @since 1.4.0
	 *
	 * @return string[] Two-element array: sanitized email and name (each may be '').
	 */
	private function resolve_registrant_identity(): array {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Anonymous MemberPress signups carry no nonce; see validate_signup().
		$email = '';

		if ( isset( $_POST['user_email'] ) && is_scalar( $_POST['user_email'] ) ) {
			$email = sanitize_email( wp_unslash( (string) $_POST['user_email'] ) );
		}

		$first = '';

		if ( isset( $_POST['user_first_name'] ) && is_scalar( $_POST['user_first_name'] ) ) {
			$first = sanitize_text_field( wp_unslash( (string) $_POST['user_first_name'] ) );
		}

		$last = '';

		if ( isset( $_POST['user_last_name'] ) && is_scalar( $_POST['user_last_name'] ) ) {
			$last = sanitize_text_field( wp_unslash( (string) $_POST['user_last_name'] ) );
		}

		$name = trim( $first . ' ' . $last );

		if ( $name === '' && isset( $_POST['user_login'] ) && is_scalar( $_POST['user_login'] ) ) {
			$name = sanitize_user( wp_unslash( (string) $_POST['user_login'] ), false );
		}
		// phpcs:enable

		return [ $email, $name ];
	}

	/**
	 * Resolve the membership (product) ID posted with the signup.
	 *
	 * Falls back to the `mepr_signup` sentinel when the field is absent or
	 * non-positive: a crafted request without mepr_product_id must not bypass
	 * the gate — storage rejects an empty form_id, which would fail open.
	 *
	 * @since 1.4.0
	 *
	 * @return int|string Membership post ID, or the `mepr_signup` sentinel.
	 */
	private function resolve_membership_id() {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Anonymous MemberPress signups carry no nonce; see validate_signup().
		if ( isset( $_POST['mepr_product_id'] ) && is_scalar( $_POST['mepr_product_id'] ) && (int) $_POST['mepr_product_id'] > 0 ) {
			return (int) $_POST['mepr_product_id'];
		}
		// phpcs:enable

		return 'mepr_signup';
	}

	/**
	 * Resolve the coupon code posted with the signup.
	 *
	 * Passed to the paid-signup test so a discount that drops the at-signup
	 * charge to zero (e.g. a 100%-off coupon) keeps the spam gate active.
	 *
	 * @since 1.4.1
	 *
	 * @return string Sanitized coupon code, or '' when absent.
	 */
	private function resolve_coupon_code(): string {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Anonymous MemberPress signups carry no nonce; see validate_signup().
		if ( isset( $_POST['mepr_coupon_code'] ) && is_scalar( $_POST['mepr_coupon_code'] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_POST['mepr_coupon_code'] ) );
		}
		// phpcs:enable

		return '';
	}
}

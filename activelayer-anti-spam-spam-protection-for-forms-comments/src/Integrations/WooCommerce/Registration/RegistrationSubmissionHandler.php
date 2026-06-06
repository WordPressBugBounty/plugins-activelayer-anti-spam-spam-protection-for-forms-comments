<?php

namespace ActiveLayer\Integrations\WooCommerce\Registration;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the WC registration flow into the spam check.
 *
 * Covers three entry points through a single validation hook:
 *   - Standalone My Account register form (classic shortcode).
 *   - Register-during-checkout on classic `[woocommerce_checkout]`.
 *   - Register-during-checkout on block-based checkout (Store API REST).
 *
 * All three flows fire `woocommerce_register_post` via `wc_create_new_customer()`,
 * so the spam gate is unified. Client-signal fields are rendered into the two
 * classic flows only; block-based checkout reaches the gate without form fields
 * (Store API uses a JSON body), and `SignalsStrippedDetector` is REST-aware so
 * it does not flag that as suspicious.
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Integrations\WooCommerce\Registration
 */
class RegistrationSubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.2.0
	 *
	 * @var RegistrationIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param RegistrationIntegration $integration Parent integration.
	 */
	public function __construct( RegistrationIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize the submission handler.
	 *
	 * @since 1.2.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.2.0
	 */
	private function hooks(): void {

		// Standalone My Account register form.
		add_action( 'woocommerce_register_form', [ $this->integration, 'output_signal_fields' ] );

		// Classic checkout with "Create an account" — woocommerce_register_form
		// does NOT fire here, but woocommerce_register_post does. Emit the
		// hidden signal fields inside the checkout form (woocommerce_after_checkout_form
		// fires OUTSIDE the </form> in WC's classic checkout template, so use
		// woocommerce_review_order_before_submit instead — it renders inside
		// the order review block, just before the #place_order button).
		add_action( 'woocommerce_review_order_before_submit', [ $this->integration, 'output_signal_fields' ] );

		// Primary validation hook (fires for classic shortcode flows AND for
		// block-based checkout via Checkout::process_customer → wc_create_new_customer).
		add_action( 'woocommerce_register_post', [ $this, 'handle_registration' ], 10, 3 );
	}

	/**
	 * WC register hook: validates the registration and blocks on spam verdict.
	 *
	 * Object signature: `do_action( 'woocommerce_register_post', $username, $email, $validation_error )`.
	 * `$validation_error` is a WP_Error instance; adding to it stops registration.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $username         Submitted username.
	 * @param string   $email            Submitted email.
	 * @param WP_Error $validation_error Errors accumulator.
	 *
	 * @return void
	 */
	public function handle_registration( $username, $email, $validation_error ): void {

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		if ( ! $validation_error instanceof WP_Error ) {
			return;
		}

		// Respect upstream validation errors — WC will already reject; skip.
		if ( $validation_error->has_errors() ) {
			return;
		}

		// Honour the checkout-context toggle. Block-based checkout reaches this hook
		// via the Store API REST route, where is_checkout() returns false; treat the
		// Store API checkout endpoint as a checkout context too so the toggle works
		// in both transports.
		$is_checkout_context = ( function_exists( 'is_checkout' ) && is_checkout() )
			|| self::is_store_api_checkout_context();

		if ( $is_checkout_context && ! $this->integration->protects_checkout_register() ) {
			return;
		}

		$clean_email = sanitize_email( (string) $email );
		$clean_login = sanitize_user( (string) $username, false );

		$meta = [
			'form_id' => 'wc_registration',
		];

		$this->integration->check_registration_spam(
			$clean_email,
			$clean_login,
			$meta,
			static function ( string $message ) use ( $validation_error ): void {
				$validation_error->add( 'activelayer_spam', $message );
			}
		);
	}

	/**
	 * Detect a Store API checkout REST request.
	 *
	 * Block-based checkout submits via POST /wp-json/wc/store/v1/checkout, where
	 * is_checkout() returns false and the request is a JSON REST call. Used by
	 * the checkout-context toggle so `protect_checkout_register` works in both
	 * the classic shortcode and the block-based flow.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private static function is_store_api_checkout_context(): bool {

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$route = self::resolve_rest_route();

		if ( $route === '' ) {
			return false;
		}

		return (bool) preg_match( '#(^|/)wc/store/v\d+/checkout(/|$)#', $route );
	}

	/**
	 * Resolve the current REST route across the URI shapes WordPress supports.
	 *
	 * Both pretty permalinks (`/wp-json/wc/store/v1/checkout`) and plain
	 * permalinks (`/?rest_route=/wc/store/v1/checkout`) must match.
	 * `WC()->is_store_api_request()` is intentionally NOT used here because
	 * it only checks the pretty URI prefix and returns false on
	 * plain-permalink sites — that would silently disable the
	 * `protect_checkout_register` toggle for legit configurations.
	 *
	 * `$_GET` / `$_SERVER` are read-only routing inputs (not form data), so
	 * the nonce-verification check does not apply. Values are still
	 * sanitized before being compared against the WC Store API route
	 * pattern.
	 *
	 * @since 1.2.0
	 *
	 * @return string The current REST route, or '' when none could be resolved.
	 */
	private static function resolve_rest_route(): string {

		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return (string) $GLOBALS['wp']->query_vars['rest_route'];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check; not form processing.
		if ( isset( $_GET['rest_route'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['rest_route'] ) );
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$qpos = strpos( $uri, '?' );

			return $qpos !== false ? substr( $uri, 0, $qpos ) : $uri;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}
}

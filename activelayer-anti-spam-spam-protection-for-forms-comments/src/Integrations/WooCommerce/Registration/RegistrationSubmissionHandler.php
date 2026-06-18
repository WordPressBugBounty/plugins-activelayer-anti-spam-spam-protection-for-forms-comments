<?php

namespace ActiveLayer\Integrations\WooCommerce\Registration;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the standalone WC My Account registration form into the spam check.
 *
 * Scope is the standalone My Account register form ONLY. Register-during-checkout
 * is intentionally NOT gated: the same `woocommerce_register_post` hook fires for
 * the classic `[woocommerce_checkout]` flow and the block-based checkout (Store
 * API REST), but blocking a registration there aborts the entire order, so a
 * false positive would cost a sale. `handle_registration()` detects the checkout
 * context (classic via `is_checkout()`, block-based via the Store API checkout
 * route) and returns without checking. Client-signal fields are rendered into the
 * My Account form only.
 *
 * @since 1.2.0
 * @since 1.4.1 Register-during-checkout is no longer checked — only the standalone My Account form is gated.
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

		// Standalone My Account register form — signal fields + spam gate.
		// Register-during-checkout is deliberately not wired up: signal fields
		// are not emitted into the checkout form, and handle_registration()
		// skips the checkout context (see its body).
		add_action( 'woocommerce_register_form', [ $this->integration, 'output_signal_fields' ] );

		// Primary validation hook. It also fires for register-during-checkout
		// (classic + block-based via wc_create_new_customer), so the handler
		// detects and skips that context.
		add_action( 'woocommerce_register_post', [ $this, 'handle_registration' ], 10, 3 );
	}

	/**
	 * WC register hook: validates the standalone My Account registration and blocks on spam.
	 *
	 * Object signature: `do_action( 'woocommerce_register_post', $username, $email, $validation_error )`.
	 * `$validation_error` is a WP_Error instance; adding to it stops registration.
	 *
	 * Returns early for any checkout context (classic or block-based Store API):
	 * register-during-checkout is not gated so the spam check can never abort an order.
	 *
	 * @since 1.2.0
	 * @since 1.4.1 Skips the register-during-checkout context entirely.
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

		// Never gate register-during-checkout: blocking there aborts the whole
		// order, so a false positive would cost a sale. Block-based checkout
		// reaches this hook via the Store API REST route, where is_checkout()
		// returns false; treat that endpoint as a checkout context too so both
		// transports are skipped. Only the standalone My Account form is gated.
		$is_checkout_context = ( function_exists( 'is_checkout' ) && is_checkout() )
			|| self::is_store_api_checkout_context();

		if ( $is_checkout_context ) {
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
	 * is_checkout() returns false and the request is a JSON REST call. Used so
	 * handle_registration() can skip the register-during-checkout context in the
	 * block-based flow as well as the classic shortcode.
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
	 * plain-permalink sites — that would let a block-based checkout
	 * registration slip through the skip and get checked anyway.
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

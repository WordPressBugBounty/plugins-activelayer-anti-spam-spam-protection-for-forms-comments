<?php

namespace ActiveLayer\Integrations\FunnelKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use Throwable;

/**
 * Synchronous submission handler for FunnelKit opt-in forms.
 *
 * Hooked to the wffn_submit_custom_optin_form AJAX action at priority 5,
 * before FunnelKit's own handler (priority 10). On a spam verdict it responds
 * with a next_url redirect back to the opt-in page (carrying ?al_blocked=1)
 * and terminates the request, so FunnelKit's actions (admin email, user email,
 * CRM contact, webhook) never run. FunnelKit's own frontend consumes the
 * next_url key and performs the redirect; on reload the integration renders a
 * server-side notice. On any other outcome it returns silently and FunnelKit
 * proceeds.
 *
 * @since 1.5.0
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.5.0
	 *
	 * @var FunnelKitIntegration
	 */
	private $integration;

	/**
	 * Set up the submission handler.
	 *
	 * @since 1.5.0
	 *
	 * @param FunnelKitIntegration $integration Parent integration reference.
	 */
	public function __construct( FunnelKitIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * AJAX callback: block the request when the submission is spam.
	 *
	 * On a spam verdict we emit ONLY a next_url redirect back to the opt-in page
	 * with ?al_blocked=1 appended. FunnelKit's own frontend handles the next_url
	 * key and performs the redirect; on the reloaded page the integration renders
	 * a server-side notice (FunnelKitIntegration::output_block_notice()). The
	 * redirect target is run through wp_validate_redirect() to prevent
	 * open-redirects. On any non-block outcome we return silently and FunnelKit's
	 * handler (priority 10) runs natively.
	 *
	 * @since 1.5.0
	 */
	public function maybe_intercept(): void {

		$optin_page_id = null;

		try {
			$optin_page_id = $this->get_blocked_optin_page_id();
		} catch ( Throwable $exception ) {
			Logger::log(
				'FunnelKit sync intercept: unexpected error',
				[
					'provider' => $this->integration->get_slug(),
					'error'    => $exception->getMessage(),
				]
			);

			return;
		}

		if ( $optin_page_id === null ) {
			return;
		}

		$permalink = get_permalink( $optin_page_id );
		$base_url  = $permalink ? $permalink : home_url( '/' );
		$redirect  = wp_validate_redirect(
			add_query_arg( 'al_blocked', '1', $base_url ),
			home_url( '/' )
		);

		wp_send_json( [ 'next_url' => $redirect ] );
	}

	/**
	 * Return the visitor-facing block message when the request is spam.
	 *
	 * The maybe_intercept() callback uses get_blocked_optin_page_id() directly
	 * so the display-only message filter cannot affect the block decision. This
	 * method remains available for tests and message-specific checks.
	 *
	 * Guard chain is fail-open: any missing precondition returns null. Guards in
	 * order: integration enabled → API key present → optin_page_id > 0 → ID
	 * resolves to a wffn_optin post (rejects unauthenticated junk IDs before
	 * reading options) → form protection enabled → posted data available.
	 *
	 * @since 1.5.0
	 *
	 * @return string|null Block message when the submission is spam, null otherwise.
	 */
	public function get_block_message(): ?string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( $this->get_blocked_optin_page_id() === null ) {
			return null;
		}

		/**
		 * Filter the spam block message shown to FunnelKit opt-in visitors.
		 *
		 * @since 1.5.0
		 *
		 * @param string $message Default spam block message.
		 *
		 * @return string
		 */
		$message = apply_filters(
			'activelayer_integrations_funnelkit_submission_handler_block_message',
			$this->integration->get_sync_block_message()
		);

		return wp_kses_post( $message );
	}

	/**
	 * Get the blocked opt-in page ID when the current request is spam.
	 *
	 * This method intentionally returns only the block decision data. Visitor
	 * message filtering is display-only and must not affect whether a confirmed
	 * spam submission is blocked.
	 *
	 * @since 1.5.0
	 *
	 * @return int|null Opt-in page ID to block, or null to allow the request.
	 */
	private function get_blocked_optin_page_id(): ?int { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->integration->is_enabled() || ! SettingsHelper::has_api_key() ) {
			return null;
		}

		// FunnelKit's public submit endpoint carries no nonce; we only read its
		// own POST field here, identically to FunnelKit's handler.
		$optin_page_id = isset( $_POST['optin_page_id'] ) ? (int) $_POST['optin_page_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $optin_page_id <= 0 ) {
			return null;
		}

		// Reject IDs that do not belong to a real FunnelKit opt-in page so
		// unauthenticated junk submissions cannot trigger option reads or API
		// calls for arbitrary post IDs.
		if ( get_post_type( $optin_page_id ) !== AdminSettings::OPTIN_POST_TYPE ) {
			return null;
		}

		if ( ! $this->is_form_protected( $optin_page_id ) ) {
			return null;
		}

		$posted = $this->integration->get_posted_data( $optin_page_id );

		if ( empty( $posted ) ) {
			return null;
		}

		$meta   = $this->integration->get_form_meta( $optin_page_id );
		$result = $this->integration->process_submission_synchronously( $posted, $meta );

		// Safe-by-default: on any failure, allow the submission through.
		if ( empty( $result['success'] ) ) {
			return null;
		}

		if ( ( $result['verdict'] ?? 'clean' ) !== 'spam' ) {
			return null;
		}

		return $optin_page_id;
	}

	/**
	 * Check whether ActiveLayer protection is enabled for the opt-in page.
	 *
	 * @since 1.5.0
	 *
	 * @param int $optin_page_id Opt-in page post ID.
	 *
	 * @return bool
	 */
	private function is_form_protected( int $optin_page_id ): bool {

		if ( $optin_page_id <= 0 ) {
			return false;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $optin_page_id );

		return ! empty( $form_settings['enabled'] );
	}
}

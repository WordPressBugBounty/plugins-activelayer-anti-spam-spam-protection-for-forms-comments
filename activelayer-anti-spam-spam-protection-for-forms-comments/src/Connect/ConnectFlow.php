<?php

namespace ActiveLayer\Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Api\ConnectClient;
use ActiveLayer\Helpers\AppUrlHelper;
use ActiveLayer\Helpers\NoticeHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Subscription\SubscriptionStats;

/**
 * Orchestrates the one-click Connect flow on the plugin side.
 *
 * Coordinates the connect flow: start() mints a per-user PKCE code verifier and
 * builds the outbound URL (carrying the verifier's challenge) to the application;
 * handle_callback() detects the return redirect and exchanges the server-issued
 * authorization code, together with the verifier, for the provisioned API key via
 * a server-to-server claim. The result is persisted as a one-time notice that
 * survives the PRG redirect.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Connect
 */
final class ConnectFlow {

	/**
	 * Prefix for the per-user pairing transient.
	 *
	 * @since 1.3.0
	 */
	private const TRANSIENT_PREFIX = 'al_connect_pairing_';

	/**
	 * Prefix for the per-user one-time result notice transient.
	 *
	 * @since 1.3.0
	 */
	private const NOTICE_PREFIX = 'al_connect_notice_';

	/**
	 * Pairing transient lifetime.
	 *
	 * @since 1.3.0
	 */
	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Notice transient lifetime (seconds).
	 *
	 * @since 1.3.0
	 */
	private const NOTICE_TTL = 60;

	/**
	 * Maximum accepted length of a claimed API key.
	 *
	 * @since 1.3.0
	 */
	private const MAX_CLAIMED_KEY_LENGTH = 256;

	/**
	 * Register the Connect return handler.
	 *
	 * The callback runs on admin_init (before any output) so the post-claim
	 * redirect fires before headers are sent. The handler is a no-op on every
	 * request that does not carry a Connect return code.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function hooks(): void {

		add_action( 'admin_init', [ new self(), 'handle_callback' ] );
	}

	/**
	 * Build the connect URL for a CTA. Idempotent within the TTL.
	 *
	 * @since 1.3.0
	 *
	 * @param string $utm_medium  Surface that hosts the CTA.
	 * @param string $utm_content Specific CTA placement.
	 *
	 * @return string Outbound connect URL.
	 */
	public function start( string $utm_medium, string $utm_content ): string {

		$key      = self::TRANSIENT_PREFIX . get_current_user_id();
		$pending  = get_transient( $key );
		$verifier = ( is_array( $pending ) && ! empty( $pending['code_verifier'] ) )
			? $pending['code_verifier']
			: wp_generate_password( 43, false ); // 43 chars: within PKCE's 43-128 unreserved range.

		set_transient(
			$key,
			[
				'code_verifier' => $verifier,
				'created_at'    => time(),
			],
			self::TTL
		);

		// Hex SHA-256 challenge: matches the app's hash_equals() check. Deliberately
		// hex (a private plugin<->app contract), not RFC 7636 base64url S256.
		return AppUrlHelper::get_connect_url(
			$utm_medium,
			$utm_content,
			hash( 'sha256', $verifier ),
			home_url(),
			$this->return_url()
		);
	}

	/**
	 * Handle the return redirect: claim the key, save it, set a one-time notice,
	 * and redirect to a clean URL. No-op (no redirect) when there is nothing to do.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function handle_callback(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-issued one-time code; the flow is bound by the per-user PKCE verifier transient.
		if ( ! is_admin() || wp_doing_ajax() || empty( $_GET['al_code'] ) || ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See guard above; the code is validated by the server-to-server claim, not trusted directly.
		$code = sanitize_text_field( wp_unslash( $_GET['al_code'] ) );

		$key = self::TRANSIENT_PREFIX . get_current_user_id();

		// Already connected: never re-claim or overwrite an existing key. Clear any
		// stale verifier transient so a stray ?al_code cannot reuse it.
		if ( SettingsHelper::has_api_key() ) {
			delete_transient( $key );

			return;
		}

		$pending = get_transient( $key );

		if ( empty( $pending['code_verifier'] ) ) {
			return; // Lost / expired / forged → silent no-op.
		}

		// Delete before the claim so a concurrent refresh cannot replay the verifier.
		delete_transient( $key );

		$result  = ( new ConnectClient() )->claim( $code, $pending['code_verifier'] );
		$api_key = is_wp_error( $result ) ? '' : $this->sanitize_claimed_key( $result['api_key'] ?? null );

		if ( $api_key === '' ) {
			Logger::log(
				'Connect flow claim failed',
				[ 'error' => is_wp_error( $result ) ? $result->get_error_message() : 'invalid_key' ]
			);

			$this->set_notice(
				__( 'Connection failed. Try Connect again, or paste your API key manually.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				NoticeHelper::TYPE_ERROR
			);
		} else {
			// Re-check at persist time: a callback racing in another tab may have
			// already connected this user between the guard above and here. Never
			// overwrite an existing key — the connection still succeeded either way.
			if ( ! SettingsHelper::has_api_key() ) {
				SettingsHelper::persist_validated_key( $api_key );

				SubscriptionStats::get_instance()->clear_cache();
				SubscriptionStats::get_instance()->schedule_refresh();
			}

			$this->set_notice(
				__( 'Connected to ActiveLayer. Your API key has been saved.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				NoticeHelper::TYPE_SUCCESS
			);
		}

		wp_safe_redirect( esc_url_raw( remove_query_arg( 'al_code' ) ) );
		exit;
	}

	/**
	 * Consume the one-time connect notice for the current user, if any.
	 *
	 * @since 1.3.0
	 *
	 * @return array|null { message, type } or null when there is no notice.
	 */
	public function take_notice(): ?array {

		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}

		delete_transient( $key );

		return [
			'message' => (string) $notice['message'],
			'type'    => (string) ( $notice['type'] ?? NoticeHelper::TYPE_SUCCESS ),
		];
	}

	/**
	 * Validate and normalize the API key returned by the claim response.
	 *
	 * Defends against a malformed or out-of-spec app response: accepts only a
	 * non-empty string of plausible length with no control characters. Returns
	 * an empty string for anything else (handled as a claim failure upstream).
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value Raw api_key value from the decoded claim payload.
	 *
	 * @return string Trimmed key, or '' when the value is not an acceptable key.
	 */
	private function sanitize_claimed_key( $value ): string {

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( $value === '' || strlen( $value ) > self::MAX_CLAIMED_KEY_LENGTH || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Persist a one-time result notice for the current user.
	 *
	 * @since 1.3.0
	 *
	 * @param string $message Notice text.
	 * @param string $type    Notice type (NoticeHelper::TYPE_*).
	 *
	 * @return void
	 */
	private function set_notice( string $message, string $type ): void {

		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			[
				'message' => $message,
				'type'    => $type,
			],
			self::NOTICE_TTL
		);
	}

	/**
	 * Settings page URL used as the return target.
	 *
	 * The application strictly validates this value (open-redirect hardening): it
	 * must be the exact plugin callback — an /wp-admin/<file>.php path with a single
	 * `page` query param and nothing else. Do not append extra query args (tab, UTM,
	 * nonce, ...) or move to a non-/wp-admin/*.php path, or the app silently drops the
	 * connect flow and the user falls back to manual paste. Pinned by
	 * ConnectFlowTest::testReturnUrlMatchesAppSecurityContract.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	private function return_url(): string {

		return admin_url( 'admin.php?page=activelayer-settings' );
	}
}

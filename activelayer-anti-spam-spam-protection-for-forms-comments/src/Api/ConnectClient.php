<?php

namespace ActiveLayer\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\AppUrlHelper;
use ActiveLayer\Logger\Logger;
use WP_Error;

/**
 * Server-to-server client for the one-click Connect "claim" exchange.
 *
 * Unlike ApiClient, this does NOT use the configured API key — it bootstraps
 * the key by exchanging a one-time authorization code + PKCE verifier with the
 * application. The code and verifier travel only in the request body and are
 * never logged.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Api
 */
final class ConnectClient {

	/**
	 * Claim endpoint path on the ActiveLayer application.
	 *
	 * @since 1.3.0
	 */
	private const CLAIM_PATH = '/api/connect/claim';

	/**
	 * Request timeout in seconds.
	 *
	 * Tuned for the synchronous Connect return: claim() runs inline during the
	 * admin Settings page render, so the value is kept low to bound the page
	 * hang if the app is slow. On timeout the flow degrades to manual paste.
	 *
	 * @since 1.3.0
	 */
	private const TIMEOUT = 8;

	/**
	 * Exchange an authorization code and PKCE verifier for the provisioned API key.
	 *
	 * @since 1.3.0
	 *
	 * @param string $code          One-time authorization code from the return redirect.
	 * @param string $code_verifier Private PKCE verifier minted by ConnectFlow.
	 *
	 * @return array|WP_Error Decoded claim payload (includes api_key) on success, WP_Error otherwise.
	 */
	public function claim( string $code, string $code_verifier ) {

		$response = wp_remote_post(
			AppUrlHelper::get_app_base() . self::CLAIM_PATH,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'code'          => $code,
						'code_verifier' => $code_verifier,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::log( 'Connect claim transport error', [ 'error' => $response->get_error_message() ] );

			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		Logger::log( 'Connect claim response', [ 'status' => $status ] );

		if ( $status !== 200 ) {
			return new WP_Error( 'al_claim_failed', 'Connect claim failed', [ 'status' => $status ] );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['api_key'] ) ) {
			return new WP_Error( 'al_claim_invalid', 'Invalid claim response' );
		}

		return $decoded;
	}
}

<?php

namespace ActiveLayer\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Logger\Logger;
use WP_Error;

/**
 * API Request Handler.
 *
 * Low-level HTTP transport for communicating with the ActiveLayer API.
 * Handles URL construction, headers, logging, and JSON parsing.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Api
 */
class ApiRequestHandler {

	/**
	 * API base URL.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * API key for authentication.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @since 1.1.0
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param string $api_url API base URL.
	 * @param string $api_key API key.
	 * @param int    $timeout Request timeout in seconds.
	 */
	public function __construct( string $api_url, string $api_key, int $timeout = 10 ) {

		$this->api_url = $api_url;
		$this->api_key = $api_key;
		$this->timeout = $timeout;
	}

	/**
	 * Make HTTP request to API.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Replaced raw payload logging with PII-safe summary (email hashed, names/messages dropped).
	 *
	 * @param string      $endpoint       API endpoint path.
	 * @param array       $data           Request data.
	 * @param string|null $custom_api_key Optional. Custom API key override.
	 * @param string      $method         Optional. HTTP method (GET, POST). Default 'POST'.
	 *
	 * @return array|WP_Error Decoded response data or error.
	 */
	public function request( string $endpoint, array $data, ?string $custom_api_key = null, string $method = 'POST' ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$api_key = $custom_api_key ?? $this->api_key;

		if ( empty( $this->api_url ) || empty( $api_key ) ) {
			return new WP_Error( 'api_config_missing', 'API URL or key not configured' );
		}

		$url = rtrim( $this->api_url, '/' ) . $endpoint;

		$args = [
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key'    => $api_key,
				'User-Agent'   => 'WordPress-ActiveLayer/' . ACTIVELAYER_PLUGIN_VERSION,
				'Accept'       => 'application/json, */*;q=0.1',
			],
		];

		// Only add body for non-GET requests.
		if ( $method !== 'GET' ) {
			$args['body'] = wp_json_encode( $data );
		}

		Logger::log(
			'API request ' . $endpoint,
			[
				'endpoint' => $endpoint,
				'method'   => $method,
				'timeout'  => $this->timeout,
				'payload'  => self::summarize_payload_for_log( $data ),
			]
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Logger::log( 'API error ' . $endpoint, [ 'error' => $response->get_error_message() ] );

			return $response;
		}

		return $this->decode_response( $endpoint, $response );
	}

	/**
	 * Decode and log the HTTP response.
	 *
	 * @since 1.1.0
	 *
	 * @param string $endpoint API endpoint for log context.
	 * @param array  $response Raw wp_remote_request response.
	 *
	 * @return array|WP_Error Decoded JSON or error.
	 */
	private function decode_response( string $endpoint, array $response ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		$decoded      = json_decode( $response_body, true );
		$decode_error = json_last_error();

		$response_log = [
			'endpoint'    => $endpoint,
			'code'        => $response_code,
			'body_length' => strlen( (string) $response_body ),
		];

		if ( $decode_error === JSON_ERROR_NONE && is_array( $decoded ) ) {
			if ( isset( $decoded['is_spam'] ) ) {
				$response_log['verdict'] = $decoded['is_spam'] ? 'spam' : 'clean';
			}

			if ( isset( $decoded['execution_time'] ) ) {
				$response_log['execution_time'] = $decoded['execution_time'];
			}
		} else {
			$response_log['json_error'] = json_last_error_msg();
		}

		Logger::log( 'API response ' . $endpoint, $response_log );

		if ( $response_code !== 200 ) {
			return new WP_Error( 'api_error', sprintf( 'API returned HTTP %d', $response_code ) );
		}

		if ( $decode_error !== JSON_ERROR_NONE ) {
			Logger::log( 'JSON error', [ 'error' => json_last_error_msg() ] );

			return new WP_Error( 'json_error', 'Invalid JSON response from API' );
		}

		return $decoded;
	}

	/**
	 * Build a PII-safe summary of an outbound API payload for logging.
	 *
	 * Hashes email, drops raw name/message/IP, retains only structural
	 * metadata (field keys, form context, signal presence).
	 *
	 * @since 1.2.0
	 *
	 * @param array $data Outbound request payload.
	 *
	 * @return array Sanitized payload summary safe to persist in logs.
	 */
	private static function summarize_payload_for_log( array $data ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$summary = [
			'fields' => array_keys( $data ),
		];

		if ( isset( $data['email'] ) && is_string( $data['email'] ) && $data['email'] !== '' ) {
			// HMAC with the WP auth salt: stable per-site (debugging) but rainbow-table-safe across sites.
			$summary['email_hash'] = hash_hmac( 'sha256', strtolower( $data['email'] ), wp_salt( 'auth' ) );
		}

		if ( isset( $data['context'] ) && is_array( $data['context'] ) ) {
			$summary['form_id']   = $data['context']['form_id'] ?? null;
			$summary['form_name'] = $data['context']['form_name'] ?? null;
			$summary['provider']  = $data['context']['provider'] ?? null;
		}

		if ( isset( $data['behavioral_signals'] ) ) {
			$summary['has_behavioral_signals'] = ! empty( $data['behavioral_signals'] );
		}

		if ( isset( $data['environment_signals'] ) ) {
			$summary['has_environment_signals'] = ! empty( $data['environment_signals'] );
		}

		return $summary;
	}

	/**
	 * Check whether the handler is configured.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if both URL and key are set.
	 */
	public function is_configured(): bool {

		return ! empty( $this->api_url ) && ! empty( $this->api_key );
	}

	/**
	 * Get configuration status.
	 *
	 * @since 1.1.0
	 *
	 * @return array Configuration status.
	 */
	public function get_config_status(): array {

		return [
			'api_url_set' => ! empty( $this->api_url ),
			'api_key_set' => ! empty( $this->api_key ),
			'timeout'     => $this->timeout,
			'configured'  => $this->is_configured(),
		];
	}
}

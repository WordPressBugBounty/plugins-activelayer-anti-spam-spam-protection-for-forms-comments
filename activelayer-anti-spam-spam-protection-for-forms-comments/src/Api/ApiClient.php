<?php

namespace ActiveLayer\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\EnvironmentSignals;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;

/**
 * Simple API Client for ActiveLayer Service.
 *
 * Handles communication with ActiveLayer anti-spam API (https://activelayer.com).
 * Measures latency and provides sync/async modes.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Api
 */
class ApiClient {

	/**
	 * HTTP request handler.
	 *
	 * @since 1.1.0
	 *
	 * @var ApiRequestHandler
	 */
	private $handler;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->handler = new ApiRequestHandler(
			ACTIVELAYER_API_URL,
			SettingsHelper::get_api_key()
		);
	}

	/**
	 * Check submission for spam.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $submission_data Submission data to check.
	 * @param string $endpoint        API endpoint ('check' or 'check-comment').
	 *
	 * @return array API response with verdict, latency, etc.
	 */
	public function check( array $submission_data, string $endpoint = 'check' ): array {

		// Prepare API request.
		$request_data = $this->prepare_request_data( $submission_data );

		// Make API call.
		$response = $this->handler->request( '/' . $endpoint, $request_data );

		// Parse response.
		return $this->parse_response( $response );
	}

	/**
	 * Check form submission for spam.
	 *
	 * @since 1.0.0
	 *
	 * @param array $submission_data Submission data to check.
	 *
	 * @return array API response with verdict, latency, etc.
	 */
	public function check_submission( array $submission_data ): array {

		return $this->check( $submission_data );
	}

	/**
	 * Check comment for spam using dedicated comment endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param array $submission_data Comment submission data to check.
	 *
	 * @return array API response with verdict, latency, etc.
	 */
	public function check_comment( array $submission_data ): array {

		return $this->check( $submission_data, 'check-comment' );
	}

	/**
	 * Send feedback to API about user correction.
	 *
	 * @since 1.1.0
	 *
	 * @param string $correct_verdict Correct verdict according to user (spam|clean).
	 * @param string $detection_id    Detection ID from original API response.
	 *
	 * @return array API response.
	 */
	public function send_feedback( string $correct_verdict, string $detection_id ): array {

		$feedback_data = $this->prepare_feedback_data( $correct_verdict, $detection_id );

		if ( empty( $feedback_data ) ) {
			return [
				'success' => false,
				'error'   => 'Missing detection_id',
			];
		}

		$response = $this->handler->request( '/feedback', $feedback_data );

		return $this->parse_feedback_response( $response );
	}

	/**
	 * Verify API key by making a request to the verification endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key API key to verify.
	 *
	 * @return array Verification result.
	 */
	public function verify_key( string $api_key ): array {

		if ( empty( $api_key ) ) {
			return [
				'success' => false,
				'message' => __( 'API key is required', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];
		}

		// Use handler with custom API key.
		$response = $this->handler->request( '/verify', [], $api_key, 'GET' );

		// Handle WP_Error response.
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			Logger::log( 'API key verification error', [ 'error' => $error_message ] );

			// Check for authentication errors.
			if ( strpos( $error_message, '401' ) !== false || strpos( $error_message, '403' ) !== false ) {
				return [
					'success' => false,
					'message' => __( 'Invalid API key', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				];
			}

			return [
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message. */
					__( 'Connection error: %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					$error_message
				),
			];
		}

		// Successful response.
		return [
			'success' => true,
			'message' => __( 'API key is valid', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'data'    => $response,
		];
	}

	/**
	 * Get API configuration status.
	 *
	 * @since 1.0.0
	 *
	 * @return array Configuration status.
	 */
	public function get_config_status(): array {

		return $this->handler->get_config_status();
	}

	/**
	 * Prepare submission data for API request.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Forwarded `data_type` from the integration (defaults to `form`).
	 *
	 * @param array $submission_data Raw submission data.
	 *
	 * @return array Formatted request data.
	 */
	private function prepare_request_data( array $submission_data ): array {

		$defaults = [
			'email'       => '',
			'name'        => '',
			'message'     => '',
			'website_url' => '',
			'ip'          => '',
			'user_agent'  => '',
			'data_type'   => 'form',
		];

		$request_data              = array_merge( $defaults, array_intersect_key( $submission_data, $defaults ) );
		$request_data['timestamp'] = $this->resolve_timestamp( $submission_data );

		$context = $this->build_request_context( $submission_data );

		if ( ! empty( $context ) ) {
			$request_data['context'] = $context;
		}

		return $request_data;
	}

	/**
	 * Build contextual metadata that accompanies every API request.
	 *
	 * Keeps the payload extensible so new attributes can be appended later.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Added support for environment_signals.
	 *
	 * @param array $submission_data Raw submission data.
	 *
	 * @return array Request context data.
	 */
	private function build_request_context( array $submission_data ): array {

		$context = [];

		if ( isset( $submission_data['context'] ) && is_array( $submission_data['context'] ) ) {
			$context = $submission_data['context'];
		}

		$site_url = home_url();

		if ( ! empty( $site_url ) ) {
			$context['site_url'] = esc_url_raw( $site_url );
		}

		// Handle environment signals if present.
		$context = $this->maybe_add_environment_signals( $context );

		return $context;
	}

	/**
	 * Validate and add environment signals to the request context.
	 *
	 * Environment signals are client-side bot detection data collected by
	 * the EnvironmentDetector JavaScript. Handles both:
	 * - raw signals (with check_timestamp) that need validation;
	 * - already-normalized signals produced upstream (without check_timestamp).
	 *
	 * Already-normalized signals are preserved as-is to avoid dropping
	 * valid data during double-processing.
	 *
	 * @since 1.1.0
	 *
	 * @param array $context Current request context.
	 *
	 * @return array Context with validated environment signals (if any).
	 */
	private function maybe_add_environment_signals( array $context ): array {

		// No signals provided - nothing to do.
		if ( ! isset( $context['environment_signals'] ) || ! is_array( $context['environment_signals'] ) ) {
			return $context;
		}

		$raw_signals = $context['environment_signals'];

		// If check_timestamp is missing, assume signals are already normalized upstream.
		if ( ! array_key_exists( 'check_timestamp', $raw_signals ) ) {
			return $context;
		}

		// Validate signals through the EnvironmentSignals class.
		$signals = new EnvironmentSignals( $raw_signals );

		if ( ! $signals->is_valid() ) {
			// Invalid signals - remove them from context to avoid sending garbage.
			unset( $context['environment_signals'] );

			return $context;
		}

		// Replace raw signals with validated API payload.
		$context['environment_signals'] = $signals->to_api_payload();

		return $context;
	}

	/**
	 * Resolve timestamp for API request.
	 *
	 * Uses the original submission time if provided (created_at),
	 * preserving accurate timing for burst detection and rate analysis
	 * when submissions are processed asynchronously via queue.
	 *
	 * @since 1.0.0
	 *
	 * @param array $submission_data Submission data that may contain created_at.
	 *
	 * @return int Unix timestamp.
	 */
	private function resolve_timestamp( array $submission_data ): int {

		// Use original submission time if provided.
		if ( ! empty( $submission_data['created_at'] ) ) {
			$timestamp = $submission_data['created_at'];

			// Handle MySQL datetime string.
			if ( is_string( $timestamp ) && ! is_numeric( $timestamp ) ) {
				$parsed = strtotime( $timestamp );

				return $parsed !== false ? $parsed : time();
			}

			return (int) $timestamp;
		}

		// Fall back to current time for sync requests.
		return time();
	}

	/**
	 * Parse API response.
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $response API response.
	 *
	 * @return array Parsed response.
	 */
	private function parse_response( $response ): array {

		if ( is_wp_error( $response ) ) {
			return [
				'success'        => false,
				'verdict'        => null,
				'error'          => $response->get_error_message(),
				'execution_time' => 0,
				'raw_response'   => null,
			];
		}

		return [
			'success'        => true,
			'verdict'        => $response['is_spam'] ? 'spam' : 'clean',
			'execution_time' => $response['execution_time'] ?? 0,
			'detection_id'   => $response['detection_id'] ?? null,
			'raw_response'   => $response,
		];
	}

	/**
	 * Prepare feedback data for API request.
	 *
	 * @since 1.1.0
	 *
	 * @param string $correct_verdict Correct verdict according to user (spam|clean).
	 * @param string $detection_id    Detection ID from original API response.
	 *
	 * @return array Formatted feedback data.
	 */
	private function prepare_feedback_data( string $correct_verdict, string $detection_id ): array {

		if ( empty( $detection_id ) ) {
			return [];
		}

		return [
			'detection_id' => $detection_id,
			'is_spam'      => $correct_verdict === 'spam',
		];
	}

	/**
	 * Parse feedback API response.
	 *
	 * @since 1.1.0
	 *
	 * @param array|WP_Error $response API response.
	 *
	 * @return array Parsed response.
	 */
	private function parse_feedback_response( $response ): array {

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		return [
			'success' => true,
			'message' => $response['message'] ?? 'Feedback sent successfully',
		];
	}
}

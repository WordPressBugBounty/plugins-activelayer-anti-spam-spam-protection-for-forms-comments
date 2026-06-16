<?php

namespace ActiveLayer\ClientSignals\Enrichers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\EnvironmentSignals;
use ActiveLayer\ClientSignals\Fields\EnvironmentField;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;

/**
 * Enriches submission payload with client-side environment signals.
 *
 * Reads the EnvironmentField hidden input from $_POST, validates via
 * EnvironmentSignals, and appends the API payload to context.
 *
 * @since 1.2.0
 */
class EnvironmentSignalsEnricher {

	/**
	 * Append environment signals to normalized submission data.
	 *
	 * No-op when environment tracking is disabled, the field is absent, JSON is
	 * invalid, or signals are invalid.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $normalized_data Normalized submission data.
	 * @param string $provider_slug   Integration slug (used for logging).
	 *
	 * @return array Submission data with environment_signals in context (or unchanged).
	 */
	public function enrich( array $normalized_data, string $provider_slug ): array {

		if ( ! SettingsHelper::is_environment_tracking_enabled() ) {
			return $normalized_data;
		}

		$raw = $this->read_from_post();

		if ( empty( $raw ) ) {
			return $normalized_data;
		}

		$signals = new EnvironmentSignals( $raw );

		if ( ! $signals->is_valid() ) {
			Logger::log(
				'Environment signals validation failed',
				[ 'provider' => $provider_slug ]
			);

			return $normalized_data;
		}

		if ( ! isset( $normalized_data['context'] ) || ! is_array( $normalized_data['context'] ) ) {
			$normalized_data['context'] = [];
		}

		$normalized_data['context']['environment_signals'] = $signals->to_api_payload();

		return $normalized_data;
	}

	/**
	 * Read and JSON-decode the environment-signals field from $_POST.
	 *
	 * @since 1.2.0
	 *
	 * @return array|null Decoded signals or null when missing/invalid.
	 */
	private function read_from_post(): ?array {

		$field_name = EnvironmentField::get_field_name();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by form provider.
		if ( ! isset( $_POST[ $field_name ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by form provider; sanitization via EnvironmentSignals.
		$raw = wp_unslash( $_POST[ $field_name ] );

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );

			return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $decoded : null;
		}

		return is_array( $raw ) ? $raw : null;
	}
}

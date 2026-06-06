<?php

namespace ActiveLayer\ClientSignals\Enrichers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\BehavioralSignals;
use ActiveLayer\Logger\Logger;

/**
 * Enriches submission payload with client-side behavioral signals.
 *
 * Wraps BehavioralSignals::from_post_data() and appends the API
 * payload to context when valid.
 *
 * @since 1.2.0
 */
class BehavioralSignalsEnricher {

	/**
	 * Append behavioral signals to normalized submission data.
	 *
	 * No-op when signals are absent or invalid.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $normalized_data Normalized submission data.
	 * @param string $provider_slug   Integration slug (used for logging).
	 *
	 * @return array Submission data with behavioral_signals in context (or unchanged).
	 */
	public function enrich( array $normalized_data, string $provider_slug ): array {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by form provider.
		$signals = BehavioralSignals::from_post_data( wp_unslash( $_POST ) );

		if ( ! $signals->is_valid() ) {
			Logger::log(
				'Behavioral signals validation failed or not present',
				[ 'provider' => $provider_slug ]
			);

			return $normalized_data;
		}

		if ( ! isset( $normalized_data['context'] ) || ! is_array( $normalized_data['context'] ) ) {
			$normalized_data['context'] = [];
		}

		$normalized_data['context']['behavioral_signals'] = $signals->to_api_payload();

		return $normalized_data;
	}
}

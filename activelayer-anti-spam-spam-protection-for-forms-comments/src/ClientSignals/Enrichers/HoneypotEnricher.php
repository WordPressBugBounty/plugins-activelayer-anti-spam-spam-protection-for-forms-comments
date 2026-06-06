<?php

namespace ActiveLayer\ClientSignals\Enrichers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\Fields\HoneypotField;
use ActiveLayer\ClientSignals\HoneypotSignals;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;

/**
 * Enriches submission payload with honeypot signals.
 *
 * Owns the honeypot-tracking setting gate: returns the input
 * unchanged when honeypot tracking is disabled.
 *
 * @since 1.2.0
 */
class HoneypotEnricher {

	/**
	 * Append honeypot signals to normalized submission data.
	 *
	 * No-op when honeypot tracking is disabled. Otherwise always
	 * appends signals and logs when the honeypot was triggered.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $normalized_data Normalized submission data.
	 * @param string $provider_slug   Integration slug (used for logging).
	 *
	 * @return array Submission data with honeypot_signals in context (or unchanged).
	 */
	public function enrich( array $normalized_data, string $provider_slug ): array {

		if ( ! SettingsHelper::is_honeypot_tracking_enabled() ) {
			return $normalized_data;
		}

		// Skip enrichment when the honeypot field was not rendered into this form.
		// Without the field in $_POST we cannot tell whether the bot left it alone
		// or whether the form simply does not protect with a honeypot; emitting
		// `honeypot_filled=false` in the latter case would be a false "OK" signal.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by form provider.
		if ( ! isset( $_POST[ HoneypotField::get_field_name() ] ) ) {
			return $normalized_data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by form provider.
		$signals = HoneypotSignals::from_post_data( wp_unslash( $_POST ) );

		if ( ! isset( $normalized_data['context'] ) || ! is_array( $normalized_data['context'] ) ) {
			$normalized_data['context'] = [];
		}

		$normalized_data['context']['honeypot_signals'] = $signals->to_api_payload();

		if ( $signals->is_triggered() ) {
			Logger::log(
				'Honeypot triggered',
				[
					'provider' => $provider_slug,
					'form_id'  => $normalized_data['context']['form_id'] ?? 'unknown',
					'filled'   => $signals->is_filled(),
				]
			);
		}

		return $normalized_data;
	}
}

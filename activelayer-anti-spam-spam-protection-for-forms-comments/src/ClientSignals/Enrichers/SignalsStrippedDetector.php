<?php

namespace ActiveLayer\ClientSignals\Enrichers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\Fields\BehavioralField;
use ActiveLayer\ClientSignals\Fields\EnvironmentField;
use ActiveLayer\ClientSignals\Fields\HoneypotField;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;

/**
 * Detects when expected client signals are stripped from the request.
 *
 * Attaches a per-field `context.signal_integrity` report so the API can tell a
 * field that arrived but is empty (`empty`) from one that was dropped entirely
 * (`absent`). Environment and behavioral fields report `ok|empty|absent`; the
 * honeypot reports `present|absent` because an empty honeypot string is the
 * expected clean value (its filled/empty value travels separately in
 * `context.honeypot_signals`). The legacy `context.signals_stripped` boolean is
 * preserved unchanged for backward compatibility.
 *
 * @since 1.2.0
 * @since 1.5.0 Add per-field signal_integrity report (ok|empty|absent).
 */
class SignalsStrippedDetector {

	/**
	 * Attach the signal_integrity report (and legacy signals_stripped flag) to context.
	 *
	 * @since 1.2.0
	 * @since 1.5.0 Add per-field signal_integrity report.
	 *
	 * @param array  $normalized_data Normalized submission data.
	 * @param string $provider_slug   Integration slug (used for logging).
	 *
	 * @return array Submission data with signal_integrity (and signals_stripped when applicable).
	 */
	public function enrich( array $normalized_data, string $provider_slug ): array {

		// REST requests with a JSON body cannot carry form-encoded hidden fields
		// in $_POST by design, so absence of signal fields there is not evidence
		// of stripping — it's the steady state for that transport (e.g. WC Store
		// API checkout). REST requests with form-encoded bodies (Contact Form 7's
		// /feedback endpoint posts FormData via REST and PHP DOES populate $_POST
		// for multipart/form-data) must still be checked, so this guard is
		// narrowed by Content-Type rather than by REST_REQUEST alone.
		if ( self::is_json_rest_request() ) {
			return $normalized_data;
		}

		// Nothing to diagnose when no signal tracker is enabled.
		if ( ! self::is_any_tracker_enabled() ) {
			return $normalized_data;
		}

		if ( ! isset( $normalized_data['context'] ) || ! is_array( $normalized_data['context'] ) ) {
			$normalized_data['context'] = [];
		}

		$normalized_data['context']['signal_integrity'] = self::build_integrity_report();

		if ( self::is_any_expected_signal_missing() ) {
			$normalized_data['context']['signals_stripped'] = true;

			Logger::log(
				'Client signals stripped',
				[
					'provider'  => $provider_slug,
					'form_id'   => $normalized_data['context']['form_id'] ?? 'unknown',
					'integrity' => $normalized_data['context']['signal_integrity'],
				]
			);
		}

		return $normalized_data;
	}

	/**
	 * Check whether any tracking-enabled signal is missing from $_POST.
	 *
	 * @since 1.2.0
	 * @since 1.4.0 Include the honeypot field when honeypot tracking is enabled.
	 *
	 * @return bool True when at least one enabled signal type was stripped.
	 */
	private static function is_any_expected_signal_missing(): bool {

		if ( SettingsHelper::is_environment_tracking_enabled()
			&& self::is_signal_field_empty( EnvironmentField::FIELD_NAME )
		) {
			return true;
		}

		if ( SettingsHelper::is_behavioral_tracking_enabled()
			&& self::is_signal_field_empty( BehavioralField::FIELD_NAME )
		) {
			return true;
		}

		if ( SettingsHelper::is_honeypot_tracking_enabled()
			&& self::is_honeypot_field_stripped()
		) {
			return true;
		}

		return false;
	}

	/**
	 * Whether any signal tracker is enabled.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	private static function is_any_tracker_enabled(): bool {

		return SettingsHelper::is_environment_tracking_enabled()
			|| SettingsHelper::is_behavioral_tracking_enabled()
			|| SettingsHelper::is_honeypot_tracking_enabled();
	}

	/**
	 * Build the per-field integrity report.
	 *
	 * Environment/behavioral report ok|empty|absent; the honeypot reports
	 * present|absent. Disabled trackers are omitted.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string,string>
	 */
	private static function build_integrity_report(): array {

		$report = [];

		if ( SettingsHelper::is_environment_tracking_enabled() ) {
			$report['environment'] = self::field_state( EnvironmentField::FIELD_NAME );
		}

		if ( SettingsHelper::is_behavioral_tracking_enabled() ) {
			$report['behavioral'] = self::field_state( BehavioralField::FIELD_NAME );
		}

		if ( SettingsHelper::is_honeypot_tracking_enabled() ) {
			$report['honeypot'] = self::field_presence( HoneypotField::FIELD_NAME );
		}

		return $report;
	}

	/**
	 * Classify a value-bearing signal field as ok|empty|absent.
	 *
	 * `empty` means the field arrived but carries no value — either no JS ran or
	 * (for behavioral) the collector never initialized; it is not, by itself,
	 * proof of stripping. `absent` means the key is missing from $_POST entirely.
	 *
	 * @since 1.5.0
	 *
	 * @param string $field_name POST field name.
	 *
	 * @return string ok|empty|absent.
	 */
	private static function field_state( string $field_name ): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by form provider.
		if ( ! isset( $_POST[ $field_name ] ) ) {
			return 'absent';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by form provider; emptiness check only.
		$raw = wp_unslash( $_POST[ $field_name ] );

		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
			return 'empty';
		}

		return 'ok';
	}

	/**
	 * Classify a presence-only field (honeypot) as present|absent.
	 *
	 * @since 1.5.0
	 *
	 * @param string $field_name POST field name.
	 *
	 * @return string present|absent.
	 */
	private static function field_presence( string $field_name ): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by form provider.
		return isset( $_POST[ $field_name ] ) ? 'present' : 'absent';
	}

	/**
	 * Detect a REST request with a JSON body.
	 *
	 * CF7 (and other form providers) submit FormData via REST endpoints; PHP
	 * still populates $_POST for `multipart/form-data` and
	 * `application/x-www-form-urlencoded` bodies, so the detector must run.
	 * Only JSON-body requests (WC Store API checkout, etc.) lack $_POST by
	 * transport, and that's what this guard scopes the skip to.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private static function is_json_rest_request(): bool {

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only header check, lowercased for comparison.
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( (string) $_SERVER['CONTENT_TYPE'] ) : '';

		return $content_type !== '' && strpos( $content_type, 'application/json' ) === 0;
	}

	/**
	 * Check whether a signal POST field is missing or empty.
	 *
	 * @since 1.2.0
	 *
	 * @param string $field_name POST field name.
	 *
	 * @return bool True when the field is absent or contains empty string.
	 */
	private static function is_signal_field_empty( string $field_name ): bool {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by form provider.
		if ( ! isset( $_POST[ $field_name ] ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by form provider; trim only checks emptiness, no output.
		$raw = wp_unslash( $_POST[ $field_name ] );

		return ! is_string( $raw ) || trim( $raw ) === '';
	}

	/**
	 * Check whether the honeypot POST field is absent or malformed.
	 *
	 * An empty honeypot string is the expected clean-human value, so only
	 * absence or array-shaped tampering is considered stripped here.
	 *
	 * @since 1.4.0
	 *
	 * @return bool True when the honeypot field was stripped or malformed.
	 */
	private static function is_honeypot_field_stripped(): bool {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by form provider.
		if ( ! isset( $_POST[ HoneypotField::FIELD_NAME ] ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by form provider; type check only.
		return ! is_string( wp_unslash( $_POST[ HoneypotField::FIELD_NAME ] ) );
	}
}

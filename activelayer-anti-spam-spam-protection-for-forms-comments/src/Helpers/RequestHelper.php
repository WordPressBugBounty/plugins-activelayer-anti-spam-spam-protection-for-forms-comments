<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RequestHelper.
 *
 * Helper methods for working with HTTP requests and user data.
 *
 * @since 1.0.0
 */
class RequestHelper {

	/**
	 * Get user IP address from request headers.
	 *
	 * Detection order (CleanTalk-style):
	 *   1. CDN-specific headers paired with provider signals (Cloudflare, Sucuri).
	 *   2. REMOTE_ADDR when it's a public address (default for sites not behind a reverse proxy).
	 *   3. Forwarded headers (X-Forwarded-For, X-Real-Ip) only when REMOTE_ADDR is a private/reserved
	 *      address — that is the signal that the request transited an upstream proxy.
	 *   4. REMOTE_ADDR even when private, as a final non-empty fallback.
	 *
	 * Forwarded headers are intentionally NOT trusted when REMOTE_ADDR is already public,
	 * because in that case the request reached us directly and any forwarded header is
	 * supplied by the client.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Replaced global proxy-trust flag with per-CDN purpose-built detection; added activelayer_get_user_ip filter; forwarded headers gated by REMOTE_ADDR being private.
	 *
	 * @return string User IP address or empty string if not found.
	 */
	public static function get_user_ip(): string {

		$ip = self::detect_ip_from_cdn();

		if ( $ip === '' ) {
			$remote_addr = self::get_remote_addr();

			if ( self::is_valid_public_ip( $remote_addr ) ) {
				// Site directly exposed: REMOTE_ADDR is the real client.
				$ip = $remote_addr;
			} else {
				// REMOTE_ADDR is private/reserved → site is behind a reverse proxy or NAT;
				// trust generic forwarded headers as the next-best signal.
				$ip = self::detect_ip_from_forwarded_chain();

				if ( $ip === '' ) {
					// Final fallback: even private REMOTE_ADDR is preferable to empty.
					$ip = $remote_addr;
				}
			}
		}

		/**
		 * Filter the detected user IP address.
		 *
		 * Allows site owners behind non-default proxies (e.g. custom CDN, internal load balancer)
		 * to override IP detection. Return an empty string to discard.
		 *
		 * @since 1.2.0
		 *
		 * @param string $ip Detected IP address.
		 */
		$ip = (string) apply_filters( 'activelayer_get_user_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Detect IP from CDN-specific headers paired with provider signals.
	 *
	 * Pair-header guards make spoofing harder: each CDN sets multiple provider-specific
	 * signal headers alongside the IP header.
	 *
	 * @since 1.2.0
	 *
	 * @return string Public IP address or empty string when no CDN match.
	 */
	private static function detect_ip_from_cdn(): string {

		// Cloudflare: trust Cf-Connecting-Ip only when paired with both Cf-Ray and Cf-IPCountry.
		if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$ip = self::extract_first_ip( 'HTTP_CF_CONNECTING_IP' );

			if ( self::is_valid_public_ip( $ip ) ) {
				return $ip;
			}
		}

		// Sucuri Firewall: trust X-Sucuri-Clientip only when paired with X-Sucuri-Country.
		// Without this guard any client could spoof the IP by sending X-Sucuri-Clientip alone.
		if ( ! empty( $_SERVER['HTTP_X_SUCURI_COUNTRY'] ) ) {
			$sucuri_ip = self::extract_first_ip( 'HTTP_X_SUCURI_CLIENTIP' );

			if ( self::is_valid_public_ip( $sucuri_ip ) ) {
				return $sucuri_ip;
			}
		}

		return '';
	}

	/**
	 * Detect IP from generic forwarded headers.
	 *
	 * Only consulted when REMOTE_ADDR is private/reserved (i.e. the request came through
	 * an upstream proxy). When called in any other context the caller is responsible for
	 * validating that forwarded headers are trustworthy.
	 *
	 * @since 1.2.0
	 *
	 * @return string Public IP address or empty string when no forwarded match.
	 */
	private static function detect_ip_from_forwarded_chain(): string {

		$forwarded_ip = self::extract_first_ip( 'HTTP_X_FORWARDED_FOR' );

		if ( self::is_valid_public_ip( $forwarded_ip ) ) {
			return $forwarded_ip;
		}

		$real_ip = self::extract_first_ip( 'HTTP_X_REAL_IP' );

		if ( self::is_valid_public_ip( $real_ip ) ) {
			return $real_ip;
		}

		return '';
	}

	/**
	 * Get IP from REMOTE_ADDR.
	 *
	 * @since 1.2.0
	 *
	 * @return string Validated IP address (may be private when behind NAT) or empty string.
	 */
	private static function get_remote_addr(): string {

		if ( empty( $_SERVER['REMOTE_ADDR'] ) || ! is_string( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Extract first IP from a comma-separated header value.
	 *
	 * @since 1.2.0
	 *
	 * @param string $header_name Server superglobal key (e.g. HTTP_X_FORWARDED_FOR).
	 *
	 * @return string Trimmed first IP candidate or empty string.
	 */
	private static function extract_first_ip( string $header_name ): string {

		if ( empty( $_SERVER[ $header_name ] ) || ! is_string( $_SERVER[ $header_name ] ) ) {
			return '';
		}

		$value = sanitize_text_field( wp_unslash( $_SERVER[ $header_name ] ) );
		$parts = explode( ',', $value );

		return trim( $parts[0] );
	}

	/**
	 * Validate that a string is a public IP (not private/reserved range).
	 *
	 * @since 1.2.0
	 *
	 * @param string $ip IP address candidate.
	 *
	 * @return bool True when valid public IPv4/IPv6.
	 */
	private static function is_valid_public_ip( string $ip ): bool {

		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
     * Recursively sanitize all values in an array.
     *
     * Defensive variant: unknown scalar types are coerced to strings then
     * sanitized via `sanitize_text_field()`. For typed settings forms where
     * booleans / ints / nulls must round-trip unchanged, use the lighter
     * {@see \ActiveLayer\Helpers\ArrayHelper::sanitize_recursive()} instead.
     *
     * @since 1.0.0
     *
     * @param mixed $data Array or scalar to sanitize.
     *
     * @return mixed Sanitized array or scalar.
     */
	public static function sanitize_array_recursive( $data ) { //phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::sanitize_array_recursive( $value );
			}

			return $data;
		}

		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}

		if ( is_numeric( $data ) ) {
			return $data; // Keep numeric values as-is.
		}

		if ( is_bool( $data ) ) {
			return $data; // Keep boolean values as-is.
		}

		if ( is_null( $data ) ) {
			return null; // Keep null as-is.
		}

		// For any other type, convert to string and sanitize.
		return sanitize_text_field( (string) $data );
	}

	/**
	 * Get user agent string.
	 *
	 * @since 1.0.0
	 *
	 * @return string User agent string or empty string if not found.
	 */
	public static function get_user_agent(): string {

		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		return '';
	}

	/**
	 * Sanitize mixed submission value into a single string.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string Sanitized string (empty when nothing useful).
	 */
	public static function sanitize_field_value( $value ): string { // phpcs:ignore Generic.Metrics.NestingLevel.MaxExceeded, Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( $value === null || is_bool( $value ) ) {
			return '';
		}

		if ( is_array( $value ) ) {
			$parts = [];

			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$parts ): void {
					if ( is_scalar( $item ) || ( is_object( $item ) && method_exists( $item, '__toString' ) ) ) {
						$string = sanitize_text_field( (string) $item );

						if ( $string !== '' ) {
							$parts[] = $string;
						}
					}
				}
			);

			return implode( ', ', $parts );
		}

		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			$value = (string) $value;
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize submission data for database storage.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Raw data.
	 *
	 * @return array Sanitized data.
	 */
	public static function sanitize_submission_data( array $data ): array {

		return self::sanitize_array_recursive( $data );
	}

	/**
	 * Format submission data for output.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_submission Raw submission from database.
	 *
	 * @return array Formatted submission.
	 */
	public static function format_submission( array $raw_submission ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$submission = $raw_submission;

		// Decode JSON fields.
		if ( ! empty( $submission['form_data'] ) && is_string( $submission['form_data'] ) ) {
			$decoded                 = json_decode( $submission['form_data'], true );
			$submission['form_data'] = $decoded ? $decoded : [];
		}

		if ( ! empty( $submission['api_response'] ) && is_string( $submission['api_response'] ) ) {
			$decoded                    = json_decode( $submission['api_response'], true );
			$submission['api_response'] = $decoded ? $decoded : [];
		}

		// Convert timestamps to Unix timestamps.
		if ( ! empty( $submission['created_at'] ) ) {
			$submission['created_at'] = strtotime( $submission['created_at'] );
		}

		if ( ! empty( $submission['processed_at'] ) ) {
			$submission['processed_at'] = strtotime( $submission['processed_at'] );
		}

		if ( isset( $submission['retry_count'] ) ) {
			$submission['retry_count'] = (int) $submission['retry_count'];
		}

		if ( isset( $submission['previous_status'] ) ) {
			$submission['previous_status'] = is_string( $submission['previous_status'] ) ? $submission['previous_status'] : '';
		}

		return $submission;
	}
}

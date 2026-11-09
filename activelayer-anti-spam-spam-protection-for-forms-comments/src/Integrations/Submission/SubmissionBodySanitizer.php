<?php

namespace ActiveLayer\Integrations\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes submission body content for storage and API transmission.
 *
 * @since 1.4.0
 */
class SubmissionBodySanitizer {

	/**
	 * Sanitize a submission body while preserving spam-relevant content.
	 *
	 * Keeps links, HTML markup text, newlines, and percent-encoded sequences intact
	 * so the spam API receives the real body content. It enforces only valid UTF-8
	 * and does not unslash; callers reading slashed sources must unslash before
	 * passing the value here.
	 *
	 * @since 1.4.0
	 * @since 1.8.0 Remove malformed UTF-8 without iconv notices or losing valid fragments.
	 *
	 * @param mixed $value Raw submission body value.
	 *
	 * @return string Content-preserving sanitized submission body.
	 */
	public static function sanitize( $value ): string {

		$value = self::normalize_to_string( $value );

		if ( $value === '' ) {
			return '';
		}

		if ( preg_match( '//u', $value ) === 1 ) {
			return $value;
		}

		// The JSON flag is available on PHP 7.2+ and drops only malformed UTF-8 bytes.
		// Unlike WordPress' iconv strip path, it retains valid content without notices.
		return (string) json_decode( wp_json_encode( $value, JSON_INVALID_UTF8_IGNORE ) );
	}

	/**
	 * Normalize supported input shapes into a string body.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $value Raw body value.
	 *
	 * @return string Normalized body string.
	 */
	private static function normalize_to_string( $value ): string {

		if ( $value === null || is_bool( $value ) ) {
			return '';
		}

		if ( is_array( $value ) ) {
			$parts = [];

			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$parts ): void {
					if ( is_scalar( $item ) || ( is_object( $item ) && method_exists( $item, '__toString' ) ) ) {
						$parts[] = (string) $item;
					}
				}
			);

			return implode( "\n", $parts );
		}

		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			return (string) $value;
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return (string) $value;
	}
}

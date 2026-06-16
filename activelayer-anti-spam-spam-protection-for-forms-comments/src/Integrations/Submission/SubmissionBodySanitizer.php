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

		return wp_check_invalid_utf8( $value, true );
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

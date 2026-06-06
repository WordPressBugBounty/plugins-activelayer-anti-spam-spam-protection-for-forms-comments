<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts the detection ID from an API response.
 *
 * Shared by admin table rows and queue worker feedback processing.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Helpers
 */
class DetectionIdResolver {

	/**
	 * Extract the detection ID from an API response payload.
	 *
	 * Handles both decoded arrays and raw JSON strings.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $api_response API response (array or JSON string).
	 *
	 * @return string Detection ID or empty string if unavailable.
	 */
	public static function resolve( $api_response ): string {

		if ( is_string( $api_response ) ) {
			$api_response = json_decode( $api_response, true );
		}

		if ( is_array( $api_response ) && ! empty( $api_response['detection_id'] ) ) {
			return (string) $api_response['detection_id'];
		}

		return '';
	}
}

<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Array transformation helpers.
 *
 * Reusable, pure-function utilities for array sanitisation. Methods are
 * static; no state.
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Helpers
 */
final class ArrayHelper {

	/**
	 * Recursively sanitize an array of settings values.
	 *
	 * Strings are sanitized with `sanitize_text_field()`; arrays are
	 * traversed recursively. All other scalar types are passed through
	 * unchanged.
	 *
	 * Use this helper for typed settings forms where booleans / ints / nulls
	 * should round-trip without coercion. For request-data sanitisation where
	 * unknown scalar types should be defensively stringified, use
	 * {@see RequestHelper::sanitize_array_recursive()} instead.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $data Data to sanitize.
	 *
	 * @return mixed Sanitized data.
	 */
	public static function sanitize_recursive( $data ) {

		if ( is_array( $data ) ) {
			return array_map( [ self::class, 'sanitize_recursive' ], $data );
		}

		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}

		return $data;
	}
}

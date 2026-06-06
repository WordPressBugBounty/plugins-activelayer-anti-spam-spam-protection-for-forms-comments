<?php

namespace ActiveLayer\ClientSignals\Parsing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared utility class for parsing client-side signals.
 *
 * Provides static methods for parsing and validating common data types
 * from raw signal arrays. Used by both EnvironmentSignals and BehavioralSignals
 * to ensure consistent handling of client-submitted data.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\ClientSignals\Parsing
 */
class SignalParser {

    /**
     * Parse a boolean value from signals array.
     *
     * Returns false if the key is not set. Otherwise, casts the value to bool.
     *
     * @since 1.1.0
     *
     * @param array  $signals Raw signals array.
     * @param string $key     Key to extract.
     *
     * @return bool Parsed boolean value.
     */
    public static function parse_bool( array $signals, string $key ): bool {

        if ( ! isset( $signals[ $key ] ) ) {
            return false;
        }

        return (bool) $signals[ $key ];
    }

    /**
     * Parse an integer value from signals array.
     *
     * Returns the default value if the key is not set or the value is not numeric.
     *
     * @since 1.1.0
     *
     * @param array  $signals       Raw signals array.
     * @param string $key           Key to extract.
     * @param int    $default_value Default value if not set or invalid.
     *
     * @return int Parsed integer value.
     */
    public static function parse_int( array $signals, string $key, int $default_value = 0 ): int {

        if ( ! isset( $signals[ $key ] ) ) {
            return $default_value;
        }

        $value = $signals[ $key ];

        if ( ! is_numeric( $value ) ) {
            return $default_value;
        }

        return (int) $value;
    }

    /**
     * Parse a nullable integer value from signals array.
     *
     * Returns null if the key is not set, the value is null, or the value is not numeric.
     *
     * @since 1.1.0
     *
     * @param array  $signals Raw signals array.
     * @param string $key     Key to extract.
     *
     * @return int|null Parsed integer or null.
     */
    public static function parse_nullable_int( array $signals, string $key ): ?int {

        if ( ! isset( $signals[ $key ] ) ) {
            return null;
        }

        $value = $signals[ $key ];

        if ( $value === null ) {
            return null;
        }

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Parse a nullable float value from signals array.
     *
     * Returns null if the key is not set, the value is null, or the value is not numeric.
     *
     * @since 1.1.0
     *
     * @param array  $signals Raw signals array.
     * @param string $key     Key to extract.
     *
     * @return float|null Parsed float or null.
     */
    public static function parse_nullable_float( array $signals, string $key ): ?float {

        if ( ! isset( $signals[ $key ] ) ) {
            return null;
        }

        $value = $signals[ $key ];

        if ( $value === null ) {
            return null;
        }

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Parse a nullable string value from signals array.
     *
     * Returns null if the key is not set, the value is null, the value is not a string,
     * or the sanitized value is empty.
     *
     * @since 1.1.0
     *
     * @param array  $signals Raw signals array.
     * @param string $key     Key to extract.
     *
     * @return string|null Sanitized string or null.
     */
    public static function parse_nullable_string( array $signals, string $key ): ?string {

        if ( ! isset( $signals[ $key ] ) ) {
            return null;
        }

        $value = $signals[ $key ];

        if ( $value === null ) {
            return null;
        }

        if ( ! is_string( $value ) ) {
            return null;
        }

        $sanitized = sanitize_text_field( $value );

        return $sanitized !== '' ? $sanitized : null;
    }
}

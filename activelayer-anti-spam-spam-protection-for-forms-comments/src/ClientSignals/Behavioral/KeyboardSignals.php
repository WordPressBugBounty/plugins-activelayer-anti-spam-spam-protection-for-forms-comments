<?php
/**
 * Keyboard behavioral signals.
 *
 * @package ActiveLayer\ClientSignals\Behavioral
 * @since   1.1.0
 */

namespace ActiveLayer\ClientSignals\Behavioral;

use ActiveLayer\ClientSignals\Contracts\SignalGroupInterface;
use ActiveLayer\ClientSignals\Parsing\SignalParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles keyboard-related behavioral signals.
 *
 * Processes signals related to keyboard input including keypress counts,
 * timing data, modifier key usage, and correction patterns.
 *
 * @since 1.1.0
 */
class KeyboardSignals implements SignalGroupInterface {

    /**
     * Total number of keypresses.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $keypress_count = 0;

    /**
     * Keypress timing data in format "timestamp,duration;...".
     *
     * @since 1.1.0
     *
     * @var string
     */
    private $keypress_timings = '';

    /**
     * Indices of modifier key usage in format "index;index;...".
     *
     * @since 1.1.0
     *
     * @var string
     */
    private $modifier_keys = '';

    /**
     * Indices of correction key usage in format "index;index;...".
     *
     * @since 1.1.0
     *
     * @var string
     */
    private $correction_keys = '';

    /**
     * Variance in keystroke timing intervals.
     *
     * @since 1.1.0
     *
     * @var float|null
     */
    private $keystroke_variance = null;

    /**
     * Parse raw signals from JavaScript.
     *
     * @since 1.1.0
     *
     * @param array $raw Raw signals array from JavaScript.
     *
     * @return void
     */
    public function parse( array $raw ): void {

        $this->keypress_count     = SignalParser::parse_int( $raw, 'keypress_count', 0 );
        $this->keypress_timings   = $this->sanitize_timing_string( $raw['keypress_timings'] ?? '' );
        $this->modifier_keys      = $this->sanitize_index_string( $raw['modifier_keys'] ?? '' );
        $this->correction_keys    = $this->sanitize_index_string( $raw['correction_keys'] ?? '' );
        $this->keystroke_variance = SignalParser::parse_nullable_float( $raw, 'keystroke_variance' );
    }

    /**
     * Get the total number of keypresses.
     *
     * @since 1.1.0
     *
     * @return int Total number of keypresses.
     */
    public function get_keypress_count(): int {

        return $this->keypress_count;
    }

    /**
     * Sanitize a timing string value.
     *
     * Accepts string "num,num;..." or array [[num, num], ...] from JavaScript.
     *
     * @since 1.1.0
     *
     * @param mixed $value Raw timing string or array value.
     *
     * @return string Sanitized timing string.
     */
    private function sanitize_timing_string( $value ): string {

        if ( is_array( $value ) ) {
            $value = $this->convert_timing_array_to_string( $value );
        }

        if ( ! is_string( $value ) || $value === '' ) {
            return '';
        }

        $sanitized = preg_replace( '/[^0-9,;.\-]/', '', $value );
        $sanitized = trim( $sanitized, ',;' );

        if ( strlen( $sanitized ) > 10000 ) {
            $sanitized      = substr( $sanitized, 0, 10000 );
            $last_semicolon = strrpos( $sanitized, ';' );

            if ( $last_semicolon !== false ) {
                $sanitized = substr( $sanitized, 0, $last_semicolon );
            }
        }

        return $sanitized;
    }

    /**
     * Convert timing array [[num, num], ...] to string "num,num;num,num;...".
     *
     * @since 1.1.0
     *
     * @param array $timing_segments Array of timing segments.
     *
     * @return string Converted timing string.
     */
    private function convert_timing_array_to_string( array $timing_segments ): string {

        if ( empty( $timing_segments ) ) {
            return '';
        }

        $segments = [];

        foreach ( $timing_segments as $segment ) {
            if ( is_array( $segment ) ) {
                $values     = array_map(
                    function ( $v ) {
                        return is_numeric( $v ) ? (string) $v : '';
                    },
                    $segment
                );
                $segments[] = implode( ',', array_filter( $values, 'strlen' ) );
            } elseif ( is_numeric( $segment ) ) {
                $segments[] = (string) $segment;
            }
        }

        return implode( ';', array_filter( $segments, 'strlen' ) );
    }

    /**
     * Sanitize an index string value.
     *
     * Accepts string "num;num;..." or array [num, num, ...] from JavaScript.
     *
     * @since 1.1.0
     *
     * @param mixed $value Raw index string or array value.
     *
     * @return string Sanitized index string.
     */
    private function sanitize_index_string( $value ): string {

        if ( is_array( $value ) ) {
            $value = $this->convert_index_array_to_string( $value );
        }

        if ( ! is_string( $value ) || $value === '' ) {
            return '';
        }

        $sanitized = preg_replace( '/[^0-9;]/', '', $value );
        $sanitized = trim( $sanitized, ';' );

        if ( strlen( $sanitized ) > 2000 ) {
            $sanitized      = substr( $sanitized, 0, 2000 );
            $last_semicolon = strrpos( $sanitized, ';' );

            if ( $last_semicolon !== false ) {
                $sanitized = substr( $sanitized, 0, $last_semicolon );
            }
        }

        return $sanitized;
    }

    /**
     * Convert index array [num, num, ...] to string "num;num;...".
     *
     * @since 1.1.0
     *
     * @param array $index_values Array of index values.
     *
     * @return string Converted index string.
     */
    private function convert_index_array_to_string( array $index_values ): string {

        if ( empty( $index_values ) ) {
            return '';
        }

        $values = array_map(
            function ( $v ) {
                return is_numeric( $v ) ? (string) (int) $v : '';
            },
            $index_values
        );

        return implode( ';', array_filter( $values, 'strlen' ) );
    }

    /**
     * Convert to normalized array for storage/logging.
     *
     * @since 1.1.0
     *
     * @return array Normalized signals array.
     */
    public function to_array(): array {

        return [
            'keypress_count'     => $this->keypress_count,
            'keypress_timings'   => $this->keypress_timings,
            'modifier_keys'      => $this->modifier_keys,
            'correction_keys'    => $this->correction_keys,
            'keystroke_variance' => $this->keystroke_variance,
        ];
    }

    /**
     * Convert to minimal API payload.
     *
     * @since 1.1.0
     *
     * @return array Payload for API submission.
     */
    public function to_api_payload(): array {

        return [
            'keypress_count'     => $this->keypress_count,
            'keypress_timings'   => $this->keypress_timings,
            'modifier_keys'      => $this->modifier_keys,
            'correction_keys'    => $this->correction_keys,
            'keystroke_variance' => $this->keystroke_variance,
        ];
    }
}

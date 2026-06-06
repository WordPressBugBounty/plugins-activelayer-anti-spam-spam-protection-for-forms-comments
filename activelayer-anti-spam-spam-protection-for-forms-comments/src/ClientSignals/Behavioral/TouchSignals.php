<?php
/**
 * Touch signals for touch interaction tracking.
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
 * Handles touch-related behavioral signals.
 *
 * @since 1.1.0
 */
class TouchSignals implements SignalGroupInterface {

    /**
     * Total number of touch events.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $touch_count = 0;

    /**
     * Touch timing data in format "timestamp,x,y;...".
     *
     * @since 1.1.0
     *
     * @var string
     */
    private $touch_timings = '';

    /**
     * Total number of touchmove events.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $touchmove_count = 0;

    /**
     * Whether device has touch capability.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $has_touch = false;

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

        $this->touch_count     = SignalParser::parse_int( $raw, 'touch_count', 0 );
        $this->touch_timings   = $this->sanitize_timing_string( $raw['touch_timings'] ?? '' );
        $this->touchmove_count = SignalParser::parse_int( $raw, 'touchmove_count', 0 );
        $this->has_touch       = SignalParser::parse_bool( $raw, 'has_touch' );
    }

    /**
     * Get the total number of touch events.
     *
     * @since 1.1.0
     *
     * @return int Total number of touch events.
     */
    public function get_touch_count(): int {

        return $this->touch_count;
    }

    /**
     * Get the touch timing data.
     *
     * @since 1.1.0
     *
     * @return string Touch timing data in format "timestamp,x,y;...".
     */
    public function get_touch_timings(): string {

        return $this->touch_timings;
    }

    /**
     * Get the total number of touchmove events.
     *
     * @since 1.1.0
     *
     * @return int Total number of touchmove events.
     */
    public function get_touchmove_count(): int {

        return $this->touchmove_count;
    }

    /**
     * Check if device has touch capability.
     *
     * @since 1.1.0
     *
     * @return bool True if device has touch capability.
     */
    public function has_touch(): bool {

        return $this->has_touch;
    }

    /**
     * Sanitize a timing string value.
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

        $sanitized = trim( preg_replace( '/[^0-9,;.\-]/', '', $value ), ',;' );

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
     * Convert a timing array to string format.
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
     * Convert to normalized array for storage/logging.
     *
     * @since 1.1.0
     *
     * @return array Normalized signals array.
     */
    public function to_array(): array {

        return [
            'touch_count'     => $this->touch_count,
            'touch_timings'   => $this->touch_timings,
            'touchmove_count' => $this->touchmove_count,
            'has_touch'       => $this->has_touch,
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
            'touch_count'     => $this->touch_count,
            'touch_timings'   => $this->touch_timings,
            'touchmove_count' => $this->touchmove_count,
            'has_touch'       => $this->has_touch,
        ];
    }
}

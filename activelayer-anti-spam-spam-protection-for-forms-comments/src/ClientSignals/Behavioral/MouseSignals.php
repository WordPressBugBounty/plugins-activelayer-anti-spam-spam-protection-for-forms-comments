<?php
/**
 * Mouse signals for click and movement tracking.
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
 * Handles mouse-related behavioral signals.
 *
 * @since 1.1.0
 */
class MouseSignals implements SignalGroupInterface {

    /**
     * Total number of mouse clicks.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $click_count = 0;

    /**
     * Click timing data "timestamp,x,y;...".
     *
     * @since 1.1.0
     *
     * @var string
     */
    private $click_timings = '';

    /**
     * Total number of mouse move events.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $move_count = 0;

    /**
     * Mouse move timing data "timestamp,x,y;...".
     *
     * @since 1.1.0
     *
     * @var string
     */
    private $move_timings = '';

    /**
     * Mouse movement efficiency metric.
     *
     * @since 1.1.0
     *
     * @var float|null
     */
    private $mouse_efficiency = null;

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

        $this->click_count      = SignalParser::parse_int( $raw, 'click_count', 0 );
        $this->click_timings    = $this->sanitize_timing_string( $raw['click_timings'] ?? '' );
        $this->move_count       = SignalParser::parse_int( $raw, 'move_count', 0 );
        $this->move_timings     = $this->sanitize_timing_string( $raw['move_timings'] ?? '' );
        $this->mouse_efficiency = SignalParser::parse_nullable_float( $raw, 'mouse_efficiency' );
    }

    /**
     * Get the total number of mouse clicks.
     *
     * @since 1.1.0
     *
     * @return int Total number of mouse clicks.
     */
    public function get_click_count(): int {

        return $this->click_count;
    }

    /**
     * Get the total number of mouse move events.
     *
     * @since 1.1.0
     *
     * @return int Total number of mouse move events.
     */
    public function get_move_count(): int {

        return $this->move_count;
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
     * Convert timing array to string format.
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
            'click_count'      => $this->click_count,
            'click_timings'    => $this->click_timings,
            'move_count'       => $this->move_count,
            'move_timings'     => $this->move_timings,
            'mouse_efficiency' => $this->mouse_efficiency,
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
            'click_count'      => $this->click_count,
            'click_timings'    => $this->click_timings,
            'move_count'       => $this->move_count,
            'move_timings'     => $this->move_timings,
            'mouse_efficiency' => $this->mouse_efficiency,
        ];
    }
}

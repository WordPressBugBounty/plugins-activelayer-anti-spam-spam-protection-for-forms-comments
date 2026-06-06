<?php
/**
 * Device signals for hardware and network information.
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
 * Handles device-related behavioral signals.
 *
 * Processes signals related to device hardware, screen information,
 * network connection, and timezone.
 *
 * @since 1.1.0
 */
class DeviceSignals implements SignalGroupInterface {

    /**
     * Screen information array with width, height, etc.
     *
     * @since 1.1.0
     *
     * @var array|null
     */
    private $screen_info = null;

    /**
     * Timezone offset in minutes.
     *
     * @since 1.1.0
     *
     * @var int|null
     */
    private $timezone = null;

    /**
     * Hardware concurrency (CPU cores).
     *
     * @since 1.1.0
     *
     * @var int|null
     */
    private $hardware_concurrency = null;

    /**
     * Device memory in GB.
     *
     * @since 1.1.0
     *
     * @var float|null
     */
    private $device_memory = null;

    /**
     * Network connection type (4g, 3g, 2g, slow-2g).
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    private $connection_type = null;

    /**
     * Network downlink speed in Mbps.
     *
     * @since 1.1.0
     *
     * @var float|null
     */
    private $connection_downlink = null;

    /**
     * Network round-trip time in ms.
     *
     * @since 1.1.0
     *
     * @var int|null
     */
    private $connection_rtt = null;

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

        $this->screen_info          = $this->sanitize_screen_info( $raw['screen_info'] ?? null );
        $this->timezone             = SignalParser::parse_nullable_int( $raw, 'timezone' );
        $this->hardware_concurrency = SignalParser::parse_nullable_int( $raw, 'hardware_concurrency' );
        $this->device_memory        = SignalParser::parse_nullable_float( $raw, 'device_memory' );
        $this->connection_type      = $this->sanitize_connection_type( $raw['connection_type'] ?? null );
        $this->connection_downlink  = SignalParser::parse_nullable_float( $raw, 'connection_downlink' );
        $this->connection_rtt       = SignalParser::parse_nullable_int( $raw, 'connection_rtt' );
    }

    /**
     * Sanitize screen info value.
     *
     * Extracts and validates numeric screen properties from the raw value.
     * Returns null if width or height are missing.
     *
     * @since 1.1.0
     *
     * @param mixed $value Raw screen info value.
     *
     * @return array|null Sanitized screen info array or null.
     */
    private function sanitize_screen_info( $value ): ?array {

        if ( $value === null || ! is_array( $value ) ) {
            return null;
        }

        $sanitized      = [];
        $numeric_fields = [
            'width',
            'height',
            'availWidth',
            'availHeight',
            'colorDepth',
            'pixelDepth',
            'devicePixelRatio',
        ];

        foreach ( $numeric_fields as $field ) {
            if ( isset( $value[ $field ] ) && is_numeric( $value[ $field ] ) ) {
                $sanitized[ $field ] = (float) $value[ $field ];
            }
        }

        // Only return if we have at least width and height.
        if ( ! isset( $sanitized['width'] ) || ! isset( $sanitized['height'] ) ) {
            return null;
        }

        return $sanitized;
    }

    /**
     * Sanitize connection type value.
     *
     * @since 1.1.0
     *
     * @param mixed $value Raw connection type value.
     *
     * @return string|null Sanitized connection type or null.
     */
    private function sanitize_connection_type( $value ): ?string {

        if ( $value === null || ! is_string( $value ) ) {
            return null;
        }

        $allowed = [ '4g', '3g', '2g', 'slow-2g' ];
        $value   = strtolower( trim( $value ) );

        return in_array( $value, $allowed, true ) ? $value : null;
    }

    /**
     * Get the screen info array.
     *
     * @since 1.1.0
     *
     * @return array|null Screen info array or null if not available.
     */
    public function get_screen_info(): ?array {

        return $this->screen_info;
    }

    /**
     * Get the timezone offset.
     *
     * @since 1.1.0
     *
     * @return int|null Timezone offset in minutes or null.
     */
    public function get_timezone(): ?int {

        return $this->timezone;
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
            'screen_info'          => $this->screen_info,
            'timezone'             => $this->timezone,
            'hardware_concurrency' => $this->hardware_concurrency,
            'device_memory'        => $this->device_memory,
            'connection_type'      => $this->connection_type,
            'connection_downlink'  => $this->connection_downlink,
            'connection_rtt'       => $this->connection_rtt,
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

        return $this->to_array();
    }
}

<?php
/**
 * Media device signals for environment detection.
 *
 * @package ActiveLayer\ClientSignals\Environment
 * @since   1.1.0
 */

namespace ActiveLayer\ClientSignals\Environment;

use ActiveLayer\ClientSignals\Contracts\SignalGroupInterface;
use ActiveLayer\ClientSignals\Parsing\SignalParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles media device and connectivity signals.
 *
 * Validates and normalizes signals related to media device APIs,
 * WebRTC availability, screen properties, and browser connectivity APIs.
 *
 * @since 1.1.0
 */
class MediaDeviceSignals implements SignalGroupInterface {

    /**
     * Whether media devices API is available.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $media_devices_available = false;

    /**
     * Whether WebRTC is available.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $webrtc_available = false;

    /**
     * Whether screen properties are suspicious.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $screen_suspicious = false;

    /**
     * Whether Battery API is missing.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $no_battery_api = false;

    /**
     * Whether Connection API is missing.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $no_connection_api = false;

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

        $this->media_devices_available = SignalParser::parse_bool( $raw, 'media_devices_available' );
        $this->webrtc_available        = SignalParser::parse_bool( $raw, 'webrtc_available' );
        $this->screen_suspicious       = SignalParser::parse_bool( $raw, 'screen_suspicious' );
        $this->no_battery_api          = SignalParser::parse_bool( $raw, 'no_battery_api' );
        $this->no_connection_api       = SignalParser::parse_bool( $raw, 'no_connection_api' );
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
            'media_devices_available' => $this->media_devices_available,
            'webrtc_available'        => $this->webrtc_available,
            'screen_suspicious'       => $this->screen_suspicious,
            'no_battery_api'          => $this->no_battery_api,
            'no_connection_api'       => $this->no_connection_api,
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

    /**
     * Check whether media devices API is available.
     *
     * @since 1.1.0
     *
     * @return bool True if media devices API is available.
     */
    public function has_media_devices_available(): bool {

        return $this->media_devices_available;
    }

    /**
     * Check whether WebRTC is available.
     *
     * @since 1.1.0
     *
     * @return bool True if WebRTC is available.
     */
    public function has_webrtc_available(): bool {

        return $this->webrtc_available;
    }

    /**
     * Check whether screen properties are suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if screen properties are suspicious.
     */
    public function has_screen_suspicious(): bool {

        return $this->screen_suspicious;
    }

    /**
     * Check whether Battery API is missing.
     *
     * @since 1.1.0
     *
     * @return bool True if Battery API is missing.
     */
    public function has_no_battery_api(): bool {

        return $this->no_battery_api;
    }

    /**
     * Check whether Connection API is missing.
     *
     * @since 1.1.0
     *
     * @return bool True if Connection API is missing.
     */
    public function has_no_connection_api(): bool {

        return $this->no_connection_api;
    }
}

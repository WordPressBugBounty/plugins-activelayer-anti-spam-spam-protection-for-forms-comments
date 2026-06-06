<?php
/**
 * WebGL environment signals.
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
 * Handles WebGL-related environment signals for bot detection.
 *
 * @since 1.1.0
 */
class WebGLSignals implements SignalGroupInterface {

    /**
     * Whether WebGL exhibits anomalies.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $webgl_anomaly = false;

    /**
     * WebGL vendor string.
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    private $webgl_vendor = null;

    /**
     * WebGL renderer string.
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    private $webgl_renderer = null;

    /**
     * Hash of WebGL rendering test output.
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    private $webgl_rendering_hash = null;

    /**
     * Noise ratio in WebGL rendering (0-1).
     *
     * @since 1.1.0
     *
     * @var float|null
     */
    private $webgl_rendering_noise = null;

    /**
     * Whether WebGL rendering is inconsistent with claimed GPU.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $webgl_rendering_suspicious = false;

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

        $this->webgl_anomaly              = SignalParser::parse_bool( $raw, 'webgl_anomaly' );
        $this->webgl_vendor               = SignalParser::parse_nullable_string( $raw, 'webgl_vendor' );
        $this->webgl_renderer             = SignalParser::parse_nullable_string( $raw, 'webgl_renderer' );
        $this->webgl_rendering_hash       = SignalParser::parse_nullable_string( $raw, 'webgl_rendering_hash' );
        $this->webgl_rendering_noise      = SignalParser::parse_nullable_float( $raw, 'webgl_rendering_noise' );
        $this->webgl_rendering_suspicious = SignalParser::parse_bool( $raw, 'webgl_rendering_suspicious' );
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
            'webgl_anomaly'              => $this->webgl_anomaly,
            'webgl_vendor'               => $this->webgl_vendor,
            'webgl_renderer'             => $this->webgl_renderer,
            'webgl_rendering_hash'       => $this->webgl_rendering_hash,
            'webgl_rendering_noise'      => $this->webgl_rendering_noise,
            'webgl_rendering_suspicious' => $this->webgl_rendering_suspicious,
        ];
    }

    /**
     * Convert to minimal API payload.
     *
     * Excludes verbose fields (webgl_vendor, webgl_renderer) that are
     * kept only for storage/logging. All scoring is performed server-side.
     *
     * @since 1.1.0
     *
     * @return array Payload for API submission.
     */
    public function to_api_payload(): array {

        return [
            'webgl_anomaly'              => $this->webgl_anomaly,
            'webgl_rendering_hash'       => $this->webgl_rendering_hash,
            'webgl_rendering_noise'      => $this->webgl_rendering_noise,
            'webgl_rendering_suspicious' => $this->webgl_rendering_suspicious,
        ];
    }

    /**
     * Check whether WebGL exhibits anomalies.
     *
     * @since 1.1.0
     *
     * @return bool True if WebGL anomaly detected.
     */
    public function has_webgl_anomaly(): bool {

        return $this->webgl_anomaly;
    }

    /**
     * Get the WebGL vendor string.
     *
     * @since 1.1.0
     *
     * @return string|null WebGL vendor or null if unavailable.
     */
    public function get_webgl_vendor(): ?string {

        return $this->webgl_vendor;
    }

    /**
     * Get the WebGL renderer string.
     *
     * @since 1.1.0
     *
     * @return string|null WebGL renderer or null if unavailable.
     */
    public function get_webgl_renderer(): ?string {

        return $this->webgl_renderer;
    }

    /**
     * Get the WebGL rendering hash.
     *
     * @since 1.1.0
     *
     * @return string|null WebGL rendering hash or null if unavailable.
     */
    public function get_webgl_rendering_hash(): ?string {

        return $this->webgl_rendering_hash;
    }

    /**
     * Get the WebGL rendering noise ratio.
     *
     * @since 1.1.0
     *
     * @return float|null Noise ratio (0-1) or null if unavailable.
     */
    public function get_webgl_rendering_noise(): ?float {

        return $this->webgl_rendering_noise;
    }

    /**
     * Check whether WebGL rendering is suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if WebGL rendering inconsistent with claimed GPU.
     */
    public function has_webgl_rendering_suspicious(): bool {

        return $this->webgl_rendering_suspicious;
    }
}

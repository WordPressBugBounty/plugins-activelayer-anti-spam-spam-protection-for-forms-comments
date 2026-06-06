<?php
/**
 * Canvas fingerprint detection signals.
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
 * Handles canvas fingerprint environment signals.
 *
 * @since 1.1.0
 */
class CanvasSignals implements SignalGroupInterface {

    /**
     * Canvas fingerprint hash.
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    private $canvas_hash = null;

    /**
     * Whether canvas output is suspicious.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $canvas_suspicious = false;

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

        $this->canvas_hash       = SignalParser::parse_nullable_string( $raw, 'canvas_hash' );
        $this->canvas_suspicious = SignalParser::parse_bool( $raw, 'canvas_suspicious' );
    }

    /**
     * Get the canvas fingerprint hash.
     *
     * @since 1.1.0
     *
     * @return string|null Canvas hash or null if unavailable.
     */
    public function get_canvas_hash(): ?string {

        return $this->canvas_hash;
    }

    /**
     * Check whether canvas output is suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if canvas is suspicious.
     */
    public function has_canvas_suspicious(): bool {

        return $this->canvas_suspicious;
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
            'canvas_hash'       => $this->canvas_hash,
            'canvas_suspicious' => $this->canvas_suspicious,
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
            'canvas_hash'       => $this->canvas_hash,
            'canvas_suspicious' => $this->canvas_suspicious,
        ];
    }
}

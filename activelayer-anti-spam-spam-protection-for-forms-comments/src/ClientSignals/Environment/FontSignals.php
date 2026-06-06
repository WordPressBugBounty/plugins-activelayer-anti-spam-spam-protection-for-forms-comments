<?php
/**
 * Font detection signals.
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
 * Handles font-related environment signals.
 *
 * Processes signals related to font detection including the number of
 * detected fonts and whether the count is suspicious for bot detection.
 *
 * @since 1.1.0
 */
class FontSignals implements SignalGroupInterface {

    /**
     * Number of fonts detected.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $fonts_detected_count = 0;

    /**
     * Whether font count is suspicious.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $fonts_suspicious = false;

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

        $this->fonts_detected_count = SignalParser::parse_int( $raw, 'fonts_detected_count', 0 );
        $this->fonts_suspicious     = SignalParser::parse_bool( $raw, 'fonts_suspicious' );
    }

    /**
     * Get the number of fonts detected.
     *
     * @since 1.1.0
     *
     * @return int Number of fonts detected.
     */
    public function get_fonts_detected_count(): int {

        return $this->fonts_detected_count;
    }

    /**
     * Check whether font count is suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if fonts are suspicious.
     */
    public function has_fonts_suspicious(): bool {

        return $this->fonts_suspicious;
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
            'fonts_detected_count' => $this->fonts_detected_count,
            'fonts_suspicious'     => $this->fonts_suspicious,
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
            'fonts_detected_count' => $this->fonts_detected_count,
            'fonts_suspicious'     => $this->fonts_suspicious,
        ];
    }
}

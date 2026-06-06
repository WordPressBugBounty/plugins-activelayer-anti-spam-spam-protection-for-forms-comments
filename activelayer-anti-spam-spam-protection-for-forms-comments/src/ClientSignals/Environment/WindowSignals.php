<?php
/**
 * Window dimension signals for headless browser detection.
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
 * Parses and normalizes window dimension signals.
 *
 * Detects headless browsers by checking for missing outer dimensions
 * or suspicious equality between inner and outer dimensions.
 *
 * @since 1.1.0
 */
class WindowSignals implements SignalGroupInterface {

    /**
     * Whether outer dimensions are missing.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $no_outer_dimensions = false;

    /**
     * Whether inner dimensions equal outer dimensions.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $inner_equals_outer = false;

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

        $this->no_outer_dimensions = SignalParser::parse_bool( $raw, 'no_outer_dimensions' );
        $this->inner_equals_outer  = SignalParser::parse_bool( $raw, 'inner_equals_outer' );
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
            'no_outer_dimensions' => $this->no_outer_dimensions,
            'inner_equals_outer'  => $this->inner_equals_outer,
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
     * Check whether outer dimensions are missing.
     *
     * @since 1.1.0
     *
     * @return bool True if outer dimensions are missing.
     */
    public function has_no_outer_dimensions(): bool {

        return $this->no_outer_dimensions;
    }

    /**
     * Check whether inner dimensions equal outer dimensions.
     *
     * @since 1.1.0
     *
     * @return bool True if inner equals outer dimensions.
     */
    public function has_inner_equals_outer(): bool {

        return $this->inner_equals_outer;
    }
}

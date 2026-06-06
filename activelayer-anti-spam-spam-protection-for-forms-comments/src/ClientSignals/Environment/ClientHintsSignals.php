<?php
/**
 * Client Hints detection signals.
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
 * Handles Client Hints related environment signals.
 *
 * @since 1.1.0
 */
class ClientHintsSignals implements SignalGroupInterface {
    /**
     * Whether Client Hints indicates headless browser.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $client_hints_headless = false;

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

        $this->client_hints_headless = SignalParser::parse_bool( $raw, 'client_hints_headless' );
    }

    /**
     * Check whether Client Hints indicates headless browser.
     *
     * @since 1.1.0
     *
     * @return bool True if Client Hints headless detected.
     */
    public function has_client_hints_headless(): bool {

        return $this->client_hints_headless;
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
            'client_hints_headless' => $this->client_hints_headless,
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
            'client_hints_headless' => $this->client_hints_headless,
        ];
    }
}

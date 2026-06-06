<?php
/**
 * Signal group interface for client-side signals.
 *
 * @package ActiveLayer\ClientSignals\Contracts
 * @since   1.1.0
 */

namespace ActiveLayer\ClientSignals\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for client-side signal groups.
 *
 * Defines the contract for parsing, normalizing, and converting
 * client-side signals (behavioral, environment, honeypot) to
 * various output formats.
 *
 * @since 1.1.0
 */
interface SignalGroupInterface {

    /**
     * Parse raw signals from JavaScript.
     *
     * Validates and normalizes raw signal data received from
     * client-side JavaScript collectors.
     *
     * @since 1.1.0
     *
     * @param array $raw Raw signals array from JavaScript.
     *
     * @return void
     */
    public function parse( array $raw ): void;

    /**
     * Convert to normalized array for storage/logging.
     *
     * Returns all signal data in a consistent format suitable for
     * storage or detailed logging. May include internal metadata
     * like validation status.
     *
     * @since 1.1.0
     *
     * @return array Normalized signals array.
     */
    public function to_array(): array;

    /**
     * Convert to minimal API payload.
     *
     * Returns the signals formatted for API submission,
     * excluding internal metadata. All scoring is performed
     * server-side by the API.
     *
     * @since 1.1.0
     *
     * @return array Payload for API submission.
     */
    public function to_api_payload(): array;
}

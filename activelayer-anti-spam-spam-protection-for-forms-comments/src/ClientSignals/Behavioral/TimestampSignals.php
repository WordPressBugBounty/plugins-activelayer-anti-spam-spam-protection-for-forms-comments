<?php
/**
 * Timestamp signals for form fill timing.
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
 * Handles timestamp-related behavioral signals.
 *
 * @since 1.1.0
 */
class TimestampSignals implements SignalGroupInterface {

    /**
     * Timestamp when user first interacted with an input field.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $input_begin = 0;

    /**
     * Timestamp when form was submitted.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $form_submit = 0;

    /**
     * Parse raw signals from JavaScript.
     *
     * @since 1.1.0
     *
     * @param array $raw Raw signals array from JavaScript.
     */
    public function parse( array $raw ): void {

        $this->input_begin = SignalParser::parse_int( $raw, 'input_begin', 0 );
        $this->form_submit = SignalParser::parse_int( $raw, 'form_submit', 0 );
    }

    /**
     * Get the input begin timestamp.
     *
     * @since 1.1.0
     *
     * @return int Timestamp when user first interacted with input, or 0.
     */
    public function get_input_begin(): int {

        return $this->input_begin;
    }

    /**
     * Get the form submit timestamp.
     *
     * @since 1.1.0
     *
     * @return int Timestamp when form was submitted, or 0.
     */
    public function get_form_submit(): int {

        return $this->form_submit;
    }

    /**
     * Get the time spent filling out the form in milliseconds.
     *
     * @since 1.1.0
     *
     * @return int Time in milliseconds, or 0 if not calculable.
     */
    public function get_fill_time_ms(): int {

        if ( $this->input_begin <= 0 || $this->form_submit <= 0 ) {
            return 0;
        }

        return $this->form_submit >= $this->input_begin
            ? $this->form_submit - $this->input_begin
            : 0;
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
            'input_begin' => $this->input_begin,
            'form_submit' => $this->form_submit,
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
            'input_begin' => $this->input_begin,
            'form_submit' => $this->form_submit,
        ];
    }
}

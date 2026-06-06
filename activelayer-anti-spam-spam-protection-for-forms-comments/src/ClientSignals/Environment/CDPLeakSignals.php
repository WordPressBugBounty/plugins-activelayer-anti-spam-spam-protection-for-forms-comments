<?php
/**
 * CDP leak environment signals.
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
 * CDP leak signals for detecting Chrome DevTools Protocol automation.
 *
 * Detects CDP-based automation tools like Puppeteer and Playwright
 * through stack trace and console debug method leaks.
 *
 * @since 1.1.0
 */
class CDPLeakSignals implements SignalGroupInterface {

    /**
     * Whether CDP stack trace leak detected.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $cdp_stack_trace_leak = false;

    /**
     * Whether CDP console debug leak detected.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $cdp_console_debug_leak = false;

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

        $this->cdp_stack_trace_leak   = SignalParser::parse_bool( $raw, 'cdp_stack_trace_leak' );
        $this->cdp_console_debug_leak = SignalParser::parse_bool( $raw, 'cdp_console_debug_leak' );
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
            'cdp_stack_trace_leak'   => $this->cdp_stack_trace_leak,
            'cdp_console_debug_leak' => $this->cdp_console_debug_leak,
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
            'cdp_stack_trace_leak'   => $this->cdp_stack_trace_leak,
            'cdp_console_debug_leak' => $this->cdp_console_debug_leak,
        ];
    }

    /**
     * Check if CDP stack trace leak was detected.
     *
     * @since 1.1.0
     *
     * @return bool True if CDP stack trace leak detected.
     */
    public function has_cdp_stack_trace_leak(): bool {

        return $this->cdp_stack_trace_leak;
    }

    /**
     * Check if CDP console debug leak was detected.
     *
     * @since 1.1.0
     *
     * @return bool True if CDP console debug leak detected.
     */
    public function has_cdp_console_debug_leak(): bool {

        return $this->cdp_console_debug_leak;
    }
}

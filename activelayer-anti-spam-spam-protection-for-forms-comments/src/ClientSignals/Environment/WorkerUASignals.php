<?php
/**
 * Worker User-Agent mismatch signals.
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
 * Handles Web Worker User-Agent and platform mismatch signals.
 *
 * @since 1.1.0
 */
class WorkerUASignals implements SignalGroupInterface {

    /**
     * Whether Web Workers are available and functional.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $worker_ua_available = false;

    /**
     * Whether User-Agent differs between main thread and Worker.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $worker_ua_mismatch = false;

    /**
     * Whether platform differs between main thread and Worker.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $worker_platform_mismatch = false;

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

        $this->worker_ua_available      = SignalParser::parse_bool( $raw, 'worker_ua_available' );
        $this->worker_ua_mismatch       = SignalParser::parse_bool( $raw, 'worker_ua_mismatch' );
        $this->worker_platform_mismatch = SignalParser::parse_bool( $raw, 'worker_platform_mismatch' );
    }

    /**
     * Check whether Web Workers are available.
     *
     * @since 1.1.0
     *
     * @return bool True if Workers available.
     */
    public function has_worker_ua_available(): bool {

        return $this->worker_ua_available;
    }

    /**
     * Check whether User-Agent differs between main thread and Worker.
     *
     * @since 1.1.0
     *
     * @return bool True if UA mismatch detected.
     */
    public function has_worker_ua_mismatch(): bool {

        return $this->worker_ua_mismatch;
    }

    /**
     * Check whether platform differs between main thread and Worker.
     *
     * @since 1.1.0
     *
     * @return bool True if platform mismatch detected.
     */
    public function has_worker_platform_mismatch(): bool {

        return $this->worker_platform_mismatch;
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
            'worker_ua_available'      => $this->worker_ua_available,
            'worker_ua_mismatch'       => $this->worker_ua_mismatch,
            'worker_platform_mismatch' => $this->worker_platform_mismatch,
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
            'worker_ua_available'      => $this->worker_ua_available,
            'worker_ua_mismatch'       => $this->worker_ua_mismatch,
            'worker_platform_mismatch' => $this->worker_platform_mismatch,
        ];
    }
}

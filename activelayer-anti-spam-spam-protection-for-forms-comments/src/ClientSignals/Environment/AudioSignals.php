<?php
/**
 * Audio detection signals.
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
 * Handles audio-related environment signals.
 *
 * Processes signals related to AudioContext detection including
 * sample rate verification and suspicious audio configuration detection.
 *
 * @since 1.1.0
 */
class AudioSignals implements SignalGroupInterface {

    /**
     * Audio sample rate from AudioContext.
     *
     * @since 1.1.0
     *
     * @var int|null
     */
    private $audio_sample_rate = null;

    /**
     * Whether audio sample rate is suspicious.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $audio_suspicious = false;

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

        $this->audio_sample_rate = SignalParser::parse_nullable_int( $raw, 'audio_sample_rate' );
        $this->audio_suspicious  = SignalParser::parse_bool( $raw, 'audio_suspicious' );
    }

    /**
     * Get the audio sample rate.
     *
     * @since 1.1.0
     *
     * @return int|null Audio sample rate or null if unavailable.
     */
    public function get_audio_sample_rate(): ?int {

        return $this->audio_sample_rate;
    }

    /**
     * Check whether audio is suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if audio configuration is suspicious.
     */
    public function has_audio_suspicious(): bool {

        return $this->audio_suspicious;
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
            'audio_sample_rate' => $this->audio_sample_rate,
            'audio_suspicious'  => $this->audio_suspicious,
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
            'audio_sample_rate' => $this->audio_sample_rate,
            'audio_suspicious'  => $this->audio_suspicious,
        ];
    }
}

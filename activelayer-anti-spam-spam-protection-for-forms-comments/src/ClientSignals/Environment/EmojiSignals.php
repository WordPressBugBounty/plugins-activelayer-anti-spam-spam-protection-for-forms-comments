<?php
/**
 * Emoji rendering signals.
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
 * Handles emoji rendering environment signals.
 *
 * @since 1.1.0
 */
class EmojiSignals implements SignalGroupInterface {

    /**
     * Whether emoji was successfully rendered on canvas.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $emoji_rendered = false;

    /**
     * Whether emoji rendering is suspicious (not rendered).
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $emoji_os_mismatch = false;

    /**
     * Hash of emoji rendering for fingerprinting.
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    private $emoji_hash = null;

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

        $this->emoji_rendered    = SignalParser::parse_bool( $raw, 'emoji_rendered' );
        $this->emoji_os_mismatch = SignalParser::parse_bool( $raw, 'emoji_os_mismatch' );
        $this->emoji_hash        = SignalParser::parse_nullable_string( $raw, 'emoji_hash' );
    }

    /**
     * Check whether emoji was successfully rendered.
     *
     * @since 1.1.0
     *
     * @return bool True if emoji was rendered.
     */
    public function has_emoji_rendered(): bool {

        return $this->emoji_rendered;
    }

    /**
     * Check whether emoji rendering is suspicious (OS mismatch).
     *
     * @since 1.1.0
     *
     * @return bool True if emoji OS mismatch detected.
     */
    public function has_emoji_os_mismatch(): bool {

        return $this->emoji_os_mismatch;
    }

    /**
     * Get the emoji rendering hash.
     *
     * @since 1.1.0
     *
     * @return string|null Emoji hash or null if not available.
     */
    public function get_emoji_hash(): ?string {

        return $this->emoji_hash;
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
            'emoji_rendered'    => $this->emoji_rendered,
            'emoji_os_mismatch' => $this->emoji_os_mismatch,
            'emoji_hash'        => $this->emoji_hash,
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
            'emoji_rendered'    => $this->emoji_rendered,
            'emoji_os_mismatch' => $this->emoji_os_mismatch,
            'emoji_hash'        => $this->emoji_hash,
        ];
    }
}

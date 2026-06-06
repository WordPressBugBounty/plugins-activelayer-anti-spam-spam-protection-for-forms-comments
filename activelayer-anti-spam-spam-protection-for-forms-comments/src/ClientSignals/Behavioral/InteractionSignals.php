<?php
/**
 * Interaction signals for user engagement tracking.
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
 * Handles interaction-related behavioral signals.
 *
 * @since 1.1.0
 */
class InteractionSignals implements SignalGroupInterface {

    /**
     * Total number of scroll events.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $scroll_count = 0;

    /**
     * Total number of input focus events.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $focus_count = 0;

    /**
     * Total number of page-level click events.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $page_click_count = 0;

    /**
     * Total number of text selection events.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $text_selection_count = 0;

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

        $this->scroll_count         = SignalParser::parse_int( $raw, 'scroll_count', 0 );
        $this->focus_count          = SignalParser::parse_int( $raw, 'focus_count', 0 );
        $this->page_click_count     = SignalParser::parse_int( $raw, 'page_click_count', 0 );
        $this->text_selection_count = SignalParser::parse_int( $raw, 'text_selection_count', 0 );
    }

    /**
     * Get the total number of scroll events.
     *
     * @since 1.1.0
     *
     * @return int Total number of scroll events.
     */
    public function get_scroll_count(): int {

        return $this->scroll_count;
    }

    /**
     * Get the total number of input focus events.
     *
     * @since 1.1.0
     *
     * @return int Total number of input focus events.
     */
    public function get_focus_count(): int {

        return $this->focus_count;
    }

    /**
     * Get the total number of page-level click events.
     *
     * @since 1.1.0
     *
     * @return int Total number of page-level click events.
     */
    public function get_page_click_count(): int {

        return $this->page_click_count;
    }

    /**
     * Get the total number of text selection events.
     *
     * @since 1.1.0
     *
     * @return int Total number of text selection events.
     */
    public function get_text_selection_count(): int {

        return $this->text_selection_count;
    }

    /**
     * Check if any interaction was recorded.
     *
     * @since 1.1.0
     *
     * @return bool True if any interaction event was recorded.
     */
    public function has_interaction(): bool {

        return $this->scroll_count > 0
            || $this->focus_count > 0
            || $this->page_click_count > 0
            || $this->text_selection_count > 0;
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
            'scroll_count'         => $this->scroll_count,
            'focus_count'          => $this->focus_count,
            'page_click_count'     => $this->page_click_count,
            'text_selection_count' => $this->text_selection_count,
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
            'scroll_count'         => $this->scroll_count,
            'focus_count'          => $this->focus_count,
            'page_click_count'     => $this->page_click_count,
            'text_selection_count' => $this->text_selection_count,
        ];
    }
}

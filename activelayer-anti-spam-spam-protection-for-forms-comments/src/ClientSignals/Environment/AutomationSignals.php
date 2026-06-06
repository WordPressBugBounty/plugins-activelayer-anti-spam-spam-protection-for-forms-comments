<?php
/**
 * Automation detection signals.
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
 * Handles automation-related environment signals.
 *
 * Processes signals related to browser automation detection including
 * WebDriver presence, automation framework detection, and headless UA.
 *
 * @since 1.1.0
 */
class AutomationSignals implements SignalGroupInterface {

    /**
     * Known automation frameworks that can be detected.
     *
     * @since 1.1.0
     *
     * @var array<string>
     */
    private const KNOWN_FRAMEWORKS = [
        'selenium',
		'puppeteer',
		'playwright',
		'phantom',
        'cypress',
		'nightmare',
		'chromedriver',
    ];

    /**
     * Whether WebDriver API is present.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $webdriver = false;

    /**
     * Detected automation framework name.
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    private $automation_framework = null;

    /**
     * Whether user agent indicates headless browser.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $headless_ua = false;

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

        $this->webdriver            = SignalParser::parse_bool( $raw, 'webdriver' );
        $this->headless_ua          = SignalParser::parse_bool( $raw, 'headless_ua' );
        $this->automation_framework = $this->parse_framework( $raw );
    }

    /**
     * Parse automation framework value.
     *
     * @since 1.1.0
     *
     * @param array $signals Raw signals array.
     *
     * @return string|null Framework name or null.
     */
    private function parse_framework( array $signals ): ?string {

        if ( ! isset( $signals['automation_framework'] ) ) {
            return null;
        }

        $value = $signals['automation_framework'];

        if ( $value === null || ! is_string( $value ) ) {
            return null;
        }

        $sanitized = sanitize_text_field( $value );

        if ( in_array( $sanitized, self::KNOWN_FRAMEWORKS, true ) ) {
            return $sanitized;
        }

        return null;
    }

    /**
     * Check whether WebDriver API is present.
     *
     * @since 1.1.0
     *
     * @return bool True if WebDriver detected.
     */
    public function has_webdriver(): bool {

        return $this->webdriver;
    }

    /**
     * Get the detected automation framework.
     *
     * @since 1.1.0
     *
     * @return string|null Framework name or null if not detected.
     */
    public function get_automation_framework(): ?string {

        return $this->automation_framework;
    }

    /**
     * Check whether user agent indicates headless browser.
     *
     * @since 1.1.0
     *
     * @return bool True if headless UA detected.
     */
    public function has_headless_ua(): bool {

        return $this->headless_ua;
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
            'webdriver'            => $this->webdriver,
            'automation_framework' => $this->automation_framework,
            'headless_ua'          => $this->headless_ua,
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
            'webdriver'            => $this->webdriver,
            'automation_framework' => $this->automation_framework,
            'headless_ua'          => $this->headless_ua,
        ];
    }
}

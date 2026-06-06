<?php
/**
 * Browser feature signals for environment detection.
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
 * Handles browser feature detection signals.
 *
 * Validates and normalizes signals related to browser feature availability
 * that may indicate headless or automated environments.
 *
 * @since 1.1.0
 */
class BrowserFeatureSignals implements SignalGroupInterface {

    /**
     * Whether browser reports no plugins.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $no_plugins = false;

    /**
     * Whether browser reports no languages.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $no_languages = false;

    /**
     * Whether Chrome runtime is missing when expected.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $chrome_runtime_missing = false;

    /**
     * Whether permissions API shows inconsistencies.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $permissions_inconsistent = false;

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

        $this->no_plugins               = SignalParser::parse_bool( $raw, 'no_plugins' );
        $this->no_languages             = SignalParser::parse_bool( $raw, 'no_languages' );
        $this->chrome_runtime_missing   = SignalParser::parse_bool( $raw, 'chrome_runtime_missing' );
        $this->permissions_inconsistent = SignalParser::parse_bool( $raw, 'permissions_inconsistent' );
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
            'no_plugins'               => $this->no_plugins,
            'no_languages'             => $this->no_languages,
            'chrome_runtime_missing'   => $this->chrome_runtime_missing,
            'permissions_inconsistent' => $this->permissions_inconsistent,
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
     * Check whether browser reports no plugins.
     *
     * @since 1.1.0
     *
     * @return bool True if browser reports no plugins.
     */
    public function has_no_plugins(): bool {

        return $this->no_plugins;
    }

    /**
     * Check whether browser reports no languages.
     *
     * @since 1.1.0
     *
     * @return bool True if browser reports no languages.
     */
    public function has_no_languages(): bool {

        return $this->no_languages;
    }

    /**
     * Check whether Chrome runtime is missing when expected.
     *
     * @since 1.1.0
     *
     * @return bool True if Chrome runtime is missing.
     */
    public function has_chrome_runtime_missing(): bool {

        return $this->chrome_runtime_missing;
    }

    /**
     * Check whether permissions API shows inconsistencies.
     *
     * @since 1.1.0
     *
     * @return bool True if permissions API is inconsistent.
     */
    public function has_permissions_inconsistent(): bool {

        return $this->permissions_inconsistent;
    }
}

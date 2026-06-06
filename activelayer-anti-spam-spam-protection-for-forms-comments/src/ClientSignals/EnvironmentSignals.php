<?php

namespace ActiveLayer\ClientSignals;

use ActiveLayer\ClientSignals\Environment\AudioSignals;
use ActiveLayer\ClientSignals\Environment\AutomationSignals;
use ActiveLayer\ClientSignals\Environment\BrowserFeatureSignals;
use ActiveLayer\ClientSignals\Environment\CanvasSignals;
use ActiveLayer\ClientSignals\Environment\CDPLeakSignals;
use ActiveLayer\ClientSignals\Environment\ClientHintsSignals;
use ActiveLayer\ClientSignals\Environment\EmojiSignals;
use ActiveLayer\ClientSignals\Environment\FontSignals;
use ActiveLayer\ClientSignals\Environment\MediaDeviceSignals;
use ActiveLayer\ClientSignals\Environment\WebGLSignals;
use ActiveLayer\ClientSignals\Environment\WindowSignals;
use ActiveLayer\ClientSignals\Environment\WorkerUASignals;
use ActiveLayer\ClientSignals\Parsing\SignalParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates and normalizes client-side environment signals.
 *
 * Receives environment detection data from JavaScript (EnvironmentDetector)
 * and provides sanitized, validated access to bot detection signals.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\ClientSignals
 */
class EnvironmentSignals {

    /**
     * Automation detection signals.
     *
     * @since 1.1.0
     *
     * @var AutomationSignals
     */
    private $automation;

    /**
     * Browser feature signals.
     *
     * @since 1.1.0
     *
     * @var BrowserFeatureSignals
     */
    private $browser;

    /**
     * WebGL signals.
     *
     * @since 1.1.0
     *
     * @var WebGLSignals
     */
    private $webgl;

    /**
     * CDP leak detection signals.
     *
     * @since 1.1.0
     *
     * @var CDPLeakSignals
     */
    private $cdp;

    /**
     * Window dimension signals.
     *
     * @since 1.1.0
     *
     * @var WindowSignals
     */
    private $window;

    /**
     * Client Hints signals.
     *
     * @since 1.1.0
     *
     * @var ClientHintsSignals
     */
    private $client_hints;

    /**
     * Canvas fingerprint signals.
     *
     * @since 1.1.0
     *
     * @var CanvasSignals
     */
    private $canvas;

    /**
     * Audio signals.
     *
     * @since 1.1.0
     *
     * @var AudioSignals
     */
    private $audio;

    /**
     * Font detection signals.
     *
     * @since 1.1.0
     *
     * @var FontSignals
     */
    private $fonts;

    /**
     * Media device signals.
     *
     * @since 1.1.0
     *
     * @var MediaDeviceSignals
     */
    private $media;

    /**
     * Worker UA signals.
     *
     * @since 1.1.0
     *
     * @var WorkerUASignals
     */
    private $worker;

    /**
     * Emoji rendering signals.
     *
     * @since 1.1.0
     *
     * @var EmojiSignals
     */
    private $emoji;

    /**
     * JavaScript timestamp when checks were performed.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private $check_timestamp = 0;

    /**
     * Whether the input signals were valid.
     *
     * @since 1.1.0
     *
     * @var bool
     */
    private $is_valid = false;

    /**
     * Constructor.
     *
     * Validates and stores environment signals from client-side JavaScript.
     *
     * @since 1.1.0
     *
     * @param array $raw_signals Raw signals array from JavaScript EnvironmentDetector.
     */
    public function __construct( array $raw_signals ) {

        // Create signal group instances.
        $this->automation   = new AutomationSignals();
        $this->browser      = new BrowserFeatureSignals();
        $this->webgl        = new WebGLSignals();
        $this->cdp          = new CDPLeakSignals();
        $this->window       = new WindowSignals();
        $this->client_hints = new ClientHintsSignals();
        $this->canvas       = new CanvasSignals();
        $this->audio        = new AudioSignals();
        $this->fonts        = new FontSignals();
        $this->media        = new MediaDeviceSignals();
        $this->worker       = new WorkerUASignals();
        $this->emoji        = new EmojiSignals();

        $this->parse_and_validate( $raw_signals );
    }

    /**
     * Parse and validate raw signals.
     *
     * All scoring is performed server-side by the API. The client only
     * sends raw detection signals.
     *
     * @since 1.1.0
     *
     * @param array $raw_signals Raw signals from JavaScript.
     *
     * @return void
     */
    private function parse_and_validate( array $raw_signals ): void {

        // Validate required key exists.
        if ( ! array_key_exists( 'check_timestamp', $raw_signals ) ) {
            $this->is_valid = false;

            return;
        }

        // Delegate parsing to signal groups.
        $this->automation->parse( $raw_signals );
        $this->browser->parse( $raw_signals );
        $this->webgl->parse( $raw_signals );
        $this->cdp->parse( $raw_signals );
        $this->window->parse( $raw_signals );
        $this->client_hints->parse( $raw_signals );
        $this->canvas->parse( $raw_signals );
        $this->audio->parse( $raw_signals );
        $this->fonts->parse( $raw_signals );
        $this->media->parse( $raw_signals );
        $this->worker->parse( $raw_signals );
        $this->emoji->parse( $raw_signals );

        // Parse timestamp (cross-cutting concern).
        $this->check_timestamp = SignalParser::parse_int( $raw_signals, 'check_timestamp', 0 );

        // Validate timestamp is reasonable (not in the future, not too old).
        $now_ms        = (int) ( microtime( true ) * 1000 );
        $one_hour_ms   = 3600 * 1000;
        $is_valid_time = $this->check_timestamp > 0
            && $this->check_timestamp <= $now_ms
            && $this->check_timestamp > ( $now_ms - $one_hour_ms );

        $this->is_valid = $is_valid_time;
    }

    /**
     * Check whether the signals are valid.
     *
     * @since 1.1.0
     *
     * @return bool True if signals passed validation.
     */
    public function is_valid(): bool {

        return $this->is_valid;
    }

    /**
     * Get the detected automation framework.
     *
     * @since 1.1.0
     *
     * @return string|null Framework name or null if not detected.
     */
    public function get_automation_framework(): ?string {

        return $this->automation->get_automation_framework();
    }

    /**
     * Check whether high confidence bot signals are present.
     *
     * Returns true if any strong bot indicators are detected:
     * - WebDriver API present
     * - Headless browser user agent
     * - Known automation framework detected
     * - CDP leak detected
     * - Client Hints headless detected
     *
     * @since 1.1.0
     *
     * @return bool True if high confidence bot signals detected.
     */
    public function has_high_confidence_signals(): bool {

        return $this->automation->has_webdriver()
            || $this->automation->has_headless_ua()
            || $this->automation->get_automation_framework() !== null
            || $this->cdp->has_cdp_stack_trace_leak()
            || $this->cdp->has_cdp_console_debug_leak()
            || $this->client_hints->has_client_hints_headless();
    }

    /**
     * Check whether WebDriver API is present.
     *
     * @since 1.1.0
     *
     * @return bool True if WebDriver detected.
     */
    public function has_webdriver(): bool {

        return $this->automation->has_webdriver();
    }

    /**
     * Check whether user agent indicates headless browser.
     *
     * @since 1.1.0
     *
     * @return bool True if headless UA detected.
     */
    public function has_headless_ua(): bool {

        return $this->automation->has_headless_ua();
    }

    /**
     * Check whether CDP stack trace leak detected.
     *
     * @since 1.1.0
     *
     * @return bool True if CDP stack trace leak detected.
     */
    public function has_cdp_stack_trace_leak(): bool {

        return $this->cdp->has_cdp_stack_trace_leak();
    }

    /**
     * Check whether CDP console debug leak detected.
     *
     * @since 1.1.0
     *
     * @return bool True if CDP console debug leak detected.
     */
    public function has_cdp_console_debug_leak(): bool {

        return $this->cdp->has_cdp_console_debug_leak();
    }

    /**
     * Check whether outer dimensions are missing.
     *
     * @since 1.1.0
     *
     * @return bool True if outer dimensions are missing.
     */
    public function has_no_outer_dimensions(): bool {

        return $this->window->has_no_outer_dimensions();
    }

    /**
     * Check whether inner equals outer dimensions.
     *
     * @since 1.1.0
     *
     * @return bool True if inner equals outer dimensions.
     */
    public function has_inner_equals_outer(): bool {

        return $this->window->has_inner_equals_outer();
    }

    /**
     * Check whether Client Hints indicates headless browser.
     *
     * @since 1.1.0
     *
     * @return bool True if Client Hints headless detected.
     */
    public function has_client_hints_headless(): bool {

        return $this->client_hints->has_client_hints_headless();
    }

    /**
     * Get the canvas fingerprint hash.
     *
     * @since 1.1.0
     *
     * @return string|null Canvas hash or null if unavailable.
     */
    public function get_canvas_hash(): ?string {

        return $this->canvas->get_canvas_hash();
    }

    /**
     * Check whether canvas output is suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if canvas is suspicious.
     */
    public function has_canvas_suspicious(): bool {

        return $this->canvas->has_canvas_suspicious();
    }

    /**
     * Get the audio sample rate.
     *
     * @since 1.1.0
     *
     * @return int|null Audio sample rate or null if unavailable.
     */
    public function get_audio_sample_rate(): ?int {

        return $this->audio->get_audio_sample_rate();
    }

    /**
     * Check whether audio is suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if audio is suspicious.
     */
    public function has_audio_suspicious(): bool {

        return $this->audio->has_audio_suspicious();
    }

    /**
     * Get the number of fonts detected.
     *
     * @since 1.1.0
     *
     * @return int Number of fonts detected.
     */
    public function get_fonts_detected_count(): int {

        return $this->fonts->get_fonts_detected_count();
    }

    /**
     * Check whether fonts are suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if fonts are suspicious.
     */
    public function has_fonts_suspicious(): bool {

        return $this->fonts->has_fonts_suspicious();
    }

    /**
     * Check whether media devices API is available.
     *
     * @since 1.1.0
     *
     * @return bool True if media devices available.
     */
    public function has_media_devices_available(): bool {

        return $this->media->has_media_devices_available();
    }

    /**
     * Check whether WebRTC is available.
     *
     * @since 1.1.0
     *
     * @return bool True if WebRTC available.
     */
    public function has_webrtc_available(): bool {

        return $this->media->has_webrtc_available();
    }

    /**
     * Check whether screen properties are suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if screen is suspicious.
     */
    public function has_screen_suspicious(): bool {

        return $this->media->has_screen_suspicious();
    }

    /**
     * Check whether Battery API is missing.
     *
     * @since 1.1.0
     *
     * @return bool True if Battery API missing.
     */
    public function has_no_battery_api(): bool {

        return $this->media->has_no_battery_api();
    }

    /**
     * Check whether Connection API is missing.
     *
     * @since 1.1.0
     *
     * @return bool True if Connection API missing.
     */
    public function has_no_connection_api(): bool {

        return $this->media->has_no_connection_api();
    }

    /**
     * Check whether Web Workers are available.
     *
     * @since 1.1.0
     *
     * @return bool True if Workers available.
     */
    public function has_worker_ua_available(): bool {

        return $this->worker->has_worker_ua_available();
    }

    /**
     * Check whether User-Agent differs between main thread and Worker.
     *
     * @since 1.1.0
     *
     * @return bool True if UA mismatch detected.
     */
    public function has_worker_ua_mismatch(): bool {

        return $this->worker->has_worker_ua_mismatch();
    }

    /**
     * Check whether platform differs between main thread and Worker.
     *
     * @since 1.1.0
     *
     * @return bool True if platform mismatch detected.
     */
    public function has_worker_platform_mismatch(): bool {

        return $this->worker->has_worker_platform_mismatch();
    }

    /**
     * Check whether emoji was successfully rendered.
     *
     * @since 1.1.0
     *
     * @return bool True if emoji was rendered.
     */
    public function has_emoji_rendered(): bool {

        return $this->emoji->has_emoji_rendered();
    }

    /**
     * Check whether emoji rendering is suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if emoji OS mismatch detected.
     */
    public function has_emoji_os_mismatch(): bool {

        return $this->emoji->has_emoji_os_mismatch();
    }

    /**
     * Get the emoji rendering hash.
     *
     * @since 1.1.0
     *
     * @return string|null Emoji hash or null if unavailable.
     */
    public function get_emoji_hash(): ?string {

        return $this->emoji->get_emoji_hash();
    }

    /**
     * Get the WebGL rendering hash.
     *
     * @since 1.1.0
     *
     * @return string|null WebGL rendering hash or null if unavailable.
     */
    public function get_webgl_rendering_hash(): ?string {

        return $this->webgl->get_webgl_rendering_hash();
    }

    /**
     * Get the WebGL rendering noise ratio.
     *
     * @since 1.1.0
     *
     * @return float|null Noise ratio (0-1) or null if unavailable.
     */
    public function get_webgl_rendering_noise(): ?float {

        return $this->webgl->get_webgl_rendering_noise();
    }

    /**
     * Check whether WebGL rendering is suspicious.
     *
     * @since 1.1.0
     *
     * @return bool True if WebGL rendering inconsistent with claimed GPU.
     */
    public function has_webgl_rendering_suspicious(): bool {

        return $this->webgl->has_webgl_rendering_suspicious();
    }

    /**
     * Convert signals to normalized array.
     *
     * Returns all signal data in a consistent format suitable for
     * storage or detailed logging.
     *
     * @since 1.1.0
     *
     * @return array Normalized signals array.
     */
    public function to_array(): array {

        return array_merge(
            $this->automation->to_array(),
            $this->browser->to_array(),
            $this->webgl->to_array(),
            $this->cdp->to_array(),
            $this->window->to_array(),
            $this->client_hints->to_array(),
            $this->canvas->to_array(),
            $this->audio->to_array(),
            $this->fonts->to_array(),
            $this->media->to_array(),
            $this->worker->to_array(),
            $this->emoji->to_array(),
            [
                'check_timestamp' => $this->check_timestamp,
                'is_valid'        => $this->is_valid,
            ]
        );
    }

    /**
     * Convert signals to minimal API payload.
     *
     * Returns only the raw detection signals needed for API submission.
     * All scoring is performed server-side by the API.
     *
     * @since 1.1.0
     *
     * @return array Minimal payload for API.
     */
    public function to_api_payload(): array {

        return array_merge(
            $this->automation->to_api_payload(),
            $this->browser->to_api_payload(),
            $this->webgl->to_api_payload(),
            $this->cdp->to_api_payload(),
            $this->window->to_api_payload(),
            $this->client_hints->to_api_payload(),
            $this->canvas->to_api_payload(),
            $this->audio->to_api_payload(),
            $this->fonts->to_api_payload(),
            $this->media->to_api_payload(),
            $this->worker->to_api_payload(),
            $this->emoji->to_api_payload()
        );
    }

    /**
     * Create instance from empty/missing signals.
     *
     * Factory method for cases where no client signals were provided.
     * Returns an invalid EnvironmentSignals instance.
     *
     * @since 1.1.0
     *
     * @return self Invalid signals instance.
     */
    public static function create_empty(): self {

        return new self( [] );
    }
}

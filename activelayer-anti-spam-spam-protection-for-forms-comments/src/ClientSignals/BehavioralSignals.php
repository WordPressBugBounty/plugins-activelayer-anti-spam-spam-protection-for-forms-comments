<?php

namespace ActiveLayer\ClientSignals;

use ActiveLayer\ClientSignals\Behavioral\DeviceSignals;
use ActiveLayer\ClientSignals\Behavioral\InteractionSignals;
use ActiveLayer\ClientSignals\Behavioral\KeyboardSignals;
use ActiveLayer\ClientSignals\Behavioral\MouseSignals;
use ActiveLayer\ClientSignals\Behavioral\TimestampSignals;
use ActiveLayer\ClientSignals\Behavioral\TouchSignals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and normalizes client-side behavioral signals.
 *
 * Receives behavioral tracking data from JavaScript (BehavioralAnalyzer)
 * and provides sanitized, validated access to user interaction signals.
 *
 * Uses composition of signal group classes to organize signal processing.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\ClientSignals
 */
class BehavioralSignals {

	/**
	 * Form field name for behavioral signals.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const FIELD_NAME = 'activelayer_behavioral_signals';

	/**
	 * Maximum age of signals in milliseconds (1 hour).
	 *
	 * @since 1.1.0
	 *
	 * @var int
	 */
	private const MAX_AGE_MS = 3600 * 1000;

	/**
	 * Timestamp signals group.
	 *
	 * @since 1.1.0
	 *
	 * @var TimestampSignals
	 */
	private $timestamps;

	/**
	 * Keyboard signals group.
	 *
	 * @since 1.1.0
	 *
	 * @var KeyboardSignals
	 */
	private $keyboard;

	/**
	 * Mouse signals group.
	 *
	 * @since 1.1.0
	 *
	 * @var MouseSignals
	 */
	private $mouse;

	/**
	 * Touch signals group.
	 *
	 * @since 1.1.0
	 *
	 * @var TouchSignals
	 */
	private $touch;

	/**
	 * Interaction signals group.
	 *
	 * @since 1.1.0
	 *
	 * @var InteractionSignals
	 */
	private $interaction;

	/**
	 * Device signals group.
	 *
	 * @since 1.1.0
	 *
	 * @var DeviceSignals
	 */
	private $device;

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
	 * Validates and stores behavioral signals from client-side JavaScript.
	 *
	 * @since 1.1.0
	 *
	 * @param array $raw_signals Raw signals array from JavaScript BehavioralAnalyzer.
	 */
	public function __construct( array $raw_signals ) {

		$this->timestamps  = new TimestampSignals();
		$this->keyboard    = new KeyboardSignals();
		$this->mouse       = new MouseSignals();
		$this->touch       = new TouchSignals();
		$this->interaction = new InteractionSignals();
		$this->device      = new DeviceSignals();

		$this->parse_and_validate( $raw_signals );
	}

	/**
	 * Parse and validate raw signals.
	 *
	 * @since 1.1.0
	 *
	 * @param array $raw_signals Raw signals from JavaScript.
	 *
	 * @return void
	 */
	private function parse_and_validate( array $raw_signals ): void {

		// Empty signals means invalid.
		if ( empty( $raw_signals ) ) {
			$this->is_valid = false;

			return;
		}

		// Delegate parsing to signal groups.
		$this->timestamps->parse( $raw_signals );
		$this->keyboard->parse( $raw_signals );
		$this->mouse->parse( $raw_signals );
		$this->touch->parse( $raw_signals );
		$this->interaction->parse( $raw_signals );
		$this->device->parse( $raw_signals );

		// Validate timestamps.
		$this->is_valid = $this->validate_timestamps();
	}

	/**
	 * Validate timestamp values.
	 *
	 * Checks that timestamps are not in the future and not older than 1 hour.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if timestamps are valid.
	 */
	private function validate_timestamps(): bool {

		$now_ms      = (int) ( microtime( true ) * 1000 );
		$form_submit = $this->timestamps->get_form_submit();
		$input_begin = $this->timestamps->get_input_begin();

		// At least form_submit should be set for valid signals.
		if ( $form_submit <= 0 ) {
			return false;
		}

		// form_submit should not be in the future (allow 5 second tolerance for clock drift).
		$future_tolerance = 5000;

		if ( $form_submit > ( $now_ms + $future_tolerance ) ) {
			return false;
		}

		// form_submit should not be older than 1 hour.
		if ( $form_submit < ( $now_ms - self::MAX_AGE_MS ) ) {
			return false;
		}

		// If input_begin is set, it should be before or equal to form_submit.
		if ( $input_begin > 0 && $input_begin > $form_submit ) {
			return false;
		}

		return true;
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
			$this->timestamps->to_array(),
			$this->keyboard->to_array(),
			$this->mouse->to_array(),
			$this->touch->to_array(),
			$this->interaction->to_array(),
			$this->device->to_array(),
			[
				'is_valid' => $this->is_valid,
			]
		);
	}

	/**
	 * Convert signals to API payload.
	 *
	 * Returns the signals formatted for API submission,
	 * excluding internal metadata like is_valid.
	 *
	 * @since 1.1.0
	 *
	 * @return array Payload for API.
	 */
	public function to_api_payload(): array {

		return array_merge(
			$this->timestamps->to_api_payload(),
			$this->keyboard->to_api_payload(),
			$this->mouse->to_api_payload(),
			$this->touch->to_api_payload(),
			$this->interaction->to_api_payload(),
			$this->device->to_api_payload()
		);
	}

	/**
	 * Create instance from empty/missing signals.
	 *
	 * Factory method for cases where no client signals were provided.
	 * Returns an invalid BehavioralSignals instance.
	 *
	 * @since 1.1.0
	 *
	 * @return self Invalid signals instance.
	 */
	public static function create_empty(): self {

		return new self( [] );
	}

	/**
	 * Create instance from POST data.
	 *
	 * Factory method that extracts and decodes behavioral signals from
	 * form POST data using the standard field name.
	 *
	 * @since 1.1.0
	 *
	 * @param array $post_data Raw POST data array.
	 *
	 * @return self BehavioralSignals instance (may be invalid if signals missing/malformed).
	 */
	public static function from_post_data( array $post_data ): self {

		if ( ! isset( $post_data[ self::FIELD_NAME ] ) ) {
			return self::create_empty();
		}

		$raw_value = $post_data[ self::FIELD_NAME ];

		if ( ! is_string( $raw_value ) || $raw_value === '' ) {
			return self::create_empty();
		}

		// Decode JSON payload.
		$decoded = json_decode( $raw_value, true );

		if ( ! is_array( $decoded ) ) {
			return self::create_empty();
		}

		return new self( $decoded );
	}

	/**
	 * Get the time spent filling out the form in milliseconds.
	 *
	 * @since 1.1.0
	 *
	 * @return int Time in milliseconds, or 0 if not calculable.
	 */
	public function get_fill_time_ms(): int {

		return $this->timestamps->get_fill_time_ms();
	}

	/**
	 * Check whether user showed any interaction activity.
	 *
	 * Returns true if any meaningful interaction was detected.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if user showed interaction activity.
	 */
	public function has_interaction(): bool {

		return $this->keyboard->get_keypress_count() > 0
			|| $this->mouse->get_click_count() > 0
			|| $this->mouse->get_move_count() > 0
			|| $this->touch->get_touch_count() > 0
			|| $this->interaction->get_scroll_count() > 0
			|| $this->interaction->get_focus_count() > 0;
	}
}

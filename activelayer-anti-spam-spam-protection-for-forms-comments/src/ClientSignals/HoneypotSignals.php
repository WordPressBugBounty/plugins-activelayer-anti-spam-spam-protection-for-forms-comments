<?php
/**
 * Honeypot signals validation and processing.
 *
 * @package ActiveLayer\ClientSignals
 * @since   1.1.0
 */

namespace ActiveLayer\ClientSignals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\ClientSignals\Fields\HoneypotField;

/**
 * HoneypotSignals class.
 *
 * Handles validation and processing of honeypot field data
 * collected from form submissions.
 *
 * @since 1.1.0
 */
class HoneypotSignals {

	/**
	 * Whether honeypot field was filled.
	 *
	 * @since 1.1.0
	 *
	 * @var bool
	 */
	private $filled = false;

	/**
	 * Value in honeypot field (should be empty).
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	private $value = '';

	/**
	 * Whether honeypot was pre-filled before any JS ran.
	 *
	 * @since 1.1.0
	 *
	 * @var bool
	 */
	private $prefilled = false;

	/**
	 * Whether honeypot field received focus.
	 *
	 * @since 1.1.0
	 *
	 * @var bool
	 */
	private $focused = false;

	/**
	 * Timestamp when honeypot received focus.
	 *
	 * @since 1.1.0
	 *
	 * @var int|null
	 */
	private $focus_time = null;

	/**
	 * Timestamp when honeypot received input.
	 *
	 * @since 1.1.0
	 *
	 * @var int|null
	 */
	private $input_time = null;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data Honeypot signal data.
	 */
	public function __construct( array $data = [] ) {

		$this->filled     = ! empty( $data['honeypot_filled'] );
		$this->value      = isset( $data['honeypot_value'] ) ? (string) $data['honeypot_value'] : '';
		$this->prefilled  = ! empty( $data['honeypot_prefilled'] );
		$this->focused    = ! empty( $data['honeypot_focused'] );
		$this->focus_time = isset( $data['honeypot_focus_time'] ) ? (int) $data['honeypot_focus_time'] : null;
		$this->input_time = isset( $data['honeypot_input_time'] ) ? (int) $data['honeypot_input_time'] : null;
	}

	/**
	 * Create from POST field value.
	 *
	 * Extracts honeypot field value directly from POST data.
	 *
	 * @since 1.1.0
	 *
	 * @param array $post_data POST data array.
	 *
	 * @return self
	 */
	public static function from_post_data( array $post_data ): self {

		$field_name = HoneypotField::get_field_name();

		$filled = false;
		$value  = '';

		if ( isset( $post_data[ $field_name ] ) ) {
			$value  = sanitize_text_field( $post_data[ $field_name ] );
			$filled = $value !== '';
		}

		return new self(
			[
				'honeypot_filled' => $filled,
				'honeypot_value'  => $value,
			]
		);
	}

	/**
	 * Create from behavioral signals JSON.
	 *
	 * Extracts honeypot data from the behavioral signals payload.
	 *
	 * @since 1.1.0
	 *
	 * @param array $behavioral_data Decoded behavioral signals.
	 *
	 * @return self
	 */
	public static function from_behavioral_data( array $behavioral_data ): self {

		return new self( $behavioral_data );
	}

	/**
	 * Check if honeypot was triggered (filled or interacted with).
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if honeypot indicates bot behavior.
	 */
	public function is_triggered(): bool {

		return $this->filled || $this->prefilled || $this->focused;
	}

	/**
	 * Check if honeypot field was filled.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_filled(): bool {

		return $this->filled || $this->prefilled;
	}

	/**
	 * Get honeypot field value.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_value(): string {

		return $this->value;
	}

	/**
	 * Convert to API payload format.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function to_api_payload(): array {

		return [
			'honeypot_filled'     => $this->filled || $this->prefilled,
			'honeypot_value'      => $this->value,
			'honeypot_prefilled'  => $this->prefilled,
			'honeypot_focused'    => $this->focused,
			'honeypot_focus_time' => $this->focus_time,
			'honeypot_input_time' => $this->input_time,
		];
	}
}

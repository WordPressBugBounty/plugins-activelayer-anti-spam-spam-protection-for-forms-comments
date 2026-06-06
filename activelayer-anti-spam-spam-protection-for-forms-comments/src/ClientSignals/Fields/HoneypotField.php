<?php
/**
 * Honeypot field for bot detection.
 *
 * @package ActiveLayer\ClientSignals\Fields
 * @since   1.1.0
 */

namespace ActiveLayer\ClientSignals\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HoneypotField class.
 *
 * Renders the honeypot field - an invisible form field that bots
 * tend to fill but humans don't see. If the honeypot field contains
 * a value on submission, it's a strong indicator of bot activity.
 *
 * @since 1.1.0
 */
class HoneypotField {

	/**
	 * Honeypot field name.
	 *
	 * Uses email-like name to attract bots that fill all email fields.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const FIELD_NAME = 'al_hp_email';

	/**
	 * CSS class for the field input.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const FIELD_CLASS = 'activelayer-hp-field';

	/**
	 * CSS class for the wrapper div.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const WRAPPER_CLASS = 'activelayer-hp-wrap';

	/**
	 * Get the field name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Field name.
	 */
	public static function get_field_name(): string {

		return self::FIELD_NAME;
	}

	/**
	 * Render the honeypot field HTML.
	 *
	 * The honeypot field is visually hidden using inline styles.
	 * It uses aria-hidden and tabindex="-1" for accessibility.
	 *
	 * @since 1.1.0
	 *
	 * @return string HTML for honeypot field.
	 */
	public static function render(): string {

		$hiding_styles = implode(
			'',
			[
				'position:absolute!important;',
				'left:-9999px!important;',
				'top:-9999px!important;',
				'width:1px!important;',
				'height:1px!important;',
				'overflow:hidden!important;',
				'opacity:0!important;',
				'pointer-events:none!important;',
			]
		);

		return sprintf(
			'<div class="%1$s" aria-hidden="true" style="%2$s">' .
			'<label class="activelayer-sr-only">%3$s</label>' .
			'<input type="email" name="%4$s" value="" class="%5$s" autocomplete="off" tabindex="-1" />' .
			'</div>',
			esc_attr( self::WRAPPER_CLASS ),
			esc_attr( $hiding_styles ),
			esc_html__( 'Leave this field empty', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			esc_attr( self::FIELD_NAME ),
			esc_attr( self::FIELD_CLASS )
		);
	}

	/**
	 * Output the honeypot field HTML.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function output(): void {

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render().
		echo self::render();
	}
}

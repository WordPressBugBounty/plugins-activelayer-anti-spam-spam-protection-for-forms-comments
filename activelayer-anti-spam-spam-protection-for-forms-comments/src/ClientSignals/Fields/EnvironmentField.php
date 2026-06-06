<?php
/**
 * Environment signals input field.
 *
 * @package ActiveLayer\ClientSignals\Fields
 * @since   1.1.0
 */

namespace ActiveLayer\ClientSignals\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EnvironmentField class.
 *
 * Renders the visually-hidden environment signals input field.
 * This field is populated by JavaScript with detection results
 * before form submission.
 *
 * @since 1.1.0
 */
class EnvironmentField {

	/**
	 * Hidden field name for environment signals.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const FIELD_NAME = 'activelayer_env_signals';

	/**
	 * CSS class for the field.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const FIELD_CLASS = 'activelayer-env-signals';

	/**
	 * CSS class for the wrapper div.
	 *
	 * @since 1.2.0
	 *
	 * @var string
	 */
	public const WRAPPER_CLASS = 'activelayer-env-wrap';

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
	 * Render the environment signals input field HTML.
	 *
	 * The field is visually hidden using inline styles inside a wrapper div.
	 * Uses type="text" instead of type="hidden" to survive bots that strip
	 * hidden inputs via `form.querySelectorAll('input[type=hidden]')`.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Render as visually-hidden type=text inside a wrapper div.
	 *
	 * @return string HTML for environment signals field.
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
			'<input type="text" name="%3$s" value="" class="%4$s" autocomplete="off" tabindex="-1" />' .
			'</div>',
			esc_attr( self::WRAPPER_CLASS ),
			esc_attr( $hiding_styles ),
			esc_attr( self::FIELD_NAME ),
			esc_attr( self::FIELD_CLASS )
		);
	}

	/**
	 * Output the environment signals input field HTML.
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

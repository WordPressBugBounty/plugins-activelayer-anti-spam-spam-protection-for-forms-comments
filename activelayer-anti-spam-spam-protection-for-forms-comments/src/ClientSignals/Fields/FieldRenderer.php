<?php
/**
 * Field renderer for client signals.
 *
 * @package ActiveLayer\ClientSignals\Fields
 * @since   1.1.0
 */

namespace ActiveLayer\ClientSignals\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\ClientSignals\ScriptLoader;

/**
 * FieldRenderer class.
 *
 * Aggregates rendering of all client signal fields.
 * Checks settings to determine which fields should be rendered.
 *
 * @since 1.1.0
 */
class FieldRenderer {

	/**
	 * Render all enabled client-signal fields.
	 *
	 * Outputs environment, behavioral, and honeypot signal fields
	 * based on their respective settings. Each field renders as a
	 * visually-hidden input wrapped in a div (see individual field
	 * classes for the anti-stripping rationale).
	 *
	 * @since 1.1.0
	 *
	 * @return string HTML for all enabled client-signal fields.
	 */
	public static function render_all(): string {

		ScriptLoader::enqueue_now();

		$output = '';

		if ( SettingsHelper::is_environment_tracking_enabled() ) {
			$output .= EnvironmentField::render();
		}

		if ( SettingsHelper::is_behavioral_tracking_enabled() ) {
			$output .= BehavioralField::render();
		}

		if ( SettingsHelper::is_honeypot_tracking_enabled() ) {
			$output .= HoneypotField::render();
		}

		return $output;
	}

	/**
	 * Output all enabled client-signal fields.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function output_all(): void {

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render methods.
		echo self::render_all();
	}
}

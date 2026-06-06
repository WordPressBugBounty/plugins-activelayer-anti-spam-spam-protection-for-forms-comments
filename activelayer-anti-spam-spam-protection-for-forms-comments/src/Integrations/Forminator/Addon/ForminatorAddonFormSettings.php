<?php
/**
 * Forminator Addon Form Settings for ActiveLayer.
 *
 * Handles per-form settings in the Forminator form builder Integrations tab.
 * Uses Forminator's internal addon settings storage (post meta) for wizard state,
 * and mirrors the enabled state to ActiveLayer's own option for runtime checks.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Integrations\Forminator
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Forminator addon naming convention.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Integrations\Forminator\AdminSettings;

/**
 * Class Forminator_Activelayer_Form_Settings.
 *
 * Naming convention required by Forminator: Forminator_{Slug}_Form_Settings.
 *
 * @since 1.1.0
 */
class Forminator_Activelayer_Form_Settings extends Forminator_Integration_Form_Settings {

	/**
	 * Wizards for form settings.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function module_settings_wizards() {

		return [
			[
				'callback'     => [ $this, 'setup_protection' ],
				'is_completed' => [ $this, 'is_protection_completed' ],
			],
		];
	}

	/**
	 * Render protection setup wizard step.
	 *
	 * Forminator wizard serializes `<form>` content from the popup on Save.
	 * We use a hidden `<input name="activelayer_activate" value="1">` inside
	 * a `<form>` tag to detect the save action via $submitted_data.
	 *
	 * @since 1.1.0
	 *
	 * @param array $submitted_data Submitted data from the wizard form.
	 *
	 * @return array
	 */
	public function setup_protection( $submitted_data ) {

		$this->addon_settings = $this->get_settings_values();
		$form_id              = (int) $this->module_id;
		$notification         = [];
		$error_message        = '';
		$is_submit            = ! empty( $submitted_data['activelayer_activate'] );

		if ( $is_submit ) {
			$this->addon_settings['connected'] = true;

			$this->save_module_settings_values();

			// Mirror to ActiveLayer option for runtime checks.
			$admin_settings = new AdminSettings();

			$admin_settings->save_form_protection( $form_id, true );

			$notification = [
				'type' => 'success',
				'text' => '<strong>' . esc_html__( 'ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</strong> '
					. esc_html__( 'spam protection is now active for this form.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];
		}

		$is_connected = ! empty( $this->addon_settings['connected'] );

		$buttons = [];

		if ( $is_connected ) {
			$buttons['disconnect']['markup'] = Forminator_Integration::get_button_markup(
				esc_html__( 'Deactivate', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'sui-button-ghost sui-tooltip sui-tooltip-top-center forminator-addon-form-disconnect',
				esc_html__( 'Deactivate ActiveLayer for this form.', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);
		}

		$buttons['next']['markup'] = '<div class="sui-actions-right">'
			. Forminator_Integration::get_button_markup(
				$is_connected
					? esc_html__( 'OK', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
					: esc_html__( 'Activate', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'sui-button-primary forminator-addon-finish'
			)
			. '</div>';

		$status_text = $is_connected
			? esc_html__( 'AI spam protection is active for this form. Submissions are being checked before notifications are sent.', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			: esc_html__( 'Click Activate to enable AI-powered spam filtering for this form. Submissions will be checked for spam before email notifications are sent.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

		$title = $is_connected
			? esc_html__( 'ActiveLayer is Active', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			: esc_html__( 'ActiveLayer Spam Protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

		$html = '<div class="forminator-integration-popup__header">'
			. '<h3 class="sui-box-title sui-lg" style="overflow: initial; white-space: normal; text-overflow: initial;">'
			. $title
			. '</h3>'
			. '<p class="sui-description">' . $status_text . '</p>'
			. $error_message
			. '</div>'
			. '<form enctype="multipart/form-data">'
			. '<input type="hidden" name="activelayer_activate" value="1" />'
			. '</form>';

		return [
			'html'         => $html,
			'redirect'     => false,
			'has_errors'   => false,
			'is_close'     => ( $is_submit && empty( $error_message ) ),
			'buttons'      => $buttons,
			'notification' => $notification,
		];
	}

	/**
	 * Check if protection is completed (connected).
	 *
	 * @since 1.1.0
	 *
	 * @param array $submitted_data Submitted data.
	 *
	 * @return bool
	 */
	public function is_protection_completed( $submitted_data = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		$this->addon_settings = $this->get_settings_values();

		return ! empty( $this->addon_settings['connected'] );
	}

	/**
	 * Disconnect form from ActiveLayer (disable protection).
	 *
	 * Called by Forminator when user clicks "Deactivate".
	 *
	 * @since 1.1.0
	 *
	 * @param array $submitted_data Submitted data.
	 */
	public function disconnect_module( $submitted_data = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		$form_id = (int) $this->module_id;

		// Clear Forminator's internal addon settings.
		$this->addon_settings = [];

		$this->save_module_settings_values();

		// Mirror to ActiveLayer option.
		$admin_settings = new AdminSettings();

		$admin_settings->save_form_protection( $form_id, false );
	}
}

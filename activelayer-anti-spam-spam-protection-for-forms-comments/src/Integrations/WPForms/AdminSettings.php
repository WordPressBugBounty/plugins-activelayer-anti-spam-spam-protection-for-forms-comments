<?php

namespace ActiveLayer\Integrations\WPForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPForms Admin Settings.
 *
 * Handles admin settings and form configuration for WPForms integration.
 * Provides form-specific anti-spam settings in the WPForms builder.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\WPForms
 */
class AdminSettings implements \ActiveLayer\Integrations\FormAdminSettingsInterface {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.0.0
	 *
	 * @var WPFormsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param WPFormsIntegration $integration Parent integration instance.
	 */
	public function __construct( WPFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Get form-specific settings.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Default flipped to opt-out — protection enabled when no explicit toggle stored.
	 *
	 * @param array $form_data Form data.
	 *
	 * @return array Form settings.
	 */
	public function get_form_settings( array $form_data ): array {

		$defaults = [
			'enabled'               => true,
			'tracking_mode'         => null,
			'tracking_mode_defined' => false,
		];

		if ( empty( $form_data ) ) {
			return $defaults;
		}

		$settings            = $form_data['settings']['anti_spam']['activelayer'] ?? [];
		$enabled             = isset( $settings['enable'] ) ? (bool) $settings['enable'] : true;
		$tracking_defined    = array_key_exists( 'tracking_mode', $settings );
		$tracking_mode_value = $tracking_defined ? (bool) $settings['tracking_mode'] : null;

		return [
			'enabled'               => $enabled,
			'tracking_mode'         => $tracking_mode_value,
			'tracking_mode_defined' => $tracking_defined,
		];
	}

	/**
	 * Retrieve form settings directly from a stored WPForms form.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return array
	 */
	public function get_form_settings_by_id( int $form_id ): array {

		if ( $form_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return [
				'enabled'               => false,
				'tracking_mode'         => null,
				'tracking_mode_defined' => false,
			];
		}

		$form = wpforms()->form->get( $form_id );

		if ( ! $form ) {
			return [
				'enabled'               => false,
				'tracking_mode'         => null,
				'tracking_mode_defined' => false,
			];
		}

		$form_data = wpforms_decode( $form->post_content );

		return $this->get_form_settings( is_array( $form_data ) ? $form_data : [] );
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		if ( ! function_exists( 'wpforms' ) || ! wpforms()->form ) {
			return [];
		}

		$forms = wpforms()->form->get( '', [ 'posts_per_page' => -1 ] );

		if ( empty( $forms ) || ! is_array( $forms ) ) {
			return [];
		}

		$result = [];

		foreach ( $forms as $form ) {
			$form_data = wpforms_decode( $form->post_content );

			if ( ! is_array( $form_data ) ) {
				continue;
			}

			$settings = $this->get_form_settings( $form_data );

			$result[] = [
				'id'      => (int) $form->ID,
				'name'    => $form_data['settings']['form_title'] ?? __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'enabled' => (bool) $settings['enabled'],
			];
		}

		return $result;
	}

	/**
	 * Save protection status for a specific form.
	 *
	 * @since 1.1.0
	 *
	 * @param int  $form_id Form ID.
	 * @param bool $enabled Whether protection is enabled.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_protection( int $form_id, bool $enabled ): bool {

		if ( ! function_exists( 'wpforms' ) || ! wpforms()->form ) {
			return false;
		}

		$form = wpforms()->form->get( $form_id );

		if ( ! $form ) {
			return false;
		}

		$form_data = wpforms_decode( $form->post_content );

		if ( ! is_array( $form_data ) ) {
			return false;
		}

		// PHP auto-vivifies intermediate array keys on assignment.
		$form_data['settings']['anti_spam']['activelayer']['enable'] = $enabled ? '1' : '0';

		// Pass the raw form data array — WPForms update() encodes internally.
		return (bool) wpforms()->form->update( $form_id, $form_data );
	}

	/**
	 * Persist explicit `'0'` for the protection toggle when the builder save drops it.
	 *
	 * WPForms renders the toggle as a plain `<input type="checkbox">`. Browsers omit
	 * unchecked checkboxes from form submissions, so `settings.anti_spam.activelayer.enable`
	 * disappears from the saved post_content. Without this filter the opt-out default
	 * read at `get_form_settings()` would re-enable protection on every reload, defeating
	 * the user's explicit opt-out. Mirrors how WPForms core preserves `store_spam_entries`
	 * via the same `?? '0'` pattern inside its own `update()` method.
	 *
	 * @since 1.3.0
	 *
	 * @param array $form Post arguments passed to wp_update_post().
	 * @param array $data Final form data array used to build $form['post_content'].
	 * @param array $args Update context (cap, context, etc.).
	 *
	 * @return array
	 */
	public function preserve_protection_toggle_on_save( array $form, array $data, array $args ): array {

		if ( ( $args['context'] ?? '' ) !== 'save_form' ) {
			return $form;
		}

		if ( isset( $data['settings']['anti_spam']['activelayer']['enable'] ) ) {
			return $form;
		}

		$data['settings']['anti_spam']['activelayer']['enable'] = '0';

		$form['post_content'] = wpforms_encode( $data );

		return $form;
	}

	/**
	 * Add anti-spam settings to WPForms builder Anti-Spam panel.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Builder default toggle now ON to match opt-out backend.
	 *
	 * @param array $form_data Form data.
	 */
	public function add_form_settings( array $form_data ): void {

		// Enable/disable toggle - stored in anti-spam section.
		wpforms_panel_field(
			'toggle',
			'anti_spam',
			'enable',
			$form_data,
			esc_html__( 'Enable ActiveLayer Protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			[
				'parent'     => 'settings',
				'subsection' => 'activelayer',
				'default'    => true,
				'tooltip'    => esc_html__( 'Enable AI-powered spam filtering for this form. Emails will be blocked until API verification completes.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			]
		);

		/**
		 * Filter whether to show the tracking mode toggle in form settings.
		 *
		 * By default, tracking mode is only visible when WP_DEBUG is enabled.
		 * Return true from this filter to show tracking mode regardless of WP_DEBUG.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $show Whether to show the tracking mode option.
		 */
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || apply_filters( 'activelayer_show_tracking_mode', false ) ) {
			wpforms_panel_field(
				'toggle',
				'anti_spam',
				'tracking_mode',
				$form_data,
				esc_html__( 'Enable tracking mode (log only)', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				[
					'parent'     => 'settings',
					'subsection' => 'activelayer',
					'default'    => false,
					'dependency' => [
						'field' => 'settings[anti_spam][activelayer][enable]',
						'value' => '1',
					],
					'tooltip'    => esc_html__( 'Analyze submissions without delaying notifications. Spam verdicts will not block the form.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				]
			);
		}
	}

	/**
	 * Get the URL-friendly slug for WPForms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'wpforms';
	}

	/**
	 * Get the admin page URL for WPForms.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=wpforms-overview';
	}

	/**
	 * Get the form edit URL template for WPForms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=wpforms-builder&view=settings&form_id=%d';
	}
}

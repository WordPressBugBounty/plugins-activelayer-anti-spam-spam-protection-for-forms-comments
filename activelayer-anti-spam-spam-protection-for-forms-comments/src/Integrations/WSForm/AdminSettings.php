<?php

namespace ActiveLayer\Integrations\WSForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Integrations\FormAdminSettingsInterface;
use WS_Form_Common;

/**
 * WS Form admin settings.
 *
 * Manages per-form protection settings for the WS Form integration.
 *
 * @since 1.4.0
 */
class AdminSettings implements FormAdminSettingsInterface {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.4.0
	 *
	 * @var WSFormIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 *
	 * @param WSFormIntegration $integration Parent integration instance.
	 */
	public function __construct( WSFormIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Get all WS Form forms with their protection status.
	 *
	 * WS Form stores forms in a custom table, so we read them via
	 * WS_Form_Common::get_forms_array( false ), which returns a
	 * [ form_id => label ] map of non-trashed forms.
	 *
	 * @since 1.4.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		if ( ! class_exists( 'WS_Form_Common' ) ) {
			return [];
		}

		$forms = WS_Form_Common::get_forms_array( false );

		if ( ! is_array( $forms ) || empty( $forms ) ) {
			return [];
		}

		$result = [];

		foreach ( $forms as $form_id => $label ) {
			$form_id = (int) $form_id;

			if ( $form_id <= 0 ) {
				continue;
			}

			$settings = $this->get_form_settings( $form_id );

			$result[] = [
				'id'      => $form_id,
				'name'    => $label !== '' ? $label : __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'enabled' => ! empty( $settings['enabled'] ),
			];
		}

		return $result;
	}

	/**
	 * Save protection status for a specific form.
	 *
	 * @since 1.4.0
	 *
	 * @param int  $form_id Form ID.
	 * @param bool $enabled Whether protection is enabled.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_protection( int $form_id, bool $enabled ): bool {

		$current            = $this->get_form_settings( $form_id );
		$current['enabled'] = $enabled;

		return $this->save_form_settings( $form_id, $current );
	}

	/**
	 * Get the URL-friendly slug for WS Form.
	 *
	 * @since 1.4.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'ws-form';
	}

	/**
	 * Get the admin page URL for WS Form.
	 *
	 * @since 1.4.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=ws-form';
	}

	/**
	 * Get the form edit URL template for WS Form.
	 *
	 * @since 1.4.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=ws-form-edit&id=%d';
	}

	/**
	 * Get per-form settings (opt-out default).
	 *
	 * @since 1.4.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array Form settings.
	 */
	public function get_form_settings( int $form_id ): array {

		$defaults = [
			'enabled' => true,
		];

		$saved = get_option( 'activelayer_ws_form_form_' . $form_id, [] );

		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save per-form settings.
	 *
	 * @since 1.4.0
	 *
	 * @param int   $form_id  Form ID.
	 * @param array $settings Settings to save.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_settings( int $form_id, array $settings ): bool {

		$sanitized = [
			'enabled' => ! empty( $settings['enabled'] ),
		];

		return (bool) update_option( 'activelayer_ws_form_form_' . $form_id, $sanitized );
	}

	/**
	 * Clean up per-form settings when a WS Form form is deleted.
	 *
	 * Hooked to wsf_form_delete, which passes the deleted form's ID.
	 *
	 * @since 1.4.0
	 *
	 * @param int $form_id Deleted form ID.
	 */
	public function cleanup_form_settings( $form_id ): void {

		$form_id = (int) $form_id;

		if ( $form_id <= 0 ) {
			return;
		}

		delete_option( 'activelayer_ws_form_form_' . $form_id );
	}
}

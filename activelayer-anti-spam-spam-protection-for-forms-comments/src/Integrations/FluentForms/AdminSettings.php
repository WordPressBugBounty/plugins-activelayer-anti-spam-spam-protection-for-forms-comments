<?php

namespace ActiveLayer\Integrations\FluentForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Integrations\FormAdminSettingsInterface;

/**
 * Fluent Forms Admin Settings.
 *
 * Manages per-form settings for Fluent Forms integration.
 *
 * @since 1.1.0
 */
class AdminSettings implements FormAdminSettingsInterface {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.1.0
	 *
	 * @var FluentFormsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param FluentFormsIntegration $integration Parent integration instance.
	 */
	public function __construct( FluentFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		global $wpdb;

		// Strip anything that is not a valid MySQL table-name character.
		// Unlike sanitize_key(), this preserves uppercase letters in the prefix.
		$table_name = preg_replace( '/[^a-zA-Z0-9_$]/', '', $wpdb->prefix . 'fluentform_forms' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$forms = $wpdb->get_results(
			"SELECT id, title FROM {$table_name} ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			OBJECT
		);

		if ( empty( $forms ) ) {
			return [];
		}

		$result = [];

		foreach ( $forms as $form ) {
			$form_id  = (int) $form->id;
			$settings = $this->get_form_settings( $form_id );

			$result[] = [
				'id'      => $form_id,
				'name'    => $form->title ? $form->title : __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'enabled' => ! empty( $settings['enabled'] ),
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

		$current            = $this->get_form_settings( $form_id );
		$current['enabled'] = $enabled;

		return $this->save_form_settings( $form_id, $current );
	}

	/**
	 * Get the URL-friendly slug for Fluent Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'fluent-forms';
	}

	/**
	 * Get the admin page URL for Fluent Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=fluent_forms';
	}

	/**
	 * Get the form edit URL template for Fluent Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=fluent_forms&route=editor&form_id=%d';
	}

	/**
	 * Get per-form settings.
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array Form settings.
	 */
	public function get_form_settings( int $form_id ): array {

		$defaults = [
			'enabled' => false,
		];

		$saved = get_option( 'activelayer_fluentforms_form_' . $form_id, [] );

		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save per-form settings.
	 *
	 * @since 1.1.0
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

		return (bool) update_option( 'activelayer_fluentforms_form_' . $form_id, $sanitized );
	}

	/**
	 * Clean up per-form settings when a Fluent Form is deleted.
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID being deleted.
	 */
	public function cleanup_form_settings( $form_id ): void {

		$form_id = (int) $form_id;

		if ( $form_id <= 0 ) {
			return;
		}

		delete_option( 'activelayer_fluentforms_form_' . $form_id );
	}
}

<?php

namespace ActiveLayer\Integrations\SureForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Integrations\FormAdminSettingsInterface;

/**
 * SureForms Admin Settings.
 *
 * Manages per-form settings for SureForms integration.
 *
 * @since 1.1.0
 */
class AdminSettings implements FormAdminSettingsInterface {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.1.0
	 *
	 * @var SureFormsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param SureFormsIntegration $integration Parent integration instance.
	 */
	public function __construct( SureFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * Queries the sureforms_form CPT for published forms.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		$forms = get_posts(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			]
		);

		if ( empty( $forms ) ) {
			return [];
		}

		$result = [];

		foreach ( $forms as $form ) {
			$form_id  = (int) $form->ID;
			$settings = $this->get_form_settings( $form_id );

			$result[] = [
				'id'      => $form_id,
				'name'    => $form->post_title ? $form->post_title : __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
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
	 * Get the URL-friendly slug for SureForms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'sureforms';
	}

	/**
	 * Get the admin page URL for SureForms.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=sureforms_menu';
	}

	/**
	 * Get the form edit URL template for SureForms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'post.php?post=%d&action=edit';
	}

	/**
	 * Get per-form settings.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Default flipped to opt-out — protection enabled when no explicit toggle stored.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array Form settings.
	 */
	public function get_form_settings( int $form_id ): array {

		$defaults = [
			'enabled' => true,
		];

		$saved = get_option( 'activelayer_sureforms_form_' . $form_id, [] );

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

		return (bool) update_option( 'activelayer_sureforms_form_' . $form_id, $sanitized );
	}

	/**
	 * Clean up per-form settings when a SureForms form is deleted.
	 *
	 * Hooked to before_delete_post. Only deletes options for sureforms_form posts.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function cleanup_form_settings( $post_id ): void {

		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return;
		}

		if ( get_post_type( $post_id ) !== 'sureforms_form' ) {
			return;
		}

		delete_option( 'activelayer_sureforms_form_' . $post_id );
	}
}

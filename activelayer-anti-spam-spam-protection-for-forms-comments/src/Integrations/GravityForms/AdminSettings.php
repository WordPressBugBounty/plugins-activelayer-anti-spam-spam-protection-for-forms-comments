<?php

namespace ActiveLayer\Integrations\GravityForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GFAPI;

/**
 * Gravity Forms per-form settings.
 *
 * Stores protection toggles in a single WP option keyed by form ID,
 * following the same pattern as the Forminator integration.
 *
 * @since 1.1.0
 */
class AdminSettings implements \ActiveLayer\Integrations\FormAdminSettingsInterface {

	/**
	 * Option key for all Gravity Forms per-form settings.
	 *
	 * @since 1.1.0
	 */
	private const SETTINGS_OPTION = 'activelayer_gravityforms_form_settings';

	/**
	 * In-memory settings cache.
	 *
	 * @since 1.1.0
	 *
	 * @var array|null
	 */
	private $settings_cache = null;

	/**
	 * Get settings for a specific form.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Default flipped to opt-out — protection enabled when no explicit toggle stored.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 *
	 * @return array {
	 *     @type bool $enabled Whether protection is on.
	 * }
	 */
	public function get_form_settings( int $form_id ): array {

		if ( $form_id <= 0 ) {
			return [
				'enabled' => true,
			];
		}

		$all_settings = $this->get_all_settings();

		if ( ! isset( $all_settings[ $form_id ] ) ) {
			return [
				'enabled' => true,
			];
		}

		$form_settings = $all_settings[ $form_id ];

		return [
			'enabled' => ! empty( $form_settings['enabled'] ),
		];
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		if ( ! class_exists( 'GFAPI' ) ) {
			return [];
		}

		// Only active, non-trashed forms (GFAPI defaults).
		$forms = GFAPI::get_forms();

		if ( ! is_array( $forms ) ) {
			return [];
		}

		$result = [];
		$seen   = [];

		foreach ( $forms as $form ) {
			$entry = $this->build_form_entry( $form );

			// Deduplicate by form ID to avoid duplicate checkboxes
			// when stale forms remain after re-seeding.
			if ( ! $entry || isset( $seen[ $entry['id'] ] ) ) {
				continue;
			}

			$seen[ $entry['id'] ] = true;
			$result[]             = $entry;
		}

		return $result;
	}

	/**
	 * Normalize a single Gravity Forms form array into a list entry.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form Raw form array from GFAPI.
	 *
	 * @return array|null Normalized entry with id, name, enabled; or null if invalid.
	 */
	private function build_form_entry( array $form ): ?array {

		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		if ( $form_id <= 0 ) {
			return null;
		}

		$settings = $this->get_form_settings( $form_id );

		return [
			'id'      => $form_id,
			'name'    => ! empty( $form['title'] ) ? $form['title'] : __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'enabled' => ! empty( $settings['enabled'] ),
		];
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

		if ( $form_id <= 0 ) {
			return false;
		}

		$all_settings = $this->get_all_settings();

		if ( ! isset( $all_settings[ $form_id ] ) ) {
			$all_settings[ $form_id ] = [];
		}

		$all_settings[ $form_id ]['enabled'] = $enabled;
		$this->settings_cache                = $all_settings;

		return update_option( self::SETTINGS_OPTION, $all_settings );
	}

	/**
	 * Save full form settings (for GF form editor sync).
	 *
	 * @since 1.1.0
	 *
	 * @param int   $form_id  Form ID.
	 * @param array $settings Settings array with 'enabled'.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_settings_full( int $form_id, array $settings ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		$all_settings             = $this->get_all_settings();
		$all_settings[ $form_id ] = $settings;
		$this->settings_cache     = $all_settings;

		return update_option( self::SETTINGS_OPTION, $all_settings );
	}

	/**
	 * Get the URL-friendly slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'gravity-forms';
	}

	/**
	 * Get the admin page URL for Gravity Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=gf_edit_forms';
	}

	/**
	 * Get the form edit URL template.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=gf_edit_forms&id=%d';
	}

	/**
	 * Clear the in-memory cache.
	 *
	 * @since 1.1.0
	 */
	public function clear_cache(): void {

		$this->settings_cache = null;
	}

	/**
	 * Load all per-form settings from the database.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function get_all_settings(): array {

		if ( $this->settings_cache !== null ) {
			return $this->settings_cache;
		}

		$settings = get_option( self::SETTINGS_OPTION, [] );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$this->settings_cache = $settings;

		return $settings;
	}
}

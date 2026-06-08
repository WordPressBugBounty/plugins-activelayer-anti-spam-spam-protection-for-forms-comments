<?php

namespace ActiveLayer\Integrations\Forminator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Integrations\FormAdminSettingsInterface;
use Forminator_API;

/**
 * Forminator Admin Settings.
 *
 * Manages per-form toggle for enabling ActiveLayer protection on Forminator forms.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Integrations\Forminator
 */
class AdminSettings implements FormAdminSettingsInterface {

	/**
	 * Option key for per-form settings storage.
	 *
	 * @since 1.1.0
	 */
	private const SETTINGS_OPTION = 'activelayer_forminator_form_settings';

	/**
	 * Whether hooks have been registered.
	 *
	 * @since 1.1.0
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Runtime settings cache.
	 *
	 * @since 1.1.0
	 *
	 * @var array|null
	 */
	private $settings_cache = null;

	/**
	 * Initialize admin settings hooks.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {

		if ( $this->initialized || ! is_admin() ) {
			return;
		}

		$this->initialized = true;
	}

	/**
	 * Get form settings for runtime checks.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Default flipped to opt-out — protection enabled when no explicit toggle stored.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array
	 */
	public function get_form_settings( int $form_id ): array {

		if ( $form_id <= 0 ) {
			return [ 'enabled' => true ];
		}

		$all_settings = $this->get_all_settings();

		$form_settings = isset( $all_settings[ $form_id ] ) ? $all_settings[ $form_id ] : [];

		// Respect explicit stored toggle (including false); default to enabled when unset.
		if ( isset( $form_settings['enabled'] ) ) {
			return [ 'enabled' => (bool) $form_settings['enabled'] ];
		}

		return [ 'enabled' => true ];
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		if ( ! $this->is_forminator_available() ) {
			return [];
		}

		// Fetch only published forms with a high per-page limit to retrieve all.
		$forms = Forminator_API::get_forms( null, 1, 999, 'publish' );

		if ( ! is_array( $forms ) ) {
			return [];
		}

		$result = [];
		$seen   = [];

		foreach ( $forms as $form ) {
			$entry = $this->build_form_entry( $form );

			// Deduplicate by form ID to avoid duplicate checkboxes
			// when stale form posts remain after re-seeding.
			if ( ! $entry || isset( $seen[ $entry['id'] ] ) ) {
				continue;
			}

			$seen[ $entry['id'] ] = true;
			$result[]             = $entry;
		}

		return $result;
	}

	/**
	 * Check whether the Forminator API is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	private function is_forminator_available(): bool {

		return class_exists( 'Forminator_API' ) && method_exists( 'Forminator_API', 'get_forms' );
	}

	/**
	 * Normalize a single Forminator form object into a list entry.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $form Raw form from Forminator_API.
	 *
	 * @return array|null Normalized entry with id, name, enabled; or null if invalid.
	 */
	private function build_form_entry( $form ): ?array {

		$form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;

		if ( $form_id <= 0 ) {
			return null;
		}

		$settings  = $this->get_form_settings( $form_id );
		$form_name = is_object( $form ) && isset( $form->settings['formName'] )
			? $form->settings['formName']
			: __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

		return [
			'id'      => $form_id,
			'name'    => $form_name,
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

		$this->settings_cache = $all_settings;

		return update_option( self::SETTINGS_OPTION, $all_settings );
	}

	/**
	 * Get the URL-friendly slug for Forminator.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'forminator';
	}

	/**
	 * Get the admin page URL for Forminator.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=forminator-cform';
	}

	/**
	 * Get the form edit URL template for Forminator.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=forminator-cform-wizard&id=%d';
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
	 * Get all form settings from the database.
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

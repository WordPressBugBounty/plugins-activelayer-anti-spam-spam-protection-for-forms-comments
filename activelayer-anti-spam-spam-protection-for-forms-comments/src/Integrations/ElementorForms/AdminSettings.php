<?php

namespace ActiveLayer\Integrations\ElementorForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Integrations\FormAdminSettingsInterface;

/**
 * Elementor Forms per-form settings.
 *
 * Stores protection toggles in a single WP option keyed by a numeric hash
 * derived from Elementor's string element IDs via abs( crc32() ).
 *
 * @since 1.1.0
 */
class AdminSettings implements FormAdminSettingsInterface {

	/**
	 * Option key for all Elementor Forms per-form settings.
	 *
	 * @since 1.1.0
	 */
	private const SETTINGS_OPTION = 'activelayer_elementor_forms_form_settings';

	/**
	 * In-memory settings cache.
	 *
	 * @since 1.1.0
	 *
	 * @var array|null
	 */
	private $settings_cache = null;

	/**
	 * In-memory forms list cache.
	 *
	 * @since 1.1.0
	 *
	 * @var array|null
	 */
	private $forms_cache = null;

	/**
	 * Get settings for a specific form.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Default flipped to opt-out — protection enabled when no explicit toggle stored.
	 *
	 * @param int $form_id Numeric form ID (crc32 hash of element ID).
	 *
	 * @return array {
	 *     @type bool $enabled Whether protection is on.
	 * }
	 */
	public function get_form_settings( int $form_id ): array {

		$defaults = [
			'enabled' => true,
		];

		if ( $form_id <= 0 ) {
			return $defaults;
		}

		$all_settings  = $this->get_all_settings();
		$form_settings = isset( $all_settings[ $form_id ] ) ? $all_settings[ $form_id ] : [];

		return wp_parse_args( $form_settings, $defaults );
	}

	/**
	 * Save settings for a specific form.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $form_id  Numeric form ID (crc32 hash of element ID).
	 * @param array $settings Settings to save.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_settings( int $form_id, array $settings ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		$sanitized = [
			'enabled' => ! empty( $settings['enabled'] ),
		];

		$all_settings             = $this->get_all_settings();
		$all_settings[ $form_id ] = $sanitized;
		$this->settings_cache     = $all_settings;

		return (bool) update_option( self::SETTINGS_OPTION, $all_settings );
	}

	/**
	 * Save protection status for a specific form.
	 *
	 * @since 1.1.0
	 *
	 * @param int  $form_id Form ID (crc32 hash of element ID).
	 * @param bool $enabled Whether protection is enabled.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_protection( int $form_id, bool $enabled ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		$current            = $this->get_form_settings( $form_id );
		$current['enabled'] = $enabled;

		return $this->save_form_settings( $form_id, $current );
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * Queries wp_postmeta for _elementor_data containing form widgets,
	 * parses JSON, and extracts element IDs and form names.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', 'enabled', and 'page_id' keys.
	 */
	public function get_forms_list(): array {

		if ( $this->forms_cache !== null ) {
			return $this->forms_cache;
		}

		global $wpdb;

		// Join with posts table to exclude revisions, drafts, and trashed pages.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s
				AND pm.meta_value LIKE %s
				AND p.post_status = 'publish'
				AND p.post_type NOT IN ('revision', 'attachment')",
				'_elementor_data',
				'%"widgetType":"form"%'
			),
			OBJECT
		);

		if ( empty( $rows ) ) {
			$this->forms_cache = [];

			return [];
		}

		$result = [];
		$seen   = [];

		foreach ( $rows as $row ) {
			$entries = $this->parse_elementor_row( $row );

			foreach ( $entries as $entry ) {
				$form_id = $entry['id'];

				// Deduplicate by numeric form ID to avoid duplicate checkboxes
				// when the same Elementor element appears across multiple pages.
				if ( isset( $seen[ $form_id ] ) ) {
					continue;
				}

				$seen[ $form_id ] = true;
				$result[]         = $entry;
			}
		}

		$this->forms_cache = $result;

		return $result;
	}

	/**
	 * Parse a single Elementor postmeta row into form entries.
	 *
	 * @since 1.1.0
	 *
	 * @param object $row Database row with post_id and meta_value.
	 *
	 * @return array[] Parsed form entries (may be empty).
	 */
	private function parse_elementor_row( object $row ): array {

		$data = json_decode( $row->meta_value, true );

		if ( ! is_array( $data ) ) {
			return [];
		}

		$page_id = (int) ( $row->post_id ?? 0 );
		$forms   = $this->find_form_widgets( $data );
		$entries = [];

		foreach ( $forms as $form ) {
			$entry = $this->build_form_entry( $form, $page_id );

			if ( $entry !== null ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Build a form entry array from a parsed Elementor form widget.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form    Parsed form widget data.
	 * @param int   $page_id Page ID where the form is embedded.
	 *
	 * @return array|null Form entry array or null if the widget has no element ID.
	 */
	private function build_form_entry( array $form, int $page_id ): ?array {

		$element_id = isset( $form['id'] ) ? $form['id'] : '';

		if ( empty( $element_id ) ) {
			return null;
		}

		$form_name  = isset( $form['settings']['form_name'] ) ? $form['settings']['form_name'] : '';
		$numeric_id = $this->element_id_to_int( $element_id );
		$settings   = $this->get_form_settings( $numeric_id );

		return [
			'id'      => $numeric_id,
			'name'    => ! empty( $form_name )
				? $form_name
				: __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'enabled' => ! empty( $settings['enabled'] ),
			'page_id' => $page_id,
		];
	}

	/**
	 * Get the URL-friendly slug for Elementor Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'elementor-forms';
	}

	/**
	 * Get the admin page URL for Elementor Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'edit.php?post_type=elementor_library';
	}

	/**
	 * Get the form edit URL template for Elementor Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'post.php?post=%d&action=elementor';
	}

	/**
	 * Convert a string element ID to a stable integer.
	 *
	 * @since 1.1.0
	 *
	 * @param string $element_id Elementor element ID (e.g., '5ed38b0').
	 *
	 * @return int Stable numeric hash.
	 */
	public function element_id_to_int( string $element_id ): int {

		return abs( crc32( $element_id ) );
	}

	/**
	 * Clear the in-memory caches.
	 *
	 * @since 1.1.0
	 */
	public function clear_cache(): void {

		$this->settings_cache = null;
		$this->forms_cache    = null;
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

	/**
	 * Recursively find form widgets in Elementor data.
	 *
	 * @since 1.1.0
	 *
	 * @param array $elements Elementor elements array.
	 *
	 * @return array Array of form widget element data.
	 */
	private function find_form_widgets( array $elements ): array {

		$forms = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['widgetType'] ) && $element['widgetType'] === 'form' ) {
				$forms[] = $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$forms = array_merge( $forms, $this->find_form_widgets( $element['elements'] ) );
			}
		}

		return $forms;
	}
}

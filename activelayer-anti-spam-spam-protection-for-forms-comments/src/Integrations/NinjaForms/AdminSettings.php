<?php

namespace ActiveLayer\Integrations\NinjaForms;

use Exception;
use function Ninja_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ninja Forms Admin Settings.
 *
 * Registers per-form toggle inside the Ninja Forms builder
 * and exposes stored configuration during runtime checks.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\NinjaForms
 */
class AdminSettings implements \ActiveLayer\Integrations\FormAdminSettingsInterface {

	/**
	 * Form setting key used to store the toggle state.
	 *
	 * @since 1.0.0
	 */
	private const SETTING_KEY = 'activelayer_enabled';

	/**
	 * Parent integration instance.
	 *
	 * @since 1.0.0
	 *
	 * @var NinjaFormsIntegration
	 */
	private $integration;

	/**
	 * Whether hooks have been registered.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param NinjaFormsIntegration $integration Parent integration instance.
	 */
	public function __construct( NinjaFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialise admin hooks when in the WordPress dashboard.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		if ( $this->initialized || ! is_admin() ) {
			return;
		}

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		$this->hooks();

		$this->initialized = true;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function hooks(): void {

		add_filter( 'ninja_forms_localize_forms_settings', [ $this, 'register_builder_setting' ] );
	}

	/**
	 * Inject ActiveLayer toggle into the Ninja Forms builder settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Existing form settings configuration.
	 *
	 * @return array Modified settings configuration.
	 */
	public function register_builder_setting( array $settings ): array {

		if ( ! isset( $settings['restrictions'] ) || ! is_array( $settings['restrictions'] ) ) {
			$settings['restrictions'] = [];
		}

		if ( isset( $settings['restrictions'][ self::SETTING_KEY ] ) ) {
			return $settings;
		}

		$settings['restrictions'][ self::SETTING_KEY ] = [
			'name'  => self::SETTING_KEY,
			'type'  => 'toggle',
			'label' => esc_html__( 'Enable ActiveLayer Protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'width' => 'full',
			'group' => 'primary',
			'value' => 0,
			'help'  => esc_html__( 'When enabled, submissions are screened by ActiveLayer before form actions run.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
		];

		return $settings;
	}

	/**
	 * Retrieve stored per-form configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return array{enabled: bool}
	 */
	public function get_form_settings( int $form_id ): array {

		$enabled = false;

		if ( $form_id <= 0 || ! function_exists( 'Ninja_Forms' ) ) {
			return [
				'enabled' => $enabled,
			];
		}

		try {
			$form = Ninja_Forms()->form( $form_id )->get();
		} catch ( Exception $e ) {
			$form = null;
		}

		if ( $form ) {
			$stored_value = $form->get_setting( self::SETTING_KEY );

			if ( $stored_value !== null && $stored_value !== '' ) {
				$enabled = (bool) $stored_value;
			}
		}

		return [
			'enabled' => $enabled,
		];
	}

	/**
	 * Fetch all Ninja Forms form objects.
	 *
	 * Handles availability checks and error handling for the NF API.
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of NF form objects, or empty array on failure.
	 */
	private function get_ninja_forms(): array {

		if ( ! function_exists( 'Ninja_Forms' ) ) {
			return [];
		}

		try {
			$forms = Ninja_Forms()->form()->get_forms();
		} catch ( \Exception $e ) {
			return [];
		}

		if ( empty( $forms ) || ! is_array( $forms ) ) {
			return [];
		}

		return $forms;
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		$forms  = $this->get_ninja_forms();
		$result = [];

		foreach ( $forms as $form ) {
			if ( ! is_object( $form ) ) {
				continue;
			}

			$form_id  = $form->get_id();
			$title    = $form->get_setting( 'title' );
			$settings = $this->get_form_settings( (int) $form_id );

			$result[] = [
				'id'      => (int) $form_id,
				'name'    => $title ? $title : __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
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

		if ( ! function_exists( 'Ninja_Forms' ) ) {
			return false;
		}

		global $wpdb;

		$meta_table = esc_sql( $wpdb->prefix . 'nf3_form_meta' );
		$value      = $enabled ? '1' : '0';

		// Upsert directly into nf3_form_meta — mirrors NF_Abstracts_Model::_save_setting().
		// We avoid NF's model API because the ModelFactory constructor pre-loads
		// settings from the NF cache (nf3_upgrades), so save() re-writes all cached
		// settings back, which can be stale or cause unintended side effects.
		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT `value` FROM `{$meta_table}` WHERE `parent_id` = %d AND `key` = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$form_id,
				self::SETTING_KEY
			)
		);

		if ( $existing !== null ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_table,
				[
					'value'      => $value,
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				],
				[
					'parent_id' => $form_id,
					'key'       => self::SETTING_KEY,
				]
			);
		} else {
			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$meta_table,
				[
					'parent_id'  => $form_id,
					'key'        => self::SETTING_KEY,
					'value'      => $value,
					'meta_key'   => self::SETTING_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);
		}

		// Delete the NF form cache so the next load pulls fresh settings from DB.
		// Without this, the ModelFactory constructor pre-loads stale cached settings
		// from nf3_upgrades and the change appears not to take effect.
		if ( class_exists( 'WPN_Helper' ) && method_exists( 'WPN_Helper', 'delete_nf_cache' ) ) {
			\WPN_Helper::delete_nf_cache( $form_id ); // phpcs:ignore WPForms.PHP.BackSlash.RemoveBackslash
		}

		return $result !== false;
	}

	/**
	 * Get the URL-friendly slug for Ninja Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'ninja-forms';
	}

	/**
	 * Get the admin page URL for Ninja Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=ninja-forms';
	}

	/**
	 * Get the form edit URL template for Ninja Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=ninja-forms&form_id=%d';
	}
}

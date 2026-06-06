<?php

namespace ActiveLayer\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for form integration admin settings that support per-form protection.
 *
 * Provides a contract for AdminSettings classes that can list forms, toggle
 * per-form protection, and report protected counts. Also centralizes
 * URL-related metadata (slugs, admin URLs, editor URLs) to avoid
 * hardcoded maps scattered across multiple files.
 *
 * @since 1.1.0
 */
interface FormAdminSettingsInterface {

	/**
	 * Get all forms with their protection status.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array;

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
	public function save_form_protection( int $form_id, bool $enabled ): bool;

	/**
	 * Get the URL-friendly slug for this integration.
	 *
	 * Used in query parameters (e.g., ?integration=contact-form-7).
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug (kebab-case).
	 */
	public function get_url_slug(): string;

	/**
	 * Get the admin page URL for this integration's plugin.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path relative to admin_url() (e.g., 'admin.php?page=wpforms-overview').
	 */
	public function get_admin_page_url(): string;

	/**
	 * Get the form edit URL template for this integration.
	 *
	 * The template must contain %d as a placeholder for the form ID.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template relative to admin_url() with %d placeholder.
	 */
	public function get_form_edit_url_template(): string;
}

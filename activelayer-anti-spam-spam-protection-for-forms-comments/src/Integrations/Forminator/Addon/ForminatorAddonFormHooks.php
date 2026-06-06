<?php
/**
 * Forminator Addon Form Hooks for ActiveLayer.
 *
 * Provides the Form Hooks class required by the Forminator addon API
 * so that `after_entry_saved()` fires for our addon.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Integrations\Forminator
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Forminator addon naming convention.

use ActiveLayer\Integrations\Forminator\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Forminator_Activelayer_Form_Hooks.
 *
 * Naming convention required by Forminator: Forminator_{Slug}_Form_Hooks.
 *
 * Marks spam entries with Forminator's native `is_spam` / `status` fields
 * after the entry has been saved to the database.
 *
 * @since 1.1.0
 */
class Forminator_Activelayer_Form_Hooks extends Forminator_Integration_Form_Hooks {

	/**
	 * Skip custom entry fields — ActiveLayer does not store data in Forminator entries.
	 *
	 * Overrides the base class to avoid calling `get_api()` which is not
	 * implemented by our addon.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $submitted_data       Submitted data.
	 * @param array  $current_entry_fields Current entry fields.
	 * @param object $entry                Entry model.
	 *
	 * @return array Empty array.
	 */
	public function add_entry_fields( $submitted_data, $current_entry_fields, $entry ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		return [];
	}

	/**
	 * Mark the entry as spam in Forminator's database after save.
	 *
	 * Fires via `attach_addons_after_entry_saved()` in Forminator's
	 * front-action handler — after entry save, before email sending.
	 *
	 * This hook only fires when `self::$is_spam` is false in Forminator,
	 * which is our case: we return false from `forminator_spam_protection`
	 * in save mode to prevent Forminator from blocking the form.
	 *
	 * @since 1.1.0
	 *
	 * @param Forminator_Form_Entry_Model $entry_model Saved entry model.
	 */
	public function after_entry_saved( $entry_model ) {

		$spam_form_id = SubmissionHandler::get_pending_spam_form_id();

		if ( $spam_form_id === null ) {
			return;
		}

		$entry_form_id = isset( $entry_model->form_id ) ? (int) $entry_model->form_id : 0;

		if ( $entry_form_id !== $spam_form_id ) {
			return;
		}

		$this->mark_entry_spam( $entry_model );
		SubmissionHandler::clear_pending_spam();
	}

	/**
	 * Update entry spam fields via direct query.
	 *
	 * Uses `$wpdb->update()` instead of `$entry_model->save()` to avoid
	 * side-effects on `date_created` and `draft_id` fields.
	 *
	 * @since 1.1.0
	 *
	 * @param Forminator_Form_Entry_Model $entry_model Entry to mark as spam.
	 */
	private function mark_entry_spam( $entry_model ): void {

		if ( ! class_exists( '\Forminator_Database_Tables' ) ) {
			return;
		}

		$entry_id = isset( $entry_model->entry_id ) ? (int) $entry_model->entry_id : 0;

		if ( $entry_id <= 0 ) {
			return;
		}

		global $wpdb;

		$table = Forminator_Database_Tables::get_table_name(
			Forminator_Database_Tables::FORM_ENTRY
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			[
				'is_spam' => 1,
				'status'  => 'spam',
			],
			[ 'entry_id' => $entry_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
	}
}

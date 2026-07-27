<?php
/**
 * Uninstall ActiveLayer Plugin
 *
 * Removes all plugin data from the database when the plugin is deleted.
 *
 * @package ActiveLayer
 * @since 1.0.0
 */

// Exit if accessed directly or not in uninstall context.
//
// Tests may set ACTIVELAYER_UNINSTALL_TESTING to include this file for the
// sole purpose of defining activelayer_uninstall_site_data() — the destructive
// dispatch below only runs under a genuine WP_UNINSTALL_PLUGIN.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! defined( 'ACTIVELAYER_UNINSTALL_TESTING' ) ) {
	exit;
}

if ( ! defined( 'ACTIVELAYER_CACHE_GROUP' ) ) {
	/**
	 * Cache group name for ActiveLayer cache entries.
	 *
	 * Note: This mirrors SubmissionCache::CACHE_GROUP intentionally.
	 * The uninstall file must work independently without loading the autoloader.
	 *
	 * @since 1.0.0
	 */
	define( 'ACTIVELAYER_CACHE_GROUP', 'activelayer' );
}

/**
 * Delete plugin options.
 *
 * Core settings:
 * - activelayer_global_settings (API key, spam behavior, etc.)
 * - activelayer_api_key_validated (API key validation cache)
 * - activelayer_onboarding_dismissed (Onboarding banner dismissed state)
 * - activelayer_onboarding_completed (Onboarding banner completed state)
 * - activelayer_storage_schema_version (Database schema version)
 * - activelayer_usage_banner_dismissed (Usage limit banner dismissed state)
 * - activelayer_logs (Ring buffer log entries)
 *
 * Integration settings (BaseFormIntegration::get_option_key()):
 * - activelayer_wpforms_settings
 * - activelayer_wp_comments_settings
 * - activelayer_contact_form_7_settings
 * - activelayer_ninja_forms_settings
 * - activelayer_formidable_forms_settings
 * - activelayer_fluent_forms_settings
 * - activelayer_sureforms_settings
 * - activelayer_forminator_settings
 * - activelayer_gravity_forms_settings
 * - activelayer_elementor_forms_settings
 * - activelayer_wc_reviews_settings (WC Reviews sub)
 * - activelayer_wc_registration_settings (WC Registration sub)
 * - activelayer_buddypress_settings
 * - activelayer_buddyboss_settings
 * - activelayer_affiliatewp_settings
 * - activelayer_memberpress_settings
 * - activelayer_ws_form_settings
 * - activelayer_funnelkit_settings
 * - activelayer_edd_reviews_settings (EDD Reviews sub)
 * - activelayer_edd_registration_settings (EDD Registration sub)
 *
 * Note: The WooCommerce umbrella (slug 'woocommerce') has no own settings
 * option — its enabled state is derived from the OR of the two sub-flags
 * above. There is therefore no `activelayer_woocommerce_settings` row to
 * delete here.
 *
 * Per-form settings (AdminSettings::SETTINGS_OPTION):
 * - activelayer_elementor_forms_form_settings
 * - activelayer_gravityforms_form_settings
 * - activelayer_forminator_form_settings
 * - activelayer_ws_form_form_{id}
 */
$activelayer_option_names = [
	// Core.
	'activelayer_global_settings',
	'activelayer_api_key_validated',
	'activelayer_onboarding_dismissed',
	'activelayer_onboarding_completed',
	'activelayer_storage_schema_version',
	'activelayer_usage_banner_dismissed',
	'activelayer_logs',
	'activelayer_plugin_version',
	'activelayer_opt_out_announce_required',

	// Integration settings.
	'activelayer_wpforms_settings',
	'activelayer_wp_comments_settings',
	'activelayer_contact_form_7_settings',
	'activelayer_ninja_forms_settings',
	'activelayer_formidable_forms_settings',
	'activelayer_fluent_forms_settings',
	'activelayer_sureforms_settings',
	'activelayer_forminator_settings',
	'activelayer_gravity_forms_settings',
	'activelayer_elementor_forms_settings',
	'activelayer_wc_reviews_settings',
	'activelayer_wc_registration_settings',
	'activelayer_buddypress_settings',
	'activelayer_buddyboss_settings',
	'activelayer_affiliatewp_settings',
	'activelayer_memberpress_settings',
	'activelayer_ws_form_settings',
	'activelayer_funnelkit_settings',
	'activelayer_edd_reviews_settings',
	'activelayer_edd_registration_settings',

	// Per-form settings.
	'activelayer_elementor_forms_form_settings',
	'activelayer_gravityforms_form_settings',
	'activelayer_forminator_form_settings',
];

if ( ! function_exists( 'activelayer_uninstall_site_data' ) ) {
	/**
	 * Remove all ActiveLayer data for the current blog.
	 *
	 * Deletes options (fixed and dynamic per-form keys), transients, the
	 * submissions table and cache entries for whichever blog is currently
	 * active. On multisite the caller wraps this in switch_to_blog() per site;
	 * WordPress runs uninstall.php only once for the whole network, so without
	 * that loop every subsite's submissions table (which holds form_data and
	 * api_response) plus its per-site options would be orphaned on delete.
	 *
	 * Also unschedules this blog's Action Scheduler jobs and the fallback
	 * WP-Cron hook, since both are stored per-site.
	 *
	 * Uses only $wpdb, core option/transient/cron APIs and (when available)
	 * Action Scheduler, so it keeps working without the plugin autoloader,
	 * exactly like the rest of this file.
	 *
	 * @since 1.6.1
	 *
	 * @param string[] $option_names Fixed core/integration option names to delete.
	 *
	 * @return void
	 */
	function activelayer_uninstall_site_data( array $option_names ): void {

		global $wpdb;

		foreach ( $option_names as $activelayer_option_name ) {
			delete_option( $activelayer_option_name );
		}

		// Delete per-form options for FluentForms, SureForms, WS Form and
		// FunnelKit (dynamic keys with a form ID suffix).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, no caching needed.
		$activelayer_per_form_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'activelayer_fluentforms_form_' ) . '%',
				$wpdb->esc_like( 'activelayer_sureforms_form_' ) . '%',
				$wpdb->esc_like( 'activelayer_ws_form_form_' ) . '%',
				$wpdb->esc_like( 'activelayer_funnelkit_form_' ) . '%'
			)
		);

		if ( $activelayer_per_form_rows ) {
			foreach ( $activelayer_per_form_rows as $activelayer_per_form_option ) {
				delete_option( $activelayer_per_form_option );
			}
		}

		// Transients.
		delete_transient( 'activelayer_subscription_stats' );
		delete_transient( 'activelayer_activation_redirect' );
		delete_transient( 'activelayer_table_creation_failed' );

		// Watchdog state options.
		delete_option( 'activelayer_last_queue_run' );
		delete_option( 'activelayer_queue_watchdog_notice' );

		// Drop the submissions table for this blog.
		$activelayer_table_to_drop = esc_sql( $wpdb->prefix . 'activelayer_submissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required for uninstall cleanup, table name sanitized.
		$wpdb->query( "DROP TABLE IF EXISTS `{$activelayer_table_to_drop}`" );

		// Clear ActiveLayer cache entries.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'list_cache_version', ACTIVELAYER_CACHE_GROUP );
			wp_cache_delete( 'queue_stats', ACTIVELAYER_CACHE_GROUP );
			wp_cache_delete( 'table_exists', ACTIVELAYER_CACHE_GROUP );
		}

		// Unschedule this blog's Action Scheduler jobs. Plugin hooks:
		// activelayer_process_submission, activelayer_cleanup_pending,
		// activelayer_refresh_subscription_stats, activelayer_queue_watchdog,
		// activelayer_retry_failed_submissions, activelayer_cleanup_submissions,
		// activelayer_send_feedback.
		//
		// Action Scheduler is loaded process-wide (from the main site), but a
		// subsite we switch_to_blog() into may never have created its own AS
		// tables — the unschedule would then query a missing table. Suppress DB
		// errors so that stays a no-op instead of a visible warning.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			$activelayer_previous_suppression = $wpdb->suppress_errors();

			as_unschedule_all_actions( 'activelayer_process_submission' );
			as_unschedule_all_actions( 'activelayer_cleanup_pending' );
			as_unschedule_all_actions( 'activelayer_refresh_subscription_stats' );
			as_unschedule_all_actions( 'activelayer_queue_watchdog' );
			as_unschedule_all_actions( 'activelayer_retry_failed_submissions' );
			as_unschedule_all_actions( 'activelayer_cleanup_submissions' );
			as_unschedule_all_actions( 'activelayer_send_feedback' );

			$wpdb->suppress_errors( $activelayer_previous_suppression );
		}

		// Clear the standard WP-Cron hook if Action Scheduler is unavailable.
		wp_clear_scheduled_hook( 'activelayer_queue_watchdog' );
	}
}

// Only run the destructive teardown during a genuine uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

if ( is_multisite() ) {
	// uninstall.php fires once for the whole network, so walk every blog and
	// clean it under its own prefix. number => 0 returns all sites; acceptable
	// here because uninstall must reach every one.
	// ponytail: loops all network sites; fine for a one-shot uninstall.
	$activelayer_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $activelayer_site_ids as $activelayer_site_id ) {
		switch_to_blog( $activelayer_site_id );
		activelayer_uninstall_site_data( $activelayer_option_names );
		restore_current_blog();
	}

	// Network-level options (stored once for the whole network).
	foreach ( $activelayer_option_names as $activelayer_option_name ) {
		delete_site_option( $activelayer_option_name );
	}

	delete_site_option( 'activelayer_last_queue_run' );
	delete_site_option( 'activelayer_queue_watchdog_notice' );
} else {
	activelayer_uninstall_site_data( $activelayer_option_names );
}

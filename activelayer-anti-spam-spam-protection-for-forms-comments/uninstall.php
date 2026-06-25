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
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Cache group name for ActiveLayer cache entries.
 *
 * Note: This mirrors SubmissionCache::CACHE_GROUP intentionally.
 * The uninstall file must work independently without loading the autoloader.
 *
 * @since 1.0.0
 */
define( 'ACTIVELAYER_CACHE_GROUP', 'activelayer' );

// Load WordPress database class.
global $wpdb;

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

foreach ( $activelayer_option_names as $activelayer_option_name ) {
	delete_option( $activelayer_option_name );

	if ( is_multisite() ) {
		delete_site_option( $activelayer_option_name );
	}
}

/**
 * Delete per-form options for FluentForms, SureForms, WS Form and FunnelKit (dynamic keys with form ID).
 */
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

/**
 * Delete transients.
 */
delete_transient( 'activelayer_subscription_stats' );
delete_transient( 'activelayer_activation_redirect' );

/**
 * Drop custom database tables.
 */
$activelayer_table_name    = $wpdb->prefix . 'activelayer_submissions';
$activelayer_table_to_drop = esc_sql( $activelayer_table_name );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required for uninstall cleanup, table name sanitized.
$wpdb->query( "DROP TABLE IF EXISTS `{$activelayer_table_to_drop}`" );

/**
 * Delete Action Scheduler actions
 * Plugin uses these hooks:
 * - activelayer_process_submission
 * - activelayer_cleanup_pending
 * - activelayer_refresh_subscription_stats
 * - activelayer_queue_watchdog
 * - activelayer_retry_failed_submissions
 * - activelayer_cleanup_submissions
 * - activelayer_send_feedback
 */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'activelayer_process_submission' );
	as_unschedule_all_actions( 'activelayer_cleanup_pending' );
	as_unschedule_all_actions( 'activelayer_refresh_subscription_stats' );
	as_unschedule_all_actions( 'activelayer_queue_watchdog' );
	as_unschedule_all_actions( 'activelayer_retry_failed_submissions' );
	as_unschedule_all_actions( 'activelayer_cleanup_submissions' );
	as_unschedule_all_actions( 'activelayer_send_feedback' );
}

// Clear standard WP-Cron hook if Action Scheduler unavailable.
wp_clear_scheduled_hook( 'activelayer_queue_watchdog' );

/**
 * Clear ActiveLayer cache entries.
 */
if ( function_exists( 'wp_cache_delete' ) ) {
	wp_cache_delete( 'list_cache_version', ACTIVELAYER_CACHE_GROUP );
	wp_cache_delete( 'queue_stats', ACTIVELAYER_CACHE_GROUP );
	wp_cache_delete( 'table_exists', ACTIVELAYER_CACHE_GROUP );
}

/**
 * Remove watchdog state options.
 */
delete_option( 'activelayer_last_queue_run' );
delete_option( 'activelayer_queue_watchdog_notice' );

if ( is_multisite() ) {
	delete_site_option( 'activelayer_last_queue_run' );
	delete_site_option( 'activelayer_queue_watchdog_notice' );
}

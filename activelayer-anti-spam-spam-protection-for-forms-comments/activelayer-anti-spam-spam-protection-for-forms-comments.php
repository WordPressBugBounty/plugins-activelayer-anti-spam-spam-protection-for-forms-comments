<?php
/**
 * Plugin Name:       ActiveLayer Anti-Spam: Spam Protection for Forms & Comments
 * Plugin URI:        https://activelayer.com/
 * Description:       Intelligent spam protection for WordPress forms and comments.
 * Requires at least: 5.5
 * Requires PHP:      7.2
 * Author:            ActiveLayer Team
 * Version:           1.5.0
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       activelayer-anti-spam-spam-protection-for-forms-comments
 * Domain Path:       /languages
 *
 * This plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * This plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this plugin. If not, see <https://www.gnu.org/licenses/>.
 */

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Plugin;
use ActiveLayer\Queue\QueueManager;
use ActiveLayer\Queue\QueueWatchdog;
use ActiveLayer\Queue\SubmissionCleanup;
use ActiveLayer\ActionScheduler\ActionSchedulerLoader;
use ActiveLayer\Storage\Storage;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @since 1.0.0
 */
const ACTIVELAYER_PLUGIN_VERSION = '1.5.0';

/**
 * Plugin file.
 *
 * @since 1.0.0
 */
const ACTIVELAYER_PLUGIN_FILE = __FILE__;

/**
 * Plugin path.
 *
 * @since 1.0.0
 */
define( 'ACTIVELAYER_PLUGIN_PATH', plugin_dir_path( ACTIVELAYER_PLUGIN_FILE ) );

/**
 * Plugin URL.
 *
 * @since 1.0.0
 */
define( 'ACTIVELAYER_PLUGIN_URL', plugin_dir_url( ACTIVELAYER_PLUGIN_FILE ) );

if ( ! defined( 'ACTIVELAYER_API_URL' ) ) {
	/**
	 * Default API endpoint URL.
	 *
	 * Defines the base URL for ActiveLayer API endpoints.
	 * Can be overridden by defining ACTIVELAYER_API_URL in wp-config.php.
	 *
	 * @since 1.0.0
	 */
	define( 'ACTIVELAYER_API_URL', 'https://api.activelayer.com/api/v1/' );
}

/**
 * Load plugin.
 *
 * @since 1.0.0
 */
function activelayer_plugin_load() {

	// Load composer autoloader.
	if ( file_exists( ACTIVELAYER_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
		require_once ACTIVELAYER_PLUGIN_PATH . 'vendor/autoload.php';
	}

	// Ensure storage schema matches the expected version.
	Storage::get_instance()->maybe_upgrade_schema();

	// Initialize Action Scheduler first.
	ActionSchedulerLoader::init();

	// Load WordPress-specific dependencies.
	if ( ! class_exists( 'WP_List_Table' ) && is_admin() ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	// Initialize queue manager (classes auto-loaded via composer).
	QueueManager::init();

	// Initialize main plugin (classes auto-loaded via composer).
	$plugin = Plugin::get_instance();

	$plugin->init();
}

add_action( 'plugins_loaded', 'activelayer_plugin_load' );

/**
 * Plugin activation hook.
 *
 * @since 1.0.0
 */
function activelayer_plugin_activate() {

	// Load composer autoloader.
	if ( file_exists( ACTIVELAYER_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
		require_once ACTIVELAYER_PLUGIN_PATH . 'vendor/autoload.php';
	}

	// Initialize database storage system.
	$storage = Storage::get_instance();

	// Always ensure table and schema exist during activation.
	$table_created = $storage->create_table();

	$storage->maybe_upgrade_schema();

	if ( ! $table_created ) {
		set_transient( 'activelayer_table_creation_failed', true, HOUR_IN_SECONDS );
	}

	// Add custom capability to administrator role.
	$admin_role = get_role( 'administrator' );

	if ( $admin_role ) {
		$admin_role->add_cap( 'manage_activelayer' );
	}

	// Set activation redirect transient for first-time installs only.
	if ( ! get_option( 'activelayer_global_settings' ) ) {
		set_transient( 'activelayer_activation_redirect', true, 30 );

		// New installs default to 30-day retention.
		$initial_settings                                       = SettingsHelper::get_global_settings();
		$initial_settings[ SettingsHelper::KEY_RETENTION_DAYS ] = 30;

		update_option( 'activelayer_global_settings', $initial_settings );
	}
}

register_activation_hook( ACTIVELAYER_PLUGIN_FILE, 'activelayer_plugin_activate' );

/**
 * Ensure custom capabilities exist for backward compatibility.
 *
 * Some installs may already have the plugin activated when a new capability is introduced.
 * This runs on admin requests so administrators keep the needed capability without manual reactivation.
 *
 * @since 1.0.0
 */
function activelayer_register_capabilities() {

	$admin_role = get_role( 'administrator' );

	if ( $admin_role && ! $admin_role->has_cap( 'manage_activelayer' ) ) {
		$admin_role->add_cap( 'manage_activelayer' );
	}
}

add_action( 'admin_init', 'activelayer_register_capabilities' );

/**
 * Display admin notice when submissions table creation failed during activation.
 *
 * @since 1.1.0
 */
function activelayer_table_creation_notice() {

	if ( ! get_transient( 'activelayer_table_creation_failed' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_activelayer' ) ) {
		return;
	}

	delete_transient( 'activelayer_table_creation_failed' );

	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'ActiveLayer:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), // phpcs:ignore WPForms.PHP.ValidateDomain.InvalidDomain
		esc_html__( 'Failed to create the submissions database table. Please deactivate and reactivate the plugin, or contact support if the issue persists.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) // phpcs:ignore WPForms.PHP.ValidateDomain.InvalidDomain
	);
}

add_action( 'admin_notices', 'activelayer_table_creation_notice' );

/**
 * Plugin deactivation hook.
 *
 * Cleans up scheduled actions to prevent orphaned cron jobs.
 *
 * @since 1.0.0
 */
function activelayer_plugin_deactivate() {

	// Clear all scheduled Action Scheduler actions.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'activelayer_process_submission' );
		as_unschedule_all_actions( 'activelayer_cleanup_pending' );
		as_unschedule_all_actions( 'activelayer_refresh_subscription_stats' );
		as_unschedule_all_actions( 'activelayer_retry_failed_submissions' );
		as_unschedule_all_actions( 'activelayer_send_feedback' );
	}

	SubmissionCleanup::unschedule();
	wp_clear_scheduled_hook( 'activelayer_cleanup_submissions' );

	QueueWatchdog::unschedule_watchdog();
	delete_option( QueueWatchdog::get_last_run_option() );
	delete_option( QueueWatchdog::get_notice_option() );

	// Remove custom capability from administrator role.
	$admin_role = get_role( 'administrator' );

	if ( $admin_role ) {
		$admin_role->remove_cap( 'manage_activelayer' );
	}
}

register_deactivation_hook( ACTIVELAYER_PLUGIN_FILE, 'activelayer_plugin_deactivate' );

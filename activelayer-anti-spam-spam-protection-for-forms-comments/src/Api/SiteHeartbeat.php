<?php

namespace ActiveLayer\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Queue\QueueManager;

/**
 * Periodically re-sends site metadata so the account stays observably alive.
 *
 * POST /verify used to fire only on connect and on the manual "Verify Key"
 * button, so first_verified_at equalled last_verified_at on virtually every
 * connected site and a silent plugin was indistinguishable from a working one
 * whose forms simply get no submissions. A weekly re-send makes
 * last_verified_at a real liveness signal and refreshes the protection state.
 *
 * @since 1.8.0
 */
class SiteHeartbeat {

	/**
	 * Action Scheduler hook name.
	 *
	 * @since 1.8.0
	 */
	private const HOOK = 'activelayer_site_heartbeat';

	/**
	 * Initialize the site heartbeat.
	 *
	 * @since 1.8.0
	 */
	public static function init(): void {

		self::hooks();
	}

	/**
	 * Register the scheduler and heartbeat callbacks.
	 *
	 * @since 1.8.0
	 */
	private static function hooks(): void {

		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * Ensure the recurring heartbeat is scheduled.
	 *
	 * @since 1.8.0
	 */
	public static function maybe_schedule(): void {

		if ( ! QueueManager::is_available() ) {
			return;
		}

		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::HOOK, [] ) ) {
			return;
		}

		as_schedule_recurring_action( time() + WEEK_IN_SECONDS, WEEK_IN_SECONDS, self::HOOK, [], 'activelayer', true );
	}

	/**
	 * Re-send site metadata for the configured key.
	 *
	 * @since 1.8.0
	 */
	public static function run(): void {

		$api_key = SettingsHelper::get_api_key();

		if ( $api_key === '' ) {
			return;
		}

		// verify_key() carries the metadata payload and logs its own failures;
		// a failed heartbeat is left to the next run rather than retried.
		( new ApiClient() )->verify_key( $api_key );
	}

	/**
	 * Cancel the scheduled heartbeat.
	 *
	 * @since 1.8.0
	 */
	public static function unschedule(): void {

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK );
	}
}

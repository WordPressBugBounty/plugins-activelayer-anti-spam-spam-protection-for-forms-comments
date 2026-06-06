<?php

namespace ActiveLayer\ActionScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActionScheduler_Versions;

/**
 * Class ActionSchedulerLoader.
 *
 * Safely loads Action Scheduler without conflicts.
 *
 * @since 1.0.0
 */
class ActionSchedulerLoader {

	/**
	 * Initialize Action Scheduler loader.
	 *
	 * @since 1.0.0
	 */
	public static function init(): void {

		// Only load if Action Scheduler is not already loaded by another plugin.
		if ( self::is_loaded() ) {
			return;
		}

		self::load_action_scheduler();
	}

	/**
	 * Check if Action Scheduler is already loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if loaded, false otherwise.
	 */
	private static function is_loaded(): bool {

		$loaded = class_exists( 'ActionScheduler' ) || function_exists( 'as_enqueue_async_action' );

		/**
		 * Filter the Action Scheduler loaded state.
		 *
		 * Used primarily within automated tests.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $loaded Whether Action Scheduler APIs are already available.
		 */
		return (bool) apply_filters( 'activelayer_action_scheduler_action_scheduler_loader_is_loaded', $loaded );
	}

	/**
	 * Load Action Scheduler from vendor directory.
	 *
	 * @since 1.0.0
	 */
	private static function load_action_scheduler(): void {

		// Check if vendor directory exists.
		$vendor_file = dirname( __DIR__, 2 ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

		/**
		 * Filter the Action Scheduler vendor file path before it is loaded.
		 *
		 * @since 1.0.0
		 *
		 * @param string $vendor_file Absolute path to the Action Scheduler bootstrap file.
		 */
		$vendor_file = (string) apply_filters( 'activelayer_action_scheduler_action_scheduler_loader_loader_file', $vendor_file );

		if ( empty( $vendor_file ) || ! file_exists( $vendor_file ) ) {
			return;
		}

		require_once $vendor_file;

		self::maybe_initialize_action_scheduler();

		/**
		 * Fires once Action Scheduler assets have been loaded.
		 *
		 * @since 1.0.0
		 *
		 * @param string $vendor_file Path that was required.
		 */
		do_action( 'activelayer_action_scheduler_action_scheduler_loader_loaded', $vendor_file );
	}

	/**
	 * Check if Action Scheduler is available after loading.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if available, false otherwise.
	 */
	public static function is_available(): bool {

		$available = function_exists( 'as_enqueue_async_action' );

		/**
		 * Filter whether Action Scheduler APIs are available.
		 *
		 * Primarily helpful for automated tests.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $available Whether APIs are callable.
		 */
		return (bool) apply_filters( 'activelayer_action_scheduler_action_scheduler_loader_is_available', $available );
	}

	/**
	 * Initialize Action Scheduler immediately when loaded during the plugins_loaded hook.
	 *
	 * Action Scheduler registers its own bootstrap callbacks on plugins_loaded priority 0.
	 * Because we include it at priority 10, those callbacks would not run until the next
	 * request. This ensures the procedural API (e.g. as_enqueue_async_action) is available
	 * on the current request as well.
	 *
	 * @since 1.0.0
	 */
	private static function maybe_initialize_action_scheduler(): void {

		/**
		 * Filter whether Action Scheduler should be initialized immediately.
		 *
		 * Allows tests or other plugins to prevent eager initialization on the
		 * current request.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $should_initialize Whether to initialize Action Scheduler right away.
		 */
		$should_initialize = (bool) apply_filters(
			'activelayer_action_scheduler_action_scheduler_loader_should_initialize',
			! function_exists( 'as_enqueue_async_action' )
		);

		if ( ! $should_initialize ) {
			return;
		}

		if ( ! did_action( 'plugins_loaded' ) ) {
			return;
		}

		if ( function_exists( 'action_scheduler_register_3_dot_9_dot_3' ) ) {
			action_scheduler_register_3_dot_9_dot_3();
		}

		if ( class_exists( 'ActionScheduler_Versions' ) ) {
			ActionScheduler_Versions::initialize_latest_version();
		} elseif ( function_exists( 'action_scheduler_initialize_3_dot_9_dot_3' ) ) {
			action_scheduler_initialize_3_dot_9_dot_3();
		}
	}
}

<?php
/**
 * Frontend script loader for client-side signals.
 *
 * @package ActiveLayer\ClientSignals
 * @since   1.1.0
 */

namespace ActiveLayer\ClientSignals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;

/**
 * ScriptLoader class.
 *
 * Enqueues the environment-detector.js and behavioral-collector.js scripts
 * on frontend pages when ActiveLayer is configured and enabled.
 *
 * @since 1.1.0
 */
class ScriptLoader {

	/**
	 * Script handle for the environment detector.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const ENV_SCRIPT_HANDLE = 'activelayer-environment-detector';

	/**
	 * Script handle for the behavioral collector.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const BEHAVIORAL_SCRIPT_HANDLE = 'activelayer-behavioral-collector';

	/**
	 * Whether scripts have already been enqueued for this request.
	 *
	 * @since 1.1.0
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Initialize the script loader.
	 *
	 * Retained for bootstrapping consistency; script enqueueing is now
	 * handled on-demand via the static enqueue_now() method.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Hook placeholder for future use.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function hooks(): void {
		// Scripts are enqueued on-demand via enqueue_now() when a protected form renders.
	}

	/**
	 * Enqueue frontend scripts.
	 *
	 * Only enqueues when ActiveLayer has an API key configured.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {

		self::enqueue_now();
	}

	/**
	 * Enqueue frontend scripts on demand.
	 *
	 * Called by FieldRenderer when a protected form renders on the page.
	 * Uses a static flag to ensure scripts are enqueued only once per request,
	 * even when multiple forms are present.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function enqueue_now(): void {

		if ( self::$enqueued ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		self::$enqueued = true;

		self::register_scripts();
	}

	/**
	 * Register and enqueue client-side signal scripts.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private static function register_scripts(): void {

		// Use minified files in production, regular files in debug mode.
		$suffix = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';

		// Enqueue environment detector if enabled.
		if ( SettingsHelper::is_environment_tracking_enabled() ) {
			wp_enqueue_script(
				self::ENV_SCRIPT_HANDLE,
				ACTIVELAYER_PLUGIN_URL . 'assets/js/environment-detector' . $suffix . '.js',
				[],
				ACTIVELAYER_PLUGIN_VERSION,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}

		// Enqueue behavioral collector if enabled.
		if ( SettingsHelper::is_behavioral_tracking_enabled() ) {
			wp_enqueue_script(
				self::BEHAVIORAL_SCRIPT_HANDLE,
				ACTIVELAYER_PLUGIN_URL . 'assets/js/behavioral-collector' . $suffix . '.js',
				[],
				ACTIVELAYER_PLUGIN_VERSION,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}
	}

	/**
	 * Reset the enqueued state.
	 *
	 * Used in tests to reset the static flag between test runs.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function reset_enqueued_state(): void {

		self::$enqueued = false;
	}
}

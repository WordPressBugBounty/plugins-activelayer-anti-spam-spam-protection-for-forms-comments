<?php

namespace ActiveLayer\Admin;

use ActiveLayer\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects plugin upgrades and manages opt-out default announcement state.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Admin
 */
class UpgradeRunner {

	/**
	 * Option name for tracking the current plugin version.
	 *
	 * @since 1.3.0
	 */
	public const OPTION_VERSION = 'activelayer_plugin_version';

	/**
	 * Option name for opt-out announce flag.
	 *
	 * @since 1.3.0
	 */
	public const OPTION_ANNOUNCE_REQUIRED = 'activelayer_opt_out_announce_required';

	/**
	 * Run upgrade detection and set flags if needed.
	 *
	 * Idempotent — safe to call multiple times. Once the stored version matches
	 * ACTIVELAYER_PLUGIN_VERSION, subsequent calls are no-ops.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function run(): void {

		if ( ! defined( 'ACTIVELAYER_PLUGIN_VERSION' ) ) {
			return;
		}

		$stored_raw     = get_option( self::OPTION_VERSION, '' );
		$stored_version = is_scalar( $stored_raw ) ? trim( (string) $stored_raw ) : '';

		if ( $stored_version === ACTIVELAYER_PLUGIN_VERSION ) {
			return;
		}

		if ( $stored_version === '' ) {
			self::maybe_announce_for_upgrade();
		}

		$result = update_option( self::OPTION_VERSION, ACTIVELAYER_PLUGIN_VERSION );

		if ( ! $result ) {
			Logger::log(
				'UpgradeRunner: failed to update plugin version marker',
				[
					'option_name' => self::OPTION_VERSION,
					'version'     => ACTIVELAYER_PLUGIN_VERSION,
				]
			);
		}
	}

	/**
	 * Set the announce flag when an existing install (with API key) upgrades.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private static function maybe_announce_for_upgrade(): void {

		$settings = get_option( 'activelayer_global_settings', [] );

		if ( is_array( $settings ) && ! empty( $settings['api_key'] ) ) {
			update_option( self::OPTION_ANNOUNCE_REQUIRED, true );
		}
	}
}

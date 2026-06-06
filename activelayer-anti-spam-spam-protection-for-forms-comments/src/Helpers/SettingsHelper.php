<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper utilities for accessing plugin settings.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Helpers
 */
class SettingsHelper {

	/**
	 * Option name for storing global settings.
	 *
	 * @since 1.0.0
	 */
	private const OPTION_NAME = 'activelayer_global_settings';

	/**
	 * Setting key for API key.
	 *
	 * @since 1.0.0
	 */
	public const KEY_API = 'api_key';

	/**
	 * Setting key for debug logging.
	 *
	 * @since 1.0.0
	 */
	public const KEY_ENABLE_LOGGING = 'enable_logging';

	/**
	 * Setting key for synchronous mode.
	 *
	 * @since 1.0.0
	 */
	public const KEY_SYNC_MODE = 'sync_mode';

	/**
	 * Setting key for environment tracking.
	 *
	 * @since 1.1.0
	 */
	public const KEY_ENVIRONMENT_TRACKING = 'enable_environment_tracking';

	/**
	 * Setting key for behavioral tracking.
	 *
	 * @since 1.1.0
	 */
	public const KEY_BEHAVIORAL_TRACKING = 'enable_behavioral_tracking';

	/**
	 * Setting key for honeypot tracking.
	 *
	 * @since 1.1.0
	 */
	public const KEY_HONEYPOT_TRACKING = 'enable_honeypot_tracking';

	/**
	 * Setting key for submission retention period in days.
	 *
	 * @since 1.1.0
	 */
	public const KEY_RETENTION_DAYS = 'retention_days';

	/**
	 * Allowed retention period options in days.
	 *
	 * 0 means "Never" (keep indefinitely).
	 *
	 * @since 1.1.0
	 *
	 * @var int[]
	 */
	public const ALLOWED_RETENTION_DAYS = [ 0, 30, 60, 90, 180, 365 ];

	/**
	 * Default global settings shape.
	 *
	 * @since 1.0.0
	 */
	private const DEFAULT_SETTINGS = [
		self::KEY_API                  => '',
		self::KEY_ENABLE_LOGGING       => false,
		self::KEY_SYNC_MODE            => false,
		self::KEY_ENVIRONMENT_TRACKING => true,
		self::KEY_BEHAVIORAL_TRACKING  => true,
		self::KEY_HONEYPOT_TRACKING    => true,
		self::KEY_RETENTION_DAYS       => 30,
	];

	/**
	 * Retrieve global settings array.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_global_settings(): array {

		$settings = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		return wp_parse_args( $settings, self::DEFAULT_SETTINGS );
	}

	/**
	 * Get configured API key, trimmed of whitespace.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 *
	 * @return string
	 */
	public static function get_api_key( ?array $settings = null ): string {

		$settings = $settings ?? self::get_global_settings();

		$api_key = $settings[ self::KEY_API ] ?? '';

		if ( ! is_string( $api_key ) ) {
			return '';
		}

		return trim( $api_key );
	}

	/**
	 * Check whether an API key is configured.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 *
	 * @return bool
	 */
	public static function has_api_key( ?array $settings = null ): bool {

		return self::get_api_key( $settings ) !== '';
	}

	/**
	 * Check whether debug logging is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled( ?array $settings = null ): bool {

		$settings = $settings ?? self::get_global_settings();

		return ! empty( $settings[ self::KEY_ENABLE_LOGGING ] );
	}

	/**
	 * Check whether synchronous mode is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 *
	 * @return bool
	 */
	public static function is_sync_mode_enabled( ?array $settings = null ): bool {

		$settings = $settings ?? self::get_global_settings();

		return ! empty( $settings[ self::KEY_SYNC_MODE ] );
	}

	/**
	 * Check whether environment tracking is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 *
	 * @return bool
	 */
	public static function is_environment_tracking_enabled( ?array $settings = null ): bool {

		$settings = $settings ?? self::get_global_settings();

		// Default to true if not explicitly set to false.
		if ( ! isset( $settings[ self::KEY_ENVIRONMENT_TRACKING ] ) ) {
			return true;
		}

		return ! empty( $settings[ self::KEY_ENVIRONMENT_TRACKING ] );
	}

	/**
	 * Check whether behavioral tracking is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 *
	 * @return bool
	 */
	public static function is_behavioral_tracking_enabled( ?array $settings = null ): bool {

		$settings = $settings ?? self::get_global_settings();

		// Default to true if not explicitly set to false.
		if ( ! isset( $settings[ self::KEY_BEHAVIORAL_TRACKING ] ) ) {
			return true;
		}

		return ! empty( $settings[ self::KEY_BEHAVIORAL_TRACKING ] );
	}

	/**
	 * Check whether honeypot tracking is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 *
	 * @return bool
	 */
	public static function is_honeypot_tracking_enabled( ?array $settings = null ): bool {

		$settings = $settings ?? self::get_global_settings();

		// Default to true if not explicitly set to false.
		if ( ! isset( $settings[ self::KEY_HONEYPOT_TRACKING ] ) ) {
			return true;
		}

		return ! empty( $settings[ self::KEY_HONEYPOT_TRACKING ] );
	}

	/**
	 * Get the configured submission retention period in days.
	 *
	 * Returns 0 when retention is disabled (submissions are kept indefinitely).
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 *
	 * @return int Number of days to retain submissions, or 0 for no retention.
	 */
	public static function get_retention_days( ?array $settings = null ): int {

		$settings = $settings ?? self::get_global_settings();

		return (int) ( $settings[ self::KEY_RETENTION_DAYS ] ?? 0 );
	}
}

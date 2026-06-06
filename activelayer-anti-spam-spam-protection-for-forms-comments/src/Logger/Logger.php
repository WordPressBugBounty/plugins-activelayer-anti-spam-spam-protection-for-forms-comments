<?php

namespace ActiveLayer\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;

/**
 * Simple Logger - stores in database, Plugin Check compliant.
 *
 * @since 1.0.0
 */
class Logger {

	/**
	 * Add log entry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param array  $data    Optional data.
	 */
	public static function log( string $message, array $data = [] ): void {

		if ( ! SettingsHelper::is_logging_enabled() ) {
			return;
		}

		$logs = get_option( 'activelayer_logs', [] );

		$logs[] = [
			'time'    => current_time( 'mysql' ),
			'message' => $message,
			'data'    => self::truncate_data( $data ),
		];

		// Keep last 200 entries.
		if ( count( $logs ) > 200 ) {
			$logs = array_slice( $logs, -200 );
		}

		update_option( 'activelayer_logs', $logs, false );
	}

	/**
	 * Get all logs.
	 *
	 * @since 1.0.0
	 *
	 * @return array Logs.
	 */
	public static function get_logs(): array {

		$logs = get_option( 'activelayer_logs', [] );

		return array_reverse( $logs );
	}

	/**
	 * Truncate data values to prevent oversized log entries.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data Log data.
	 * @param int   $max  Maximum string length per value.
	 *
	 * @return array Truncated data.
	 */
	private static function truncate_data( array $data, int $max = 500 ): array {

		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) && strlen( $value ) > $max ) {
				$data[ $key ] = substr( $value, 0, $max ) . '...(truncated)';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::truncate_data( $value, $max );
			}
		}

		return $data;
	}

	/**
	 * Clear logs.
	 *
	 * @since 1.0.0
	 */
	public static function clear(): void {

		update_option( 'activelayer_logs', [], false );
	}
}

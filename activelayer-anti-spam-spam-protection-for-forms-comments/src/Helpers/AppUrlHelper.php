<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper for building URLs to the ActiveLayer application.
 *
 * Centralizes register URL construction so every CTA leaving the plugin
 * carries the `from=wp-plugin` source signal alongside the canonical UTM set.
 * The signal allows the application to detect plugin-originated sign-ups and
 * auto-provision a default project + API key during registration.
 *
 * @since 1.2.0
 */
class AppUrlHelper {

	/**
	 * Base register URL on the ActiveLayer application.
	 *
	 * @since 1.2.0
	 */
	private const REGISTER_URL = 'https://app.activelayer.com/account/register';

	/**
	 * Dedicated source signal value attached to register URLs.
	 *
	 * Survives UTM stripping and intermediate redirects so the application
	 * can identify plugin-originated registrations reliably.
	 *
	 * @since 1.2.0
	 */
	private const SOURCE_VALUE = 'wp-plugin';

	/**
	 * Build a register URL with the source signal and canonical UTM parameters.
	 *
	 * @since 1.2.0
	 *
	 * @param string $utm_medium  Identifies the surface that hosted the CTA (e.g. connection_bar, dashboard_widget).
	 * @param string $utm_content Identifies the specific CTA placement (e.g. create_account).
	 *
	 * @return string Full register URL.
	 */
	public static function get_register_url( string $utm_medium, string $utm_content ): string {

		return add_query_arg(
			[
				'from'         => self::SOURCE_VALUE,
				'utm_campaign' => 'plugin',
				'utm_source'   => 'WordPress',
				'utm_medium'   => $utm_medium,
				'utm_content'  => $utm_content,
				'utm_locale'   => get_locale(),
			],
			self::REGISTER_URL
		);
	}
}

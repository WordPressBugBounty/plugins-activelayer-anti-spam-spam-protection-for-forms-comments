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
	 * Default base URL of the ActiveLayer application.
	 *
	 * @since 1.3.0
	 */
	private const DEFAULT_APP_BASE = 'https://app.activelayer.com';

	/**
	 * Path of the register endpoint on the application.
	 *
	 * @since 1.3.0
	 */
	private const REGISTER_PATH = '/account/register';

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
	 * @since 1.3.0 Derives the endpoint from get_app_base() (override-aware).
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
			self::get_app_base() . self::REGISTER_PATH
		);
	}

	/**
	 * Get the base URL of the ActiveLayer application.
	 *
	 * Override by defining ACTIVELAYER_APP_URL in wp-config.php (used by the
	 * e2e suite to point the flow at the mock app). Mirrors the
	 * ACTIVELAYER_API_URL override convention.
	 *
	 * @since 1.3.0
	 *
	 * @return string Base URL without a trailing slash.
	 */
	public static function get_app_base(): string {

		if ( defined( 'ACTIVELAYER_APP_URL' ) && is_string( ACTIVELAYER_APP_URL ) && ACTIVELAYER_APP_URL !== '' ) {
			return rtrim( ACTIVELAYER_APP_URL, '/' );
		}

		return self::DEFAULT_APP_BASE;
	}

	/**
	 * Build the one-click Connect URL: the register URL plus PKCE + return parameters.
	 *
	 * @since 1.3.0
	 *
	 * @param string $utm_medium     Surface that hosted the CTA.
	 * @param string $utm_content    Specific CTA placement.
	 * @param string $code_challenge PKCE challenge: hex-encoded SHA-256 of the private code_verifier. Deliberately hex (matches the app-side hash_equals check), not RFC 7636 base64url S256.
	 * @param string $site_url       Originating site URL (home_url()).
	 * @param string $return_url     WP-admin URL to return to after registration.
	 *
	 * @return string Full connect URL.
	 */
	public static function get_connect_url( string $utm_medium, string $utm_content, string $code_challenge, string $site_url, string $return_url ): string {

		return add_query_arg(
			[
				'code_challenge' => $code_challenge,
				'site_url'       => $site_url,
				'return_url'     => $return_url,
			],
			self::get_register_url( $utm_medium, $utm_content )
		);
	}
}

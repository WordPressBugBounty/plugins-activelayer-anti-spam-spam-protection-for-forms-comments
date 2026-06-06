<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Subscription\SubscriptionStats;

/**
 * Helper for upgrade URLs and usage limit awareness.
 *
 * Centralizes upgrade link generation and usage threshold checks
 * for product education features.
 *
 * @since 1.1.0
 */
class UpgradeHelper {

	/**
	 * Usage percentage threshold for warning state.
	 *
	 * @since 1.1.0
	 */
	private const WARNING_THRESHOLD = 80;

	/**
	 * Usage percentage threshold for exhausted state.
	 *
	 * @since 1.1.0
	 */
	private const EXHAUSTED_THRESHOLD = 100;

	/**
	 * Option key for dismissed usage limit banner.
	 *
	 * @since 1.1.0
	 */
	public const OPTION_BANNER_DISMISSED = 'activelayer_usage_banner_dismissed';

	/**
	 * Build an upgrade URL with UTM parameters.
	 *
	 * @since 1.1.0
	 *
	 * @param string $utm_content Identifies the CTA placement.
	 *
	 * @return string Full upgrade URL.
	 */
	public static function get_upgrade_url( string $utm_content = 'upgrade_cta' ): string {

		return add_query_arg(
			[
				'utm_campaign' => 'plugin',
				'utm_source'   => 'WordPress',
				'utm_medium'   => 'freeplugin',
				'utm_content'  => $utm_content,
				'utm_locale'   => get_locale(),
			],
			'https://activelayer.com/pricing/'
		);
	}

	/**
	 * Check if current plan is a free plan.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $stats Optional pre-fetched stats.
	 *
	 * @return bool True if user is on a free plan.
	 */
	public static function is_free_plan( ?array $stats = null ): bool {

		$stats = $stats ?? self::get_stats();

		if ( ! ( $stats['success'] ?? false ) ) {
			return false;
		}

		$plan_name = strtolower( $stats['plan_name'] ?? '' );

		return strpos( $plan_name, 'free' ) !== false;
	}

	/**
	 * Check if quota is exhausted using cached stats only.
	 *
	 * Unlike is_usage_exhausted(), this method NEVER triggers an API request.
	 * Returns false when cache is empty — safe-by-default.
	 *
	 * Intended for use in frontend submission paths where HTTP latency
	 * must be avoided.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if cached stats show usage >= 100%.
	 */
	public static function is_quota_exhausted_cached(): bool {

		if ( ! SettingsHelper::has_api_key() ) {
			return false;
		}

		$cached = get_transient( SubscriptionStats::CACHE_KEY );

		if ( ! is_array( $cached ) || empty( $cached['success'] ) ) {
			return false;
		}

		return ( (float) ( $cached['usage_percentage'] ?? 0 ) ) >= self::EXHAUSTED_THRESHOLD;
	}

	/**
	 * Check if usage has reached the exhausted threshold (100%).
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $stats Optional pre-fetched stats.
	 *
	 * @return bool True if usage is at or above 100%.
	 */
	public static function is_usage_exhausted( ?array $stats = null ): bool {

		$stats = $stats ?? self::get_stats();

		if ( ! ( $stats['success'] ?? false ) ) {
			return false;
		}

		return ( (float) ( $stats['usage_percentage'] ?? 0 ) ) >= self::EXHAUSTED_THRESHOLD;
	}

	/**
	 * Check if usage is nearing the limit (at or above warning threshold).
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $stats Optional pre-fetched stats.
	 *
	 * @return bool True if usage is at or above warning threshold.
	 */
	public static function is_usage_warning( ?array $stats = null ): bool {

		$stats = $stats ?? self::get_stats();

		if ( ! ( $stats['success'] ?? false ) ) {
			return false;
		}

		return ( (float) ( $stats['usage_percentage'] ?? 0 ) ) >= self::WARNING_THRESHOLD;
	}

	/**
	 * Check if the usage limit banner has been dismissed for the current billing period.
	 *
	 * For lifetime plans, the banner is dismissed once permanently.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $stats Optional pre-fetched stats.
	 *
	 * @return bool True if banner was dismissed for this period.
	 */
	public static function is_banner_dismissed( ?array $stats = null ): bool {

		$stats     = $stats ?? self::get_stats();
		$dismissed = get_option( self::OPTION_BANNER_DISMISSED, [] );

		if ( ! is_array( $dismissed ) ) {
			return false;
		}

		$period_key = self::get_period_key( $stats );

		return $period_key === ( $dismissed['period'] ?? '' );
	}

	/**
	 * Dismiss the usage limit banner for the current billing period.
	 *
	 * For lifetime plans, dismisses permanently.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $stats Optional pre-fetched stats.
	 */
	public static function dismiss_banner( ?array $stats = null ): void {

		$stats      = $stats ?? self::get_stats();
		$period_key = self::get_period_key( $stats );

		update_option(
			self::OPTION_BANNER_DISMISSED,
			[
				'period'    => $period_key,
				'dismissed' => time(),
			],
			false
		);
	}

	/**
	 * Check if the plan uses a lifetime quota instead of monthly renewal.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $stats Optional pre-fetched stats.
	 *
	 * @return bool True if period type is lifetime.
	 */
	public static function is_lifetime_plan( ?array $stats = null ): bool {

		$stats = $stats ?? self::get_stats();

		return ( $stats['period_type'] ?? 'monthly' ) === 'lifetime';
	}

	/**
	 * Get a stable key identifying the current billing period.
	 *
	 * Returns 'lifetime' for lifetime plans, 'YYYY-MM' for monthly.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $stats Optional pre-fetched stats.
	 *
	 * @return string Period key.
	 */
	private static function get_period_key( ?array $stats = null ): string {

		$stats = $stats ?? self::get_stats();

		if ( self::is_lifetime_plan( $stats ) ) {
			return 'lifetime';
		}

		return ( $stats['period_year'] ?? 0 ) . '-' . ( $stats['period_month'] ?? 0 );
	}

	/**
	 * Get a human-readable billing period label (e.g., "February 2026").
	 *
	 * Returns empty string for lifetime plans since they have no billing period.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $stats Optional pre-fetched stats.
	 *
	 * @return string Period label or empty string if unavailable.
	 */
	public static function get_period_label( ?array $stats = null ): string {

		$stats = $stats ?? self::get_stats();

		if ( self::is_lifetime_plan( $stats ) ) {
			return '';
		}

		$month = (int) ( $stats['period_month'] ?? 0 );
		$year  = (int) ( $stats['period_year'] ?? 0 );

		if ( $month < 1 || $month > 12 || $year < 2000 ) {
			return '';
		}

		$timestamp = mktime( 0, 0, 0, $month, 1, $year );

		return wp_date( 'F Y', $timestamp );
	}

	/**
	 * Get subscription stats (cached).
	 *
	 * @since 1.1.0
	 *
	 * @return array Stats array.
	 */
	private static function get_stats(): array {

		if ( ! SettingsHelper::has_api_key() ) {
			return [ 'success' => false ];
		}

		return SubscriptionStats::get_instance()->get_stats();
	}
}

<?php

namespace ActiveLayer\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Api\ApiRequestHandler;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;

/**
 * Subscription Statistics Manager.
 *
 * Handles fetching and caching of subscription statistics from the API.
 * Implements smart caching to minimize API calls.
 *
 * @since 1.0.0
 */
class SubscriptionStats {

	/**
	 * Cache key for subscription stats.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'activelayer_subscription_stats';

	/**
	 * Cache expiration time in seconds (1 hour).
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	public const CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubscriptionStats
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return SubscriptionStats
	 */
	public static function get_instance(): SubscriptionStats {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor for singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Get subscription statistics.
	 *
	 * Returns cached data if available and fresh, otherwise fetches from API.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force_refresh Force refresh from API, bypass cache.
	 *
	 * @return array Subscription stats.
	 */
	public function get_stats( bool $force_refresh = false ): array {

		// Try to get from cache first.
		if ( ! $force_refresh ) {
			$cached = $this->get_cached_stats();

			if ( $cached !== null ) {
				return $cached;
			}
		}

		// Fetch fresh data from API.
		return $this->fetch_and_cache_stats();
	}

	/**
	 * Get cached subscription stats.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Cached stats or null if not found/expired.
	 */
	private function get_cached_stats(): ?array {

		$cached = get_transient( self::CACHE_KEY );

		if ( $cached === false ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Fetch subscription stats from API and cache them.
	 *
	 * @since 1.0.0
	 *
	 * @return array Subscription stats.
	 */
	private function fetch_and_cache_stats(): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$stats = $this->get_subscription_stats();

		if ( $stats['success'] ?? false ) {
			// Cache successful response.
			set_transient( self::CACHE_KEY, $stats, self::CACHE_EXPIRATION );

			Logger::log(
                'Subscription stats fetched',
                [
					'plan'      => $stats['plan_name'] ?? 'unknown',
					'remaining' => $stats['requests_remaining'] ?? 0,
                ]
            );
		} else {
			// On error, try to return stale cache if available.
			$cached = get_transient( self::CACHE_KEY );

			if ( $cached !== false ) {
				Logger::log(
                    'Using stale subscription cache due to API error',
                    [
						'error' => $stats['error'] ?? 'unknown error',
                    ]
                );

				return $cached;
			}

			Logger::log(
                'Subscription stats fetch failed',
                [
					'error' => $stats['error'] ?? 'unknown error',
                ]
            );
		}

		return $stats;
	}

	/**
	 * Fetch subscription statistics from API.
	 *
	 * Gets user's plan details, usage statistics, and quota information.
	 *
	 * @since 1.0.0
	 *
	 * @return array Subscription stats or error.
	 */
	private function get_subscription_stats(): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded -- Complexity is necessary for this method.

		if ( ! SettingsHelper::has_api_key() ) {
			return [
				'success' => false,
				'error'   => __( 'API key not configured', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];
		}

		// Make GET request to /stats endpoint.
		$handler  = new ApiRequestHandler(
			ACTIVELAYER_API_URL,
			SettingsHelper::get_api_key()
		);
		$response = $handler->request( '/stats', [], null, 'GET' );

		// Handle WP_Error response.
		if ( is_wp_error( $response ) ) {
			Logger::log(
                'Subscription stats fetch error',
                [
					'error' => $response->get_error_message(),
                ]
            );

			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		// Parse and return subscription data.
		$data = $response['data'] ?? [];

		$period      = $data['period'] ?? [];
		$period_type = $period['type'] ?? 'monthly';

		return [
			'success'            => (bool) ( $response['success'] ?? false ),
			'plan_name'          => $data['plan']['name'] ?? 'Unknown',
			'plan_id'            => $data['plan']['id'] ?? 0,
			'requests_limit'     => $data['usage']['total_requests'] ?? 0,
			'requests_used'      => $data['usage']['used_requests'] ?? 0,
			'requests_remaining' => $data['usage']['remaining_requests'] ?? 0,
			'usage_percentage'   => $data['usage']['usage_percentage'] ?? 0,
			'period_type'        => $period_type,
			'period_month'       => $period['month'] ?? 0,
			'period_year'        => $period['year'] ?? 0,
			'timestamp'          => $response['timestamp'] ?? '',
			'raw_response'       => $response,
		];
	}

	/**
	 * Schedule background refresh of subscription stats.
	 *
	 * This method should be called when a spam check is performed
	 * to keep stats updated without blocking the main request.
	 *
	 * @since 1.0.0
	 */
	public function schedule_refresh(): void {

		// Schedule immediate background fetch (skip if one is already pending).
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'activelayer_refresh_subscription_stats', [], 'activelayer' ) ) {
				return;
			}

			as_enqueue_async_action(
				'activelayer_refresh_subscription_stats',
				[],
				'activelayer'
			);

			Logger::log( 'Scheduled subscription stats refresh', [] );
		} else {
			// If Action Scheduler not available, fetch synchronously.
			$this->fetch_and_cache_stats();
		}
	}

	/**
	 * Process background refresh job.
	 *
	 * Called by Action Scheduler to refresh stats in background.
	 *
	 * @since 1.0.0
	 */
	public function process_refresh(): void {

		Logger::log( 'Processing subscription stats refresh', [] );
		$this->fetch_and_cache_stats();
	}

	/**
	 * Clear cached subscription stats.
	 *
	 * @since 1.0.0
	 */
	public function clear_cache(): void {

		delete_transient( self::CACHE_KEY );
		Logger::log( 'Subscription stats cache cleared', [] );
	}

	/**
	 * Check if subscription stats are available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if stats are available (cached or can be fetched).
	 */
	public function has_stats(): bool {

		$cached = get_transient( self::CACHE_KEY );

		if ( $cached !== false && ( $cached['success'] ?? false ) ) {
			return true;
		}

		// Check if API key is configured.
		return SettingsHelper::has_api_key();
	}
}

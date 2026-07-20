<?php
/**
 * Assembles aggregated statistics for the Abilities API.
 *
 * @package ActiveLayer
 */

namespace ActiveLayer\Abilities;

/**
 * Pure transformer: raw stats arrays -> get-stats output.
 *
 * @since 1.6.0
 */
class StatsFormatter {

	/**
	 * Build the stats payload.
	 *
	 * @since 1.6.0
	 *
	 * @param array      $queue_stats  Output of Storage::get_queue_stats().
	 * @param array      $daily        Output of Storage::get_daily_counts().
	 * @param array|null $subscription Output of SubscriptionStats::get_stats(), or null.
	 *
	 * @return array Stats payload (totals, daily, optional subscription).
	 */
	public function build( array $queue_stats, array $daily, ?array $subscription ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$payload = [
			'totals' => $queue_stats,
			'daily'  => array_values( $daily ),
		];

		if ( is_array( $subscription ) && ! empty( $subscription['success'] ) ) {
			$payload['subscription'] = [
				'plan_name'          => (string) ( $subscription['plan_name'] ?? '' ),
				'requests_limit'     => (int) ( $subscription['requests_limit'] ?? 0 ),
				'requests_used'      => (int) ( $subscription['requests_used'] ?? 0 ),
				'requests_remaining' => (int) ( $subscription['requests_remaining'] ?? 0 ),
				'usage_percentage'   => (float) ( $subscription['usage_percentage'] ?? 0 ),
				// Preserve the SubscriptionStats tri-state (true/false/null); null = unknown card state, do not coerce.
				'has_payment_method' => $subscription['has_payment_method'] ?? null,
			];
		}

		return $payload;
	}
}

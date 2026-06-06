<?php

namespace ActiveLayer\Storage\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Storage\SchemaManager;
use ActiveLayer\Storage\SubmissionCache;
use DateTimeImmutable;

/**
 * Handles analytics and statistics for submissions.
 *
 * @since 1.0.0
 */
class SubmissionAnalytics {

	/**
	 * Schema manager instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SchemaManager
	 */
	private $schema_manager;

	/**
	 * Cache helper instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionCache
	 */
	private $cache;

	/**
	 * Submission analytics constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param SchemaManager   $schema_manager Schema manager instance.
	 * @param SubmissionCache $cache          Cache helper instance.
	 */
	public function __construct( SchemaManager $schema_manager, SubmissionCache $cache ) {

		$this->schema_manager = $schema_manager;
		$this->cache          = $cache;
	}

	/**
	 * Get distinct providers from submissions.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of provider names.
	 */
	public function get_distinct_providers(): array {

		if ( ! $this->schema_manager->table_exists() ) {
			return [];
		}

		$cache_key = 'distinct_providers_' . $this->cache->get_list_cache_version();
		$cached    = wp_cache_get( $cache_key, $this->cache->get_cache_group() );

		if ( $cached !== false ) {
			return $cached;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$results = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT DISTINCT provider FROM `$table_name` WHERE provider != %s ORDER BY provider ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				''
			)
		);

		$providers = $results ? $results : [];

		wp_cache_set( $cache_key, $providers, $this->cache->get_cache_group(), 5 * MINUTE_IN_SECONDS );

		return $providers;
	}

	/**
	 * Count pending submissions older than the given threshold.
	 *
	 * @since 1.0.0
	 *
	 * @param int $threshold_seconds Threshold in seconds.
	 *
	 * @return int Count of pending submissions older than threshold.
	 */
	public function count_pending_older_than( int $threshold_seconds ): int {

		if ( $threshold_seconds <= 0 ) {
			return 0;
		}

		if ( ! $this->schema_manager->table_exists() ) {
			return 0;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$threshold_time = time() - $threshold_seconds;

		if ( $threshold_time <= 0 ) {
			return 0;
		}

		$threshold_date = wp_date( 'Y-m-d H:i:s', $threshold_time );

		if ( $threshold_date === false ) {
			return 0;
		}

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast COUNT query runs only for watchdog checks.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table_name` WHERE status = 'pending' AND created_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold_date
			)
		);

		return (int) $count;
	}

	/**
	 * Get basic queue statistics for dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @return array Basic stats array; returns zeros when the table does not exist.
	 */
	public function get_queue_stats(): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->schema_manager->table_exists() ) {
			return [
				'total'               => 0,
				'pending'             => 0,
				'clean'               => 0,
				'spam'                => 0,
				'failed'              => 0,
				'trash'               => 0,
				'accuracy_percentage' => 0,
				'total_with_verdict'  => 0,
			];
		}

		$cache_key = 'queue_stats';
		$cached    = wp_cache_get( $cache_key, $this->cache->get_cache_group() );

		if ( $cached !== false ) {
			return $cached;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$result = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as pending,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as clean,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as spam,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as failed,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as trash
				FROM `$table_name`", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'pending',
				'clean',
				'spam',
				'failed',
				'trash'
			),
			ARRAY_A
		);

		if ( ! $result ) {
			return [
				'total'               => 0,
				'pending'             => 0,
				'clean'               => 0,
				'spam'                => 0,
				'failed'              => 0,
				'trash'               => 0,
				'total_with_verdict'  => 0,
				'accuracy_percentage' => 0,
			];
		}

		$accuracy_result = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_with_verdict,
					SUM(CASE
						WHEN (verdict = %s AND status = %s) OR (verdict = %s AND status = %s)
						THEN 1
						ELSE 0
					END) as correct_predictions
				FROM `$table_name` WHERE verdict IS NOT NULL AND verdict != '' AND status IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'spam',
				'spam',
				'clean',
				'clean',
				'clean',
				'spam'
			),
			ARRAY_A
		);

		$total_with_verdict  = 0;
		$accuracy_percentage = 0;

		if ( $accuracy_result ) {
			$total_with_verdict  = (int) ( $accuracy_result['total_with_verdict'] ?? 0 );
			$correct_predictions = (int) ( $accuracy_result['correct_predictions'] ?? 0 );
			$accuracy_percentage = $total_with_verdict > 0 ? round( ( $correct_predictions / $total_with_verdict ) * 100, 1 ) : 0;
		}

		$stats = [
			'total'               => (int) $result['total'],
			'pending'             => (int) $result['pending'],
			'clean'               => (int) $result['clean'],
			'spam'                => (int) $result['spam'],
			'failed'              => (int) $result['failed'],
			'trash'               => (int) $result['trash'],
			'accuracy_percentage' => $accuracy_percentage,
			'total_with_verdict'  => $total_with_verdict,
		];

		wp_cache_set( $cache_key, $stats, $this->cache->get_cache_group(), 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Get daily submission counts grouped by status.
	 *
	 * Returns an array of daily totals for the given number of days,
	 * zero-filling any days with no submissions.
	 *
	 * @since 1.1.0
	 *
	 * @param int $days Number of days to look back (default 7).
	 *
	 * @return array[] Each element: ['date' => 'Y-m-d', 'total' => int, 'clean' => int, 'spam' => int, 'failed' => int].
	 */
	public function get_daily_counts( int $days = 7 ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->schema_manager->table_exists() ) {
			return [];
		}

		$cache_key = 'daily_counts_' . $days;
		$cached    = wp_cache_get( $cache_key, $this->cache->get_cache_group() );

		if ( $cached !== false ) {
			return $cached;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		// Use WordPress-aware date to avoid MySQL/PHP timezone mismatches.
		$start_date = wp_date( 'Y-m-d 00:00:00', strtotime( '-' . ( $days - 1 ) . ' days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name escaped via esc_sql().
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(created_at) AS date,
					COUNT(*) AS total,
					SUM(CASE WHEN status = 'spam' THEN 1 ELSE 0 END) AS spam,
					SUM(CASE WHEN status = 'clean' THEN 1 ELSE 0 END) AS clean,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
				FROM `$table_name`
				WHERE created_at >= %s
				GROUP BY DATE(created_at)
				ORDER BY date ASC",
				$start_date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Index results by date for quick lookup.
		$indexed = [];

		if ( $results ) {
			foreach ( $results as $row ) {
				$indexed[ $row['date'] ] = $row;
			}
		}

		// Zero-fill missing days.
		$counts = [];
		$today  = new DateTimeImmutable( wp_date( 'Y-m-d' ), wp_timezone() );

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = $today->modify( "-{$i} days" )->format( 'Y-m-d' );

			if ( isset( $indexed[ $date ] ) ) {
				$counts[] = [
					'date'   => $date,
					'total'  => (int) $indexed[ $date ]['total'],
					'clean'  => (int) $indexed[ $date ]['clean'],
					'spam'   => (int) $indexed[ $date ]['spam'],
					'failed' => (int) $indexed[ $date ]['failed'],
				];
			} else {
				$counts[] = [
					'date'   => $date,
					'total'  => 0,
					'clean'  => 0,
					'spam'   => 0,
					'failed' => 0,
				];
			}
		}

		wp_cache_set( $cache_key, $counts, $this->cache->get_cache_group(), 5 * MINUTE_IN_SECONDS );

		return $counts;
	}
}

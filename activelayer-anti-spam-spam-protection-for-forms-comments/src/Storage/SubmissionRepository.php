<?php

namespace ActiveLayer\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Storage\Analytics\SubmissionAnalytics;
use ActiveLayer\Storage\Query\SubmissionQueryBuilder;
use ActiveLayer\Storage\Status\SubmissionStatusManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Handles database CRUD and query logic for submissions.
 *
 * @since 1.0.0
 */
class SubmissionRepository {

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
	 * Query builder instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionQueryBuilder
	 */
	private $query_builder;

	/**
	 * Analytics instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionAnalytics
	 */
	private $analytics;

	/**
	 * Status manager instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionStatusManager
	 */
	private $status_manager;

	/**
	 * Submission repository constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param SchemaManager           $schema_manager Schema manager instance.
	 * @param SubmissionCache         $cache          Cache helper instance.
	 * @param SubmissionQueryBuilder  $query_builder  Query builder instance (optional, will be created if not provided).
	 * @param SubmissionAnalytics     $analytics      Analytics instance (optional, will be created if not provided).
	 * @param SubmissionStatusManager $status_manager Status manager instance (optional, will be created if not provided).
	 */
	public function __construct( SchemaManager $schema_manager, SubmissionCache $cache, SubmissionQueryBuilder $query_builder = null, SubmissionAnalytics $analytics = null, SubmissionStatusManager $status_manager = null ) {

		$this->schema_manager = $schema_manager;
		$this->cache          = $cache;
		$this->query_builder  = $query_builder ?? new SubmissionQueryBuilder();
		$this->analytics      = $analytics ?? new SubmissionAnalytics( $schema_manager, $cache );
		$this->status_manager = $status_manager ?? new SubmissionStatusManager( $schema_manager, $cache, $this->query_builder );
	}

	/**
	 * Create a pending submission entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Form submission data.
	 * @param array $meta    Metadata (form_id, provider, etc.).
	 *
	 * @throws InvalidArgumentException If required fields are missing or invalid.
	 * @throws RuntimeException When the submission could not be persisted.
	 *
	 * @return string Unique submission ID.
	 */
	public function create_pending( array $payload, array $meta ): string {

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		if ( empty( $meta['provider'] ) ) {
			throw new InvalidArgumentException( 'Provider is required' );
		}

		if ( empty( $meta['form_id'] ) ) {
			throw new InvalidArgumentException( 'Form ID is required' );
		}

		if ( ! $this->query_builder->is_allowed_provider( $meta['provider'] ) ) {
			throw new InvalidArgumentException( 'Invalid provider' );
		}

		$data = [
			'provider'     => sanitize_text_field( $meta['provider'] ),
			'form_id'      => sanitize_text_field( $meta['form_id'] ),
			'entry_id'     => sanitize_text_field( $meta['entry_id'] ?? null ),
			'status'       => 'pending',
			'retry_count'  => 0,
			'verdict'      => null,
			'form_data'    => wp_json_encode( RequestHelper::sanitize_submission_data( $payload ) ),
			'api_response' => null,
			'created_at'   => current_time( 'mysql' ),
			'processed_at' => null,
		];

		$result = $wpdb->insert( $table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $result === false ) {
			throw new RuntimeException( esc_html__( 'Database operation failed. Please contact support.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		$submission_id = (string) $wpdb->insert_id;

		$this->cache->clear_submission_cache( $submission_id );

		return $submission_id;
	}

	/**
	 * Update submission status after processing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id     Submission ID.
	 * @param string $status New status (clean|spam|failed).
	 * @param array  $extra  Additional data (api_response, etc.). Accepts optional 'processed_at' to set a specific timestamp (or null).
	 *
	 * @return bool True on success, false on failure (including missing table or invalid status).
	 */
	public function update_status( string $id, string $status, array $extra = [] ): bool {

		return $this->status_manager->update_status( $id, $status, $extra );
	}

	/**
	 * Update the entry_id for a submission.
	 *
	 * Used to store the provider-specific entry identifier after initial submission
	 * creation (e.g., WordPress comment ID which is only available after insertion).
	 *
	 * @since 1.1.0
	 *
	 * @param string $id       Submission ID.
	 * @param string $entry_id Provider entry identifier.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function update_entry_id( string $id, string $entry_id ): bool {

		if ( ! $this->schema_manager->table_exists() ) {
			return false;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			[ 'entry_id' => sanitize_text_field( $entry_id ) ],
			[ 'id' => (int) $id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $result !== false ) {
			$this->cache->clear_submission_cache( $id );
		}

		return $result !== false;
	}

	/**
	 * Move a submission to trash while preserving its previous status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True on success, false if missing table or submission.
	 */
	public function move_to_trash( string $id ): bool {

		return $this->status_manager->move_to_trash( $id );
	}

	/**
	 * Restore a trashed submission to its previous status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True on success, false if missing table or submission.
	 */
	public function restore_from_trash( string $id ): bool {

		return $this->status_manager->restore_from_trash( $id );
	}

	/**
	 * Mark a submission as failed and increment its retry counter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id    Submission ID.
	 * @param array  $extra Optional extra data to persist.
	 *
	 * @return bool True on success, false if missing table.
	 */
	public function mark_failed( string $id, array $extra = [] ): bool {

		return $this->status_manager->mark_failed( $id, $extra );
	}

	/**
	 * Atomically claim a pending submission for processing.
	 *
	 * @since 1.1.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True if successfully claimed, false if already claimed or missing.
	 */
	public function claim_for_processing( string $id ): bool {

		return $this->status_manager->claim_for_processing( $id );
	}

	/**
	 * Reset a failed submission back to pending for retry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True on success, false if missing table.
	 */
	public function reset_for_retry( string $id ): bool {

		return $this->status_manager->reset_for_retry( $id );
	}

	/**
	 * Find submission by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return array|null Submission data or null if not found or table is absent.
	 */
	public function find( string $id ): ?array {

		$cached = $this->cache->get_submission( $id );

		if ( $cached !== null ) {
			return $cached;
		}

		if ( ! $this->schema_manager->table_exists() ) {
			return null;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$result = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `$table_name` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id
			),
			ARRAY_A
		);

		if ( ! $result ) {
			return null;
		}

		$formatted = RequestHelper::format_submission( $result );

		$this->cache->set_submission( $id, $formatted );

		return $formatted;
	}

	/**
	 * Get submissions for admin interface with basic filtering.
	 * Sanitizes filter arguments before querying the submissions table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Query arguments (status, provider, limit, offset, search, exclude_trash).
	 *
	 * @return array Array with 'items' and 'total' keys. Limit is clamped to a safe range to avoid heavy queries.
	 */
	public function get_submissions( array $args = [] ): array {

		$normalized_args = $this->query_builder->normalize_args( $args );
		$status          = $normalized_args['status'];
		$provider        = $normalized_args['provider'];
		$search          = $normalized_args['search'];
		$limit           = $normalized_args['limit'];
		$offset          = $normalized_args['offset'];
		$exclude_trash   = $normalized_args['exclude_trash'];

		$version   = $this->cache->get_list_cache_version();
		$cache_key = "submissions_list_{$version}_" . md5( wp_json_encode( compact( 'status', 'provider', 'limit', 'offset', 'search', 'exclude_trash' ) ) );
		$cached    = wp_cache_get( $cache_key, $this->cache->get_cache_group() );

		if ( $cached !== false ) {
			return $cached;
		}

		if ( ! $this->schema_manager->table_exists() ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$where = $this->query_builder->build_where_clause(
			[
				'status'        => $status,
				'provider'      => $provider,
				'search'        => $search,
				'exclude_trash' => $exclude_trash,
			]
		);

		$where_clause  = $where['clause'];
		$where_values  = $where['values'];
		$select_values = array_merge( $where_values, [ $limit, $offset ] );

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $select_values is a dynamic array containing WHERE values + LIMIT + OFFSET.
				"SELECT * FROM `$table_name` {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$select_values
			),
			ARRAY_A
		);

		if ( $where_values ) {
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `$table_name` {$where_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$where_values
				)
			);
		} else {
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM `$table_name`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		$items = $results ? array_map( [ RequestHelper::class, 'format_submission' ], $results ) : [];

		$result = [
			'items' => $items,
			'total' => $total,
		];

		wp_cache_set( $cache_key, $result, $this->cache->get_cache_group(), 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Get distinct providers from submissions.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of provider names.
	 */
	public function get_distinct_providers(): array {

		return $this->analytics->get_distinct_providers();
	}

	/**
	 * Retrieve failed submissions that are eligible for retry.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit       Maximum number of submissions to fetch.
	 * @param int $max_retries Maximum retry attempts allowed.
	 *
	 * @return array Submissions ready to be retried. Returns empty if table or retry_count column is missing.
	 */
	public function get_failed_submissions_for_retry( int $limit = 50, int $max_retries = 5 ): array {

		if ( $limit <= 0 || $max_retries <= 0 ) {
			return [];
		}

		if ( ! $this->schema_manager->table_exists() || ! $this->schema_manager->column_exists( 'retry_count' ) ) {
			return [];
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$limit       = max( 1, $limit );
		$max_retries = max( 1, $max_retries );

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `$table_name` WHERE status = 'failed' AND retry_count < %d ORDER BY processed_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$max_retries,
				$limit
			),
			ARRAY_A
		);

		return $results ? array_map( [ RequestHelper::class, 'format_submission' ], $results ) : [];
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

		return $this->analytics->count_pending_older_than( $threshold_seconds );
	}

	/**
	 * Get basic queue statistics for dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @return array Basic stats array; returns zeros when the table does not exist.
	 */
	public function get_queue_stats(): array {

		return $this->analytics->get_queue_stats();
	}

	/**
	 * Get daily submission counts grouped by status.
	 *
	 * @since 1.1.0
	 *
	 * @param int $days Number of days to look back (default 7).
	 *
	 * @return array[] Each element: ['date' => 'Y-m-d', 'total' => int, 'clean' => int, 'spam' => int, 'failed' => int].
	 */
	public function get_daily_counts( int $days = 7 ): array {

		return $this->analytics->get_daily_counts( $days );
	}

	/**
	 * Delete submission by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True on success, false on failure or when table is absent.
	 */
	public function delete( string $id ): bool {

		if ( ! $this->schema_manager->table_exists() ) {
			return false;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			[ 'id' => (int) $id ],
			[ '%d' ]
		);

		if ( $result !== false ) {
			$this->cache->clear_submission_cache( $id );
		}

		return $result !== false;
	}

	/**
	 * Delete all submissions with a given status.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status One of: pending, clean, spam, failed, trash.
	 *
	 * @return int Number of deleted submissions, or 0 on invalid status or absent table.
	 */
	public function delete_by_status( string $status ): int {

		$allowed_statuses = [ 'pending', 'clean', 'spam', 'failed', 'trash' ];

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return 0;
		}

		if ( ! $this->schema_manager->table_exists() ) {
			return 0;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM `$table_name` WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status
			)
		);

		$deleted_count = (int) $wpdb->rows_affected;

		if ( $deleted_count > 0 ) {
			$this->cache->invalidate_list_cache();
		}

		return $deleted_count;
	}

	/**
	 * Delete submissions older than specified days.
	 *
	 * @since 1.0.0
	 *
	 * @param int $days Number of days. Submissions older than this will be deleted.
	 *
	 * @return int Number of deleted submissions.
	 */
	public function delete_older_than( int $days ): int {

		if ( $days <= 0 ) {
			return 0;
		}

		if ( ! $this->schema_manager->table_exists() ) {
			return 0;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$cutoff_date = wp_date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		if ( $cutoff_date === false ) {
			return 0;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM `$table_name` WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff_date
			)
		);

		$deleted_count = (int) $wpdb->rows_affected;

		if ( $deleted_count > 0 ) {
			$this->cache->invalidate_list_cache();
		}

		return $deleted_count;
	}
}

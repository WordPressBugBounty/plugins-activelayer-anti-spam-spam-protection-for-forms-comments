<?php

namespace ActiveLayer\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission Storage façade.
 *
 * Delegates schema, cache, and repository responsibilities to dedicated services
 * while keeping the public API stable for existing consumers.
 *
 * @since 1.0.0
 */
class Storage {

	/**
	 * Table name for submissions (without prefix).
	 *
	 * @since 1.0.0
	 */
	public const TABLE_NAME = 'activelayer_submissions';

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Storage|null
	 */
	private static $instance;

	/**
	 * Cache helper instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionCache
	 */
	private $cache;

	/**
	 * Schema manager instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SchemaManager
	 */
	private $schema_manager;

	/**
	 * Submission repository instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionRepository
	 */
	private $repository;

	/**
	 * Private constructor for singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		$this->cache          = new SubmissionCache();
		$this->schema_manager = new SchemaManager( $this->cache, self::TABLE_NAME );
		$this->repository     = new SubmissionRepository( $this->schema_manager, $this->cache );
	}

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Storage Storage instance.
	 */
	public static function get_instance(): Storage {

		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Ensure the submissions table schema matches the expected version.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema(): void {

		$this->schema_manager->maybe_upgrade_schema();
	}

	/**
	 * Create a pending submission entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Form submission data.
	 * @param array $meta    Metadata (form_id, provider, etc.).
	 *
	 * @return string Unique submission ID.
	 */
	public function create_pending( array $payload, array $meta ): string {

		return $this->repository->create_pending( $payload, $meta );
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
	 * @return bool True on success, false on failure.
	 */
	public function update_status( string $id, string $status, array $extra = [] ): bool {

		return $this->repository->update_status( $id, $status, $extra );
	}

	/**
	 * Update the entry_id for a submission.
	 *
	 * @since 1.1.0
	 *
	 * @param string $id       Submission ID.
	 * @param string $entry_id Provider entry identifier.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function update_entry_id( string $id, string $entry_id ): bool {

		return $this->repository->update_entry_id( $id, $entry_id );
	}

	/**
	 * Move a submission to trash while preserving its previous status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True on success.
	 */
	public function move_to_trash( string $id ): bool {

		return $this->repository->move_to_trash( $id );
	}

	/**
	 * Restore a trashed submission to its previous status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True on success.
	 */
	public function restore_from_trash( string $id ): bool {

		return $this->repository->restore_from_trash( $id );
	}

	/**
	 * Mark a submission as failed and increment its retry counter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id    Submission ID.
	 * @param array  $extra Optional extra data to persist.
	 *
	 * @return bool True on success.
	 */
	public function mark_failed( string $id, array $extra = [] ): bool {

		return $this->repository->mark_failed( $id, $extra );
	}

	/**
	 * Atomically claim a pending submission for processing.
	 *
	 * @since 1.1.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True if successfully claimed.
	 */
	public function claim_for_processing( string $id ): bool {

		return $this->repository->claim_for_processing( $id );
	}

	/**
	 * Reset a failed submission back to pending for retry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True on success.
	 */
	public function reset_for_retry( string $id ): bool {

		return $this->repository->reset_for_retry( $id );
	}

	/**
	 * Find submission by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return array|null Submission data or null if not found.
	 */
	public function find( string $id ): ?array {

		return $this->repository->find( $id );
	}

	/**
	 * Get submissions for admin interface with basic filtering.
	 * Sanitizes filter arguments before querying the submissions table.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Added search filtering by partial match in stored form data (e.g., email).
	 *
	 * @param array $args Query arguments (status, provider, limit, offset, search, exclude_trash).
	 *
	 * @return array Array with 'items' and 'total' keys.
	 */
	public function get_submissions( array $args = [] ): array {

		return $this->repository->get_submissions( $args );
	}

	/**
	 * Get distinct providers from submissions.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of provider names.
	 */
	public function get_distinct_providers(): array {

		return $this->repository->get_distinct_providers();
	}

	/**
	 * Retrieve failed submissions that are eligible for retry.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit       Maximum number of submissions to fetch.
	 * @param int $max_retries Maximum retry attempts allowed.
	 *
	 * @return array Submissions ready to be retried.
	 */
	public function get_failed_submissions_for_retry( int $limit = 50, int $max_retries = 5 ): array {

		return $this->repository->get_failed_submissions_for_retry( $limit, $max_retries );
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

		return $this->repository->count_pending_older_than( $threshold_seconds );
	}

	/**
	 * Get fully-qualified submissions table name with the current WordPress prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return string Submissions table name.
	 */
	public function get_table_name(): string {

		return $this->schema_manager->get_table_name();
	}

	/**
	 * Create database table with simplified schema.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	public function create_table(): bool {

		return $this->schema_manager->create_table();
	}

	/**
	 * Get SQL schema definition for the submissions table.
	 *
	 * @since 1.0.0
	 *
	 * @return string SQL statement for dbDelta.
	 */
	public function get_schema_sql(): string {

		return $this->schema_manager->get_schema_sql();
	}

	/**
	 * Check if table exists.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $use_cache Whether to use cached result.
	 *
	 * @return bool True if table exists, false otherwise.
	 */
	public function table_exists( bool $use_cache = true ): bool {

		return $this->schema_manager->table_exists( $use_cache );
	}

	/**
	 * Get basic queue statistics for dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @return array Basic stats array.
	 */
	public function get_queue_stats(): array {

		return $this->repository->get_queue_stats();
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

		return $this->repository->get_daily_counts( $days );
	}

	/**
	 * Delete submission by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $id ): bool {

		return $this->repository->delete( $id );
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

		return $this->repository->delete_by_status( $status );
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

		return $this->repository->delete_older_than( $days );
	}
}

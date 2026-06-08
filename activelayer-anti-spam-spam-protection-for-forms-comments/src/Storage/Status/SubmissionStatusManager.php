<?php

namespace ActiveLayer\Storage\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Storage\Query\SubmissionQueryBuilder;
use ActiveLayer\Storage\SchemaManager;
use ActiveLayer\Storage\SubmissionCache;

/**
 * Handles status updates and transitions for submissions.
 *
 * @since 1.0.0
 */
class SubmissionStatusManager {

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
	 * Status manager constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param SchemaManager          $schema_manager Schema manager instance.
	 * @param SubmissionCache        $cache          Cache helper instance.
	 * @param SubmissionQueryBuilder $query_builder  Query builder instance.
	 */
	public function __construct( SchemaManager $schema_manager, SubmissionCache $cache, SubmissionQueryBuilder $query_builder ) {

		$this->schema_manager = $schema_manager;
		$this->cache          = $cache;
		$this->query_builder  = $query_builder;
	}

	/**
	 * Update submission status after processing.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Fires the activelayer_submission_status_changed action after a successful update.
	 *
	 * @param string $id     Submission ID.
	 * @param string $status New status (clean|spam|failed).
	 * @param array  $extra  Additional data (api_response, etc.). Accepts optional 'processed_at' to set a specific timestamp (or null).
	 *
	 * @return bool True on success, false on failure (including missing table or invalid status).
	 */
	public function update_status( string $id, string $status, array $extra = [] ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->schema_manager->table_exists() ) {
			return false;
		}

		$normalized_status = sanitize_key( $status );

		if ( ! $this->query_builder->is_allowed_status( $normalized_status ) ) {
			return false;
		}

		// Capture the previous status before overwriting it.
		$previous   = $this->find_submission( $id );
		$old_status = $previous['status'] ?? null;

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$update_data = [
			'status'       => $normalized_status,
			'processed_at' => $extra['processed_at'] ?? current_time( 'mysql' ),
		];

		if ( isset( $extra['verdict'] ) ) {
			$update_data['verdict'] = sanitize_text_field( $extra['verdict'] );
		}

		if ( isset( $extra['api_response'] ) ) {
			$update_data['api_response'] = wp_json_encode( RequestHelper::sanitize_submission_data( $extra['api_response'] ) );
		}

		if ( isset( $extra['retry_count'] ) && $this->schema_manager->column_exists( 'retry_count' ) ) {
			$update_data['retry_count'] = (int) $extra['retry_count'];
		}

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			$update_data,
			[ 'id' => (int) $id ],
			null,
			[ '%d' ]
		);

		if ( $result !== false ) {
			$this->cache->clear_submission_cache( $id );

			if ( $result > 0 ) {
				/**
				 * Fires after a submission's status has been successfully updated.
				 *
				 * Fires on any successful status transition — synchronous (in-request)
				 * and asynchronous (queue worker) verdict paths, as well as manual or
				 * admin status updates — since all converge on this method.
				 *
				 * @since 1.3.0
				 *
				 * @param string      $id         Submission identifier.
				 * @param string      $new_status The newly applied status.
				 * @param string|null $old_status The status before this update, or null if unknown.
				 */
				do_action( 'activelayer_submission_status_changed', $id, $normalized_status, $old_status );
			}
		}

		return $result !== false;
	}

	/**
	 * Move a submission to trash while preserving its previous status.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $id             Submission ID.
	 * @param string|null $current_status Current status of the submission (optional, will be fetched if not provided).
	 *
	 * @return bool True on success, false if missing table or submission.
	 */
	public function move_to_trash( string $id, ?string $current_status = null ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->schema_manager->table_exists() ) {
			return false;
		}

		// If current status not provided, fetch it.
		if ( $current_status === null ) {
			$submission = $this->find_submission( $id );

			if ( ! $submission ) {
				return false;
			}

			$current_status = $submission['status'] ?? 'pending';
		}

		// Already in trash - nothing to do.
		if ( $current_status === 'trash' ) {
			return true;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			[
				'status'          => 'trash',
				'previous_status' => sanitize_text_field( $current_status ),
			],
			[ 'id' => (int) $id ],
			null,
			[ '%d' ]
		);

		if ( $result !== false ) {
			$this->cache->clear_submission_cache( $id );
		}

		return $result !== false;
	}

	/**
	 * Restore a trashed submission to its previous status.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $id              Submission ID.
	 * @param string|null $previous_status Previous status to restore to (optional, will be fetched if not provided).
	 *
	 * @return bool True on success, false if missing table or submission.
	 */
	public function restore_from_trash( string $id, ?string $previous_status = null ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->schema_manager->table_exists() ) {
			return false;
		}

		$submission = $this->find_submission( $id );

		if ( ! $submission || ( $submission['status'] ?? '' ) !== 'trash' ) {
			return false;
		}

		if ( $previous_status === null ) {
			$previous_status = sanitize_text_field( $submission['previous_status'] ?? 'pending' );

			if ( $previous_status === '' ) {
				$previous_status = 'pending';
			}
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			[
				'status'          => $previous_status,
				'previous_status' => null,
			],
			[ 'id' => (int) $id ],
			null,
			[ '%d' ]
		);

		if ( $result !== false ) {
			$this->cache->clear_submission_cache( $id );
		}

		return $result !== false;
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
	public function mark_failed( string $id, array $extra = [] ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->schema_manager->table_exists() ) {
			return false;
		}

		global $wpdb;

		$table_name       = esc_sql( $this->schema_manager->get_table_name() );
		$has_retry_column = $this->schema_manager->column_exists( 'retry_count' );

		// Build SET clauses for atomic update.
		$set_parts  = [
			'status = %s',
			'processed_at = %s',
		];
		$set_values = [
			'failed',
			current_time( 'mysql' ),
		];

		// Atomically increment retry_count to avoid read-modify-write races.
		if ( $has_retry_column ) {
			$set_parts[] = 'retry_count = retry_count + 1';
		}

		if ( isset( $extra['verdict'] ) ) {
			$set_parts[]  = 'verdict = %s';
			$set_values[] = sanitize_text_field( $extra['verdict'] );
		}

		if ( isset( $extra['api_response'] ) ) {
			$set_parts[]  = 'api_response = %s';
			$set_values[] = wp_json_encode( RequestHelper::sanitize_submission_data( $extra['api_response'] ) );
		}

		$set_values[] = (int) $id;

		// All $set_parts are hardcoded SQL fragments with %s placeholders; actual values go through prepare().
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"UPDATE `$table_name` SET " . implode( ', ', $set_parts ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$set_values
			)
		);

		$this->cache->clear_submission_cache( $id );

		return $result !== false;
	}

	/**
	 * Atomically claim a pending submission for processing.
	 *
	 * Uses UPDATE ... WHERE status='pending' AND processed_at IS NULL
	 * to ensure only one worker can claim a submission. Sets processed_at
	 * as a claim marker; the final timestamp is overwritten by update_status().
	 *
	 * @since 1.1.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return bool True if successfully claimed, false if already claimed or missing.
	 */
	public function claim_for_processing( string $id ): bool {

		if ( ! $this->schema_manager->table_exists() ) {
			return false;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE `$table_name` SET processed_at = %s WHERE id = %d AND status = 'pending' AND processed_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				(int) $id
			)
		);

		if ( $wpdb->rows_affected > 0 ) {
			$this->cache->clear_submission_cache( $id );

			return true;
		}

		return false;
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

		if ( ! $this->schema_manager->table_exists() ) {
			return false;
		}

		global $wpdb;

		$table_name = esc_sql( $this->schema_manager->get_table_name() );

		// Atomically reset only if still in 'failed' status to prevent duplicate resets.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE `$table_name` SET status = 'pending', processed_at = NULL, verdict = NULL, api_response = NULL WHERE id = %d AND status = 'failed'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id
			)
		);

		$this->cache->clear_submission_cache( $id );

		return $wpdb->rows_affected > 0;
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
	private function find_submission( string $id ): ?array {

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
}

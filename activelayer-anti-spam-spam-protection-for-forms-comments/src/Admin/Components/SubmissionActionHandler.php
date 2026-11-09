<?php

namespace ActiveLayer\Admin\Components;

use ActiveLayer\Integrations\Comments\AdminSettings;
use ActiveLayer\Integrations\Comments\NativeModerationFeedback;
use ActiveLayer\Integrations\Comments\PluginInitiatedGuard;
use ActiveLayer\Queue\QueueManager;
use ActiveLayer\Storage\Storage;
use WP_Comment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission Action Handler.
 *
 * Business logic for submission actions: recheck, mark clean/spam, and status
 * transition validation. Decoupled from the admin page controller so it can be
 * reused by bulk actions, single row actions, and future REST/AJAX handlers.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 *
 * @package ActiveLayer\Admin
 */
class SubmissionActionHandler {

	/**
	 * Storage instance.
	 *
	 * @since 1.1.0
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param Storage|null $storage Optional storage instance (defaults to singleton).
	 */
	public function __construct( ?Storage $storage = null ) {

		$this->storage = $storage ?? Storage::get_instance();
	}

	/**
	 * Recheck a submission by resetting it to pending and re-queuing.
	 *
	 * Only submissions with `failed` status can be rechecked. Uses an atomic
	 * UPDATE … WHERE status='failed' to prevent races with concurrent retry sweeps.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True if rechecked, false if submission is not in failed status.
	 */
	public function recheck( string $submission_id ): bool {

		// Atomically reset only if still in 'failed' status.
		// Prevents race condition with concurrent retry sweeps.
		if ( ! $this->storage->reset_for_retry( $submission_id ) ) {
			return false;
		}

		QueueManager::queue( $submission_id );

		return true;
	}

	/**
	 * Handle user correction of API verdict.
	 *
	 * Updates the local status, synchronizes existing WordPress comments, and queues
	 * feedback only after successful local changes. Native moderation callers can
	 * disable synchronization to preserve their already-applied moderation decision.
	 *
	 * @since 1.1.0
	 * @since 1.8.0 Synchronize manual WordPress comment corrections.
	 * @since 1.8.0 Rollback with a valid processing date after native write failure.
	 *
	 * @param string $submission_id       Submission ID.
	 * @param string $new_status          New status (clean|spam).
	 * @param bool   $sync_native_comment Whether to synchronize a linked WP comment.
	 *
	 * @return bool True if the status was changed, false otherwise.
	 */
	public function correct( string $submission_id, string $new_status, bool $sync_native_comment = true ): bool {

		if ( ! $this->is_valid_transition( $submission_id, $new_status ) ) {
			return false;
		}

		$submission  = $this->storage->find( $submission_id );
		$api_verdict = $submission['verdict'] ?? null;

		// Update local status immediately.
		if ( ! $this->storage->update_status( $submission_id, $new_status ) ) {
			return false;
		}

		if ( $sync_native_comment && ! $this->synchronize_comment( $submission_id, $new_status, $submission ) ) {
			// The formatted submission carries a Unix timestamp, not a database DATETIME.
			$this->storage->update_status( $submission_id, $submission['status'] );

			return false;
		}

		// Send feedback to API if we have an API verdict.
		if ( $api_verdict ) {
			QueueManager::queue_feedback( $submission_id, $new_status );
		}

		return true;
	}

	/**
	 * Apply an explicit correction to an existing normal WordPress comment.
	 *
	 * Missing, deleted, trashed, and tracking-only comments remain untouched.
	 * Manual spam actions retain the native comment regardless of discard settings.
	 * Clean corrections use integration defaults and preserve native moderation
	 * when auto-approval is disabled.
	 *
	 * @since 1.8.0
	 * @since 1.8.0 Record successful explicit moderation against later automatic verdicts.
	 *
	 * @param string $submission_id Submission identifier.
	 * @param string $new_status    Corrected classification.
	 * @param array  $submission    Stored submission before correction.
	 *
	 * @return bool True when synchronized or no native change is required.
	 */
	private function synchronize_comment( string $submission_id, string $new_status, array $submission ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ( $submission['provider'] ?? '' ) !== 'wp_comments' ) {
			return true;
		}

		$settings = wp_parse_args( get_option( 'activelayer_wp_comments_settings', [] ), AdminSettings::DEFAULT_SETTINGS );

		if ( ! empty( $submission['tracking_mode'] ) || ! empty( $settings['tracking_mode'] ) ) {
			return true;
		}

		$comment = $this->resolve_comment( $submission_id, $submission );

		if ( ! $comment || $comment->comment_approved === 'trash' ) {
			return true;
		}

		$updated = PluginInitiatedGuard::run(
			function () use ( $comment, $new_status, $settings ) {

				if ( $new_status === 'clean' ) {
					return $this->clean_comment( $comment, ! empty( $settings['auto_approve_clean'] ) );
				}

				return $comment->comment_approved === 'spam' || wp_spam_comment( $comment->comment_ID );
			}
		);

		if ( ! $updated ) {
			return false;
		}

		update_comment_meta( $comment->comment_ID, 'activelayer_status', $new_status );
		update_comment_meta( $comment->comment_ID, NativeModerationFeedback::MODERATION_META_KEY, $new_status );

		return true;
	}

	/**
	 * Correct a comment's classification without bypassing native moderation.
	 *
	 * Without auto-approval, unspam restores WordPress's pre-spam status and
	 * pending comments stay pending. The initial screening decision must not
	 * override a later manual hold.
	 *
	 * @since 1.8.0
	 *
	 * @param WP_Comment $comment      Existing normal comment.
	 * @param bool       $auto_approve Whether clean comments may be approved.
	 *
	 * @return bool Whether the native update succeeded or was unnecessary.
	 */
	private function clean_comment( WP_Comment $comment, bool $auto_approve ): bool {

		if ( ! $auto_approve && $comment->comment_approved === 'spam' ) {
			return wp_unspam_comment( $comment->comment_ID );
		}

		if ( $auto_approve && $comment->comment_approved !== '1' ) {
			return wp_set_comment_status( $comment->comment_ID, 'approve' );
		}

		return true;
	}

	/**
	 * Find a native comment using persisted linkage without recreating anything.
	 *
	 * Native submission meta is authoritative. An entry-ID fallback must match
	 * the stored post and cannot belong to a different linked submission.
	 * Review comment types are excluded even if a stale entry ID points at one.
	 *
	 * @since 1.8.0
	 *
	 * @param string $submission_id Submission identifier.
	 * @param array  $submission    Stored submission data.
	 *
	 * @return WP_Comment|null Existing compatible comment, or null.
	 */
	private function resolve_comment( string $submission_id, array $submission ): ?WP_Comment { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		global $wpdb;

		// Direct metadata lookup avoids provider filters that hide comment types.
		$linked_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				'activelayer_submission_id',
				$submission_id
			)
		);

		$entry_id   = (string) ( $submission['entry_id'] ?? '' );
		$comment_id = $linked_id ? (int) $linked_id : ( ctype_digit( $entry_id ) ? (int) $entry_id : 0 );
		$comment    = $comment_id > 0 ? get_comment( $comment_id ) : null;

		if ( ! $comment || ! in_array( $comment->comment_type, [ '', 'comment' ], true ) ) {
			return null;
		}

		$linked_submission = (string) get_comment_meta( $comment_id, 'activelayer_submission_id', true );

		if ( $linked_submission !== '' && $linked_submission !== $submission_id ) {
			return null;
		}

		if ( ! $linked_id && (string) $comment->comment_post_ID !== (string) ( $submission['form_id'] ?? '' ) ) {
			return null;
		}

		return $comment;
	}

	/**
	 * Validate whether a status transition is allowed.
	 *
	 * Only clean→spam and spam→clean transitions are permitted.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $new_status    Target status.
	 *
	 * @return bool True if the transition is valid.
	 */
	public function is_valid_transition( string $submission_id, string $new_status ): bool {

		if ( ! in_array( $new_status, [ 'clean', 'spam' ], true ) ) {
			return false;
		}

		$submission = $this->storage->find( $submission_id );

		if ( ! $submission ) {
			return false;
		}

		$allowed_targets = [
			'spam'  => 'clean',
			'clean' => 'spam',
		];

		$expected = $allowed_targets[ $submission['status'] ] ?? null;

		return $new_status === $expected;
	}
}

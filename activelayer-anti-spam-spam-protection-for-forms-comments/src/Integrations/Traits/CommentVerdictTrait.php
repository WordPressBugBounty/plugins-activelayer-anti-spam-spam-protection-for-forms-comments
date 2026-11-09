<?php

namespace ActiveLayer\Integrations\Traits;

use ActiveLayer\Integrations\Comments\PluginInitiatedGuard;
use ActiveLayer\Integrations\Comments\NativeModerationFeedback;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\SubmissionCache;
use ActiveLayer\Storage\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comment verdict handling trait.
 *
 * Provides shared comment verdict methods for comment-based integrations
 * (WordPress Comments, WooCommerce Reviews, etc.).
 *
 * Requires the consuming class to provide:
 * - get_settings(): array
 * - get_slug(): string
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Integrations\Traits
 */
trait CommentVerdictTrait {

	use SilentDiscardTrait;

	/**
	 * Allow clean comment submission.
	 *
	 * Finds the comment by submission ID, marks it as clean via meta,
	 * and auto-approves if enabled, preserving upstream spam and trash decisions.
	 *
	 * @since 1.2.0
	 * @since 1.2.0 Wrap WP comment-status calls in plugin-initiated guard to prevent feedback loops.
	 * @since 1.8.0 Respect external moderation and restore only an owned hold.
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True on success.
	 */
	protected function allow_submission( string $submission_id ): bool {

		// Find comment by submission ID.
		$comment_id = $this->get_comment_id_by_submission( $submission_id );

		if ( ! $comment_id ) {
			return false;
		}

		// Get settings to check if auto-approval is enabled.
		$settings = $this->get_settings();

		// Capture hold ownership before replacing the classification metadata.
		$owns_hold = get_comment_meta( $comment_id, 'activelayer_status', true ) === 'pending';

		update_comment_meta( $comment_id, 'activelayer_status', 'clean' );

		if ( metadata_exists( 'comment', $comment_id, NativeModerationFeedback::MODERATION_META_KEY ) ) {
			return true;
		}

		// Approval is limited to pending comments with no recorded external decision.
		$comment = get_comment( $comment_id );

		if ( ! $comment || $comment->comment_approved !== '0' ) {
			return true;
		}

		// Older queued submissions may have had an upstream rejection forced to pending.
		$original_status   = get_comment_meta( $comment_id, 'activelayer_original_status', true );
		$upstream_rejected = in_array( $original_status, [ 'spam', 'trash' ], true );

		if ( ! empty( $settings['auto_approve_clean'] ) && ! $upstream_rejected ) {
			PluginInitiatedGuard::run(
				static function () use ( $comment_id ) {
					wp_set_comment_status( $comment_id, 'approve' );
				}
			);

			return true;
		}

		// Restore upstream rejections, or release our hold when auto-approval is disabled.
		if ( $upstream_rejected || $owns_hold ) {
			$this->restore_original_comment_status( $comment_id );
		}

		return true;
	}

	/**
	 * Block spam comment submission.
	 *
	 * Finds the comment by submission ID, marks it as spam via meta,
	 * and either deletes it outright (silent discard for high-confidence
	 * spam) or moves it to the spam folder.
	 *
	 * @since 1.2.0
	 * @since 1.2.0 Wrap WP comment-status calls in plugin-initiated guard to prevent feedback loops.
	 * @since 1.8.0 Respect external moderation and restore only an owned hold.
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True on success.
	 */
	protected function block_submission( string $submission_id ): bool {

		// Find comment by submission ID.
		$comment_id = $this->get_comment_id_by_submission( $submission_id );

		if ( ! $comment_id ) {
			return false;
		}

		// Get settings to check if auto-spam is enabled.
		$settings = $this->get_settings();

		// Capture hold ownership before replacing the classification metadata.
		$owns_hold = get_comment_meta( $comment_id, 'activelayer_status', true ) === 'pending';

		update_comment_meta( $comment_id, 'activelayer_status', 'spam' );

		if ( metadata_exists( 'comment', $comment_id, NativeModerationFeedback::MODERATION_META_KEY ) ) {
			return true;
		}

		// Move to spam folder if auto-spam is enabled.
		if ( ! empty( $settings['auto_spam_detected'] ) ) {
			if ( $this->should_silently_discard( $submission_id, $settings ) ) {
				wp_delete_comment( $comment_id, true );

				return true;
			}

			PluginInitiatedGuard::run(
				static function () use ( $comment_id ) {
					wp_spam_comment( $comment_id );
				}
			);

			return true;
		}

		// Auto-spam disabled: the comment was forced to pending before the verdict.
		// Restore its original status so the user is not silently penalised.
		$comment = get_comment( $comment_id );

		if ( $owns_hold && $comment && $comment->comment_approved === '0' ) {
			$this->restore_original_comment_status( $comment_id );
		}

		return true;
	}

	/**
	 * Restore a comment to the status it would have had without ActiveLayer.
	 *
	 * Reads the `activelayer_original_status` meta — captured at the
	 * `pre_comment_approved` filter just before our pending override —
	 * and applies it via the appropriate WordPress helper. Empty values
	 * fall back to "hold" (pending), keeping the comment in moderation so
	 * an admin can review it instead of auto-approving an unknown status.
	 *
	 * @since 1.2.0
	 * @since 1.2.0 Dispatch deferred moderator notification on hold restore; default branch holds (fail-closed) instead of auto-approving.
	 * @since 1.2.0 Wrap WP comment-status calls in plugin-initiated guard to prevent feedback loops.
	 *
	 * @param int $comment_id Comment ID.
	 *
	 * @return void
	 */
	private function restore_original_comment_status( int $comment_id ): void {

		$original_status = get_comment_meta( $comment_id, 'activelayer_original_status', true );

		if ( $original_status === '' ) {
			$original_status = '0';
		}

		PluginInitiatedGuard::run(
			static function () use ( $comment_id, $original_status ) {

				switch ( (string) $original_status ) {
					case 'spam':
						wp_spam_comment( $comment_id );
						break;

					case 'trash':
						wp_trash_comment( $comment_id );
						break;

					case '1':
						wp_set_comment_status( $comment_id, 'approve' );
						break;

					default:
						// Includes '0' and any unexpected value — fail closed to hold so an admin can review.
						wp_set_comment_status( $comment_id, 'hold' );
						// `wp_set_comment_status('hold')` doesn't trigger moderator notification on its own.
						// Dispatch the email that `maybe_suppress_moderator_notification` held back at submit time.
						wp_new_comment_notify_moderator( $comment_id );
				}
			}
		);
	}

	/**
	 * Get comment ID by submission ID.
	 *
	 * Uses the persisted entry ID as a fallback while insertion metadata is being
	 * finalized. Queries comment meta directly via $wpdb to bypass query filters such as
	 * WooCommerce's `comments_clauses_without_product_reviews`, which would
	 * otherwise exclude product reviews from generic `get_comments()` calls.
	 *
	 * @since 1.2.0
	 * @since 1.8.0 Resolve persisted entry IDs before correlation meta is finalized.
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return int|null Comment ID or null if not found.
	 */
	private function get_comment_id_by_submission( string $submission_id ): ?int {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$comment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				'activelayer_submission_id',
				$submission_id
			)
		);

		if ( $comment_id ) {
			return (int) $comment_id;
		}

		// The worker may run after insertion but before temporary meta is replaced.
		$submission = Storage::get_instance()->find( $submission_id );

		$provider = $submission['provider'] ?? '';

		if ( ! $submission || $provider !== $this->get_slug() ) {
			return null;
		}

		$entry_id = (int) ( $submission['entry_id'] ?? 0 );

		return $entry_id > 0 && get_comment( $entry_id ) ? $entry_id : null;
	}

	/**
	 * Handle submission failure fallback.
	 *
	 * Restores the comment to its original status when submission processing
	 * fails while the comment remains in our pending hold. Keeps the submission
	 * link and original status for retries without undoing later manual moderation.
	 * Tracking mode leaves native moderation and notification timing unchanged.
	 *
	 * @since 1.2.0
	 * @since 1.8.0 Retain retry linkage and skip status restoration for tracking submissions.
	 * @since 1.8.0 Preserve external decisions when releasing a failed check.
	 *
	 * @param string $submission_id Submission ID.
	 * @param array  $submission    Submission data.
	 */
	public function handle_submission_failed( string $submission_id, array $submission ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( $this->get_slug() !== ( $submission['provider'] ?? '' ) ) {
			return;
		}

		$comment_id = $this->get_comment_id_by_submission( $submission_id );

		if ( ! $comment_id ) {
			return;
		}

		$comment = get_comment( $comment_id );

		$settings = $this->get_settings();

		// Tracking mode never owns a moderation hold or a deferred notification.
		if ( ! metadata_exists( 'comment', $comment_id, NativeModerationFeedback::MODERATION_META_KEY ) && empty( $settings['tracking_mode'] ) && $comment && $comment->comment_approved === '0' && get_comment_meta( $comment_id, 'activelayer_status', true ) === 'pending' ) {
			$this->restore_original_comment_status( $comment_id );
		}

		delete_comment_meta( $comment_id, 'activelayer_status' );
		wp_cache_delete( 'activelayer_comment_id_' . md5( $submission_id ), SubmissionCache::CACHE_GROUP );

		Logger::log(
			'Comment submission failed - released pending verification',
			[
				'submission_id' => $submission_id,
				'comment_id'    => $comment_id,
			]
		);
	}
}

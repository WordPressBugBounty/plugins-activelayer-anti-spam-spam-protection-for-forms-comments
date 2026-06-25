<?php

namespace ActiveLayer\Integrations\Traits;

use ActiveLayer\Integrations\Comments\PluginInitiatedGuard;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\SubmissionCache;

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
	 * and auto-approves if the setting is enabled.
	 *
	 * @since 1.2.0
	 * @since 1.2.0 Wrap WP comment-status calls in plugin-initiated guard to prevent feedback loops.
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

		// Always update comment meta to mark as clean.
		update_comment_meta( $comment_id, 'activelayer_status', 'clean' );

		// Only intervene while the comment is still in the pending state we forced
		// during preprocess_comment. If another plugin already moved it (spam, trash,
		// etc.) we respect that decision.
		$comment = get_comment( $comment_id );

		if ( ! $comment || $comment->comment_approved !== '0' ) {
			return true;
		}

		if ( ! empty( $settings['auto_approve_clean'] ) ) {
			PluginInitiatedGuard::run(
				static function () use ( $comment_id ) {
					wp_set_comment_status( $comment_id, 'approve' );
				}
			);

			return true;
		}

		// Auto-approve disabled: don't impose our pending state on the user — restore
		// whatever WordPress would have decided without ActiveLayer.
		$this->restore_original_comment_status( $comment_id );

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

		// Always update comment meta to mark as spam.
		update_comment_meta( $comment_id, 'activelayer_status', 'spam' );

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

		if ( $comment && $comment->comment_approved === '0' ) {
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
	 * Queries comment meta directly via $wpdb to bypass query filters such as
	 * WooCommerce's `comments_clauses_without_product_reviews`, which would
	 * otherwise exclude product reviews from generic `get_comments()` calls.
	 *
	 * @since 1.2.0
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

		return $comment_id ? (int) $comment_id : null;
	}

	/**
	 * Handle submission failure fallback.
	 *
	 * Restores the comment to its original status when submission processing
	 * fails, and cleans up all ActiveLayer comment meta.
	 *
	 * @since 1.2.0
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

		$this->restore_original_comment_status( $comment_id );

		delete_comment_meta( $comment_id, 'activelayer_status' );
		delete_comment_meta( $comment_id, 'activelayer_submission_id' );
		delete_comment_meta( $comment_id, 'activelayer_original_status' );
		wp_cache_delete( 'activelayer_comment_id_' . md5( $submission_id ), SubmissionCache::CACHE_GROUP );

		Logger::log(
			'Comment submission failed - restored original status',
			[
				'submission_id' => $submission_id,
				'comment_id'    => $comment_id,
			]
		);
	}
}

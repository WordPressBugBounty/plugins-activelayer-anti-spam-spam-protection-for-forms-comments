<?php

namespace ActiveLayer\Integrations\Comments;

use ActiveLayer\Admin\Components\SubmissionActionHandler;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;
use WP_Comment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listener for native WordPress comment moderation events.
 *
 * Bridges admin actions on `edit-comments.php` (inline Spam / Approve links,
 * bulk "Mark as spam", row actions, programmatic `wp_set_comment_status()`)
 * to ActiveLayer's feedback pipeline. Resolves the comment to a stored
 * submission via `activelayer_submission_id` comment meta and delegates to
 * {@see SubmissionActionHandler::correct()}, which atomically synchronizes
 * the local submissions table and queues async API feedback.
 *
 * Covers both providers that store the comment-meta link: WordPress Comments
 * (`CommentsIntegration`) and WooCommerce Reviews (`ReviewsIntegration`).
 *
 * Feedback loops with plugin-initiated `wp_spam_comment()` /
 * `wp_set_comment_status()` calls are prevented via
 * {@see PluginInitiatedGuard::is_active()}.
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Integrations\Comments
 */
class NativeModerationFeedback {

	/**
	 * Submission action handler.
	 *
	 * @since 1.2.0
	 *
	 * @var SubmissionActionHandler
	 */
	private $action_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param SubmissionActionHandler|null $action_handler Injected for tests; defaults to a fresh handler.
	 */
	public function __construct( ?SubmissionActionHandler $action_handler = null ) {

		$this->action_handler = $action_handler ?? new SubmissionActionHandler();
	}

	/**
	 * Initialize the listener.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function hooks(): void {

		add_action( 'transition_comment_status', [ $this, 'handle_transition' ], 10, 3 );
	}

	/**
	 * Handle a comment status transition.
	 *
	 * Resolves the comment to an ActiveLayer submission via comment meta, maps
	 * the new WordPress status to an internal verdict, and dispatches a feedback
	 * correction via {@see SubmissionActionHandler::correct()} when appropriate.
	 * No-ops when the transition is plugin-initiated, the status is unmappable,
	 * the comment has no linked submission, or no API verdict has been recorded.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $new_status New WordPress comment status.
	 * @param string     $old_status Previous status.
	 * @param WP_Comment $comment    Comment object.
	 *
	 * @return void
	 */
	public function handle_transition( $new_status, $old_status, $comment ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		// Skip events triggered by our own auto-spam / auto-approve calls.
		if ( PluginInitiatedGuard::is_active() ) {
			return;
		}

		if ( ! is_object( $comment ) || empty( $comment->comment_ID ) ) {
			return;
		}

		$mapped = self::map_wp_status( (string) $new_status, (string) $old_status );

		if ( $mapped === null ) {
			return;
		}

		$submission_id = (string) get_comment_meta( (int) $comment->comment_ID, 'activelayer_submission_id', true );

		if ( $submission_id === '' ) {
			// Comment was created before ActiveLayer or by an unsupported provider — silently skip.
			return;
		}

		$submission = Storage::get_instance()->find( $submission_id );

		if ( ! $submission ) {
			return;
		}

		// No API verdict yet → nothing to correct, skip silently.
		if ( empty( $submission['verdict'] ) ) {
			return;
		}

		// Use the existing correction handler: it validates the transition,
		// updates local status atomically, and queues async feedback.
		$corrected = $this->action_handler->correct( $submission_id, $mapped );

		if ( $corrected ) {
			Logger::log(
				'Native comment moderation feedback dispatched',
				[
					'submission_id' => $submission_id,
					'comment_id'    => (int) $comment->comment_ID,
					'new_status'    => $mapped,
					'old_status'    => (string) $old_status,
				]
			);
		}
	}

	/**
	 * Map a WordPress comment-status transition to an ActiveLayer internal status.
	 *
	 * Most cases key off `$new_status` alone: `spam` → `spam`, `approved` /
	 * numeric `1` → `clean`. Everything else (`unapproved`/`hold`, `trash`,
	 * `delete`, unknown) is ignored.
	 *
	 * Special case: `unapproved` after `spam` is a clean feedback signal.
	 * When ActiveLayer auto-spams a comment that was forced to pending by
	 * `pre_comment_approved`, WordPress stores `_wp_trash_meta_status` as `'0'`,
	 * so the native "Not Spam" action calls `wp_unspam_comment()` which restores
	 * the comment to pending and fires `transition_comment_status('unapproved',
	 * 'spam', …)`. That is the main false-positive correction path for this
	 * integration and must produce clean feedback.
	 *
	 * @since 1.2.0
	 *
	 * @param string $new_status New WordPress comment status.
	 * @param string $old_status Previous status; pass empty string when not in a
	 *                           transition context (returns the static mapping only).
	 *
	 * @return string|null `spam`, `clean`, or null when no feedback should be sent.
	 */
	public static function map_wp_status( string $new_status, string $old_status = '' ): ?string {

		// Unspam back to pending — the auto-spam false-positive correction path.
		if ( $new_status === 'unapproved' && $old_status === 'spam' ) {
			return 'clean';
		}

		switch ( $new_status ) {
			case 'spam':
				$mapped = 'spam';
				break;

			case 'approved':
			case '1':
				$mapped = 'clean';
				break;

			default:
				// 'unapproved' / '0' (pending/hold), 'trash', 'delete', unknown → no signal.
				$mapped = null;
		}

		return $mapped;
	}
}

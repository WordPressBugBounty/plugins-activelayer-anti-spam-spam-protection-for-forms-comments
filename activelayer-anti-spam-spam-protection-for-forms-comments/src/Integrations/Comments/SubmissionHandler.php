<?php

namespace ActiveLayer\Integrations\Comments;

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Integrations\Submission\SubmissionBodySanitizer;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;
use Exception;
use WP_Comment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Comments Submission Handler.
 *
 * Handles WordPress comment submissions, data normalization, and processing.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\Comments
 */
class SubmissionHandler {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.0.0
	 *
	 * @var CommentsIntegration
	 */
	private $integration;

	/**
	 * Submission IDs that must remain pending until async verdict.
	 *
	 * Request-scoped set keyed by submission ID. Used by the
	 * `pre_comment_approved` filter to force `0` (pending) for comments
	 * we intercepted in `preprocess_comment`, preventing WordPress
	 * auto-approval (e.g., a previously-approved author) before the queue
	 * worker runs. Upstream spam and trash decisions remain unchanged.
	 *
	 * @since 1.2.0
	 *
	 * @var array<string, true>
	 */
	private $pending_submission_ids = [];

	/**
	 * Native WordPress approval decisions captured before our pending override.
	 *
	 * Request-scoped map keyed by submission ID. Holds the value
	 * `wp_allow_comment()` chose for the submission so we can restore it
	 * later when auto-action is disabled or a clean verdict conflicts with
	 * an upstream spam or trash decision.
	 *
	 * @since 1.2.0
	 *
	 * @var array<string, string>
	 */
	private $native_decisions = [];

	/**
	 * Request-local payloads awaiting a successful native comment insertion.
	 *
	 * Keys are temporary correlation tokens, never persisted submission IDs.
	 * A processed marker prevents the insertion and comment_post hooks from
	 * creating or queueing the same submission twice.
	 *
	 * @since 1.8.0
	 *
	 * @var array<string, array>
	 */
	private $staged_submissions = [];

	/**
	 * Comments being finalized before WordPress sends its insertion notifications.
	 *
	 * @since 1.8.0
	 *
	 * @var array<int, true>
	 */
	private $finalizing_comment_ids = [];



	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param CommentsIntegration $integration Parent integration.
	 */
	public function __construct( CommentsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize submission handler.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added native-status capture and force-pending filters on `pre_comment_approved`.
	 * @since 1.2.0 Suppress core moderator notification while awaiting async verdict via `notify_moderator`.
	 * @since 1.8.0 Finalize persisted submissions on wp_insert_comment before queueing.
	 */
	private function hooks(): void {

		// Hook into comment submission before it's saved.
		add_filter( 'preprocess_comment', [ $this, 'handle_submission' ], 10, 1 );

		// Capture WordPress's native approval decision just before we override it.
		add_filter( 'pre_comment_approved', [ $this, 'capture_native_status' ], 98, 2 );

		// Force pending status late so WordPress auto-approval cannot override us.
		add_filter( 'pre_comment_approved', [ $this, 'force_pending_status' ], 99, 2 );

		// Hook into comment insertion to get the comment ID.
		add_action( 'wp_insert_comment', [ $this, 'handle_comment_inserted' ], 10, 2 );
		add_action( 'comment_post', [ $this, 'handle_comment_post' ], 10, 3 );

		add_filter( 'notify_moderator', [ $this, 'maybe_suppress_moderator_notification' ], 10, 2 );
		add_filter( 'notify_post_author', [ $this, 'maybe_suppress_author_notification' ], 10, 2 );
	}

	/**
	 * Handle WordPress comment submission.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Skip review comment type and track pending submissions for force-pending filter.
	 * @since 1.5.0 Skip edd_review comment type (handled by EDD Reviews integration).
	 * @since 1.8.0 Stage payloads in memory until native insertion succeeds.
	 * @since 1.8.0 Always exclude logged-in administrators before validation and tracking.
	 *
	 * @param array $commentdata Comment data array.
	 *
	 * @return array Modified comment data.
	 */
	public function handle_submission( array $commentdata ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Skip if integration is disabled.
		if ( ! $this->integration->is_enabled() ) {
			return $commentdata;
		}

		// Check if anti-spam is enabled for comments.
		$comment_settings = $this->integration->get_admin_settings()->get_comment_settings();

		if ( ! $comment_settings['enabled'] ) {
			return $commentdata;
		}

		$global_settings = SettingsHelper::get_global_settings();

		if ( ! SettingsHelper::has_api_key( $global_settings ) ) {
			// No API key configured - skip tracking and API calls.
			return $commentdata;
		}

		// Administrators are always excluded, including in tracking mode.
		if ( in_array( 'administrator', wp_get_current_user()->roles, true ) ) {
			return $commentdata;
		}

		$tracking_mode_enabled = ! empty( $comment_settings['tracking_mode'] );

		// Skip logged-in users if setting is disabled.
		if ( ! $comment_settings['check_logged_in_users'] && is_user_logged_in() ) {
			return $commentdata;
		}

		// Reviews are handled by WooCommerce Reviews (umbrella sub-integration).
		$comment_type = $commentdata['comment_type'] ?? '';

		if ( $comment_type === 'review' ) {
			return $commentdata;
		}

		// EDD product reviews are handled by the EDD Reviews integration.
		if ( $comment_type === 'edd_review' ) {
			return $commentdata;
		}

		// Check comment length constraints.
		$comment_content = $commentdata['comment_content'] ?? '';
		$comment_length  = mb_strlen( trim( $comment_content ) );

		if ( ! $tracking_mode_enabled ) {
			// Check minimum length.
			if ( $comment_length < $comment_settings['min_comment_length'] ) {
				wp_die(
					sprintf(
					/* translators: %d: minimum required comment length in characters. */
						esc_html__( 'Error: Comment must be at least %d characters long.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						absint( $comment_settings['min_comment_length'] )
					),
					esc_html__( 'Comment Too Short', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					[
						'response'  => 400,
						'back_link' => true,
					]
				);
			}

			// Check maximum length.
			if ( $comment_length > $comment_settings['max_comment_length'] ) {
				wp_die(
					sprintf(
					/* translators: %d: maximum allowed comment length in characters. */
						esc_html__( 'Error: Comment must be no longer than %d characters.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						absint( $comment_settings['max_comment_length'] )
					),
					esc_html__( 'Comment Too Long', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					[
						'response'  => 400,
						'back_link' => true,
					]
				);
			}
		}

		// Skip trackbacks if setting is disabled.
		if ( $comment_type === 'trackback' && ! $comment_settings['check_trackbacks'] ) {
			return $commentdata;
		}

		// Skip pingbacks if setting is disabled.
		if ( $comment_type === 'pingback' && ! $comment_settings['check_pingbacks'] ) {
			return $commentdata;
		}

		if ( UpgradeHelper::is_quota_exhausted_cached() ) {
			return $commentdata;
		}

		$existing_token = $commentdata['comment_meta']['activelayer_submission_id'] ?? '';

		if ( isset( $this->staged_submissions[ $existing_token ] ) ) {
			return $commentdata;
		}

		try {
			// Keep preprocessing request-local: WordPress can still reject the comment.
			$token                               = wp_generate_uuid4();
			$normalized_data                     = $this->normalize_form_data( $commentdata );
			$normalized_data['_wp_comment_data'] = $commentdata;
			$this->staged_submissions[ $token ]  = [
				'form_data'     => $normalized_data,
				'meta'          => $this->get_form_meta( $commentdata ),
				'tracking_mode' => $tracking_mode_enabled,
				'processed'     => false,
			];
			$commentdata['comment_meta']['activelayer_submission_id'] = $token;

			if ( ! $tracking_mode_enabled ) {
				$commentdata['comment_approved']        = 0;
				$this->pending_submission_ids[ $token ] = true;
			}
		} catch ( \Exception $exception ) {
			Logger::log( 'Comment staging failed', [ 'error' => $exception->getMessage() ] );
		}

		return $commentdata;
	}

	/**
	 * Force pending status for comments held for async verdict.
	 *
	 * Runs after `wp_check_comment_data()` decides the native approval
	 * status. When we intercepted this submission in `handle_submission`,
	 * override WordPress auto-approval (e.g. a previously-approved author)
	 * so the comment stays pending until the queue worker delivers a verdict.
	 *
	 * Preserves upstream spam, trash, and `WP_Error` decisions.
	 * Not applied in tracking mode (no submission ID tracked).
	 *
	 * @since 1.2.0
	 *
	 * @param int|string|\WP_Error $approved    Current approval decision.
	 * @param array                $commentdata Comment data passed through filters.
	 *
	 * @return int|string|\WP_Error Zero to force pending, original value otherwise.
	 */
	public function force_pending_status( $approved, $commentdata ) {

		if ( is_wp_error( $approved ) || in_array( $approved, [ 'spam', 'trash' ], true ) || ! is_array( $commentdata ) ) {
			return $approved;
		}

		$submission_id = $commentdata['comment_meta']['activelayer_submission_id'] ?? '';

		if ( $submission_id !== '' && isset( $this->pending_submission_ids[ (string) $submission_id ] ) ) {
			return 0;
		}

		return $approved;
	}

	/**
	 * Capture WordPress's native approval decision before our override.
	 *
	 * Runs at priority 98 — just before `force_pending_status` (99) — so we
	 * record what `wp_allow_comment()` would have used for this comment.
	 * The captured value is later persisted as `activelayer_original_status`
	 * meta in `handle_comment_post`, and read back by `restore_original_comment_status`
	 * when a verdict comes in with auto-action disabled.
	 *
	 * `WP_Error` is left for the upstream filter chain to handle.
	 *
	 * @since 1.2.0
	 *
	 * @param int|string|\WP_Error $approved    Current approval decision.
	 * @param array                $commentdata Comment data passed through filters.
	 *
	 * @return int|string|\WP_Error Unchanged input.
	 */
	public function capture_native_status( $approved, $commentdata ) {

		if ( is_wp_error( $approved ) || ! is_array( $commentdata ) ) {
			return $approved;
		}

		$submission_id = $commentdata['comment_meta']['activelayer_submission_id'] ?? '';

		if ( $submission_id !== '' && isset( $this->pending_submission_ids[ (string) $submission_id ] ) ) {
			$this->native_decisions[ (string) $submission_id ] = (string) $approved;
		}

		return $approved;
	}

	/**
	 * Handle comment post action to store comment ID.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Persist captured native approval decision as `activelayer_original_status` meta.
	 * @since 1.8.0 Finalize staged comments idempotently without updating product reviews or temporary token IDs.
	 *
	 * @param int|mixed       $comment_id  Comment ID (can be int or string).
	 * @param int|string|bool $approved    Comment approval status (can be 1, 0, 'spam', 'trash', true, false).
	 * @param array           $commentdata Comment data.
	 */
	public function handle_comment_post( $comment_id, $approved, array $commentdata ): void {

		// Product reviews are finalized by their dedicated integrations.
		if ( in_array( $commentdata['comment_type'] ?? '', [ 'review', 'edd_review' ], true ) ) {
			return;
		}

		// Ensure comment_id is an integer.
		$comment_id = (int) $comment_id;

		// Skip if integration is disabled.
		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		// Check if we have a ActiveLayer submission ID. wp_insert_comment has already
		// propagated `activelayer_submission_id` from $commentdata['comment_meta'] to
		// the commentmeta table, so we don't add it again here.
		if ( ! isset( $commentdata['comment_meta']['activelayer_submission_id'] ) ) {
			return;
		}

		$submission_id = (string) $commentdata['comment_meta']['activelayer_submission_id'];

		if ( isset( $this->staged_submissions[ $submission_id ] ) ) {
			$this->process_inserted_comment( $comment_id, $approved, $submission_id );

			return;
		}

		$this->finalize_legacy_comment( $comment_id, $submission_id );
	}

	/**
	 * Link a legacy submission without interpreting a temporary token as an ID.
	 *
	 * @since 1.8.0
	 *
	 * @param int    $comment_id    Persisted native comment ID.
	 * @param string $submission_id Legacy submission identifier.
	 */
	private function finalize_legacy_comment( int $comment_id, string $submission_id ): void {

		add_comment_meta( $comment_id, 'activelayer_status', 'pending' );

		// Persist the native pre-override approval decision so we can restore it
		// later when a verdict arrives with auto-action disabled.
		if ( isset( $this->native_decisions[ $submission_id ] ) ) {
			add_comment_meta(
				$comment_id,
				'activelayer_original_status',
				$this->native_decisions[ $submission_id ]
			);
			unset( $this->native_decisions[ $submission_id ] );
		}

		// Only persisted numeric IDs may reach storage; casting a replayed UUID
		// to an integer could overwrite an unrelated submission with that prefix.
		if ( ctype_digit( $submission_id ) ) {
			Storage::get_instance()->update_entry_id( $submission_id, (string) $comment_id );
		}
	}

	/**
	 * Finalize preprocessed comments even when the caller bypasses comment_post.
	 *
	 * @since 1.8.0
	 *
	 * @param int        $comment_id Native comment ID.
	 * @param WP_Comment $comment    Successfully inserted comment.
	 */
	public function handle_comment_inserted( $comment_id, $comment ): void {

		$token = (string) get_comment_meta( (int) $comment_id, 'activelayer_submission_id', true );

		if ( ! isset( $this->staged_submissions[ $token ] ) ) {
			return;
		}

		$this->process_inserted_comment( (int) $comment_id, $comment->comment_approved, $token );
	}

	/**
	 * Persist and queue a submission only after its native comment exists.
	 *
	 * The stored entry ID and original moderation decision are available before
	 * enqueueing, allowing a concurrent worker to act before token replacement.
	 * Queue failures preserve linkage for retries and restore native moderation.
	 *
	 * @since 1.8.0
	 *
	 * @param int        $comment_id Native comment ID.
	 * @param int|string $approved   Native insertion status.
	 * @param string     $token      Request-local correlation token.
	 */
	private function process_inserted_comment( int $comment_id, $approved, string $token ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$staged = $this->staged_submissions[ $token ];

		if ( $staged['processed'] ) {
			return;
		}

		$this->staged_submissions[ $token ]['processed'] = true;
		$this->finalizing_comment_ids[ $comment_id ]     = true;
		$meta             = $staged['meta'];
		$meta['entry_id'] = (string) $comment_id;
		$submission_id    = '';
		$queue_failed     = true;

		update_comment_meta( $comment_id, 'activelayer_status', 'pending' );
		update_comment_meta( $comment_id, 'activelayer_original_status', $this->native_decisions[ $token ] ?? (string) ( $staged['form_data']['_wp_comment_data']['comment_approved'] ?? $approved ) );

		try {
			$submission_id = $this->process_submission_direct( $staged['form_data'], $meta );
			$queue_failed  = ! empty( $meta['queue_failed'] );
		} catch ( \Exception $exception ) {
			Logger::log(
				'Inserted comment could not be queued',
				[
					'comment_id' => $comment_id,
					'error'      => $exception->getMessage(),
				]
			);
			$submission    = Storage::get_instance()->find_by_entry_id( $this->integration->get_slug(), (string) $comment_id );
			$submission_id = (string) ( $submission['id'] ?? '' );
		}

		unset( $this->native_decisions[ $token ] );
		$this->staged_submissions[ $token ] = [ 'processed' => true ];

		if ( $submission_id === '' ) {
			$this->integration->handle_submission_failed( $token, [ 'provider' => $this->integration->get_slug() ] );
			delete_comment_meta( $comment_id, 'activelayer_submission_id' );
			delete_comment_meta( $comment_id, 'activelayer_original_status' );
			unset( $this->pending_submission_ids[ $token ], $this->finalizing_comment_ids[ $comment_id ] );

			return;
		}

		update_comment_meta( $comment_id, 'activelayer_submission_id', $submission_id );
		unset( $this->pending_submission_ids[ $token ] );

		if ( ! $staged['tracking_mode'] ) {
			$this->pending_submission_ids[ $submission_id ] = true;
		}

		if ( $queue_failed ) {
			Storage::get_instance()->mark_failed(
				$submission_id,
				[
					'api_response' => [
						'success' => false,
						'error'   => 'queue_unavailable',
					],
				]
			);
			$this->integration->handle_submission_failed( $submission_id, [ 'provider' => $this->integration->get_slug() ] );
			unset( $this->pending_submission_ids[ $submission_id ] );
		}

		unset( $this->finalizing_comment_ids[ $comment_id ] );
	}

	/**
	 * Defer author mail triggered by synchronous status restoration to core.
	 *
	 * The later comment_post hook sends fallback notifications once. Comments
	 * awaiting a verdict stay suppressed in the insertion request; the worker
	 * request has no request-local hold and sends normal status notifications.
	 *
	 * @since 1.8.0
	 *
	 * @param bool $maybe_notify Current notification decision.
	 * @param int  $comment_id   Native comment ID.
	 *
	 * @return bool Whether the author should be notified now.
	 */
	public function maybe_suppress_author_notification( $maybe_notify, $comment_id ): bool {

		if ( isset( $this->finalizing_comment_ids[ (int) $comment_id ] ) ) {
			return false;
		}

		return $this->maybe_suppress_moderator_notification( $maybe_notify, $comment_id );
	}

	/**
	 * Suppress WordPress moderator notification while ActiveLayer awaits an async verdict.
	 *
	 * Hooked to `notify_moderator` to short-circuit the email that core sends
	 * from `wp_new_comment_notify_moderator` when our `pre_comment_approved`
	 * filter has forced the comment to pending. The deferred notification is
	 * dispatched later from `CommentVerdictTrait::restore_original_comment_status`.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $maybe_notify Current notify decision.
	 * @param int  $comment_id   Comment ID being evaluated.
	 *
	 * @return bool
	 */
	public function maybe_suppress_moderator_notification( $maybe_notify, $comment_id ): bool {

		if ( ! $maybe_notify ) {
			return (bool) $maybe_notify;
		}

		$submission_id = (string) get_comment_meta( (int) $comment_id, 'activelayer_submission_id', true );

		if ( $submission_id === '' ) {
			return (bool) $maybe_notify;
		}

		if ( isset( $this->pending_submission_ids[ $submission_id ] ) ) {
			return false;
		}

		return (bool) $maybe_notify;
	}

	/**
	 * Normalize WordPress comment data to standard format.
	 *
	 * @since 1.0.0
	 * @since 1.4.0 Deliver submission body raw (unslashed, content-preserving) for the API.
	 *
	 * @param array $commentdata Raw WordPress comment data.
	 *
	 * @return array Normalized data.
	 */
	public function normalize_form_data( array $commentdata ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$email       = isset( $commentdata['comment_author_email'] ) ? sanitize_email( (string) $commentdata['comment_author_email'] ) : '';
		$name        = isset( $commentdata['comment_author'] ) ? RequestHelper::sanitize_field_value( (string) $commentdata['comment_author'] ) : '';
		$message_raw = isset( $commentdata['comment_content'] ) ? (string) $commentdata['comment_content'] : '';
		$message     = SubmissionBodySanitizer::sanitize( wp_unslash( $message_raw ) );
		$website_url = isset( $commentdata['comment_author_url'] ) ? esc_url_raw( (string) $commentdata['comment_author_url'] ) : '';
		$post_id     = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
		$parent_id   = isset( $commentdata['comment_parent'] ) ? (int) $commentdata['comment_parent'] : 0;

		$normalized = [
			'email'       => $email,
			'name'        => $name,
			'message'     => $message,
			'website_url' => $website_url,
			'post_id'     => $post_id,
			'parent'      => $parent_id,
		];

		$base_data = [
			'ip'         => RequestHelper::get_user_ip(),
			'user_agent' => RequestHelper::get_user_agent(),
		];

		$normalized = array_filter(
			$normalized,
			static function ( $value ) {

				return $value !== null && $value !== '';
			}
		);

		$context = $this->build_submission_context( $commentdata );

		if ( ! empty( $context ) ) {
			$normalized['context'] = $context;
		}

		return array_merge( $base_data, $normalized );
	}

	/**
	 * Build contextual payload to accompany API calls.
	 *
	 * @since 1.0.0
	 *
	 * @param array $commentdata Raw comment data.
	 *
	 * @return array Context data.
	 */
	private function build_submission_context( array $commentdata ): array {

		/**
		 * Filters the context sent with comment submissions.
		 *
		 * @since 1.0.0
		 *
		 * @param array $context     Context data being sent.
		 * @param array $commentdata Raw comment data.
		 */
		$context = apply_filters( 'activelayer_comment_submission_context', [], $commentdata ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		if ( ! is_array( $context ) || empty( $context ) ) {
			return [];
		}

		return array_filter(
			$context,
			static function ( $value ) {

				return $value !== null && $value !== '';
			}
		);
	}


	/**
	 * Get WordPress comment metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param array $commentdata WordPress comment data.
	 *
	 * @return array Comment metadata.
	 */
	public function get_form_meta( array $commentdata ): array {

		$post_id = $commentdata['comment_post_ID'] ?? 0;
		$post    = get_post( $post_id );

		return [
			'provider'      => $this->integration->get_slug(),
			'provider_name' => $this->integration->get_name(),
			'form_id'       => (string) $post_id, // Use post ID as form identifier.
			'post_id'       => $post_id,
			'post_title'    => $post->post_title ?? '',
			'post_type'     => $post->post_type ?? '',
			'post_url'      => get_permalink( $post_id ),
			'comment_type'  => $commentdata['comment_type'] ?? '',
			'user_id'       => $commentdata['user_id'] ?? 0,
		];
	}

	/**
	 * Process submission directly without additional normalization.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form_data Already normalized form data.
	 * @param array $meta      Form metadata.
	 *
	 * @return string Submission ID.
	 *
	 * @throws Exception If failed to create submission in storage.
	 */
	private function process_submission_direct( array $form_data, array &$meta ): string {

		try {
			return $this->integration->process_submission( $form_data, $meta );
		} catch ( Exception $exception ) {
			Logger::log(
				'Failed to queue comment submission',
				[
					'error'   => $exception->getMessage(),
					'post_id' => $meta['post_id'] ?? null,
				]
			);

			throw $exception;
		}
	}
}

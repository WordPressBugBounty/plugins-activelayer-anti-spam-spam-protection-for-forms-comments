<?php

namespace ActiveLayer\Integrations\Comments;

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Integrations\Submission\SubmissionBodySanitizer;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;
use Exception;

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
	 * auto-approval (e.g., previously-approved author, disallowed-keys
	 * list) before the queue worker runs.
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
	 * later when a verdict comes back with auto-action disabled.
	 *
	 * @since 1.2.0
	 *
	 * @var array<string, string>
	 */
	private $native_decisions = [];

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
	 */
	private function hooks(): void {

		// Hook into comment submission before it's saved.
		add_filter( 'preprocess_comment', [ $this, 'handle_submission' ], 10, 1 );

		// Capture WordPress's native approval decision just before we override it.
		add_filter( 'pre_comment_approved', [ $this, 'capture_native_status' ], 98, 2 );

		// Force pending status late so WordPress auto-approval cannot override us.
		add_filter( 'pre_comment_approved', [ $this, 'force_pending_status' ], 99, 2 );

		// Hook into comment insertion to get the comment ID.
		add_action( 'comment_post', [ $this, 'handle_comment_post' ], 10, 3 );

		add_filter( 'notify_moderator', [ $this, 'maybe_suppress_moderator_notification' ], 10, 2 );
	}

	/**
	 * Handle WordPress comment submission.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Skip review comment type and track pending submissions for force-pending filter.
	 * @since 1.5.0 Skip edd_review comment type (handled by EDD Reviews integration).
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

		try {
			$commentdata['comment_meta'] = $commentdata['comment_meta'] ?? [];

			// Normalize comment data for API.
			$normalized_data = $this->normalize_form_data( $commentdata );

			// Get comment metadata.
			$meta = $this->get_form_meta( $commentdata );

			// Store original comment data for later use.
			$normalized_data['_wp_comment_data'] = $commentdata;

			// Process submission.
			$submission_id = $this->process_submission_direct( $normalized_data, $meta );

			$queue_failed = ! empty( $meta['queue_failed'] );

			if ( ! $queue_failed ) {
				// Store submission ID so wp_insert_comment auto-propagates it as comment meta.
				$commentdata['comment_meta']['activelayer_submission_id'] = $submission_id;

				// In tracking mode, let WordPress handle comment approval normally.
				// In normal mode, set comment as pending for moderation until API verification.
				if ( ! $tracking_mode_enabled ) {
					$commentdata['comment_approved']                = 0;
					$this->pending_submission_ids[ $submission_id ] = true;
				}
			}
		} catch ( \Exception $e ) {
			Logger::log(
				'Comment submission handling failed',
				[
					'error' => $e->getMessage(),
				]
			);
		}

		return $commentdata;
	}

	/**
	 * Force pending status for comments held for async verdict.
	 *
	 * Runs after `wp_check_comment_data()` decides the native approval
	 * status. When we intercepted this submission in `handle_submission`,
	 * override WordPress auto-approval (previously-approved author,
	 * disallowed-keys, etc.) so the comment stays pending until the
	 * queue worker delivers a verdict.
	 *
	 * Preserves `WP_Error` returns so upstream short-circuits are not lost.
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

		if ( is_wp_error( $approved ) || ! is_array( $commentdata ) ) {
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
	 *
	 * @param int|mixed       $comment_id  Comment ID (can be int or string).
	 * @param int|string|bool $approved    Comment approval status (can be 1, 0, 'spam', 'trash', true, false).
	 * @param array           $commentdata Comment data.
	 */
	public function handle_comment_post( $comment_id, $approved, array $commentdata ): void {

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

		// Store comment ID as entry_id in submission for admin edit links.
		Storage::get_instance()->update_entry_id( $submission_id, (string) $comment_id );
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

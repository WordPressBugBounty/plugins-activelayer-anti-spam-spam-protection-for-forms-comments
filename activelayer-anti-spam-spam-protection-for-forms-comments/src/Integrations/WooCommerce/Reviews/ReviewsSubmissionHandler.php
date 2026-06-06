<?php

namespace ActiveLayer\Integrations\WooCommerce\Reviews;

use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Logger\Logger;
use ActiveLayer\Storage\Storage;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Reviews Submission Handler.
 *
 * Handles WooCommerce product review submissions, data normalization, and processing.
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Integrations\WooCommerce\Reviews
 */
class ReviewsSubmissionHandler {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.2.0
	 *
	 * @var ReviewsIntegration
	 */
	private $integration;

	/**
	 * Submission IDs that must remain pending until async verdict.
	 *
	 * Request-scoped set keyed by submission ID. Used by the
	 * `pre_comment_approved` filter to force `0` (pending) for reviews
	 * we intercepted in `preprocess_comment`, preventing WordPress/
	 * WooCommerce auto-approval before the queue worker runs.
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
	 * `wp_allow_comment()` chose for the review so we can restore it
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
	 * @since 1.2.0
	 *
	 * @param ReviewsIntegration $integration Parent integration.
	 */
	public function __construct( ReviewsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize submission handler.
	 *
	 * @since 1.2.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.2.0
	 * @since 1.2.0 Suppress core moderator notification while awaiting async verdict via `notify_moderator`.
	 */
	private function hooks(): void {

		add_filter( 'preprocess_comment', [ $this, 'handle_submission' ], 10, 1 );
		add_filter( 'pre_comment_approved', [ $this, 'capture_native_status' ], 98, 2 );
		add_filter( 'pre_comment_approved', [ $this, 'force_pending_status' ], 99, 2 );
		add_action( 'comment_post', [ $this, 'handle_comment_post' ], 10, 3 );

		add_filter( 'notify_moderator', [ $this, 'maybe_suppress_moderator_notification' ], 10, 2 );
	}

	/**
	 * Handle WooCommerce review submission.
	 *
	 * @since 1.2.0
	 *
	 * @param array $commentdata Comment data array.
	 *
	 * @return array Modified comment data.
	 */
	public function handle_submission( array $commentdata ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ! $this->integration->is_enabled() ) {
			return $commentdata;
		}

		$review_settings = $this->integration->get_admin_settings()->get_review_settings();

		// Only handle WooCommerce product reviews.
		$comment_type = $commentdata['comment_type'] ?? '';
		$post_id      = $commentdata['comment_post_ID'] ?? 0;

		if ( $comment_type !== 'review' || get_post_type( $post_id ) !== 'product' ) {
			return $commentdata;
		}

		// Skip logged-in users if setting is disabled.
		if ( ! $review_settings['check_logged_in_users'] && is_user_logged_in() ) {
			return $commentdata;
		}

		// Skip verified product owners if setting is disabled.
		if ( is_user_logged_in() && ! $review_settings['check_verified_owners'] ) {
			$email   = $commentdata['comment_author_email'] ?? '';
			$user_id = $commentdata['user_id'] ?? 0;

			if ( $email && function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( $email, (int) $user_id, (int) $post_id ) ) {
				return $commentdata;
			}
		}

		try {
			$commentdata['comment_meta'] = $commentdata['comment_meta'] ?? [];

			$normalized_data = $this->normalize_form_data( $commentdata );
			$meta            = $this->get_form_meta( $commentdata );

			$normalized_data['_wp_comment_data'] = $commentdata;

			$submission_id = $this->process_submission_direct( $normalized_data, $meta );

			$queue_failed = ! empty( $meta['queue_failed'] );

			if ( ! $queue_failed ) {
				$commentdata['comment_meta']['activelayer_submission_id'] = $submission_id;
				$commentdata['comment_approved']                          = 0;
				$this->pending_submission_ids[ $submission_id ]           = true;
			}
		} catch ( Exception $e ) {
			Logger::log(
				'Review submission handling failed',
				[
					'error'      => $e->getMessage(),
					'product_id' => $post_id,
				]
			);
		}

		return $commentdata;
	}

	/**
	 * Force pending status for reviews held for async verdict.
	 *
	 * Runs after `wp_check_comment_data()` decides the native approval
	 * status. When we intercepted this submission in `handle_submission`,
	 * override WordPress/WooCommerce auto-approval so the review stays
	 * pending until the queue worker delivers a verdict.
	 *
	 * Preserves `WP_Error` returns so upstream short-circuits are not lost.
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
	 * record what `wp_allow_comment()` would have used for this review.
	 * The captured value is later persisted as `activelayer_original_status`
	 * meta in `handle_comment_post`, and read back by `restore_original_comment_status`
	 * when a verdict comes in with auto-action disabled.
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
	 * @since 1.2.0
	 *
	 * @param int|mixed       $comment_id  Comment ID.
	 * @param int|string|bool $approved    Comment approval status.
	 * @param array           $commentdata Comment data.
	 */
	public function handle_comment_post( $comment_id, $approved, array $commentdata ): void {

		$comment_id = (int) $comment_id;

		if ( ! $this->integration->is_enabled() ) {
			return;
		}

		// wp_insert_comment has already propagated `activelayer_submission_id`
		// from $commentdata['comment_meta'] to the commentmeta table, so we
		// don't add it again here.
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

		Storage::get_instance()->update_entry_id( $submission_id, (string) $comment_id );
	}

	/**
	 * Suppress WordPress moderator notification while ActiveLayer awaits an async verdict.
	 *
	 * Hooked to `notify_moderator` to short-circuit the email that core sends
	 * from `wp_new_comment_notify_moderator` when our `pre_comment_approved`
	 * filter has forced the review to pending. The deferred notification is
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
	 * Normalize WooCommerce review data to standard format.
	 *
	 * @since 1.2.0
	 *
	 * @param array $commentdata Raw WordPress comment data.
	 *
	 * @return array Normalized data.
	 */
	public function normalize_form_data( array $commentdata ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$email       = isset( $commentdata['comment_author_email'] ) ? sanitize_email( (string) $commentdata['comment_author_email'] ) : '';
		$name        = isset( $commentdata['comment_author'] ) ? RequestHelper::sanitize_field_value( (string) $commentdata['comment_author'] ) : '';
		$message_raw = isset( $commentdata['comment_content'] ) ? (string) $commentdata['comment_content'] : '';
		$message     = RequestHelper::sanitize_field_value( $message_raw );
		$post_id     = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;

		$normalized = [
			'email'   => $email,
			'name'    => $name,
			'message' => $message,
			'post_id' => $post_id,
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
	 * Build contextual payload for WooCommerce review.
	 *
	 * @since 1.2.0
	 *
	 * @param array $commentdata Raw comment data.
	 *
	 * @return array Context data.
	 */
	private function build_submission_context( array $commentdata ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
		$email   = $commentdata['comment_author_email'] ?? '';
		$user_id = $commentdata['user_id'] ?? 0;

		// Read rating from the public review form. WordPress core does not nonce-protect
		// comment submissions (anonymous comments must work without auth), so the value
		// is treated as untrusted: cast to int and clamped to the WC rating range 0-5.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Untrusted input, sanitised via int cast and 0-5 clamp on the next line.
		$raw_rating = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;
		$rating     = max( 0, min( 5, $raw_rating ) );

		$verified = false;

		if ( $email && function_exists( 'wc_customer_bought_product' ) ) {
			$verified = wc_customer_bought_product( $email, (int) $user_id, $post_id );
		}

		$context = [
			'form_id'      => 'wc_review',
			'product_id'   => $post_id,
			'product_name' => $post_id > 0 ? get_the_title( $post_id ) : '',
			'rating'       => $rating,
			'verified'     => (bool) $verified,
		];

		return array_filter(
			$context,
			static function ( $value ) {

				return $value !== null && $value !== '';
			}
		);
	}

	/**
	 * Get WooCommerce review metadata.
	 *
	 * @since 1.2.0
	 *
	 * @param array $commentdata WordPress comment data.
	 *
	 * @return array Review metadata.
	 */
	public function get_form_meta( array $commentdata ): array {

		$post_id = $commentdata['comment_post_ID'] ?? 0;
		$post    = get_post( $post_id );

		return [
			'provider'      => $this->integration->get_slug(),
			'provider_name' => $this->integration->get_name(),
			'form_id'       => (string) $post_id,
			'post_id'       => $post_id,
			'post_title'    => $post->post_title ?? '',
			'post_type'     => $post->post_type ?? '',
			'post_url'      => get_permalink( $post_id ),
			'comment_type'  => $commentdata['comment_type'] ?? 'review',
			'user_id'       => $commentdata['user_id'] ?? 0,
		];
	}

	/**
	 * Process submission directly.
	 *
	 * @since 1.2.0
	 *
	 * @param array $form_data Normalized form data.
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
				'Failed to queue review submission',
				[
					'error'      => $exception->getMessage(),
					'product_id' => $meta['post_id'] ?? null,
				]
			);

			throw $exception;
		}
	}
}

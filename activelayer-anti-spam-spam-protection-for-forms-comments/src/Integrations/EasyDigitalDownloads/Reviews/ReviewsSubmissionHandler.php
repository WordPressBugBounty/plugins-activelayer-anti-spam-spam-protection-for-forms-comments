<?php

namespace ActiveLayer\Integrations\EasyDigitalDownloads\Reviews;

use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Integrations\Comments\PluginInitiatedGuard;
use ActiveLayer\Integrations\Submission\SubmissionBodySanitizer;
use ActiveLayer\Logger\Logger;
use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD Reviews Submission Handler.
 *
 * EDD reviews are WordPress comments (`comment_type = 'edd_review'`) posted
 * against the `download` CPT by EDD Reviews' own pipeline in
 * `EDD_Reviews::process_review()`:
 *
 *   1. `$comment_allowed = wp_allow_comment( $args )`  — fires `pre_comment_approved` (our check_review).
 *   2. `wp_insert_comment( wp_filter_comment( $args ) )`.
 *   3. `add_comment_meta( 'edd_review_approved', $comment_allowed )` — uses the step 1 value.
 *   4. `do_action( 'edd_reviews_after_insert_review', $review_id, $args )` — our handle_after_insert.
 *   5. `update_post_meta( 'edd_reviews_average_rating', ... )`.
 *   6. `if ( 1 === $comment_allowed ) create_reviewer_discount()` — the money side-effect.
 *
 * SYNCHRONOUS MODEL. The spam check runs inline at step 1 (`pre_comment_approved`),
 * the only lever on `$comment_allowed`. Because the verdict is known before EDD
 * freezes that value:
 *   - a SPAM review returns `'spam'` → handle_after_insert hides it (EDD-native meta
 *     spam flag or silent-discard) AND step 6 never issues a reviewer discount (`1 === 'spam'` is false);
 *   - a CLEAN review returns the native decision unchanged — ActiveLayer never approves
 *     or discounts a review itself; EDD inserts clean reviews approved as it normally would;
 *   - any API/quota/storage failure (or disabled auto-spam) likewise returns the native
 *     decision unchanged (fail-open: never block a review on infrastructure failure).
 *
 * The verdict is final at insert time — there is no async hold/restore dance and no
 * queue-worker verdict handler. `check_review` stashes the verdict in {@see $pending_verdict}
 * (one review per request) so `handle_after_insert` can apply the
 * EDD spam side-effects (silent-discard / EDD-native meta spam flag) once the comment ID
 * exists. Because that mutation runs before EDD's average-rating recompute (step 5),
 * the cached average stays correct with no manual refresh.
 *
 * @since 1.5.0
 *
 * @package ActiveLayer\Integrations\EasyDigitalDownloads\Reviews
 */
class ReviewsSubmissionHandler {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.5.0
	 *
	 * @var ReviewsIntegration
	 */
	private $integration;

	/**
	 * Request-scoped verdict bridge between the two EDD hooks.
	 *
	 * EDD's `process_review()` handles exactly one review per request, so a single
	 * slot carries the verdict from `pre_comment_approved` (where it is computed) to
	 * `edd_reviews_after_insert_review` (where the comment ID exists and spam
	 * side-effects are applied). Storing a scalar — rather than keying on comment
	 * fields — means a `preprocess_comment` filter that rewrites the comment between
	 * the two hooks cannot desync the lookup.
	 *
	 * @since 1.5.0
	 *
	 * @var array{submission_id:string, verdict:string}|null
	 */
	private $pending_verdict = null;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param ReviewsIntegration $integration Parent integration.
	 */
	public function __construct( ReviewsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize submission handler.
	 *
	 * @since 1.5.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * `pre_comment_approved` runs at priority 99 (after core/third-party approval
	 * logic) so the value we return is the one EDD freezes into `$comment_allowed`,
	 * which gates both the visibility meta and the reviewer discount.
	 *
	 * @since 1.5.0
	 */
	private function hooks(): void {

		add_filter( 'pre_comment_approved', [ $this, 'check_review' ], 99, 2 );
		add_action( 'edd_reviews_after_insert_review', [ $this, 'handle_after_insert' ], 10, 1 );
	}

	/**
	 * Synchronously spam-check an EDD review at `pre_comment_approved`.
	 *
	 * Returns the approval scalar EDD freezes into `$comment_allowed`: `'spam'` for an
	 * auto-actioned spam review (hidden + discount denied), and the unchanged native
	 * decision for clean / fail-open / auto-spam-disabled cases (ActiveLayer never
	 * approves or discounts a review itself).
	 *
	 * @since 1.5.0
	 *
	 * @param int|string|WP_Error $approved     WordPress approval decision (1|0|'spam'|'trash'|WP_Error).
	 * @param array               $comment_data EDD review args (no comment_meta, no rating/title).
	 *
	 * @return int|string|WP_Error
	 */
	public function check_review( $approved, $comment_data ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Preserve WP_Error short-circuits (duplicate/flood) and malformed shapes.
		if ( $approved instanceof WP_Error || ! is_array( $comment_data ) ) {
			return $approved;
		}

		// Front-end review submissions only: skip admin edits, REST, and our own
		// plugin-initiated comment status changes.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || PluginInitiatedGuard::is_active() ) {
			return $approved;
		}

		if ( ! $this->should_check( $comment_data ) ) {
			return $approved;
		}

		try {
			$normalized                     = $this->normalize_form_data( $comment_data );
			$normalized['_wp_comment_data'] = $comment_data;

			$result = $this->integration->process_submission_synchronously( $normalized, $this->get_form_meta( $comment_data ) );
		} catch ( Exception $e ) {
			Logger::log( 'EDD review sync check failed', [ 'error' => $e->getMessage() ] );

			return $approved; // Fail-open.
		}

		// Fail-open: API/quota/storage error must never block the review or withhold
		// the discount. The submission row is recorded 'failed' for admin visibility.
		if ( empty( $result['success'] ) ) {
			return $approved;
		}

		$verdict  = $result['verdict'] ?? 'clean';
		$settings = $this->integration->get_admin_settings()->get_review_settings();

		// One review per request — carry the verdict to handle_after_insert.
		$this->pending_verdict = [
			'submission_id' => (string) ( $result['submission_id'] ?? '' ),
			'verdict'       => $verdict,
		];

		if ( $verdict === 'spam' && ! empty( $settings['auto_spam_detected'] ) ) {
			// 'spam' freezes $comment_allowed = 'spam': EDD hides the review and the
			// `1 === $comment_allowed` discount gate is skipped.
			return 'spam';
		}

		// Clean reviews need no action: EDD already inserts them approved. Returning the
		// native decision keeps ActiveLayer out of the approval/discount path entirely.
		return $approved;
	}

	/**
	 * Apply EDD spam side-effects once the review comment exists.
	 *
	 * Delegates an auto-actioned spam verdict to the integration, which either
	 * hard-deletes high-confidence spam or flags it spam EDD-native via the
	 * `edd_review_approved` meta. Clean / fail-open / auto-spam-disabled reviews need no post-insert
	 * work — EDD already wrote the correct visibility meta from our
	 * `pre_comment_approved` return value.
	 *
	 * @since 1.5.0
	 *
	 * @param int $review_id The ID of the inserted review.
	 *
	 * @return void
	 */
	public function handle_after_insert( $review_id ): void {

		$review_id = (int) $review_id;

		if ( ! $review_id ) {
			return;
		}

		$result = $this->pending_verdict;

		if ( $result === null || $result['verdict'] !== 'spam' ) {
			return;
		}

		$settings = $this->integration->get_admin_settings()->get_review_settings();

		if ( empty( $settings['auto_spam_detected'] ) ) {
			return;
		}

		$this->integration->handle_spam_review( $review_id, $result['submission_id'] );
	}

	/**
	 * Decide whether a review should be spam-checked.
	 *
	 * @since 1.5.0
	 *
	 * @param array $comment_data EDD review args.
	 *
	 * @return bool
	 */
	private function should_check( array $comment_data ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ( $comment_data['comment_type'] ?? '' ) !== 'edd_review' ) {
			return false;
		}

		$post_id = isset( $comment_data['comment_post_ID'] ) ? (int) $comment_data['comment_post_ID'] : 0;

		if ( get_post_type( $post_id ) !== 'download' ) {
			return false;
		}

		// Only gate top-level reviews. Replies (owner/admin responses) post through
		// EDD's process_reply() and must not be spam-checked.
		if ( ! empty( $comment_data['comment_parent'] ) ) {
			return false;
		}

		if ( ! $this->integration->is_enabled() ) {
			return false;
		}

		$review_settings = $this->integration->get_admin_settings()->get_review_settings();

		// Skip logged-in users when the setting is disabled.
		if ( empty( $review_settings['check_logged_in_users'] ) && is_user_logged_in() ) {
			return false;
		}

		// Skip verified product owners when the setting is disabled.
		if ( is_user_logged_in() && empty( $review_settings['check_verified_owners'] ) ) {
			$email   = $comment_data['comment_author_email'] ?? '';
			$user_id = $comment_data['user_id'] ?? 0;

			if ( $email && function_exists( 'edd_has_user_purchased' ) && edd_has_user_purchased( (int) $user_id, $post_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize EDD review data to standard format.
	 *
	 * @since 1.5.0
	 *
	 * @param array $commentdata Raw WordPress comment data.
	 *
	 * @return array Normalized data.
	 */
	public function normalize_form_data( array $commentdata ): array {

		$email       = isset( $commentdata['comment_author_email'] ) ? sanitize_email( (string) $commentdata['comment_author_email'] ) : '';
		$name        = isset( $commentdata['comment_author'] ) ? RequestHelper::sanitize_field_value( (string) $commentdata['comment_author'] ) : '';
		$message_raw = isset( $commentdata['comment_content'] ) ? (string) $commentdata['comment_content'] : '';
		$message     = SubmissionBodySanitizer::sanitize( wp_unslash( $message_raw ) );
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
	 * Build contextual payload for the EDD review.
	 *
	 * @since 1.5.0
	 *
	 * @param array $commentdata Raw comment data.
	 *
	 * @return array Context data.
	 */
	private function build_submission_context( array $commentdata ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
		$email   = $commentdata['comment_author_email'] ?? '';
		$user_id = $commentdata['user_id'] ?? 0;

		// Read rating and title from the public review form. EDD Reviews does not
		// nonce-protect these per-field (guest reviews must work), so values are
		// treated as untrusted: the rating is unslashed + absint and clamped to
		// 0-5; the title is unslashed and run through sanitize_text_field().
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Untrusted public form input, sanitised inline below.
		$rating = isset( $_POST['edd-reviews-review-rating'] )
			? min( 5, absint( wp_unslash( $_POST['edd-reviews-review-rating'] ) ) )
			: 0;

		$title = isset( $_POST['edd-reviews-review-title'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['edd-reviews-review-title'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$verified = false;

		if ( $email && function_exists( 'edd_has_user_purchased' ) ) {
			$verified = edd_has_user_purchased( (int) $user_id, $post_id );
		}

		$context = [
			'form_id'       => 'edd_review',
			'download_id'   => $post_id,
			'download_name' => $post_id > 0 ? get_the_title( $post_id ) : '',
			'review_title'  => $title,
			'rating'        => $rating,
			'verified'      => (bool) $verified,
		];

		return array_filter(
			$context,
			static function ( $value ) {

				return $value !== null && $value !== '';
			}
		);
	}

	/**
	 * Get EDD review metadata.
	 *
	 * @since 1.5.0
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
			'comment_type'  => $commentdata['comment_type'] ?? 'edd_review',
			'user_id'       => $commentdata['user_id'] ?? 0,
		];
	}
}

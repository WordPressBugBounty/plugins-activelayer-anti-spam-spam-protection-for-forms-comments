<?php

namespace ActiveLayer\Integrations\EasyDigitalDownloads\Reviews;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\Comments\PluginInitiatedGuard;
use ActiveLayer\Integrations\Traits\SilentDiscardTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD Reviews Integration.
 *
 * Handles Easy Digital Downloads product review spam detection. EDD reviews are
 * WordPress comments (`comment_type = 'edd_review'`) on the `download` CPT,
 * managed by the EDD Reviews add-on.
 *
 * Detection is SYNCHRONOUS: {@see ReviewsSubmissionHandler} runs the spam check
 * inline at `pre_comment_approved`, the only lever on EDD's `$comment_allowed`
 * value (which gates both the review's public visibility and the reviewer
 * discount). The verdict is therefore final at insert time — there is no async
 * hold/restore lifecycle and no queue-worker verdict handler. This class owns the
 * EDD-specific spam side-effects the handler delegates to (silent-discard +
 * EDD-native meta spam-flagging) plus payload normalization and settings.
 *
 * @since 1.5.0
 *
 * @package ActiveLayer\Integrations\EasyDigitalDownloads\Reviews
 */
class ReviewsIntegration extends BaseFormIntegration {

	use SilentDiscardTrait;

	/**
	 * Submission handler instance.
	 *
	 * @since 1.5.0
	 *
	 * @var ReviewsSubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings instance.
	 *
	 * @since 1.5.0
	 *
	 * @var ReviewsAdminSettings
	 */
	private $admin_settings;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {

		// Pass the slug explicitly so settings load from `activelayer_edd_reviews_settings`
		// during the parent constructor, not from an auto-generated key.
		parent::__construct( 'EDD Reviews', 'edd_reviews' );

		$this->submission_handler = new ReviewsSubmissionHandler( $this );
		$this->admin_settings     = new ReviewsAdminSettings( $this );
	}

	/**
	 * Initialize integration.
	 *
	 * @since 1.5.0
	 */
	public function init(): void {

		if ( ! $this->is_active() ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->submission_handler->init();
		$this->admin_settings->init();

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.5.0
	 */
	private function hooks(): void {

		// EDD Reviews renders this hook inside its review <form> (templates/reviews.php).
		add_action( 'edd_reviews_form_before_submit', [ $this, 'output_signal_fields' ] );
	}

	/**
	 * Output hidden ActiveLayer signal fields in the EDD review form.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function output_signal_fields(): void {

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		FieldRenderer::output_all();
	}

	/**
	 * Check if EDD Reviews is available.
	 *
	 * @since 1.5.0
	 *
	 * @return bool True if EDD and the EDD Reviews add-on are active.
	 */
	public function is_active(): bool {

		return function_exists( 'EDD' ) && class_exists( 'EDD_Reviews' );
	}

	/**
	 * Apply the spam side-effects for an auto-actioned spam review.
	 *
	 * The storefront visibility is already handled by EDD (it wrote
	 * `edd_review_approved = 'spam'` from the `'spam'` value the handler returned
	 * at `pre_comment_approved`). Here we either hard-delete high-confidence spam
	 * (silent-discard) or mark it spam EDD-native — only the `edd_review_approved`
	 * meta carries the spam state, mirroring EDD's own "Mark as Spam" action, which
	 * never touches `comment_approved`. Leaving the comment at `comment_approved = 1`
	 * keeps it visible in EDD's Reviews → Spam list (whose query only surfaces
	 * comments approved 0/1) while `hide_reviews` keeps it off the storefront. EDD
	 * recomputes `edd_reviews_average_rating` immediately after this hook, so the
	 * cached average reflects the spam flag without manual intervention.
	 *
	 * @since 1.5.0
	 * @since 1.5.0 Mark non-discarded spam EDD-native (meta only) instead of moving the comment to the WordPress spam folder, so it appears in EDD's Reviews → Spam list.
	 *
	 * @param int    $comment_id    Review comment ID.
	 * @param string $submission_id Submission ID (source of the spam score).
	 *
	 * @return void
	 */
	public function handle_spam_review( int $comment_id, string $submission_id ): void {

		$discard = $this->should_silently_discard( $submission_id, $this->get_settings() );

		PluginInitiatedGuard::run(
			static function () use ( $comment_id, $discard ) {

				if ( $discard ) {
					// Hard-delete high-confidence spam; the reviewer discount was
					// already denied at pre_comment_approved.
					wp_delete_comment( $comment_id, true );

					return;
				}

				// Mark spam the EDD-native way: flag the meta and leave
				// comment_approved untouched. EDD already wrote this meta from our
				// 'spam' verdict at insert; reassert it defensively so the review is
				// guaranteed to land in EDD's Reviews → Spam list.
				update_comment_meta( $comment_id, 'edd_review_approved', 'spam' );
			}
		);
	}

	/**
	 * Normalize review data to standard format.
	 *
	 * @since 1.5.0
	 *
	 * @param array $raw_data Raw review data.
	 *
	 * @return array Normalized data.
	 */
	protected function normalize_form_data( array $raw_data ): array {

		if ( isset( $raw_data['_wp_comment_data'] ) && is_array( $raw_data['_wp_comment_data'] ) ) {
			$raw_data = $raw_data['_wp_comment_data'];
		}

		return $this->submission_handler->normalize_form_data( $raw_data );
	}

	/**
	 * Get review metadata.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $form_instance Comment data array.
	 *
	 * @return array Review metadata.
	 */
	protected function get_form_meta( $form_instance ): array {

		return $this->submission_handler->get_form_meta( $form_instance );
	}

	/**
	 * Get default review integration settings.
	 *
	 * @since 1.5.0
	 *
	 * @return array
	 */
	protected function get_default_settings(): array {

		return ReviewsAdminSettings::DEFAULT_SETTINGS;
	}

	/**
	 * Submissions from this integration represent product reviews.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public function get_data_type(): string {

		return 'review';
	}

	/**
	 * Check if the integration is enabled for runtime operation.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {

		return SettingsHelper::has_api_key() && ! empty( $this->settings['enabled'] );
	}

	/**
	 * Check if integration is enabled in settings.
	 *
	 * @since 1.5.0
	 *
	 * @return bool True if enabled in settings.
	 */
	public function is_setting_enabled(): bool {

		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Get admin settings instance.
	 *
	 * @since 1.5.0
	 *
	 * @return ReviewsAdminSettings Admin settings instance.
	 */
	public function get_admin_settings(): ReviewsAdminSettings {

		return $this->admin_settings;
	}
}

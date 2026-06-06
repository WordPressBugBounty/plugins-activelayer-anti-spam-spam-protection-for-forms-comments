<?php

namespace ActiveLayer\Integrations\WooCommerce\Reviews;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\Traits\CommentVerdictTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Reviews Integration.
 *
 * Main integration class that handles WooCommerce product review spam detection.
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Integrations\WooCommerce\Reviews
 */
class ReviewsIntegration extends BaseFormIntegration {

	use CommentVerdictTrait;

	/**
	 * Submission handler instance.
	 *
	 * @since 1.2.0
	 *
	 * @var ReviewsSubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings instance.
	 *
	 * @since 1.2.0
	 *
	 * @var ReviewsAdminSettings
	 */
	private $admin_settings;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {

		// Pass the slug explicitly so settings load from `activelayer_wc_reviews_settings`
		// during the parent constructor, not from the auto-generated `woocommerce_reviews` key.
		parent::__construct( 'WooCommerce Reviews', 'wc_reviews' );

		$this->submission_handler = new ReviewsSubmissionHandler( $this );
		$this->admin_settings     = new ReviewsAdminSettings( $this );
	}

	/**
	 * Initialize integration.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
	 */
	private function hooks(): void {

		add_action( 'activelayer_queue_worker_verdict_received', [ $this, 'handle_verdict_action' ], 10, 3 );
		add_action( 'activelayer_queue_worker_submission_failed', [ $this, 'handle_submission_failed' ], 10, 2 );

		add_action( 'comment_form_after_fields', [ $this, 'output_environment_signals_field' ] );
		add_action( 'comment_form_logged_in_after', [ $this, 'output_environment_signals_field' ] );
	}

	/**
	 * Output hidden environment signals field in the review form.
	 *
	 * Only outputs on product pages (WooCommerce review forms).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function output_environment_signals_field(): void {

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		if ( get_post_type() !== 'product' ) {
			return;
		}

		FieldRenderer::output_all();
	}

	/**
	 * Check if WooCommerce is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if WooCommerce is active.
	 */
	public function is_active(): bool {

		return class_exists( 'WooCommerce' );
	}

	/**
	 * Handle API verdict action.
	 *
	 * @since 1.2.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $verdict       API verdict (clean/spam).
	 * @param array  $submission    Submission data.
	 */
	public function handle_verdict_action( string $submission_id, string $verdict, array $submission ): void {

		if ( $submission['provider'] !== $this->get_slug() ) {
			return;
		}

		$this->handle_verdict( $submission_id, $verdict );
	}

	/**
	 * Normalize review data to standard format.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 *
	 * @return array
	 */
	protected function get_default_settings(): array {

		return ReviewsAdminSettings::DEFAULT_SETTINGS;
	}

	/**
	 * Submissions from this integration represent product reviews.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_data_type(): string {

		return 'review';
	}

	/**
	 * Check if the integration is enabled for runtime operation.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {

		return SettingsHelper::has_api_key() && ! empty( $this->settings['enabled'] );
	}

	/**
	 * Check if integration is enabled in settings.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if enabled in settings.
	 */
	public function is_setting_enabled(): bool {

		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Get admin settings instance.
	 *
	 * @since 1.2.0
	 *
	 * @return ReviewsAdminSettings Admin settings instance.
	 */
	public function get_admin_settings(): ReviewsAdminSettings {

		return $this->admin_settings;
	}
}

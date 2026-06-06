<?php

namespace ActiveLayer\Integrations\Comments;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\Traits\CommentVerdictTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Comments Integration.
 *
 * Main integration class that handles WordPress comment spam detection.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\Comments
 */
class CommentsIntegration extends BaseFormIntegration {

	use CommentVerdictTrait;

	/**
	 * Submission handler instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings instance.
	 *
	 * @since 1.0.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		parent::__construct( 'WP Comments' );

		// Initialize components.
		$this->submission_handler = new SubmissionHandler( $this );
		$this->admin_settings     = new AdminSettings( $this );
	}

	/**
	 * Initialize integration.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		if ( ! $this->is_active() ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		// Initialize components that need hooks.
		$this->submission_handler->init();
		$this->admin_settings->init();

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function hooks(): void {

		// Hook for handling API verdicts using base class pattern.
		add_action( 'activelayer_queue_worker_verdict_received', [ $this, 'handle_verdict_action' ], 10, 3 );
		add_action( 'activelayer_queue_worker_submission_failed', [ $this, 'handle_submission_failed' ], 10, 2 );

		// Add hidden field for environment signals to comment forms.
		add_action( 'comment_form_after_fields', [ $this, 'output_environment_signals_field' ] );
		add_action( 'comment_form_logged_in_after', [ $this, 'output_environment_signals_field' ] );
	}

	/**
	 * Output the hidden environment signals field in the comment form.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Skip product post-type to defer to WooCommerce Reviews integration.
	 *
	 * @return void
	 */
	public function output_environment_signals_field(): void {

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		// Product review forms are handled by WooCommerce Reviews (umbrella sub-integration).
		if ( function_exists( 'get_post_type' ) && get_post_type() === 'product' ) {
			return;
		}

		FieldRenderer::output_all();
	}

	/**
	 * Check if WordPress comments are available.
	 * WordPress core is always available, so this always returns true.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if WordPress comments are available.
	 */
	public function is_active(): bool {

		// WordPress core comments are always available.
		return true;
	}

	/**
	 * Handle API verdict action wrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $verdict       API verdict (clean/spam).
	 * @param array  $submission    Submission data.
	 */
	public function handle_verdict_action( string $submission_id, string $verdict, array $submission ): void {

		// Only handle our own submissions.
		if ( $submission['provider'] !== $this->get_slug() ) {
			return;
		}

		if ( $this->is_tracking_mode_active() ) {
			$comment_id = $this->get_comment_id_by_submission( $submission_id );

			if ( ! $comment_id ) {
				return;
			}

			$status = ( $verdict === 'clean' ) ? 'clean' : 'spam';

			update_comment_meta( $comment_id, 'activelayer_status', $status );

			return;
		}

		// Use base class method.
		$this->handle_verdict( $submission_id, $verdict );
	}

	/**
	 * Normalize comment data to standard format.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_data Raw comment data.
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
	 * Get comment metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $form_instance Comment data array.
	 *
	 * @return array Comment metadata.
	 */
	protected function get_form_meta( $form_instance ): array {

		return $this->submission_handler->get_form_meta( $form_instance );
	}

	/**
	 * Get default comment integration settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_default_settings(): array {

		return AdminSettings::DEFAULT_SETTINGS;
	}

	/**
	 * Submissions from this integration represent WP comments.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_data_type(): string {

		return 'comment';
	}

	/**
	 * Check if the integration is enabled for runtime operation.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {

		return SettingsHelper::has_api_key() && ! empty( $this->settings['enabled'] );
	}

	/**
	 * Check if integration is enabled in settings.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if enabled in settings.
	 */
	public function is_setting_enabled(): bool {

		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Get admin settings instance for external access.
	 *
	 * @since 1.0.0
	 *
	 * @return AdminSettings Admin settings instance.
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Check whether comment tracking mode is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_tracking_mode_active(): bool {

		$comment_settings = $this->admin_settings->get_comment_settings();

		return ! empty( $comment_settings['tracking_mode'] );
	}
}

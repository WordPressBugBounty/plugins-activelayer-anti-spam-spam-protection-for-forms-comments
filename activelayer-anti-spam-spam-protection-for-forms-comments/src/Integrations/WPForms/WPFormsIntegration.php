<?php

namespace ActiveLayer\Integrations\WPForms;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPForms Integration.
 *
 * Integration with WPForms plugin for anti-spam processing.
 * Hooks into WPForms submission process and queues for background checking.
 *
 * @since   1.0.0
 *
 * @package ActiveLayer\Integrations\WPForms
 */
class WPFormsIntegration extends BaseFormIntegration {

	const STRATEGY_ASYNC      = 'async';
	const STRATEGY_SYNC_BLOCK = 'sync_block';
	const STRATEGY_SYNC_SAVE  = 'sync_save';

	/**
	 * Submission handler instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Email reconstructor instance.
	 *
	 * @since 1.0.0
	 *
	 * @var EmailReconstructor
	 */
	private $email_reconstructor;

	/**
	 * Admin settings instance.
	 *
	 * @since 1.0.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Queue failure cache for current request.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $queue_failures = [
		'forms'   => [],
		'entries' => [],
	];

	/**
	 * Track forms already processed synchronously within current request.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,bool>
	 */
	private $sync_validated_forms = [];

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		parent::__construct( 'WPForms' );

		// Initialize sub-components.
		$this->submission_handler  = new SubmissionHandler( $this );
		$this->email_reconstructor = new EmailReconstructor( $this );
		$this->admin_settings      = new AdminSettings( $this );
	}

	/**
	 * Initialize integration.
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
	 */
	private function hooks(): void {

		// Hook into WPForms processing - after entry is created.
		add_action( 'wpforms_process_complete', [ $this->submission_handler, 'handle_submission' ], 5, 4 );

		// Run synchronous validation early when entries are not stored.
		add_action( 'wpforms_process', [ $this, 'maybe_handle_sync_submission' ], 9, 3 );

		// Block all emails until API verification.
		add_filter( 'wpforms_disable_all_emails', [ $this->email_reconstructor, 'should_disable_emails' ], 10, 5 );

		// Hook for handling API verdicts using base class pattern.
		add_action( 'activelayer_queue_worker_verdict_received', [ $this, 'handle_verdict_action' ], 10, 3 );
		add_action( 'activelayer_queue_worker_submission_failed', [ $this, 'handle_submission_failed' ], 10, 2 );

		// Add settings to WPForms builder Anti-Spam panel.
		add_action( 'wpforms_admin_builder_anti_spam_panel_content', [ $this->admin_settings, 'add_form_settings' ], 30, 1 );

		// Add hidden field for environment signals to protected forms.
		add_action( 'wpforms_display_submit_before', [ $this, 'output_environment_signals_field' ], 10, 1 );
	}

	/**
	 * Output the hidden environment signals field in the form.
	 *
	 * Only outputs for forms that have ActiveLayer protection enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form_data Form configuration.
	 *
	 * @return void
	 */
	public function output_environment_signals_field( array $form_data ): void {

		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! $this->is_form_protected( $form_data ) ) {
			return;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		FieldRenderer::output_all();
	}

	/**
	 * Check if WPForms plugin is active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if WPForms is active.
	 */
	public function is_active(): bool {

		return function_exists( 'wpforms' ) || class_exists( 'WPForms' );
	}

	/**
	 * Handle API verdict action.
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

		$form_id       = isset( $submission['form_id'] ) ? (int) $submission['form_id'] : 0;
		$tracking_mode = $this->is_tracking_mode_for_form_id( $form_id );

		// Use base class method with tracking context.
		$this->handle_verdict(
			$submission_id,
			$verdict,
			[
				'tracking_mode' => $tracking_mode,
			]
		);
	}

	/**
	 * Handle submission failure fallback.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Skip duplicate spam_status meta inserts on retries.
	 *
	 * @param string $submission_id Submission ID.
	 * @param array  $submission    Submission data.
	 */
	public function handle_submission_failed( string $submission_id, array $submission ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( $this->get_slug() !== ( $submission['provider'] ?? '' ) ) {
			return;
		}

		$entry_id = isset( $submission['entry_id'] ) ? (int) $submission['entry_id'] : 0;
		$form_id  = isset( $submission['form_id'] ) ? (int) $submission['form_id'] : 0;

		$this->register_queue_failure( $form_id, $entry_id );

		if ( $entry_id && function_exists( 'wpforms' ) && ! $this->entry_has_spam_status( $entry_id, 'failed' ) ) {
			wpforms()->obj( 'entry_meta' )->add(
				[
					'entry_id' => $entry_id,
					'form_id'  => $form_id,
					'type'     => 'spam_status',
					'data'     => 'failed',
				]
			);
		}

		$tracking_mode = $this->is_tracking_mode_for_form_id( $form_id );

		if ( ! $tracking_mode ) {
			$this->email_reconstructor->allow_submission( $submission_id );
		}

		Logger::log(
			'WPForms submission failed - restored notifications',
			[
				'submission_id' => $submission_id,
				'entry_id'      => $entry_id,
				'form_id'       => $form_id,
			]
		);
	}

	/**
	 * Check whether the WPForms entry already has a spam_status meta row with the given value.
	 *
	 * Uses WPForms_Entry_Meta_Handler::get_meta() with a count query so we never
	 * insert duplicate spam_status meta rows when failure handlers run more than
	 * once (e.g., on Action Scheduler retries).
	 *
	 * @since 1.2.0
	 *
	 * @param int    $entry_id WPForms entry identifier.
	 * @param string $status   Spam status value to look for (e.g., 'failed', 'spam', 'clean').
	 *
	 * @return bool True when a matching meta row already exists.
	 */
	private function entry_has_spam_status( int $entry_id, string $status ): bool {

		if ( $entry_id <= 0 || $status === '' || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );

		if ( ! $entry_meta || ! method_exists( $entry_meta, 'get_meta' ) ) {
			return false;
		}

		$count = $entry_meta->get_meta(
			[
				'entry_id' => $entry_id,
				'type'     => 'spam_status',
				'data'     => $status,
			],
			true
		);

		return (int) $count > 0;
	}

	/**
	 * Normalize WPForms data to standard format.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_data Raw WPForms fields data.
	 *
	 * @return array Normalized data.
	 */
	protected function normalize_form_data( array $raw_data ): array {

		// API expects only: name, email, website_url, message, ip, user_agent.
		return [
			'name'        => $this->find_wpforms_field( $raw_data, [ 'name' ] ),
			'email'       => $this->find_wpforms_field( $raw_data, [ 'email' ] ),
			'website_url' => $this->find_wpforms_field( $raw_data, [ 'url' ] ),
			'message'     => $this->find_wpforms_field( $raw_data, [ 'textarea' ], true ),
			'ip'          => RequestHelper::get_user_ip(),
			'user_agent'  => RequestHelper::get_user_agent(),
		];
	}

	/**
	 * Find field value in WPForms data by patterns.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_data    WPForms fields data.
	 * @param array $patterns    Field name patterns to search for.
	 * @param bool  $collect_all Whether to capture all matches instead of returning early.
	 *
	 * @return string Field value or empty string.
	 */
	public function find_wpforms_field( array $raw_data, array $patterns, bool $collect_all = false ): string {

		$matches = [];

		foreach ( $raw_data as $field_data ) {
			$value = $this->match_wpforms_field_value( $field_data, $patterns );

				if ( $value === '' ) {
					continue;
				}

			if ( ! $collect_all ) {
				return $value;
			}

			$matches[] = $value;
		}

		if ( $collect_all && $matches ) {
			return implode( "\n\n", $matches );
		}

		return '';
	}

	/**
	 * Attempt to match and sanitize a single WPForms field value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $field_data Field payload.
	 * @param array $patterns   Patterns to scan for.
	 *
	 * @return string
	 */
	private function match_wpforms_field_value( $field_data, array $patterns ): string {

		if ( ! $this->is_valid_wpforms_field_data( $field_data ) ) {
			return '';
		}

		$haystacks = $this->get_field_haystacks( $field_data );

		if ( $this->haystack_matches_patterns( $haystacks, $patterns ) ) {
			return RequestHelper::sanitize_field_value( $field_data['value'] );
		}

		return '';
	}

	/**
	 * Confirm a WPForms field payload is valid.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $field_data Field payload.
	 *
	 * @return bool
	 */
	private function is_valid_wpforms_field_data( $field_data ): bool {

		return is_array( $field_data ) && isset( $field_data['value'] );
	}

	/**
	 * Normalize WPForms field attributes used for pattern matching.
	 *
	 * @since 1.0.0
	 *
	 * @param array $field_data Field payload.
	 *
	 * @return array
	 */
	private function get_field_haystacks( array $field_data ): array {

		return array_filter(
			[
				strtolower( (string) ( $field_data['type'] ?? '' ) ),
				strtolower( (string) ( $field_data['name'] ?? '' ) ),
				strtolower( (string) ( $field_data['label'] ?? '' ) ),
			]
		);
	}

	/**
	 * Determine whether any haystack contains the provided patterns.
	 *
	 * @since 1.0.0
	 *
	 * @param array $haystacks Normalized field attributes.
	 * @param array $patterns  Patterns to scan for.
	 *
	 * @return bool
	 */
	private function haystack_matches_patterns( array $haystacks, array $patterns ): bool {

		foreach ( $haystacks as $haystack ) {
			if ( $this->value_contains_pattern( $haystack, $patterns ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the haystack contains any of the supplied patterns.
	 *
	 * @since 1.0.0
	 *
	 * @param string $haystack Normalized field attribute.
	 * @param array  $patterns Patterns to scan for.
	 *
	 * @return bool
	 */
	private function value_contains_pattern( string $haystack, array $patterns ): bool {

		foreach ( $patterns as $pattern ) {
			if ( $pattern !== '' && strpos( $haystack, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get WPForms metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form_instance WPForms form data.
	 *
	 * @return array Form metadata.
	 */
	public function get_form_meta( $form_instance ): array {

		return [
			'form_id'    => $form_instance['id'],
			'form_title' => $form_instance['settings']['form_title'] ?? 'WPForms Form',
			'entry_id'   => null,
		];
	}

	/**
	 * Get admin settings instance.
	 *
	 * @since 1.0.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Allow emails temporarily for sync+entries clean verdicts.
	 *
	 * Sets the flag on the email reconstructor so WPForms sends notifications.
	 *
	 * @since 1.1.0
	 */
	public function allow_sync_emails(): void {

		$this->email_reconstructor->set_allow_emails_temporarily( true );
	}

	/**
	 * Re-send emails for a clean submission via EmailReconstructor.
	 *
	 * Used by SYNC_SAVE strategy where emails were initially blocked
	 * because the verdict was not yet available when WPForms sent notifications.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True on success.
	 */
	public function resend_clean_emails( string $submission_id ): bool {

		return $this->email_reconstructor->allow_submission( $submission_id );
	}

	/**
	 * Mark a WPForms entry as spam.
	 *
	 * Updates the entry status to 'spam' in the WPForms entries table.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $entry_id  Entry identifier.
	 * @param array $form_data Form configuration.
	 */
	public function mark_entry_spam( int $entry_id, array $form_data ): void {

		if ( $entry_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return;
		}

		wpforms()->obj( 'entry' )->update(
			$entry_id,
			[ 'status' => 'spam' ],
			'',
			'',
			[ 'cap' => false ]
		);
	}

	/**
	 * Check whether the form can store entries (WPForms Pro with entries enabled).
	 *
	 * @since 1.1.0
	 *
	 * @param array $form_data Form configuration.
	 *
	 * @return bool
	 */
	public function can_store_entries( array $form_data ): bool {

		if ( ! function_exists( 'wpforms' ) || ! wpforms()->is_pro() ) {
			return false;
		}

		return empty( $form_data['settings']['disable_entries'] );
	}

	/**
	 * Determine the processing strategy for the form.
	 *
	 * Returns the strategy based on sync mode and entry storage capability:
	 * - 'async': background processing via Action Scheduler.
	 * - 'sync_block': synchronous API check, block spam before entry creation.
	 * - 'sync_save': synchronous API check after entry is saved.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form_data Form configuration.
	 *
	 * @return string One of self::STRATEGY_ASYNC, self::STRATEGY_SYNC_BLOCK, or self::STRATEGY_SYNC_SAVE.
	 */
	public function get_processing_strategy( array $form_data ): string {

		if ( $this->is_sync_mode() ) {
			return $this->can_store_entries( $form_data ) ? self::STRATEGY_SYNC_SAVE : self::STRATEGY_SYNC_BLOCK;
		}

		return $this->can_store_entries( $form_data ) ? self::STRATEGY_ASYNC : self::STRATEGY_SYNC_BLOCK;
	}

	/**
	 * Handle synchronous verification when WPForms skips entry storage.
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields    Form fields data.
	 * @param array $entry     Raw entry data (unused).
	 * @param array $form_data Form configuration.
	 */
	public function maybe_handle_sync_submission( array $fields, array $entry, array $form_data ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! $this->is_form_protected( $form_data ) ) {
			return;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		if ( $this->get_processing_strategy( $form_data ) !== self::STRATEGY_SYNC_BLOCK ) {
			return;
		}

		$form_id = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;

		if ( $form_id && ! empty( $this->sync_validated_forms[ $form_id ] ) ) {
			return;
		}

		if ( $form_id ) {
			$this->sync_validated_forms[ $form_id ] = true;
		}

		$tracking_mode         = $this->is_tracking_mode_for_form( $form_data );
		$meta                  = $this->get_form_meta( $form_data );
		$meta['entry_id']      = null;
		$meta['tracking_mode'] = $tracking_mode;

		if ( $tracking_mode ) {
			try {
				// Tracking mode with disabled WPForms entries: queue for async analysis so the submitter is never blocked.
				$submission_id = $this->process_submission( $fields, $meta );
			} catch ( \Exception $exception ) {
				Logger::log(
					'WPForms tracking submission error',
					[
						'form_id' => $meta['form_id'] ?? 0,
						'error'   => $exception->getMessage(),
					]
				);

				$submission_id = '';
			}

			if ( ! empty( $meta['queue_failed'] ) ) {
				if ( $form_id ) {
					$this->register_queue_failure( $form_id );
				}

				Logger::log(
					'WPForms tracking submission not queued',
					[
						'form_id'       => $meta['form_id'] ?? 0,
						'submission_id' => $submission_id,
					]
				);
			}

			return;
		}

		$result = $this->process_submission_synchronously( $fields, $meta );

		if ( empty( $result['success'] ) ) {
			return;
		}

		if ( 'spam' === ( $result['verdict'] ?? 'clean' ) && empty( $result['tracking_mode'] ) ) {
			wpforms()->process->errors[ $form_data['id'] ]['activelayer'] = $this->get_sync_block_message();
		}
	}

	/**
	 * Determine if email interception should be skipped and notifications sent immediately.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form_data Form configuration.
	 *
	 * @return bool
	 */
	public function should_bypass_email_interception( array $form_data ): bool {

		return $this->get_processing_strategy( $form_data ) === self::STRATEGY_SYNC_BLOCK;
	}

	/**
	 * Determine whether ActiveLayer protection is enabled for a form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form_data Form configuration.
	 *
	 * @return bool
	 */
	public function is_form_protected( array $form_data ): bool {

		if ( empty( $form_data['id'] ) ) {
			return false;
		}

		$form_settings = $this->admin_settings->get_form_settings( $form_data );

		return ! empty( $form_settings['enabled'] );
	}

	/**
	 * Allow clean submission - send emails and mark entry as clean.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True on success.
	 */
	protected function allow_submission( string $submission_id ): bool {

		// Send emails for clean submission.
		$result = $this->email_reconstructor->allow_submission( $submission_id );

		// Mark entry as clean in WPForms.
		$this->update_entry_status( $submission_id, 'clean' );

		return $result;
	}

	/**
	 * Block spam submission - mark entry as spam.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True on success.
	 */
	protected function block_submission( string $submission_id ): bool {

		// Mark entry as spam in WPForms.
		$this->update_entry_status( $submission_id, 'spam' );

		return true;
	}

	/**
	 * Update WPForms entry status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $status        Status (clean/spam).
	 *
	 * @return void True on success.
	 */
	private function update_entry_status( string $submission_id, string $status ): void {

		if ( ! function_exists( 'wpforms' ) ) {
			return;
		}

		$submission = $this->get_storage()->find( $submission_id );

		if ( ! $submission || empty( $submission['entry_id'] ) ) {
			return;
		}

		$entry_id = $submission['entry_id'];
		$form_id  = $submission['form_id'];

		// Mark entry status in WPForms.
		wpforms()->obj( 'entry_meta' )->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $form_id,
				'type'     => 'spam_status',
				'data'     => $status,
			]
		);

		// Update entry status if spam.
		if ( $status === 'spam' ) {
			wpforms()->obj( 'entry' )->update(
				$entry_id,
				[ 'status' => 'spam' ],
				'',
				'',
				[ 'cap' => false ]
			);
		}
	}

	/**
	 * Register a queue failure for current request.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $form_id  Form identifier.
	 * @param int|null $entry_id Entry identifier.
	 */
	public function register_queue_failure( int $form_id, ?int $entry_id = null ): void {

		if ( $form_id ) {
			$this->queue_failures['forms'][ $form_id ] = true;
		}

		if ( $entry_id ) {
			$this->queue_failures['entries'][ $entry_id ] = true;
		}
	}

	/**
	 * Check if current submission should bypass email blocking due to queue failure.
	 *
	 * @since 1.0.0
	 *
	 * @param array      $form_data Form configuration.
	 * @param int|string $entry_id  Entry identifier.
	 *
	 * @return bool
	 */
	public function has_queue_failure( array $form_data = [], $entry_id = null ): bool {

		if ( $entry_id && ! empty( $this->queue_failures['entries'][ (int) $entry_id ] ) ) {
			return true;
		}

		$form_id = $form_data['id'] ?? null;

		return $form_id && ! empty( $this->queue_failures['forms'][ (int) $form_id ] );
	}

	/**
	 * Check whether tracking mode is enabled for a given form configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form_data Form configuration as supplied by WPForms.
	 *
	 * @return bool
	 */
	public function is_tracking_mode_for_form( array $form_data ): bool {

		$form_settings = $this->admin_settings->get_form_settings( $form_data );

		if ( ! empty( $form_settings['tracking_mode_defined'] ) ) {
			return ! empty( $form_settings['tracking_mode'] );
		}

		return false;
	}

	/**
	 * Check whether tracking mode is enabled for a stored form ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return bool
	 */
	public function is_tracking_mode_for_form_id( int $form_id ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		$form_settings = $this->admin_settings->get_form_settings_by_id( $form_id );

		if ( ! empty( $form_settings['tracking_mode_defined'] ) ) {
			return ! empty( $form_settings['tracking_mode'] );
		}

		return false;
	}
}

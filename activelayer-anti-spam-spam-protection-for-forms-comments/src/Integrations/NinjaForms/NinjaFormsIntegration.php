<?php

namespace ActiveLayer\Integrations\NinjaForms;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use Exception;

use function Ninja_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ninja Forms Integration.
 *
 * Handles submissions originating from Ninja Forms and coordinates
 * ActiveLayer queueing plus delayed email delivery.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\NinjaForms
 */
class NinjaFormsIntegration extends BaseFormIntegration {

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
	 * Spam check action instance.
	 *
	 * @since 1.1.0
	 *
	 * @var SpamCheckAction
	 */
	private $spam_check_action;

	/**
	 * Admin settings helper.
	 *
	 * @since 1.0.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Replay flag for email simulation.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $replaying = false;

	/**
	 * Cached global settings array.
	 *
	 * @since 1.0.0
	 *
	 * @var array|null
	 */
	private $global_settings = null;

	/**
	 * Cached per-form protection flags.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,bool>
	 */
	private $protected_forms = [];

	/**
	 * Form ID currently being processed.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $current_form_id = 0;

	/**
	 * Track forms that disable entry storage and require synchronous processing.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,bool>
	 */
	private $forms_without_storage = [];

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		parent::__construct( 'Ninja Forms' );

		$this->submission_handler  = new SubmissionHandler( $this );
		$this->email_reconstructor = new EmailReconstructor( $this );
		$this->admin_settings      = new AdminSettings( $this );
		$this->spam_check_action   = new SpamCheckAction();

		$this->spam_check_action->set_integration( $this );
	}

	/**
	 * Initialise integration hooks.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		$this->hooks();
		$this->admin_settings->init();
	}

	/**
	 * Register runtime hooks.
	 *
	 * @since 1.0.0
	 */
	private function hooks(): void {

		// Capture outgoing email attempts.
		add_filter(
			'ninja_forms_action_email_send',
			[ $this->email_reconstructor, 'intercept_email' ],
			5,
			5
		);

		// Track form context while actions are prepared.
		add_filter(
			'ninja_forms_run_action_settings',
			[ $this, 'capture_action_settings' ],
			5,
			4
		);

		// Register the spam check action type with Ninja Forms
		// after NF has finished loading (actions array is populated).
		add_action(
			'ninja_forms_loaded',
			[ $this, 'register_spam_check_action' ]
		);

		// Inject the spam check action into protected forms.
		add_filter(
			'ninja_forms_submission_actions',
			[ $this, 'inject_spam_check_action' ],
			5,
			3
		);

		// Handle completed submission payload.
		add_action(
			'ninja_forms_after_submission',
			[ $this->submission_handler, 'handle_submission' ],
			999,
			1
		);

		// React to spam verdicts.
		add_action(
			'activelayer_queue_worker_verdict_received',
			[ $this, 'handle_verdict_action' ],
			10,
			3
		);

		add_action(
			'activelayer_queue_worker_submission_failed',
			[ $this, 'handle_submission_failed' ],
			10,
			2
		);

		// Add hidden field for environment signals to protected forms.
		add_filter(
			'ninja_forms_display_after_fields',
			[ $this, 'add_environment_signals_field' ],
			10,
			2
		);
	}

	/**
	 * Add the hidden environment signals field to the form HTML.
	 *
	 * Only adds for forms that have ActiveLayer protection enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param string     $html    Existing HTML content.
	 * @param int|string $form_id Form identifier.
	 *
	 * @return string Modified HTML with hidden field appended.
	 */
	public function add_environment_signals_field( string $html, $form_id ): string {

		$form_id = (int) $form_id;

		if ( $form_id <= 0 ) {
			return $html;
		}

		if ( ! $this->is_form_protected( $form_id ) ) {
			return $html;
		}

		return $html . FieldRenderer::render_all();
	}

	/**
	 * Check if Ninja Forms plugin is active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return class_exists( 'Ninja_Forms' );
	}

	/**
	 * Capture form context while Ninja Forms prepares action settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array      $settings      Action settings.
	 * @param int        $form_id       Form identifier.
	 * @param int|string $action_id     Action identifier (unused).
	 * @param array      $form_settings Form configuration (unused).
	 *
	 * @return array Unmodified action settings.
	 */
	public function capture_action_settings( array $settings, $form_id, $action_id, array $form_settings ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		if ( $form_id > 0 ) {
			$this->current_form_id = (int) $form_id;

			$this->is_form_protected( $this->current_form_id ); // Warm cache for later checks.
		}

		return $settings;
	}

	/**
	 * Handle queue verdict results.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 * @param string $verdict       Verdict string.
	 * @param array  $submission    Stored submission payload.
	 */
	public function handle_verdict_action( string $submission_id, string $verdict, array $submission ): void {

		if ( $submission['provider'] !== $this->get_slug() ) {
			return;
		}

		$this->handle_verdict(
			$submission_id,
			$verdict,
			[]
		);
	}

	/**
	 * Fallback when queue job failed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 * @param array  $submission    Stored submission data.
	 */
	public function handle_submission_failed( string $submission_id, array $submission ): void {

		if ( $this->get_slug() !== ( $submission['provider'] ?? '' ) ) {
			return;
		}

		$this->email_reconstructor->allow_submission( $submission_id );
		$this->email_reconstructor->cleanup_submission_assets( $submission_id );
	}

	/**
	 * Register the ActiveLayer spam check action type with Ninja Forms.
	 *
	 * Called on `ninja_forms_loaded` so the NF actions array is ready.
	 *
	 * @since 1.1.0
	 */
	public function register_spam_check_action(): void {

		if ( function_exists( 'Ninja_Forms' ) ) {
			Ninja_Forms()->actions['activelayer_spam_check'] = $this->spam_check_action;
		}
	}

	/**
	 * Inject the spam check action into the form's action list.
	 *
	 * Only injects when the form is protected and the processing strategy
	 * requires synchronous blocking. The actual spam check runs inside
	 * the NF action processing loop via {@see SpamCheckAction::process()}.
	 *
	 * @since 1.1.0
	 *
	 * @param array $actions    Action definitions queued for execution.
	 * @param array $form_cache Cached form data (unused).
	 * @param array $form_data  Submission payload prepared by Ninja Forms.
	 *
	 * @return array
	 */
	public function inject_spam_check_action( array $actions, array $form_cache, array $form_data ): array {

		unset( $form_cache );

		$form_id = isset( $form_data['form_id'] ) ? (int) $form_data['form_id'] : 0;

		if ( $form_id <= 0 ) {
			return $actions;
		}

		if ( ! $this->is_form_protected( $form_id ) ) {
			return $actions;
		}

		$global_settings = $this->get_global_settings();

		if ( ! SettingsHelper::has_api_key( $global_settings ) ) {
			return $actions;
		}

		if ( $this->get_processing_strategy( $form_id, $actions ) !== self::STRATEGY_SYNC_BLOCK ) {
			return $actions;
		}

		// Prepend the spam check action so it runs first after sorting.
		array_unshift(
			$actions,
			[
				'id'       => 'activelayer_spam_check',
				'settings' => [
					'type'   => 'activelayer_spam_check',
					'label'  => 'ActiveLayer Spam Check',
					'active' => '1',
				],
			]
		);

		return $actions;
	}

	/**
	 * Normalise Ninja Forms data for API payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_data Submission data array from Ninja Forms.
	 *
	 * @return array
	 */
	protected function normalize_form_data( array $raw_data ): array {

		$fields = $raw_data['fields'] ?? [];

		return [
			'name'        => $this->find_field_value( $fields, [ 'name', 'full_name', 'first_name', 'last_name' ] ),
			'email'       => $this->find_field_value( $fields, [ 'email', 'emailaddress', 'email_address' ] ),
			'website_url' => $this->find_field_value( $fields, [ 'website', 'url' ] ),
			'message'     => $this->find_field_value( $fields, [ 'message', 'comments', 'feedback', 'description' ] ),
			'ip'          => RequestHelper::get_user_ip(),
			'user_agent'  => RequestHelper::get_user_agent(),
		];
	}

	/**
	 * Retrieve metadata about the form.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $form_instance Ninja Forms ID.
	 *
	 * @return array
	 */
	public function get_form_meta( $form_instance ): array {

		$form_id = (int) $form_instance;

		try {
			$form = Ninja_Forms()->form( $form_id )->get();
		} catch ( Exception $e ) {
			$form = null;
		}

		return [
			'form_id'    => $form_id,
			'form_title' => $form ? ( $form->get_setting( 'title' ) ?? 'Ninja Form' ) : 'Ninja Form',
		];
	}

	/**
	 * Check whether the form stores entries (has an active save action).
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return bool
	 */
	public function can_store_entries( int $form_id ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		if ( isset( $this->forms_without_storage[ $form_id ] ) ) {
			return ! $this->forms_without_storage[ $form_id ];
		}

		$actions  = $this->get_form_actions( $form_id );
		$has_save = $this->has_active_save_action( $actions );

		$this->forms_without_storage[ $form_id ] = ! $has_save;

		return $has_save;
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
	 * @param int   $form_id Form identifier.
	 * @param array $actions Optional set of actions already prepared by Ninja Forms.
	 *
	 * @return string One of self::STRATEGY_ASYNC, self::STRATEGY_SYNC_BLOCK, or self::STRATEGY_SYNC_SAVE.
	 */
	public function get_processing_strategy( int $form_id, array $actions = [] ): string {

		// Prime the cache when actions are provided by the caller.
		if ( $form_id > 0 && ! empty( $actions ) && ! isset( $this->forms_without_storage[ $form_id ] ) ) {
			$this->forms_without_storage[ $form_id ] = ! $this->has_active_save_action( $actions );
		}

		if ( $this->is_sync_mode() ) {
			return $this->can_store_entries( $form_id ) ? self::STRATEGY_SYNC_SAVE : self::STRATEGY_SYNC_BLOCK;
		}

		return $this->can_store_entries( $form_id ) ? self::STRATEGY_ASYNC : self::STRATEGY_SYNC_BLOCK;
	}

	/**
	 * Whether email interception should be skipped for the given form.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return bool
	 */
	public function should_bypass_email_interception( int $form_id ): bool {

		return $this->get_processing_strategy( $form_id ) === self::STRATEGY_SYNC_BLOCK;
	}

	/**
	 * Access admin settings helper.
	 *
	 * @since 1.0.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Begin replay phase (prevents recursion).
	 *
	 * @since 1.0.0
	 */
	public function begin_replay(): void {

		$this->replaying = true;
	}

	/**
	 * End replay phase.
	 *
	 * @since 1.0.0
	 */
	public function end_replay(): void {

		$this->replaying = false;
	}

	/**
	 * Are we currently replaying emails?
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_replaying(): bool {

		return $this->replaying;
	}

	/**
	 * Provide access to reconstructor from handlers.
	 *
	 * @since 1.0.0
	 *
	 * @return EmailReconstructor
	 */
	public function get_email_reconstructor(): EmailReconstructor {

		return $this->email_reconstructor;
	}

	/**
	 * Retrieve cached global settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_global_settings(): array {

		if ( $this->global_settings === null ) {
			$this->global_settings = SettingsHelper::get_global_settings();
		}

		return $this->global_settings;
	}

	/**
	 * Retrieve configured actions for the given form.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return array<int,mixed>
	 */
	private function get_form_actions( int $form_id ): array {

		if ( $form_id <= 0 || ! function_exists( 'Ninja_Forms' ) ) {
			return [];
		}

		try {
			$actions = Ninja_Forms()->form( $form_id )->get_actions();
		} catch ( Exception $exception ) {
			unset( $exception );

			return [];
		}

		return is_array( $actions ) ? $actions : [];
	}

	/**
	 * Check if the provided action list contains an active "save" action.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,mixed> $actions Action definitions or objects.
	 *
	 * @return bool
	 */
private function has_active_save_action( array $actions ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		foreach ( $actions as $action ) {
			if ( is_array( $action ) ) {
				$settings = $action['settings'] ?? $action;
				$type     = isset( $settings['type'] ) ? strtolower( (string) $settings['type'] ) : '';
				$active   = isset( $settings['active'] ) ? (bool) $settings['active'] : true;
			} elseif ( is_object( $action ) && method_exists( $action, 'get_setting' ) ) {
				$type   = strtolower( (string) $action->get_setting( 'type' ) );
				$active = (bool) $action->get_setting( 'active' );
			} else {
				continue;
			}

				if ( $type === 'save' && $active ) {
				return true;
			}
			}

		return false;
	}

	/**
	 * Determine whether ActiveLayer should protect the given form.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form identifier.
	 *
	 * @return bool
	 */
	public function is_form_protected( int $form_id ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		if ( isset( $this->protected_forms[ $form_id ] ) ) {
			return $this->protected_forms[ $form_id ];
		}

		if ( ! $this->is_enabled() ) {
			$this->protected_forms[ $form_id ] = false;

			return false;
		}

		$global_settings = $this->get_global_settings();

		if ( ! SettingsHelper::has_api_key( $global_settings ) ) {
			$this->protected_forms[ $form_id ] = false;

			return false;
		}

		$form_settings = $this->admin_settings->get_form_settings( $form_id );
		$enabled       = ! empty( $form_settings['enabled'] );

		$this->protected_forms[ $form_id ] = $enabled;

		return $enabled;
	}

	/**
	 * Get form ID associated with the current email action.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_current_form_id(): int {

		return $this->current_form_id;
	}

	/**
	 * Reset cached settings when reloading integration state.
	 *
	 * @since 1.0.0
	 */
	public function reload_settings(): void {

		parent::reload_settings();

		$this->global_settings       = null;
		$this->protected_forms       = [];
		$this->current_form_id       = 0;
		$this->forms_without_storage = [];
	}

	/**
	 * Allow email delivery once verdict is clean.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 *
	 * @return bool
	 */
	protected function allow_submission( string $submission_id ): bool {

		$allowed = $this->email_reconstructor->allow_submission( $submission_id );

		if ( $allowed ) {
			$this->email_reconstructor->cleanup_submission_assets( $submission_id );
		}

		return $allowed;
	}

	/**
	 * Block spam submissions – nothing to do besides cleanup.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 *
	 * @return bool
	 */
	protected function block_submission( string $submission_id ): bool {

		$this->email_reconstructor->cleanup_submission_assets( $submission_id );

		return true;
	}

	/**
	 * Try to find a field value by key hints.
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields  Submission fields.
	 * @param array $needles Keys to look for.
	 *
	 * @return string
	 */
	private function find_field_value( array $fields, array $needles ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		foreach ( $fields as $field ) {
			$key   = strtolower( (string) ( $field['key'] ?? '' ) );
			$type  = strtolower( (string) ( $field['type'] ?? '' ) );
			$value = $field['value'] ?? '';

			if ( $value === '' ) {
				continue;
			}

			if ( in_array( $key, $needles, true ) || in_array( $type, $needles, true ) ) {
				return RequestHelper::sanitize_field_value( $value );
			}
		}

		return '';
	}
}

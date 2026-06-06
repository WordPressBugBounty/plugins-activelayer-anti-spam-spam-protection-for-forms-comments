<?php

namespace ActiveLayer\Integrations\NinjaForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Logger\Logger;
use NF_Actions_Email;
use NF_Adapters_SubmissionsSubmission;
use Throwable;
use function Ninja_Forms;

/**
 * Ninja Forms Email Reconstructor.
 *
 * Captures outgoing email actions and replays them by leveraging native
 * Ninja Forms submission data once a ActiveLayer verdict is received.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\NinjaForms
 */
class EmailReconstructor {

	/**
	 * Transient prefix for persisted email action metadata.
	 *
	 * @since 1.0.0
	 */
	private const TRANSIENT_PREFIX = 'activelayer_ninja_actions_';

	/**
	 * Lifetime for persisted action metadata (seconds).
	 *
	 * @since 1.0.0
	 */
	private const ACTION_CACHE_TTL = 2 * DAY_IN_SECONDS;

	/**
	 * Parent integration reference.
	 *
	 * @since 1.0.0
	 *
	 * @var NinjaFormsIntegration
	 */
	private $integration;

	/**
	 * Captured email action settings for the current request.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,array>
	 */
	private $captured_emails = [];

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param NinjaFormsIntegration $integration Integration instance.
	 */
	public function __construct( NinjaFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Intercept outgoing email actions.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $sent            Whether the email has already been sent.
	 * @param array  $action_settings Email action settings.
	 * @param string $message         Email body (unused).
	 * @param array  $headers         Headers (unused).
	 * @param array  $attachments     Attachments (unused).
	 *
	 * @return bool
	 */
	public function intercept_email( $sent, $action_settings, $message, $headers, $attachments ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		unset( $message, $headers, $attachments );

		$preflight_checks = [
			$sent === true,
			$this->integration->is_replaying(),
			! $this->integration->is_enabled(),
		];

		if ( in_array( true, $preflight_checks, true ) ) {
			return $sent;
		}

		$form_id = $this->integration->get_current_form_id();

		$form_checks = [
			$form_id <= 0,
		];

		if ( $form_id > 0 ) {
			$form_checks[] = ! $this->integration->is_form_protected( $form_id );
			$form_checks[] = $this->integration->should_bypass_email_interception( $form_id );
		}

		if ( in_array( true, $form_checks, true ) ) {
			return $sent;
		}

		$action_id = isset( $action_settings['id'] ) ? (int) $action_settings['id'] : 0;

		$this->captured_emails[] = [
			'id'              => $action_id,
			'action_settings' => $action_settings,
		];

		return true;
	}

	/**
	 * Drain captured email actions for the current request.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array>
	 */
	public function drain_captured_emails(): array {

		$emails                = $this->captured_emails;
		$this->captured_emails = [];

		return $emails;
	}

	/**
	 * Persist captured email metadata using a transient keyed by submission ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 * @param array  $actions       Captured action metadata.
	 */
	public function persist_captured_emails( string $submission_id, array $actions ): void {

		$prepared = [];

		foreach ( $actions as $action ) {
			$action_id = isset( $action['id'] ) ? (int) $action['id'] : 0;
			$settings  = $action['action_settings'] ?? [];

			if ( $action_id <= 0 && empty( $settings ) ) {
				continue;
			}

			$prepared[] = [
				'id'              => $action_id,
				'action_settings' => $settings,
			];
		}

		$key = self::TRANSIENT_PREFIX . $submission_id;

		if ( empty( $prepared ) ) {
			delete_transient( $key );

			return;
		}

		set_transient( $key, $prepared, self::ACTION_CACHE_TTL );
	}

	/**
	 * Replay captured emails immediately during the original request.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $form_id         Form identifier.
	 * @param array $request_context Submission payload supplied by Ninja Forms.
	 * @param array $captured_emails Captured email actions.
	 */
	public function send_captured_emails_now( int $form_id, array $request_context, array $captured_emails ): void {

		if ( empty( $captured_emails ) ) {
			return;
		}

		$context = [
			'form_id'       => $form_id,
			'payload'       => $request_context,
			'form_settings' => $request_context['settings'] ?? [],
			'submission'    => null,
		];

		$this->integration->begin_replay();

		foreach ( $captured_emails as $action ) {
			$this->run_email_action( $action, $context );
		}

		$this->integration->end_replay();
	}

	/**
	 * Replay stored email actions once a clean verdict is returned.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 *
	 * @return bool
	 */
    public function allow_submission( string $submission_id ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$submission = $this->integration->get_storage()->find( $submission_id );

		if ( ! $submission ) {
			return false;
		}

		$form_id  = isset( $submission['form_id'] ) ? (int) $submission['form_id'] : 0;
		$entry_id = isset( $submission['entry_id'] ) ? (int) $submission['entry_id'] : 0;

		if ( $form_id <= 0 || $entry_id <= 0 ) {
			return false;
		}

		$actions = $this->get_persisted_actions( $submission_id );

		if ( empty( $actions ) ) {
			Logger::log(
				'Ninja Forms: no captured actions found for replay',
				[
					'submission_id' => $submission_id,
				]
			);

			return false;
		}

		$context = $this->build_context_from_entry( $form_id, $entry_id );

		if ( $context === null ) {
			return false;
		}

		$all_sent = true;

		$this->integration->begin_replay();

		foreach ( $actions as $action ) {
			if ( ! $this->run_email_action( $action, $context ) ) {
				$all_sent = false;
			}
		}

		$this->integration->end_replay();

		return $all_sent;
	}

	/**
	 * Cleanup persisted action metadata for a submission.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 */
	public function cleanup_submission_assets( string $submission_id ): void {

		delete_transient( self::TRANSIENT_PREFIX . $submission_id );
	}

	/**
	 * Retrieve persisted actions for a submission.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 *
	 * @return array<int,array>
	 */
	private function get_persisted_actions( string $submission_id ): array {

		$stored = get_transient( self::TRANSIENT_PREFIX . $submission_id );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Build execution context using the stored Ninja Forms entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id  Form identifier.
	 * @param int $entry_id Entry identifier.
	 *
	 * @return array|null
	 */
    private function build_context_from_entry( int $form_id, int $entry_id ): ?array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$form = Ninja_Forms()->form( $form_id );

		if ( ! $form ) {
			return null;
		}

		try {
			$submission = $form->get_sub( $entry_id );
		} catch ( Throwable $e ) {
			$submission = null;
		}

		if ( ! $submission ) {
			return null;
		}

		$form_settings = $form->get_settings();
		$field_values  = $submission->get_field_values();

		$data = [
			'fields'        => [],
			'fields_by_key' => [],
			'settings'      => $form_settings,
			'form_id'       => $form_id,
		];

		foreach ( $field_values as $index => $value ) {
			$field_id    = (int) str_replace( '_field_', '', (string) $index );
			$field_model = $form->get_field( $field_id );

			if ( ! $field_model ) {
				continue;
			}

			$settings                   = $field_model->get_settings();
			$settings['value']          = $value;
			$entry                      = $settings;
			$entry['settings']          = $settings;
			$entry['value']             = $value;
			$entry['settings']['value'] = $value;

			if ( (string) $field_id === (string) $index ) {
				$data['fields_by_key'][ $field_id ] = $entry;
			} else {
				$data['fields'][ $field_id ] = $entry;
			}
		}

		return [
			'form_id'       => $form_id,
			'payload'       => $data,
			'form_settings' => $form_settings,
			'submission'    => $submission,
		];
	}

	/**
	 * Execute a single email action for the provided context.
	 *
	 * @since 1.0.0
	 *
	 * @param array $action  Captured action metadata.
	 * @param array $context Execution context.
	 *
	 * @return bool
	 */
	private function run_email_action( array $action, array $context ): bool {

		$action_settings = $this->resolve_action_settings( $action, $context['form_id'] );

		if ( empty( $action_settings ) ) {
			return false;
		}

		$action_id = isset( $action_settings['id'] ) ? (int) $action_settings['id'] : 0;

		/**
		 * Filters email action settings before replaying the action.
		 *
		 * @since 1.0.0
		 *
		 * @hook ninja_forms_run_action_settings
		 *
		 * @param array $action_settings Email action settings.
		 * @param int   $form_id         Form identifier.
		 * @param int   $action_id       Action identifier.
		 * @param array $form_settings   Form settings.
		 */
		$prepared_settings = apply_filters( 'ninja_forms_run_action_settings', $action_settings, $context['form_id'], $action_id, $context['form_settings'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WPForms.PHP.ValidateHooks.InvalidHookName -- Using Ninja Forms core hook.

		if ( $context['submission'] ) {
			$prepared_settings = $this->process_merge_tags( $prepared_settings, $context['form_id'], $context['submission'] );
		}

		try {
			$email_action = new NF_Actions_Email();
			$result       = $email_action->process( $prepared_settings, $context['form_id'], $context['payload'] );

			return (bool) ( $result['actions']['email']['sent'] ?? false );
		} catch ( Throwable $e ) {
			Logger::log(
				'Ninja Forms: failed to replay email action',
				[
					'form_id'   => $context['form_id'],
					'action_id' => $action_id,
					'error'     => $e->getMessage(),
				]
			);

			return false;
		}
	}

	/**
	 * Resolve action settings using captured metadata or current form configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $action  Captured action metadata.
	 * @param int   $form_id Form identifier.
	 *
	 * @return array
	 */
    private function resolve_action_settings( array $action, int $form_id ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$settings = $action['action_settings'] ?? [];

		if ( ! empty( $settings ) ) {
			return $settings;
		}

		$action_id = isset( $action['id'] ) ? (int) $action['id'] : 0;

		if ( $action_id <= 0 ) {
			return [];
		}

		$form = Ninja_Forms()->form( $form_id );

		if ( ! $form ) {
			return [];
		}

		$actions = $form->get_actions();

		foreach ( $actions as $action_model ) {
			if ( (int) $action_model->get_id() === $action_id ) {
				$settings       = $action_model->get_settings();
				$settings['id'] = $action_id;

				return $settings;
			}
		}

		return [];
	}

	/**
	 * Apply merge tags for the given action settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings   Action settings.
	 * @param int   $form_id    Form identifier.
	 * @param mixed $submission Submission instance.
	 *
	 * @return array
	 */
	private function process_merge_tags( array $settings, int $form_id, $submission ): array {

		$fields_merge_tags = Ninja_Forms()->merge_tags['fields'] ?? null;

		if ( ! $fields_merge_tags ) {
			return $settings;
		}

		$fields_merge_tags->set_form_id( $form_id );

		$fields  = Ninja_Forms()->form( $form_id )->get_fields();
		$adapter = new NF_Adapters_SubmissionsSubmission( $fields, $form_id, $submission );

		foreach ( $adapter as $field ) {
			$fields_merge_tags->add_field( $field );
		}

		if ( method_exists( $fields_merge_tags, 'include_all_fields_merge_tags' ) ) {
			$fields_merge_tags->include_all_fields_merge_tags();
			foreach ( $adapter as $field ) {
				$fields_merge_tags->add_field( $field );
			}
		}

		return $this->apply_merge_tags_recursive( $settings );
	}

	/**
	 * Recursively apply Ninja Forms merge tags to scalar values.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value to process.
	 *
	 * @return mixed
	 */
	private function apply_merge_tags_recursive( $value ) {

		if ( is_string( $value ) && $value !== '' ) {
			/**
			 * Filters a Ninja Forms merge tag value.
			 *
			 * @since 1.0.0
			 *
			 * @hook ninja_forms_merge_tags
			 *
			 * @param string $value Merge tag content.
			 */
			return apply_filters( 'ninja_forms_merge_tags', $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WPForms.PHP.ValidateHooks.InvalidHookName -- Using Ninja Forms core hook.
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->apply_merge_tags_recursive( $item );
			}
		}

		return $value;
	}
}

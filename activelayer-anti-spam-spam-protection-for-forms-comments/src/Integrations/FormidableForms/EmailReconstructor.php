<?php

namespace ActiveLayer\Integrations\FormidableForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;
use FrmEntry;
use FrmForm;
use FrmFormActionsController;
use FrmNotification;
use Throwable;

/**
 * Formidable Forms Email Reconstructor.
 *
 * Captures email actions to replay them via the native notification
 * system after ActiveLayer approves a submission.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\FormidableForms
 */
class EmailReconstructor {

	/**
	 * Transient prefix for persisted email context.
	 *
	 * @since 1.0.0
	 */
	private const TRANSIENT_PREFIX = 'activelayer_formidable_actions_';

	/**
	 * Lifetime (in seconds) for stored action context.
	 *
	 * @since 1.0.0
	 */
	private const ACTION_CACHE_TTL = 2 * DAY_IN_SECONDS;

	/**
	 * Parent integration.
	 *
	 * @since 1.0.0
	 *
	 * @var FormidableFormsIntegration
	 */
	private $integration;

	/**
	 * Captured email actions keyed by entry id.
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
	 * @param FormidableFormsIntegration $integration Integration instance.
	 */
	public function __construct( FormidableFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Intercept Formidable email actions and prevent immediate sending.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $skip   Whether default processing should be skipped.
	 * @param object $action Action object.
	 * @param object $entry  Entry object.
	 * @param object $form   Form object.
	 * @param string $event  Event name.
	 *
	 * @return bool
	 */
	public function intercept_action( $skip, $action, $entry, $form, $event ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( $skip
			|| $this->integration->is_email_interception_suspended()
			|| ! $this->integration->is_enabled()
			|| 'email' !== ( $action->post_excerpt ?? '' )
		) {
			return $skip;
		}

		unset( $event );

		$form_id  = is_object( $form ) ? (int) $form->id : (int) $form;
		$entry_id = is_object( $entry ) ? (int) $entry->id : 0;

		if ( $form_id <= 0 || $entry_id <= 0 ) {
			return $skip;
		}

		$form_settings = $this->integration->get_admin_settings()->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return $skip;
		}

		if ( $this->integration->should_bypass_email_interception( $form_id ) ) {
			return $skip;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return $skip;
		}

		$this->captured_emails[ $entry_id ][] = [
			'action_id' => $action->ID ?? null,
			'form_id'   => $form_id,
		];

		return true;
	}

	/**
	 * Drain captured email actions for an entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int $entry_id Entry identifier.
	 *
	 * @return array
	 */
	public function drain_captured_emails( int $entry_id ): array {

		if ( empty( $this->captured_emails[ $entry_id ] ) ) {
			return [];
		}

		$emails = $this->captured_emails[ $entry_id ];

		unset( $this->captured_emails[ $entry_id ] );

		return $emails;
	}

	/**
	 * Persist captured email metadata for deferred replay.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 * @param int    $form_id       Form identifier.
	 * @param int    $entry_id      Entry identifier.
	 * @param string $event         Triggering event name.
	 * @param array  $actions       Captured actions.
	 */
	public function persist_captured_emails( string $submission_id, int $form_id, int $entry_id, string $event, array $actions ): void {

		$prepared_actions = [];

		foreach ( $actions as $action ) {
			$prepared = $this->sanitize_action( $action );

			if ( empty( $prepared ) ) {
				continue;
			}

			$prepared_actions[] = $prepared;
		}

		$key = self::TRANSIENT_PREFIX . $submission_id;

		if ( empty( $prepared_actions ) ) {
			delete_transient( $key );

			return;
		}

		set_transient(
			$key,
			[
				'form_id'  => $form_id,
				'entry_id' => $entry_id,
				'event'    => $event,
				'actions'  => $prepared_actions,
			],
			self::ACTION_CACHE_TTL
		);
	}

	/**
	 * Replay Formidable email actions after clean verdict.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 *
	 * @return bool
	 */
	public function allow_submission( string $submission_id ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$context = $this->get_persisted_context( $submission_id );

		if ( $context === null ) {
			return false;
		}

		$this->integration->suspend_email_interception();

		$all_sent = true;

		foreach ( $context['actions'] as $action ) {
			if ( ! $this->send_email_action( $action, $context ) ) {
				$all_sent = false;
			}
		}

		$this->integration->resume_email_interception();

		return $all_sent;
	}

	/**
	 * Replay captured email actions directly without persisting.
	 *
	 * Used as a safe-by-default fallback when no submission_id is available
	 * (e.g. storage error during synchronous processing).
	 *
	 * @since 1.1.0
	 *
	 * @param array  $email_contexts Captured email contexts from drain_captured_emails().
	 * @param int    $form_id        Form identifier.
	 * @param int    $entry_id       Entry identifier.
	 * @param string $event          Triggering event name.
	 */
	public function replay_emails_now( array $email_contexts, int $form_id, int $entry_id, string $event = 'create' ): void {

		if ( empty( $email_contexts ) ) {
			return;
		}

		$context = [
			'form_id'  => $form_id,
			'entry_id' => $entry_id,
			'event'    => $event,
		];

		$this->integration->suspend_email_interception();

		foreach ( $email_contexts as $email ) {
			$this->send_email_action( $email, $context );
		}

		$this->integration->resume_email_interception();
	}

	/**
	 * Remove persisted context for a submission.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 */
	public function cleanup_submission_assets( string $submission_id ): void {

		delete_transient( self::TRANSIENT_PREFIX . $submission_id );
	}

	/**
	 * Retrieve persisted context for a submission.
	 *
	 * @since 1.0.0
	 *
	 * @param string $submission_id Submission identifier.
	 *
	 * @return array|null
	 */
	private function get_persisted_context( string $submission_id ): ?array {

		$context = get_transient( self::TRANSIENT_PREFIX . $submission_id );

		if ( ! is_array( $context ) || empty( $context['actions'] ) ) {
			return null;
		}

		return $context;
	}

	/**
	 * Send a captured email action using Formidable's notification system.
	 *
	 * @since 1.0.0
	 *
	 * @param array $email   Email context.
	 * @param array $context Submission context.
	 *
	 * @return bool
	 */
	private function send_email_action( array $email, array $context ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$action_id = isset( $email['action_id'] ) ? (int) $email['action_id'] : 0;
		$form_id   = isset( $context['form_id'] ) ? (int) $context['form_id'] : 0;
		$entry_id  = isset( $context['entry_id'] ) ? (int) $context['entry_id'] : 0;

		if ( $action_id <= 0 || $entry_id <= 0 || $form_id <= 0 ) {
			return false;
		}

		try {
			$action_control = FrmFormActionsController::get_form_actions( 'email' );

			if ( ! $action_control ) {
				return false;
			}

			$action_object = $action_control->get_single_action( $action_id );

			if ( ! $action_object ) {
				return false;
			}

			$entry_object = FrmEntry::getOne( $entry_id, true );
			$form_object  = FrmForm::getOne( $form_id );

			if ( ! $entry_object || ! $form_object ) {
				return false;
			}

			$event = isset( $context['event'] ) && is_string( $context['event'] ) ? $context['event'] : 'create';

			new FrmNotification();

			/**
			 * Fires when Formidable processes an email action.
			 *
			 * @since 1.0.0
			 *
			 * @hook frm_trigger_email_action
			 *
			 * @param object $action_object Action object.
			 * @param object $entry_object  Entry object.
			 * @param object $form_object   Form object.
			 * @param string $event         Event name.
			 */
			do_action( 'frm_trigger_email_action', $action_object, $entry_object, $form_object, $event ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WPForms.PHP.ValidateHooks.InvalidHookName -- Using Formidable Forms hook.

			/**
			 * Fires for a specific Formidable email action event.
			 *
			 * @since 1.0.0
			 *
			 * @hook frm_trigger_email_{event}_action
			 *
			 * @param object $action_object Action object.
			 * @param object $entry_object  Entry object.
			 * @param object $form_object   Form object.
			 */
			do_action( 'frm_trigger_email_' . $event . '_action', $action_object, $entry_object, $form_object ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WPForms.PHP.ValidateHooks.InvalidHookName -- Using Formidable Forms hook.

			return true;
		} catch ( Throwable $exception ) {
			Logger::log(
				'Formidable Forms: failed to replay email action',
				[
					'form_id'   => $form_id,
					'action_id' => $action_id,
					'entry_id'  => $entry_id,
					'error'     => $exception->getMessage(),
				]
			);

			return false;
		}
	}

	/**
	 * Reduce captured action metadata to the essentials.
	 *
	 * @since 1.0.0
	 *
	 * @param array $action Raw action array.
	 *
	 * @return array Sanitized data.
	 */
	private function sanitize_action( array $action ): array {

		$action_id = isset( $action['action_id'] ) ? (int) $action['action_id'] : 0;

		if ( $action_id <= 0 ) {
			return [];
		}

		return [
			'action_id' => $action_id,
		];
	}
}

<?php

namespace ActiveLayer\Integrations\WPForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Admin\Components\SubmissionActionHandler;
use ActiveLayer\Integrations\Comments\PluginInitiatedGuard;
use ActiveLayer\Logger\Logger;

/**
 * Keeps the spam status of WPForms Pro entries and ActiveLayer submissions in sync.
 *
 * Manual corrections in ActiveLayer (Mark as Clean / Mark as Spam) are mirrored
 * onto the WPForms entry, and WPForms "Mark as Not Spam" / "Mark as Spam" actions
 * are mirrored back onto the ActiveLayer submission, including API feedback.
 *
 * The WPForms side goes through SpamEntry so the entry log, Akismet reporting and
 * other WPForms side effects match its native UI. Notification emails are not
 * re-sent when ActiveLayer un-spams an entry (same as WPForms' bulk "Not Spam").
 *
 * @since 1.7.0
 */
class EntryStatusSync {

	/**
	 * Spam reason shown in the WPForms entry log when ActiveLayer marks it as spam.
	 *
	 * @since 1.7.0
	 */
	const SPAM_REASON = 'ActiveLayer';

	/**
	 * Parent integration instance.
	 *
	 * @since 1.7.0
	 *
	 * @var WPFormsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 *
	 * @param WPFormsIntegration $integration Parent integration instance.
	 */
	public function __construct( WPFormsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.7.0
	 */
	public function hooks(): void {

		add_action( 'activelayer_submission_status_changed', [ $this, 'sync_entry_from_submission' ], 10, 3 );
		add_action( 'wpforms_pro_anti_spam_entry_set_as_not_spam', [ $this, 'handle_entry_set_as_not_spam' ], 10, 1 );
		add_action( 'wpforms_pro_anti_spam_entry_marked_as_spam', [ $this, 'handle_entry_marked_as_spam' ], 10, 1 );
	}

	/**
	 * Mirror a manual ActiveLayer status correction onto the WPForms entry.
	 *
	 * Only clean<->spam transitions are handled: automatic verdicts (pending -> clean|spam)
	 * already update the entry through the verdict handlers. Trashed or incomplete
	 * provider entries retain their lifecycle status.
	 *
	 * @since 1.7.0
	 *
	 * @param string      $submission_id Submission identifier.
	 * @param string      $new_status    New submission status.
	 * @param string|null $old_status    Previous submission status.
	 */
	public function sync_entry_from_submission( string $submission_id, string $new_status, ?string $old_status ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$final_statuses = [ 'clean', 'spam' ];

		if ( ! in_array( $old_status, $final_statuses, true ) || ! in_array( $new_status, $final_statuses, true ) ) {
			return;
		}

		$submission = $this->integration->get_storage()->find( $submission_id );
		$provider   = $submission['provider'] ?? '';

		if ( ! $submission || $provider !== $this->integration->get_slug() || empty( $submission['entry_id'] ) ) {
			return;
		}

		$spam_entry = wpforms()->obj( 'spam_entry' );
		$entries    = wpforms()->obj( 'entry' );

		// Spam entries are a WPForms Pro feature.
		if ( ! is_object( $spam_entry ) || ! is_object( $entries ) ) {
			return;
		}

		$entry = $entries->get( (int) $submission['entry_id'], [ 'cap' => false ] );

		if ( ! in_array( $entry->status ?? null, [ '', 'spam' ], true ) ) {
			return;
		}

		$entry_is_spam = ( $entry->status ?? '' ) === 'spam';
		$wants_clean   = $new_status === 'clean';

		if ( $wants_clean !== $entry_is_spam ) {
			return; // Already in sync.
		}

		// Flip the status with cap => false first: SpamEntry's own update() runs WPForms'
		// edit_entry_single check, which silently bails for non-admin ActiveLayer managers.
		$entries->update( (int) $entry->entry_id, [ 'status' => $entry_is_spam ? '' : 'spam' ], '', '', [ 'cap' => false ] );

		// Flag the call stack so the WPForms hooks fired below do not re-enter the sync.
		PluginInitiatedGuard::run(
			static function () use ( $spam_entry, $entry, $entry_is_spam ) {
				if ( $entry_is_spam ) {
					$spam_entry->set_as_not_spam( $entry );
				} else {
					$spam_entry->set_as_spam( (int) $entry->entry_id, (int) $entry->form_id, self::SPAM_REASON );
				}
			}
		);

		Logger::log(
			'WPForms entry status synced from submission',
			[
				'submission_id' => $submission_id,
				'entry_id'      => (int) $entry->entry_id,
				'status'        => $new_status,
			]
		);
	}

	/**
	 * Handle WPForms "Mark as Not Spam".
	 *
	 * @since 1.7.0
	 *
	 * @param int|string $entry_id WPForms entry identifier.
	 */
	public function handle_entry_set_as_not_spam( $entry_id ): void {

		$this->sync_submission_from_entry( (int) $entry_id, 'clean' );
	}

	/**
	 * Handle WPForms "Mark as Spam".
	 *
	 * @since 1.7.0
	 *
	 * @param int|string $entry_id WPForms entry identifier.
	 */
	public function handle_entry_marked_as_spam( $entry_id ): void {

		$this->sync_submission_from_entry( (int) $entry_id, 'spam' );
	}

	/**
	 * Mirror a WPForms entry status change onto the matching ActiveLayer submission.
	 *
	 * Reuses SubmissionActionHandler::correct() so only clean<->spam transitions are
	 * applied and API feedback is queued exactly like a manual correction.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $entry_id WPForms entry identifier.
	 * @param string $status   Target submission status (clean|spam).
	 */
	private function sync_submission_from_entry( int $entry_id, string $status ): void {

		// The change originated from ActiveLayer (see sync_entry_from_submission) - nothing to do.
		if ( $entry_id <= 0 || PluginInitiatedGuard::is_active() ) {
			return;
		}

		$storage    = $this->integration->get_storage();
		$submission = $storage->find_by_entry_id( $this->integration->get_slug(), (string) $entry_id );

		if ( ! $submission ) {
			Logger::log(
				'WPForms entry has no ActiveLayer submission to sync',
				[
					'entry_id' => $entry_id,
					'status'   => $status,
				]
			);

			return;
		}

		$current_status = $submission['status'] ?? '';

		if ( $current_status === $status ) {
			return;
		}

		$handler = new SubmissionActionHandler( $storage );

		if ( ! $handler->correct( (string) $submission['id'], $status ) ) {
			return;
		}

		Logger::log(
			'Submission status synced from WPForms entry',
			[
				'submission_id' => (string) $submission['id'],
				'entry_id'      => $entry_id,
				'status'        => $status,
			]
		);
	}
}

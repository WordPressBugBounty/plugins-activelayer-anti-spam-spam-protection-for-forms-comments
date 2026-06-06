<?php

namespace ActiveLayer\Admin\Components;

use ActiveLayer\Queue\QueueManager;
use ActiveLayer\Storage\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission Action Handler.
 *
 * Business logic for submission actions: recheck, mark clean/spam, and status
 * transition validation. Decoupled from the admin page controller so it can be
 * reused by bulk actions, single row actions, and future REST/AJAX handlers.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 *
 * @package ActiveLayer\Admin
 */
class SubmissionActionHandler {

	/**
	 * Storage instance.
	 *
	 * @since 1.1.0
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param Storage|null $storage Optional storage instance (defaults to singleton).
	 */
	public function __construct( ?Storage $storage = null ) {

		$this->storage = $storage ?? Storage::get_instance();
	}

	/**
	 * Recheck a submission by resetting it to pending and re-queuing.
	 *
	 * Only submissions with `failed` status can be rechecked. Uses an atomic
	 * UPDATE … WHERE status='failed' to prevent races with concurrent retry sweeps.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID.
	 *
	 * @return bool True if rechecked, false if submission is not in failed status.
	 */
	public function recheck( string $submission_id ): bool {

		// Atomically reset only if still in 'failed' status.
		// Prevents race condition with concurrent retry sweeps.
		if ( ! $this->storage->reset_for_retry( $submission_id ) ) {
			return false;
		}

		QueueManager::queue( $submission_id );

		return true;
	}

	/**
	 * Handle user correction of API verdict.
	 *
	 * Updates the local status and queues feedback to the API.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $new_status    New status (clean|spam).
	 *
	 * @return bool True if the status was changed, false otherwise.
	 */
	public function correct( string $submission_id, string $new_status ): bool {

		if ( ! $this->is_valid_transition( $submission_id, $new_status ) ) {
			return false;
		}

		$submission  = $this->storage->find( $submission_id );
		$api_verdict = $submission['verdict'] ?? null;

		// Update local status immediately.
		$this->storage->update_status( $submission_id, $new_status );

		// Send feedback to API if we have an API verdict.
		if ( $api_verdict ) {
			QueueManager::queue_feedback( $submission_id, $new_status );
		}

		return true;
	}

	/**
	 * Validate whether a status transition is allowed.
	 *
	 * Only clean→spam and spam→clean transitions are permitted.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param string $new_status    Target status.
	 *
	 * @return bool True if the transition is valid.
	 */
	public function is_valid_transition( string $submission_id, string $new_status ): bool {

		if ( ! in_array( $new_status, [ 'clean', 'spam' ], true ) ) {
			return false;
		}

		$submission = $this->storage->find( $submission_id );

		if ( ! $submission ) {
			return false;
		}

		$allowed_targets = [
			'spam'  => 'clean',
			'clean' => 'spam',
		];

		$expected = $allowed_targets[ $submission['status'] ] ?? null;

		return $new_status === $expected;
	}
}

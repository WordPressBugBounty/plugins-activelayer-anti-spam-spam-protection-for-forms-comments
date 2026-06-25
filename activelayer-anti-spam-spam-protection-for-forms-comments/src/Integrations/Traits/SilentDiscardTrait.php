<?php

namespace ActiveLayer\Integrations\Traits;

use ActiveLayer\Storage\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * High-confidence spam silent-discard decision.
 *
 * Shared by comment/review integrations that may hard-delete spam above a
 * configurable confidence threshold instead of keeping it for moderation. Reads
 * the spam score from the submission's stored API response and compares it to
 * the integration-configured threshold.
 *
 * Self-contained: depends only on {@see Storage} and the `$settings` array
 * passed in by the caller.
 *
 * @since 1.5.0
 *
 * @package ActiveLayer\Integrations\Traits
 */
trait SilentDiscardTrait {

	/**
	 * Decide whether to silently discard a spam submission instead of flagging it.
	 *
	 * Returns true only when the toggle is enabled, the score is numeric, and it
	 * meets the threshold — missing scores fall back to normal spam handling to
	 * avoid silent over-deletion on partial data.
	 *
	 * @since 1.2.0
	 *
	 * @param string $submission_id Submission ID.
	 * @param array  $settings      Integration settings.
	 *
	 * @return bool
	 */
	private function should_silently_discard( string $submission_id, array $settings ): bool {

		if ( empty( $settings['auto_delete_high_confidence_spam'] ) ) {
			return false;
		}

		$score = $this->read_submission_spam_score( $submission_id );

		if ( $score === null ) {
			return false;
		}

		$threshold = isset( $settings['delete_spam_score_threshold'] )
			? (int) $settings['delete_spam_score_threshold']
			: 95;

		return $score >= $threshold;
	}

	/**
	 * Resolve the spam score stored on a submission's API response.
	 *
	 * Returns null when the submission row is missing or the response payload
	 * lacks a usable numeric `total_score` — letting callers fall through to the
	 * default spam handling instead of acting on partial data.
	 *
	 * @since 1.2.0
	 *
	 * @param string $submission_id Submission identifier.
	 *
	 * @return int|null
	 */
	private function read_submission_spam_score( string $submission_id ): ?int {

		$submission = Storage::get_instance()->find( $submission_id );

		if ( ! is_array( $submission ) ) {
			return null;
		}

		$api_response = $submission['api_response'] ?? null;

		// format_submission() already decodes the JSON string to an array, but guard
		// against edge-cases where the value may still be a raw JSON string.
		if ( is_string( $api_response ) ) {
			$api_response = json_decode( $api_response, true );
		}

		if ( ! is_array( $api_response ) || ! isset( $api_response['total_score'] ) || ! is_numeric( $api_response['total_score'] ) ) {
			return null;
		}

		return (int) $api_response['total_score'];
	}
}

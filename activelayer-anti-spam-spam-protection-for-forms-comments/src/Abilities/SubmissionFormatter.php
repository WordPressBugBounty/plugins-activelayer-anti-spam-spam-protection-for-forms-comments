<?php
/**
 * Formats stored submissions into safe Abilities API output.
 *
 * @package ActiveLayer
 */

namespace ActiveLayer\Abilities;

use ActiveLayer\Helpers\DetectionIdResolver;

/**
 * Pure transformer: submission row -> safe summary/detail arrays.
 *
 * @since 1.6.0
 */
class SubmissionFormatter {

	/**
	 * Build a compact summary of a submission.
	 *
	 * @since 1.6.0
	 *
	 * @param array $submission Submission as returned by RequestHelper::format_submission().
	 *
	 * @return array Safe summary keyed id, provider, status, verdict, email, score, created.
	 */
	public function summary( array $submission ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$form_data    = $this->array_value( $submission, 'form_data' );
		$api_response = $this->array_value( $submission, 'api_response' );

		$summary = [
			'id'       => (int) ( $submission['id'] ?? 0 ),
			'provider' => (string) ( $submission['provider'] ?? '' ),
			'status'   => (string) ( $submission['status'] ?? '' ),
			'verdict'  => (string) ( $submission['verdict'] ?? '' ),
			'email'    => sanitize_email( (string) ( $form_data['email'] ?? '' ) ),
			'score'    => isset( $api_response['score'] ) ? (float) $api_response['score'] : null,
			'created'  => (int) ( $submission['created_at'] ?? 0 ),
		];

		/**
		 * Filters the submission summary exposed via the Abilities API.
		 *
		 * @since 1.6.0
		 *
		 * @param array $summary    Formatted summary.
		 * @param array $submission Raw submission.
		 */
		return apply_filters( 'activelayer_abilities_submission_summary', $summary, $submission );
	}

	/**
	 * Build a detailed view of a submission.
	 *
	 * @since 1.6.0
	 *
	 * @param array $submission     Submission as returned by RequestHelper::format_submission().
	 * @param bool  $include_fields Whether to include the decoded form fields.
	 *
	 * @return array Safe detail array.
	 */
	public function detail( array $submission, bool $include_fields = true ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$form_data = $this->array_value( $submission, 'form_data' );

		/**
		 * Filters whether to mask the submission IP address in Abilities output.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $mask Whether to mask the IP. Default false.
		 */
		$mask = (bool) apply_filters( 'activelayer_abilities_mask_ip_address', false );

		$detail = array_merge(
			$this->summary( $submission ),
			[
				'detection_id'    => DetectionIdResolver::resolve( $submission['api_response'] ?? null ),
				'ip_address'      => $this->mask_ip( (string) ( $form_data['ip'] ?? '' ), $mask ),
				'user_agent'      => (string) ( $form_data['user_agent'] ?? '' ),
				'processed'       => isset( $submission['processed_at'] ) ? (int) $submission['processed_at'] : null,
				'previous_status' => (string) ( $submission['previous_status'] ?? '' ),
				'retry_count'     => (int) ( $submission['retry_count'] ?? 0 ),
				'entry_id'        => (string) ( $submission['entry_id'] ?? '' ),
			]
		);

		if ( $include_fields ) {
			$fields = $form_data;

			if ( $mask && isset( $fields['ip'] ) ) {
				$fields['ip'] = $this->mask_ip( (string) $fields['ip'], true );
			}

			$detail['fields'] = $fields;
		}

		/**
		 * Filters the submission detail exposed via the Abilities API.
		 *
		 * @since 1.6.0
		 *
		 * @param array $detail     Formatted detail.
		 * @param array $submission Raw submission.
		 */
		return apply_filters( 'activelayer_abilities_submission_detail', $detail, $submission );
	}

	/**
	 * Mask an IP address when masking is enabled.
	 *
	 * @since 1.6.0
	 *
	 * @param string $ip   IP address.
	 * @param bool   $mask Whether to mask.
	 *
	 * @return string Masked or original IP.
	 */
	private function mask_ip( string $ip, bool $mask ): string {

		if ( $ip === '' || ! $mask ) {
			return $ip;
		}

		$parts = explode( '.', $ip );

		if ( count( $parts ) === 4 ) {
			return '***.***.***.' . $parts[3];
		}

		// Non-IPv4 input (e.g. IPv6) has no dotted-octet structure; fall back to full redaction.
		return '***';
	}

	/**
	 * Safely read an array-typed key.
	 *
	 * @since 1.6.0
	 *
	 * @param array  $submission Submission array.
	 * @param string $key        Key to read.
	 *
	 * @return array Array value or empty array.
	 */
	private function array_value( array $submission, string $key ): array {

		return isset( $submission[ $key ] ) && is_array( $submission[ $key ] ) ? $submission[ $key ] : [];
	}
}

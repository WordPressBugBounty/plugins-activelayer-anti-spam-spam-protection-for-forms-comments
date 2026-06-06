<?php
/**
 * Privacy Manager for GDPR compliance.
 *
 * Implements WordPress Privacy API hooks for data export and erasure.
 *
 * @package ActiveLayer
 * @since   1.0.1
 */

namespace ActiveLayer\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Storage\Storage;

/**
 * Manages GDPR data export and erasure for ActiveLayer submissions.
 *
 * @since 1.0.0
 */
class PrivacyManager {

	/**
	 * Number of submissions to process per page during export/erasure.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private const ITEMS_PER_PAGE = 50;

	/**
	 * Privacy data group identifier.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const GROUP_ID = 'activelayer-submissions';

	/**
	 * Register privacy hooks.
	 *
	 * @since 1.0.0
	 */
	public function hooks(): void {

		// Register data exporter.
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );

		// Register data eraser.
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Register the personal data exporter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $exporters Registered exporters.
	 *
	 * @return array Modified exporters array.
	 */
	public function register_exporter( array $exporters ): array {

		$exporters['activelayer'] = [
			'exporter_friendly_name' => __( 'ActiveLayer Spam Check Data', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'callback'               => [ $this, 'export_personal_data' ],
		];

		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @since 1.0.0
	 *
	 * @param array $erasers Registered erasers.
	 *
	 * @return array Modified erasers array.
	 */
	public function register_eraser( array $erasers ): array {

		$erasers['activelayer'] = [
			'eraser_friendly_name' => __( 'ActiveLayer Spam Check Data', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'callback'             => [ $this, 'erase_personal_data' ],
		];

		return $erasers;
	}

	/**
	 * Export personal data for a user.
	 *
	 * Called by WordPress Privacy Tools when processing a data export request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email_address User's email address.
	 * @param int    $page          Page number for pagination.
	 *
	 * @return array Export response with 'data' and 'done' keys.
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {

		$submissions = $this->find_submissions_by_email( $email_address, $page );
		$export_data = [];

		foreach ( $submissions as $submission ) {
			$data_items = $this->build_submission_export_data( $submission );

			$export_data[] = [
				'group_id'          => self::GROUP_ID,
				'group_label'       => __( 'ActiveLayer Form Submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'group_description' => __( 'Data collected during spam analysis of form submissions.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'item_id'           => 'submission-' . $submission['id'],
				'data'              => $data_items,
			];
		}

		// Check if there are more pages.
		$done = count( $submissions ) < self::ITEMS_PER_PAGE;

		return [
			'data' => $export_data,
			'done' => $done,
		];
	}

	/**
	 * Build export data items for a single submission.
	 *
	 * @since 1.0.0
	 *
	 * @param array $submission Submission record.
	 *
	 * @return array Data items for export.
	 */
	private function build_submission_export_data( array $submission ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$form_data = $submission['form_data'] ?? [];

		$data_items = [
			[
				'name'  => __( 'Submission ID', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'value' => $submission['id'],
			],
			[
				'name'  => __( 'Form Provider', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'value' => $submission['provider'] ?? '',
			],
			[
				'name'  => __( 'Form ID', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'value' => $submission['form_id'] ?? '',
			],
			[
				'name'  => __( 'Status', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'value' => $submission['status'] ?? '',
			],
			[
				'name'  => __( 'Verdict', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'value' => $submission['verdict'] ?? __( 'Not yet processed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			],
			[
				'name'  => __( 'Submitted At', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'value' => $submission['created_at'] ?? '',
			],
		];

		return $this->add_form_data_fields( $data_items, $form_data );
	}

	/**
	 * Add form data fields to export data items.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data_items Existing data items array.
	 * @param array $form_data  Form data from submission.
	 *
	 * @return array Modified data items with form fields added.
	 */
	private function add_form_data_fields( array $data_items, array $form_data ): array {

		$field_mappings = [
			'email'       => __( 'Email', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'name'        => __( 'Name', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'message'     => __( 'Message', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'website_url' => __( 'Website URL', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'ip'          => __( 'IP Address', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'user_agent'  => __( 'User Agent', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
		];

		foreach ( $field_mappings as $key => $label ) {
			if ( ! empty( $form_data[ $key ] ) ) {
				$data_items[] = [
					'name'  => $label,
					'value' => $form_data[ $key ],
				];
			}
		}

		return $data_items;
	}

	/**
	 * Erase personal data for a user.
	 *
	 * Called by WordPress Privacy Tools when processing a data erasure request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email_address User's email address.
	 * @param int    $page          Page number for pagination.
	 *
	 * @return array Erasure response with 'items_removed', 'items_retained', 'messages', and 'done' keys.
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {

		$submissions   = $this->find_submissions_by_email( $email_address, $page );
		$items_removed = 0;
		$messages      = [];

		$storage = Storage::get_instance();

		foreach ( $submissions as $submission ) {
			$deleted = $storage->delete( (string) $submission['id'] );

			if ( $deleted ) {
				++$items_removed;
			} else {
				$messages[] = sprintf(
					/* translators: %d: submission ID that could not be deleted. */
					__( 'Could not delete submission #%d.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					$submission['id']
				);
			}
		}

		// Check if there are more pages to process.
		// If we deleted items, there might be more on the "next" page (which is now at this page position).
		$remaining = $this->count_submissions_by_email( $email_address );
		$done      = $remaining === 0;

		return [
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * Find submissions by email address.
	 *
	 * Searches the form_data JSON column for matching email addresses.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Email address to search for.
	 * @param int    $page  Page number (1-indexed).
	 *
	 * @return array Array of submission records.
	 */
	private function find_submissions_by_email( string $email, int $page = 1 ): array {

		$storage = Storage::get_instance();

		if ( ! $storage->table_exists() ) {
			return [];
		}

		global $wpdb;

		$table_name = esc_sql( $storage->get_table_name() );
		$offset     = ( max( 1, $page ) - 1 ) * self::ITEMS_PER_PAGE;

		// Search for email in JSON form_data column.
		// The email is stored as: "email":"user@example.com".
		$like_pattern = '%"email":"' . $wpdb->esc_like( $email ) . '"%';

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM `$table_name` WHERE form_data LIKE %s ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like_pattern,
				self::ITEMS_PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return [];
		}

		// Decode form_data JSON for each result.
		return array_map(
			static function ( $row ) {
				if ( ! empty( $row['form_data'] ) ) {
					$decoded          = json_decode( $row['form_data'], true );
					$row['form_data'] = is_array( $decoded ) ? $decoded : [];
				} else {
					$row['form_data'] = [];
				}

				return $row;
			},
			$results
		);
	}

	/**
	 * Count total submissions for an email address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Email address to count.
	 *
	 * @return int Number of matching submissions.
	 */
	private function count_submissions_by_email( string $email ): int {

		$storage = Storage::get_instance();

		if ( ! $storage->table_exists() ) {
			return 0;
		}

		global $wpdb;

		$table_name   = esc_sql( $storage->get_table_name() );
		$like_pattern = '%"email":"' . $wpdb->esc_like( $email ) . '"%';

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table_name` WHERE form_data LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like_pattern
			)
		);
	}
}

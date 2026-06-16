<?php

namespace ActiveLayer\Storage\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles query construction and argument normalization for submissions.
 *
 * @since 1.0.0
 */
class SubmissionQueryBuilder {

	/**
	 * Allowed submission providers.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added `wc_reviews` provider.
	 * @since 1.2.0 Added `wc_registration` provider.
	 * @since 1.3.0 Added `buddypress` provider.
	 * @since 1.3.0 Added `buddyboss` provider (BuddyBoss Platform signup integration).
	 * @since 1.4.0 Added `ws_form` provider (WS Form integration).
	 * @since 1.4.0 Added `affiliatewp` provider (AffiliateWP affiliate-registration integration).
	 * @since 1.4.0 Added `memberpress` provider (MemberPress membership-signup integration).
	 *
	 * @var array
	 */
	private $allowed_providers = [
		'wpforms',
		'wp_comments',
		'contact_form_7',
		'ninja_forms',
		'formidable_forms',
		'forminator',
		'fluent_forms',
		'sureforms',
		'gravity_forms',
		'elementor_forms',
		'wc_reviews',
		'wc_registration',
		'buddypress',
		'buddyboss',
		'ws_form',
		'affiliatewp',
		'memberpress',
	];

	/**
	 * Allowed submission statuses.
	 *
	 * Current set: pending, clean, spam, failed, trash.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $allowed_statuses = [
		'pending',
		'clean',
		'spam',
		'failed',
		'trash',
	];

	/**
	 * Get allowed providers.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of allowed provider names.
	 */
	public function get_allowed_providers(): array {

		return $this->allowed_providers;
	}

	/**
	 * Get allowed statuses.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of allowed status names.
	 */
	public function get_allowed_statuses(): array {

		return $this->allowed_statuses;
	}

	/**
	 * Check if a provider is allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider Provider name to check.
	 *
	 * @return bool True if provider is allowed, false otherwise.
	 */
	public function is_allowed_provider( string $provider ): bool {

		return in_array( $provider, $this->allowed_providers, true );
	}

	/**
	 * Check if a status is allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Status name to check.
	 *
	 * @return bool True if status is allowed, false otherwise.
	 */
	public function is_allowed_status( string $status ): bool {

		return in_array( $status, $this->allowed_statuses, true );
	}

	/**
	 * Normalize arguments for submissions queries.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Raw query arguments.
	 *
	 * @return array Normalized arguments (status, provider, search, limit, offset, exclude_trash) with limit clamped to 1-200 and offset floored at 0.
	 */
	public function normalize_args( array $args ): array {

		$status        = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$provider      = isset( $args['provider'] ) ? sanitize_text_field( (string) $args['provider'] ) : '';
		$search        = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
		$limit         = (int) ( $args['limit'] ?? 50 );
		$offset        = (int) ( $args['offset'] ?? 0 );
		$exclude_trash = ! empty( $args['exclude_trash'] );

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		return [
			'status'        => $status,
			'provider'      => $provider,
			'search'        => $search,
			'limit'         => $limit,
			'offset'        => $offset,
			'exclude_trash' => $exclude_trash,
		];
	}

	/**
	 * Build parameterized WHERE clause for submissions queries.
	 *
	 * Returns a clause template with %s/%d placeholders and a separate values
	 * array so callers can pass both into $wpdb->prepare() for proper escaping.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Returns array with 'clause' and 'values' keys instead of pre-interpolated string.
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array{clause: string, values: array} SQL fragment with placeholders and corresponding values.
	 */
	public function build_where_clause( array $args ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$templates = [];
		$values    = [];

		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';

		if ( $status !== '' ) {
			$templates[] = 'status = %s';
			$values[]    = $status;
		}

		if ( ! empty( $args['exclude_trash'] ) ) {
			$templates[] = 'status != %s';
			$values[]    = 'trash';
		}

		$provider = isset( $args['provider'] ) ? sanitize_text_field( (string) $args['provider'] ) : '';

		if ( $provider !== '' ) {
			$templates[] = 'provider = %s';
			$values[]    = $provider;
		}

		$search = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		if ( $search !== '' ) {
			global $wpdb;

			$templates[] = 'form_data LIKE %s';
			$values[]    = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return [
			'clause' => $templates ? 'WHERE ' . implode( ' AND ', $templates ) : '',
			'values' => $values,
		];
	}
}

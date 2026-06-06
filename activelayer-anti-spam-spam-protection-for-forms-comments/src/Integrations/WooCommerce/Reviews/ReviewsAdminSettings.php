<?php

namespace ActiveLayer\Integrations\WooCommerce\Reviews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Reviews Admin Settings.
 *
 * Handles admin settings and configuration for the WooCommerce Reviews integration.
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Integrations\WooCommerce\Reviews
 */
class ReviewsAdminSettings {

	/**
	 * Default review integration settings.
	 *
	 * @since 1.2.0
	 */
	public const DEFAULT_SETTINGS = [
		'enabled'                          => false,
		'check_logged_in_users'            => false,
		'check_verified_owners'            => false,
		'auto_approve_clean'               => true,
		'auto_spam_detected'               => true,
		'auto_delete_high_confidence_spam' => false,
		'delete_spam_score_threshold'      => 95,
	];

	/**
	 * Parent integration instance.
	 *
	 * @since 1.2.0
	 *
	 * @var ReviewsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param ReviewsIntegration $integration Parent integration.
	 */
	public function __construct( ReviewsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize admin settings.
	 *
	 * @since 1.2.0
	 */
	public function init(): void {
	}

	/**
	 * Get review settings.
	 *
	 * @since 1.2.0
	 *
	 * @return array Review settings.
	 */
	public function get_review_settings(): array {

		$option_name = $this->integration->get_option_key();
		$saved       = get_option( $option_name, [] );

		return wp_parse_args( $saved, self::DEFAULT_SETTINGS );
	}

	/**
	 * Update review settings.
	 *
	 * @since 1.2.0
	 *
	 * @param array $settings New settings to save.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function update_review_settings( array $settings ): bool {

		$option_name = $this->integration->get_option_key();

		// Reject non-numeric input (null, '', 'abc', arrays, objects) so a malformed
		// payload cannot silently land at threshold 0 and discard every spam verdict.
		$raw_threshold = $settings['delete_spam_score_threshold'] ?? null;
		$threshold     = is_numeric( $raw_threshold )
			? max( 0, min( 100, (int) $raw_threshold ) )
			: 95;

		$clean_settings = [
			'enabled'                          => ! empty( $settings['enabled'] ),
			'check_logged_in_users'            => ! empty( $settings['check_logged_in_users'] ),
			'check_verified_owners'            => ! empty( $settings['check_verified_owners'] ),
			'auto_approve_clean'               => ! empty( $settings['auto_approve_clean'] ),
			'auto_spam_detected'               => ! empty( $settings['auto_spam_detected'] ),
			'auto_delete_high_confidence_spam' => ! empty( $settings['auto_delete_high_confidence_spam'] ),
			'delete_spam_score_threshold'      => $threshold,
		];

		return update_option( $option_name, $clean_settings );
	}

	/**
	 * Get integration status for admin display.
	 *
	 * @since 1.2.0
	 *
	 * @return array Status information.
	 */
	public function get_status(): array {

		$settings = $this->get_review_settings();

		return [
			'name'        => 'WooCommerce Reviews',
			'slug'        => $this->integration->get_slug(),
			'active'      => $this->integration->is_active(),
			'enabled'     => $settings['enabled'],
			'description' => esc_html__( 'Analyze WooCommerce product reviews for spam detection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'settings'    => $settings,
		];
	}
}

<?php

namespace ActiveLayer\Integrations\Comments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Comments Admin Settings.
 *
 * Handles admin settings and configuration for the Comments integration.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\Comments
 */
class AdminSettings {

	/**
	 * Default comment integration settings.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added auto-delete-high-confidence-spam fields.
	 */
	public const DEFAULT_SETTINGS = [
		'enabled'                          => true,
		'tracking_mode'                    => false,
		'check_logged_in_users'            => false,
		'auto_approve_clean'               => true,
		'auto_spam_detected'               => true,
		'auto_delete_high_confidence_spam' => false,
		'delete_spam_score_threshold'      => 95,
		'min_comment_length'               => 10,
		'max_comment_length'               => 1000,
		'check_trackbacks'                 => true,
		'check_pingbacks'                  => true,
	];

	/**
	 * Parent integration instance.
	 *
	 * @since 1.0.0
	 *
	 * @var CommentsIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param CommentsIntegration $integration Parent integration.
	 */
	public function __construct( CommentsIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize admin settings.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
	}

	/**
	 * Get comment settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Comment settings.
	 */
	public function get_comment_settings(): array {

		$option_name                       = $this->integration->get_option_key();
		$saved                             = get_option( $option_name, [] );
		$tracking_mode_defined             = is_array( $saved ) && array_key_exists( 'tracking_mode', $saved );
		$settings                          = wp_parse_args( $saved, self::DEFAULT_SETTINGS );
		$settings['tracking_mode_defined'] = $tracking_mode_defined;

		return $settings;
	}

	/**
	 * Update comment settings.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added auto_delete_high_confidence_spam and delete_spam_score_threshold fields with threshold clamping.
	 *
	 * @param array $settings New settings to save.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function update_comment_settings( array $settings ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$option_name     = $this->integration->get_option_key();
		$current_enabled = $this->get_comment_settings()['enabled'];

		// Reject non-numeric input (null, '', 'abc', arrays, objects) so a malformed
		// payload cannot silently land at threshold 0 and discard every spam verdict.
		$raw_threshold = $settings['delete_spam_score_threshold'] ?? null;
		$threshold     = is_numeric( $raw_threshold )
			? max( 0, min( 100, (int) $raw_threshold ) )
			: 95;

		$clean_settings = [
			// Preserve current enabled state if not explicitly provided.
			'enabled'                          => array_key_exists( 'enabled', $settings ) ? ! empty( $settings['enabled'] ) : $current_enabled,
			'tracking_mode'                    => isset( $settings['tracking_mode'] ),
			'check_logged_in_users'            => isset( $settings['check_logged_in_users'] ),
			'auto_approve_clean'               => isset( $settings['auto_approve_clean'] ),
			'auto_spam_detected'               => isset( $settings['auto_spam_detected'] ),
			'auto_delete_high_confidence_spam' => ! empty( $settings['auto_delete_high_confidence_spam'] ),
			'delete_spam_score_threshold'      => $threshold,
			'check_trackbacks'                 => isset( $settings['check_trackbacks'] ),
			'check_pingbacks'                  => isset( $settings['check_pingbacks'] ),
			'min_comment_length'               => absint( $settings['min_comment_length'] ?? 10 ),
			'max_comment_length'               => absint( $settings['max_comment_length'] ?? 1000 ),
		];

		$result = update_option( $option_name, $clean_settings );

		return $result;
	}

	/**
	 * Get integration status for admin display.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Wrap description string with the translation helper.
	 *
	 * @return array Status information.
	 */
	public function get_status(): array {

		$settings = $this->get_comment_settings();

		return [
			'name'        => 'WordPress Comments',
			'slug'        => $this->integration->get_slug(),
			'active'      => $this->integration->is_active(),
			'enabled'     => $settings['enabled'],
			'description' => esc_html__( 'Analyze WordPress comments for spam detection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'settings'    => $settings,
		];
	}
}

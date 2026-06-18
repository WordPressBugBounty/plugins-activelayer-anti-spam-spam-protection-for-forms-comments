<?php

namespace ActiveLayer\Integrations\MemberPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MemberPress admin settings.
 *
 * Stores configuration under option key `activelayer_memberpress_settings`.
 * `enabled` (default true, opt-out) toggles protection once the API key is
 * connected; `block_paid_signups` (default false) opts in to also gating
 * real-money signups. Mirrors AffiliateWP\AdminSettings.
 *
 * @since 1.4.0
 *
 * @package ActiveLayer\Integrations\MemberPress
 */
class AdminSettings {

	/**
	 * Default settings — enabled by default (opt-out); paid signups not gated.
	 *
	 * @since 1.4.0
	 * @since 1.4.1 Added `block_paid_signups` (default false).
	 */
	public const DEFAULT_SETTINGS = [
		'enabled'            => true,
		'block_paid_signups' => false,
	];

	/**
	 * Parent integration instance.
	 *
	 * @since 1.4.0
	 *
	 * @var MemberPressIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 *
	 * @param MemberPressIntegration $integration Parent integration.
	 */
	public function __construct( MemberPressIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize. Reserved for future settings-panel wiring.
	 *
	 * @since 1.4.0
	 */
	public function init(): void {
	}

	/**
	 * Read current settings from storage.
	 *
	 * @since 1.4.0
	 *
	 * @return array
	 */
	public function get_settings(): array {

		$option_name = $this->integration->get_option_key();
		$saved       = get_option( $option_name, [] );

		return wp_parse_args( $saved, self::DEFAULT_SETTINGS );
	}

	/**
	 * Persist settings.
	 *
	 * Full replace: callers must pass the complete intended state. The panel
	 * save path re-posts `enabled` alongside `block_paid_signups` so a panel
	 * save never drops the master toggle.
	 *
	 * @since 1.4.0
	 * @since 1.4.1 Also persists `block_paid_signups`.
	 *
	 * @param array $settings Submitted settings.
	 *
	 * @return bool True on success.
	 */
	public function update_settings( array $settings ): bool {

		$option_name = $this->integration->get_option_key();

		$clean_settings = [
			'enabled'            => ! empty( $settings['enabled'] ),
			'block_paid_signups' => ! empty( $settings['block_paid_signups'] ),
		];

		return update_option( $option_name, $clean_settings );
	}

	/**
	 * Status summary for admin display.
	 *
	 * @since 1.4.0
	 *
	 * @return array
	 */
	public function get_status(): array {

		$settings = $this->get_settings();

		return [
			'name'        => $this->integration->get_name(),
			'slug'        => $this->integration->get_slug(),
			'active'      => $this->integration->is_active(),
			'enabled'     => $settings['enabled'],
			'description' => esc_html__( 'Block spam sign-ups on MemberPress membership registration forms.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'settings'    => $settings,
		];
	}
}

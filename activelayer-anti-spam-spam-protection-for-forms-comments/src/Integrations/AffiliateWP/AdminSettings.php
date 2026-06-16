<?php

namespace ActiveLayer\Integrations\AffiliateWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AffiliateWP admin settings.
 *
 * Stores configuration under option key `activelayer_affiliatewp_settings`.
 * Single `enabled` toggle, default true (opt-out — protection on after the
 * API key is connected). Mirrors WooCommerce\Registration\RegistrationAdminSettings.
 *
 * @since 1.4.0
 *
 * @package ActiveLayer\Integrations\AffiliateWP
 */
class AdminSettings {

	/**
	 * Default settings — enabled by default (opt-out).
	 *
	 * @since 1.4.0
	 */
	public const DEFAULT_SETTINGS = [
		'enabled' => true,
	];

	/**
	 * Parent integration instance.
	 *
	 * @since 1.4.0
	 *
	 * @var AffiliateWPIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 *
	 * @param AffiliateWPIntegration $integration Parent integration.
	 */
	public function __construct( AffiliateWPIntegration $integration ) {

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
	 * @since 1.4.0
	 *
	 * @param array $settings Submitted settings.
	 *
	 * @return bool True on success.
	 */
	public function update_settings( array $settings ): bool {

		$option_name = $this->integration->get_option_key();

		$clean_settings = [
			'enabled' => ! empty( $settings['enabled'] ),
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
			'description' => esc_html__( 'Block spam registrations on the AffiliateWP affiliate registration form.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'settings'    => $settings,
		];
	}
}

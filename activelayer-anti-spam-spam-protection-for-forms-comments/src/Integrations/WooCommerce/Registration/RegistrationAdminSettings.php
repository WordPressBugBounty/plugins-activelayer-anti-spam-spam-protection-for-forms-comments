<?php

namespace ActiveLayer\Integrations\WooCommerce\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Registration admin settings.
 *
 * Stores configuration under option key `activelayer_wc_registration_settings`.
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Integrations\WooCommerce\Registration
 */
class RegistrationAdminSettings {

	/**
	 * Default settings.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Default flipped to opt-out — protection enabled by default after API key connection.
	 * @since 1.4.1 Removed `protect_checkout_register` — register-during-checkout is no longer checked.
	 */
	public const DEFAULT_SETTINGS = [
		'enabled' => true,
	];

	/**
	 * Parent integration instance.
	 *
	 * @since 1.2.0
	 *
	 * @var RegistrationIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param RegistrationIntegration $integration Parent integration.
	 */
	public function __construct( RegistrationIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize.
	 *
	 * @since 1.2.0
	 */
	public function init(): void {
	}

	/**
	 * Read current settings from storage.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function get_registration_settings(): array {

		$option_name = $this->integration->get_option_key();
		$saved       = get_option( $option_name, [] );

		return wp_parse_args( $saved, self::DEFAULT_SETTINGS );
	}

	/**
	 * Persist settings.
	 *
	 * @since 1.2.0
	 *
	 * @param array $settings Submitted settings.
	 *
	 * @return bool True on success.
	 */
	public function update_registration_settings( array $settings ): bool {

		$option_name = $this->integration->get_option_key();

		$clean_settings = [
			'enabled' => ! empty( $settings['enabled'] ),
		];

		return update_option( $option_name, $clean_settings );
	}

	/**
	 * Status summary for admin display.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function get_status(): array {

		$settings = $this->get_registration_settings();

		return [
			'name'        => 'WooCommerce Registration',
			'slug'        => $this->integration->get_slug(),
			'active'      => $this->integration->is_active(),
			'enabled'     => $settings['enabled'],
			'description' => esc_html__( 'Block spam registrations on the WooCommerce My Account form and the checkout register flow.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'settings'    => $settings,
		];
	}
}

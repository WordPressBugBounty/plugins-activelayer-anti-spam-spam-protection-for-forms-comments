<?php

namespace ActiveLayer\Integrations\EasyDigitalDownloads\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD Registration admin settings.
 *
 * Stores configuration under option key `activelayer_edd_registration_settings`.
 * Single `enabled` toggle, default true (opt-out — protection on after the API
 * key is connected). No paid-signup option: standalone EDD registration creates
 * a free account and never charges money, so a block can never cost a sale.
 * Mirrors WooCommerce\Registration\RegistrationAdminSettings.
 *
 * @since 1.5.0
 *
 * @package ActiveLayer\Integrations\EasyDigitalDownloads\Registration
 */
class RegistrationAdminSettings {

	/**
	 * Default settings — enabled by default (opt-out).
	 *
	 * @since 1.5.0
	 */
	public const DEFAULT_SETTINGS = [
		'enabled' => true,
	];

	/**
	 * Parent integration instance.
	 *
	 * @since 1.5.0
	 *
	 * @var RegistrationIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param RegistrationIntegration $integration Parent integration.
	 */
	public function __construct( RegistrationIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize.
	 *
	 * @since 1.5.0
	 */
	public function init(): void {
	}

	/**
	 * Read current settings from storage.
	 *
	 * @since 1.5.0
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
	 * @since 1.5.0
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
	 * @since 1.5.0
	 *
	 * @return array
	 */
	public function get_status(): array {

		$settings = $this->get_registration_settings();

		return [
			'name'        => 'EDD Registration',
			'slug'        => $this->integration->get_slug(),
			'active'      => $this->integration->is_active(),
			'enabled'     => $settings['enabled'],
			'description' => esc_html__( 'Block spam registrations on the Easy Digital Downloads registration form.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'settings'    => $settings,
		];
	}
}

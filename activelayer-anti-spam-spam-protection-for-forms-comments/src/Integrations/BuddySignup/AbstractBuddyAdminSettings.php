<?php

namespace ActiveLayer\Integrations\BuddySignup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared admin-settings surface for the BuddyPress / BuddyBoss signup
 * integrations.
 *
 * Owns the entire persistence + status-summary contract; concrete subclasses
 * provide only the human-readable description string. The option key is
 * derived from the parent integration's slug via `get_option_key()`, so
 * each flavour writes to its own row (`activelayer_buddypress_settings` vs
 * `activelayer_buddyboss_settings`).
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Integrations\BuddySignup
 */
abstract class AbstractBuddyAdminSettings {

	/**
	 * Default settings — enabled by default (opt-out); admin can disable per flavour.
	 *
	 * @since 1.3.0
	 */
	public const DEFAULT_SETTINGS = [
		'enabled' => true,
	];

	/**
	 * Parent integration instance.
	 *
	 * @since 1.3.0
	 *
	 * @var AbstractBuddySignupIntegration
	 */
	protected $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 *
	 * @param AbstractBuddySignupIntegration $integration Parent integration.
	 */
	public function __construct( AbstractBuddySignupIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Initialize.
	 *
	 * Reserved for future settings-panel wiring (AJAX save handlers etc.).
	 * The constructor is intentionally side-effect-free so concrete
	 * integrations can instantiate this eagerly without scheduling hooks.
	 *
	 * @since 1.3.0
	 */
	public function init(): void {
	}

	/**
	 * Read current settings from storage.
	 *
	 * @since 1.3.0
	 *
	 * @return array
	 */
	public function get_settings(): array {

		$option_name = $this->integration->get_option_key();
		$saved       = get_option( $option_name, [] );

		return wp_parse_args( $saved, static::DEFAULT_SETTINGS );
	}

	/**
	 * Persist settings.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
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
			'description' => $this->get_description(),
			'settings'    => $settings,
		];
	}

	/**
	 * Human-readable description shown on the Integrations admin page.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	abstract protected function get_description(): string;
}

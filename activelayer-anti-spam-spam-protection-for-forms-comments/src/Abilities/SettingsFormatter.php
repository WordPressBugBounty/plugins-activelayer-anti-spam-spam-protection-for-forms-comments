<?php
/**
 * Assembles safe settings + integration list for the Abilities API.
 *
 * @package ActiveLayer
 */

namespace ActiveLayer\Abilities;

use ActiveLayer\Helpers\SettingsHelper;

/**
 * Pure transformer: registry status -> get-settings output.
 *
 * Never exposes the API key or any secret.
 *
 * @since 1.6.0
 */
class SettingsFormatter {

	/**
	 * Build the settings payload.
	 *
	 * @since 1.6.0
	 *
	 * @param array $registry_status Output of IntegrationRegistry::get_status().
	 *
	 * @return array Settings payload (settings, integrations).
	 */
	public function build( array $registry_status ): array {

		$settings = [
			'sync_mode'            => SettingsHelper::is_sync_mode_enabled(),
			'logging_enabled'      => SettingsHelper::is_logging_enabled(),
			'environment_tracking' => SettingsHelper::is_environment_tracking_enabled(),
			'behavioral_tracking'  => SettingsHelper::is_behavioral_tracking_enabled(),
			'honeypot_tracking'    => SettingsHelper::is_honeypot_tracking_enabled(),
			'retention_days'       => SettingsHelper::get_retention_days(),
		];

		$integrations = [];

		$status_integrations = isset( $registry_status['integrations'] ) && is_array( $registry_status['integrations'] )
			? $registry_status['integrations']
			: [];

		foreach ( $status_integrations as $slug => $info ) {
			$integrations[] = [
				'slug'      => (string) $slug,
				'name'      => (string) ( $info['name'] ?? '' ),
				'enabled'   => (bool) ( $info['enabled'] ?? false ),
				'available' => (bool) ( $info['plugin_active'] ?? false ),
			];
		}

		return [
			'settings'     => $settings,
			'integrations' => $integrations,
		];
	}
}

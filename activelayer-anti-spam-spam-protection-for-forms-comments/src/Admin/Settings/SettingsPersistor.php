<?php

namespace ActiveLayer\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Subscription\SubscriptionStats;

/**
 * Handles persistence of settings to WordPress options.
 *
 * @since 1.0.0
 * @since 1.2.0 Removed WPCommentsSettingsPage wrapper.
 */
class SettingsPersistor {

	/**
	 * Integration registry.
	 *
	 * @since 1.0.0
	 *
	 * @var IntegrationRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Made $wp_comments_page optional (integration settings moved to IntegrationsPage).
	 * @since 1.2.0 Removed $wp_comments_page parameter.
	 *
	 * @param IntegrationRegistry $registry Integration registry instance.
	 */
	public function __construct( IntegrationRegistry $registry ) {

		$this->registry = $registry;
	}

	/**
	 * Save all settings from POST data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data POST data array.
	 *
	 * @return array Notice data with 'message' and 'type' keys.
	 */
	public function save_settings( array $post_data ): array {

		if ( empty( $post_data ) ) {
			return [
				'message' => '',
				'type'    => 'success',
			];
		}

		// Capture API-key state BEFORE saving global settings. When no API key
		// is configured, integration checkboxes are rendered as disabled in the
		// HTML form and browsers never submit disabled inputs. Saving integration
		// settings from such a submission would reset every integration to
		// disabled — so we skip the integration save entirely in that case.
		$had_api_key = SettingsHelper::has_api_key();

		$removing_api_key = isset( $post_data['activelayer_remove_api_key'] );

		// Save global settings.
		$notice_message = $this->save_global_settings( $post_data, $removing_api_key );

		// Save integration settings only when checkboxes were actually
		// submittable (API key was present when the form was rendered).
		if ( $had_api_key ) {
			$this->save_integration_settings( $post_data );
		}

		return [
			'message' => $notice_message,
			'type'    => 'success',
		];
	}

	/**
	 * Save global settings (API key, logging, sync mode, tracking options).
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data        POST data array.
	 * @param bool  $removing_api_key Whether removing API key.
	 *
	 * @return string Notice message.
	 */
	public function save_global_settings( array $post_data, bool $removing_api_key = false ): string {

		$settings = [
			SettingsHelper::KEY_API                  => $removing_api_key ? '' : sanitize_text_field( $post_data[ SettingsHelper::KEY_API ] ?? '' ),
			SettingsHelper::KEY_ENABLE_LOGGING       => isset( $post_data[ SettingsHelper::KEY_ENABLE_LOGGING ] ),
			SettingsHelper::KEY_SYNC_MODE            => isset( $post_data[ SettingsHelper::KEY_SYNC_MODE ] ),
			SettingsHelper::KEY_ENVIRONMENT_TRACKING => isset( $post_data[ SettingsHelper::KEY_ENVIRONMENT_TRACKING ] ),
			SettingsHelper::KEY_BEHAVIORAL_TRACKING  => isset( $post_data[ SettingsHelper::KEY_BEHAVIORAL_TRACKING ] ),
			SettingsHelper::KEY_RETENTION_DAYS       => $this->sanitize_retention_days( $post_data ),
		];

		$settings[ SettingsHelper::KEY_API ] = SettingsHelper::get_api_key( $settings );

		// Check if API key changed - clear validation if it did.
		$old_settings = SettingsHelper::get_global_settings();
		$old_api_key  = SettingsHelper::get_api_key( $old_settings );

		if ( $settings[ SettingsHelper::KEY_API ] !== $old_api_key ) {
			delete_option( 'activelayer_api_key_validated' );
			SubscriptionStats::get_instance()->clear_cache();
		}

		update_option( 'activelayer_global_settings', $settings );

		if ( $removing_api_key ) {
			return __( 'API key removed.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		return __( 'Settings saved.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}

	/**
	 * Save integration settings from POST data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_data POST data array.
	 */
	public function save_integration_settings( array $post_data ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( empty( $post_data ) ) {
			return;
		}

		// Only save integration settings when the integration form section was
		// actually rendered. The hidden marker field is output by
		// IntegrationListRenderer and guarantees that checkbox absence means
		// "unchecked", not "the form section was never on the page".
		if ( empty( $post_data['integrations_present'] ) ) {
			return;
		}

		$posted_integrations = $this->sanitize_integrations_data( $post_data['integrations'] ?? [] );

		$integrations_status = $this->registry->get_status();
		$all_integrations    = $integrations_status['integrations'] ?? [];

		foreach ( $all_integrations as $integration_data ) {
			$integration_slug     = $integration_data['slug'] ?? '';
			$integration_settings = $posted_integrations[ $integration_slug ] ?? [];

			if ( $integration_slug === '' ) {
				continue;
			}

			$this->persist_integration_settings( $integration_slug, $integration_settings );
		}
	}

	/**
	 * Persist settings for a single integration.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Removed wp_comments branch (handled by IntegrationsPage AJAX).
	 *
	 * @param string $integration_name     Integration identifier.
	 * @param array  $integration_settings Sanitized integration settings.
	 */
	private function persist_integration_settings( string $integration_name, array $integration_settings ): void {

		$sanitized   = [ 'enabled' => isset( $integration_settings['enabled'] ) ];
		$integration = $this->registry->get_integration( $integration_name );
		$option_name = $integration ? $integration->get_option_key() : "activelayer_{$integration_name}_settings";

		update_option( $option_name, $sanitized );
	}

	/**
	 * Sanitize and validate retention days from POST data.
	 *
	 * @since 1.1.0
	 *
	 * @param array $post_data POST data array.
	 *
	 * @return int Validated retention days, or 0 if invalid.
	 */
	private function sanitize_retention_days( array $post_data ): int {

		$value = (int) ( $post_data[ SettingsHelper::KEY_RETENTION_DAYS ] ?? 0 );

		if ( ! in_array( $value, SettingsHelper::ALLOWED_RETENTION_DAYS, true ) ) {
			return 0;
		}

		return $value;
	}

	/**
	 * Recursively sanitize integration settings data.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Data to sanitize.
	 *
	 * @return mixed Sanitized data.
	 */
	public function sanitize_integrations_data( $data ) {

		if ( is_array( $data ) ) {
			return array_map( [ $this, 'sanitize_integrations_data' ], $data );
		}

		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}

		return $data;
	}
}

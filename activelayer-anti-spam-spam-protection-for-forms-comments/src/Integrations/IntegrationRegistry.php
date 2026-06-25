<?php

namespace ActiveLayer\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Logger\Logger;

/**
 * Integration Registry.
 *
 * Manages all form provider integrations.
 * Singleton pattern for centralized access.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations
 */
class IntegrationRegistry {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @var IntegrationRegistry|null
	 */
	private static $instance = null;

	/**
	 * Registered integrations.
	 *
	 * @since 1.0.0
	 *
	 * @var BaseFormIntegration[]
	 */
	private $integrations = [];

	/**
	 * Active integrations.
	 *
	 * @since 1.0.0
	 *
	 * @var BaseFormIntegration[]
	 */
	private $active_integrations = [];

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return IntegrationRegistry Registry instance.
	 */
	public static function get_instance(): IntegrationRegistry {

		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register form integration.
	 *
	 * @since 1.0.0
	 *
	 * @param BaseFormIntegration $integration Integration instance.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function register( BaseFormIntegration $integration ): bool {

		$slug = $integration->get_slug();
		$name = $integration->get_name();

		if ( isset( $this->integrations[ $slug ] ) ) {
			// Integration already registered.
			return false;
		}

		$this->integrations[ $slug ] = $integration;

		// Auto-activate if plugin is active and integration is enabled.
		if ( $integration->is_active() && $integration->is_enabled() ) {
			$this->activate( $slug );
		}

		return true;
	}

	/**
	 * Activate integration by slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Integration slug.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function activate( string $slug ): bool {

		if ( ! isset( $this->integrations[ $slug ] ) ) {
			return false;
		}

		$integration = $this->integrations[ $slug ];

		if ( ! $integration->is_active() ) {
			// Cannot activate - plugin not active.
			return false;
		}

		if ( ! $integration->is_enabled() ) {
			// Cannot activate - integration disabled.
			return false;
		}

		// Initialize integration.
		try {
			$integration->init();
			$this->active_integrations[ $slug ] = $integration;

			return true;
		} catch ( \Exception $e ) {
			Logger::log(
				'Failed to activate integration',
				[
					'integration' => $slug,
					'error'       => $e->getMessage(),
				]
			);

			return false;
		}
	}

	/**
	 * Deactivate integration by slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Integration slug.
	 *
	 * @return bool True on success.
	 */
	public function deactivate( string $slug ): bool {

		if ( isset( $this->active_integrations[ $slug ] ) ) {
			unset( $this->active_integrations[ $slug ] );
		}

		return true;
	}

	/**
	 * Get integration by slug (regardless of active status).
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Integration slug.
	 *
	 * @return BaseFormIntegration|null Integration instance or null.
	 */
	public function get_integration( string $slug ): ?BaseFormIntegration {

		return $this->integrations[ $slug ] ?? null;
	}

	/**
	 * Get the FormAdminSettingsInterface for an integration by registry slug.
	 *
	 * @since 1.1.0
	 *
	 * @param string $slug Registry slug.
	 *
	 * @return FormAdminSettingsInterface|null Admin settings instance or null.
	 */
	public function get_form_admin_settings( string $slug ): ?FormAdminSettingsInterface {

		$integration = $this->get_integration( $slug );

		if ( ! $integration || ! method_exists( $integration, 'get_admin_settings' ) ) {
			return null;
		}

		$admin_settings = $integration->get_admin_settings();

		return $admin_settings instanceof FormAdminSettingsInterface ? $admin_settings : null;
	}

	/**
	 * Get human-readable display name for a provider slug.
	 *
	 * Looks up the registered integration by slug and returns its name.
	 * Falls back to a sub-provider alias map (for slugs that live inside
	 * an umbrella integration, e.g. WooCommerce children) and then to a
	 * title-cased slug for unknown providers.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added WC umbrella sub-provider display name map (wc_reviews, wc_registration).
	 * @since 1.5.0 Added EDD umbrella sub-provider display names (edd_registration, edd_reviews).
	 *
	 * @param string $slug Provider slug from storage.
	 *
	 * @return string Human-readable provider name, or capitalized slug as fallback.
	 */
	public static function get_provider_display_name( string $slug ): string {

		$instance    = self::get_instance();
		$integration = $instance->get_integration( $slug );

		if ( $integration !== null ) {
			return $integration->get_name();
		}

		// Sub-integrations that live inside an umbrella (e.g. WooCommerce children).
		$sub_provider_names = [
			'wc_reviews'       => 'WooCommerce Reviews',
			'wc_registration'  => 'WooCommerce Registration',
			'edd_registration' => 'EDD Registration',
			'edd_reviews'      => 'EDD Reviews',
		];

		if ( isset( $sub_provider_names[ $slug ] ) ) {
			return $sub_provider_names[ $slug ];
		}

		// Fallback for unknown/legacy providers: convert slug to title case.
		return ucwords( str_replace( '_', ' ', $slug ) );
	}

	/**
	 * Get registry status.
	 *
	 * @since 1.0.0
	 *
	 * @return array Status information.
	 */
	public function get_status(): array {

		$status = [
			'total_registered' => count( $this->integrations ),
			'total_active'     => count( $this->active_integrations ),
			'integrations'     => [],
		];

		foreach ( $this->integrations as $slug => $integration ) {
			$display_name = $integration->get_name();

			$status['integrations'][ $slug ] = [
				'name'          => $display_name,
				'slug'          => $slug,
				'registered'    => true,
				'active'        => isset( $this->active_integrations[ $slug ] ),
				'plugin_active' => $integration->is_active(),
				'enabled'       => $integration->is_setting_enabled(),
				'settings'      => $integration->get_settings(),
			];
		}

		return $status;
	}

	/**
	 * Get per-integration protected forms count.
	 *
	 * Returns the number of forms with ActiveLayer protection enabled
	 * for each installed integration. Comments integration is excluded
	 * as it has no per-form concept. Paused integrations (plugin active
	 * but not enabled) return a null count to distinguish from enabled
	 * integrations with zero protected forms.
	 *
	 * @since 1.1.0
	 *
	 * @return array {
	 *     @type array  $integrations Per-slug counts: [ 'slug' => [ 'name' => string, 'count' => int|null ] ].
	 *     @type int    $total        Total protected forms across all integrations.
	 * }
	 */
	public function get_protected_forms_summary(): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$summary = [
			'integrations' => [],
			'total'        => 0,
		];

		foreach ( $this->integrations as $slug => $integration ) {
			// Skip Comments — no per-form concept.
			if ( $slug === 'wp_comments' ) {
				continue;
			}

			if ( ! $integration->is_active() ) {
				continue;
			}

			$admin_settings = $this->get_form_admin_settings( $slug );

			if ( ! $admin_settings ) {
				continue;
			}

			// Null count for paused integrations; actual count when enabled.
			$count = $integration->is_enabled()
				? count( array_filter( array_column( $admin_settings->get_forms_list(), 'enabled' ) ) )
				: null;

			$summary['integrations'][ $slug ] = [
				'name'  => $integration->get_name(),
				'count' => $count,
			];

			if ( $count !== null ) {
				$summary['total'] += $count;
			}
		}

		return $summary;
	}

	/**
	 * Build the onboarding step-2 completion payload for AJAX responses.
	 *
	 * Returned shape is the standard payload merged into `wp_send_json_success`
	 * data by integration AJAX handlers so the onboarding banner JS can update
	 * step completion without a page reload.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Step 2 auto-completes when the API key is validated, to stay in sync with OnboardingManager after the opt-out defaults flip.
	 *
	 * @return array {
	 *     @type bool $step_2_completed True when API key is connected and validated.
	 * }
	 */
	public function build_onboarding_step_payload(): array {

		// Mirror OnboardingManager::get_steps(): with opt-out defaults, all
		// supported forms protect automatically once the API key is validated,
		// so step 2 completion is driven by step 1 completion.
		$has_api_key        = SettingsHelper::has_api_key();
		$api_key            = SettingsHelper::get_api_key();
		$api_key_validation = get_option( 'activelayer_api_key_validated', [] );

		$is_key_validated = $has_api_key
			&& is_array( $api_key_validation )
			&& ! empty( $api_key_validation['is_valid'] )
			&& ! empty( $api_key_validation['key'] )
			&& $api_key_validation['key'] === $api_key;

		return [
			'step_2_completed' => $has_api_key && $is_key_validated,
		];
	}

	/**
	 * Refresh active integrations.
	 * Check plugin status and re-activate if needed.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of integrations refreshed.
	 */
	public function refresh(): int {

		$refreshed = 0;

		foreach ( $this->integrations as $slug => $integration ) {
			// Reload settings from database to ensure fresh data.
			$integration->reload_settings();

			$is_currently_active = isset( $this->active_integrations[ $slug ] );
			$should_be_active    = $integration->is_active() && $integration->is_enabled();

			if ( $should_be_active && ! $is_currently_active ) {
				// Should be active but isn't - activate.
				if ( $this->activate( $slug ) ) {
					++$refreshed;
				}
			} elseif ( ! $should_be_active && $is_currently_active ) {
				// Shouldn't be active but is - deactivate.
				$this->deactivate( $slug );
				++$refreshed;
			}
		}

		return $refreshed;
	}
}

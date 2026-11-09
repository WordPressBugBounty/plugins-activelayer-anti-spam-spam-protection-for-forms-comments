<?php

namespace ActiveLayer\Protection;

use Throwable;
use ActiveLayer\Integrations\EasyDigitalDownloads\Registration\RegistrationIntegration as EddRegistration;
use ActiveLayer\Integrations\EasyDigitalDownloads\Reviews\ReviewsIntegration as EddReviews;
use ActiveLayer\Integrations\WooCommerce\Registration\RegistrationIntegration as WooRegistration;
use ActiveLayer\Integrations\WooCommerce\Reviews\ReviewsIntegration as WooReviews;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Subscription\SubscriptionStats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observe known interruptions and maintain local counters without transmitting them.
 *
 * @since 1.8.0
 */
class ProtectionMonitor {

	/**
	 * Request-local identities shared by the native and processing hooks.
	 *
	 * @since 1.8.0
	 *
	 * @var array
	 */
	private $observed = [];

	/**
	 * Request identity; never contains submitted values.
	 *
	 * @since 1.8.0
	 *
	 * @var string
	 */
	private $request_id;

	/**
	 * Create one request-local observer.
	 *
	 * @since 1.8.0
	 */
	public function __construct() {

		$this->request_id = wp_generate_uuid4();
	}

	/**
	 * Register lifecycle hooks and schedule maintenance on init independently of protection.
	 *
	 * @since 1.8.0
	 */
	public function hooks(): void {

		if ( (int) get_option( 'activelayer_protection_schema' ) !== 3 && ! get_transient( 'activelayer_protection_schema_retry' ) ) {
			EpisodeStore::install();
		}
		add_action( 'activelayer_protection_maintenance', [ $this, 'run_maintenance' ] );
		add_action( 'activelayer_key_connected', [ $this, 'connected' ] );
		add_action( 'activelayer_protection_observe', [ $this, 'observe' ], 10, 3 );
		add_action( 'activelayer_protection_check_result', [ $this, 'check_result' ], 10, 5 );
		add_action( 'activelayer_subscription_stats_updated', [ $this, 'stats_updated' ] );
		add_action( 'update_option_activelayer_global_settings', [ $this, 'settings_changed' ], 10, 2 );
		add_action( 'init', [ $this, 'schedule_maintenance' ] );
		( new NativeObserver( $this ) )->hooks();
		( new AdminStatus( $this ) )->hooks();
	}

	/**
	 * Schedule local maintenance after translations are safe to load.
	 *
	 * Cron schedule filters may translate their display labels. Evaluating them
	 * during plugins_loaded triggers WordPress' early-translation notice on first
	 * boot, so the hourly event is registered on init and remains idempotent.
	 *
	 * @since 1.8.0
	 */
	public function schedule_maintenance(): void {

		if ( ! wp_next_scheduled( 'activelayer_protection_maintenance' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'activelayer_protection_maintenance' );
		}
	}

	/**
	 * Resolve a local fingerprint without persisting an additional credential.
	 *
	 * @since 1.8.0
	 *
	 * @return string Credential fingerprint or initially unbound scope.
	 */
	public function scope(): string {

		$key = SettingsHelper::get_api_key();

		return $key !== '' ? hash( 'sha256', $key ) : (string) get_option( 'activelayer_protection_scope', '' );
	}

	/**
	 * Identify a configured credential that the server explicitly rejected.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Whether the current key has a confirmed authentication failure.
	 */
	public static function known_disconnected(): bool {

		$key = SettingsHelper::get_api_key();

		return $key !== '' && get_option( 'activelayer_protection_rejected_scope', '' ) === hash( 'sha256', $key );
	}

	/**
	 * Record an eligible submission only in a known interruption state.
	 *
	 * @since 1.8.0
	 *
	 * @param string $slug    Integration slug.
	 * @param mixed  $form_id Local form identifier.
	 * @param array  $form    Form configuration, never a submission body.
	 */
	public function observe( string $slug, $form_id = 0, array $form = [] ): void {

		try {
			if ( ! $this->eligible( $slug, $form_id, $form ) ) {
				return;
			}
		} catch ( Throwable $error ) {
			// A provider version mismatch must never interrupt native submission handling.
			return;
		}
		$reason = ! SettingsHelper::has_api_key() ? 'not_connected' : ( UpgradeHelper::is_quota_exhausted_cached() ? 'quota_exhausted' : '' );
		$token  = $slug . ':' . (string) $form_id;

		if ( $reason === '' || isset( $this->observed[ $token ] ) ) {
			return;
		}
		$this->observed[ $token ] = true;

		( new EpisodeStore() )->observe( $reason, $this->scope(), $this->request_id . ':' . $token );
	}

	/**
	 * Respect the existing integration and per-form configuration without a key.
	 *
	 * @since 1.8.0
	 *
	 * @param string $slug    Integration slug.
	 * @param mixed  $form_id Local form identifier.
	 * @param array  $form    Form configuration.
	 *
	 * @return bool Whether this submission belongs to an enabled surface.
	 */
	private function eligible( string $slug, $form_id, array $form ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Existing per-provider settings have distinct accessors.

		$integration = IntegrationRegistry::get_instance()->get_integration( $slug );
		$children    = [

			'wc_reviews'       => WooReviews::class,
			'wc_registration'  => WooRegistration::class,
			'edd_reviews'      => EddReviews::class,
			'edd_registration' => EddRegistration::class,
		];

		if ( ! $integration && isset( $children[ $slug ] ) ) {
			$integration = new $children[ $slug ]();
		}
		if ( ! $integration ) {
			return false;
		}
		$integration = clone $integration;

		$integration->reload_settings();
		if ( ! $integration->is_active() || ! $integration->is_setting_enabled() ) {
			return false;
		}
		if ( ! method_exists( $integration, 'get_admin_settings' ) ) {
			return true;
		}
		$admin = $integration->get_admin_settings();

		if ( $slug === 'wp_comments' ) {
			$settings = $admin->get_comment_settings();

			if ( empty( $settings['check_logged_in_users'] ) && is_user_logged_in() ) {
				return false;
			}
		}
		if ( ! method_exists( $admin, 'get_form_settings' ) ) {
			return true;
		}
		if ( $slug === 'wpforms' ) {
			$settings = $form ? $admin->get_form_settings( $form ) : $admin->get_form_settings_by_id( (int) $form_id );
		} else {
			if ( ! $form_id ) {
				return false;
			}
			$settings = $admin->get_form_settings( (int) $form_id );
		}

		return ! empty( $settings['enabled'] );
	}

	/**
	 * Bind initial observations and close the interrupted connection on verification.
	 *
	 * @since 1.8.0
	 */
	public function connected(): void {

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}
		$store = new EpisodeStore();
		$scope = $this->scope();
		$old   = (string) get_option( 'activelayer_protection_scope', '' );

		if ( $old !== $scope ) {
			$store->close( $old );
		}
		// Only the empty, never-owned scope can bind; rejected keys cannot consume it.
		$store->bind( $scope );
		$store->close( $scope, 'not_connected' );
		update_option( 'activelayer_protection_scope', $scope, false );
		delete_option( 'activelayer_protection_rejected_scope' );
	}

	/**
	 * Clear credential-specific state before a different key can reuse cached quota.
	 *
	 * @since 1.8.0
	 *
	 * @param array $old     Previous settings.
	 * @param array $updated Updated settings.
	 */
	public function settings_changed( $old, $updated ): void {

		if ( ( $old['api_key'] ?? '' ) === ( $updated['api_key'] ?? '' ) ) {
			return;
		}
		$scope = ! empty( $old['api_key'] ) ? hash( 'sha256', trim( $old['api_key'] ) ) : (string) get_option( 'activelayer_protection_scope', '' );

		( new EpisodeStore() )->close( $scope );
		if ( $scope !== '' ) {
			update_option( 'activelayer_protection_scope', $scope, false );
		}
		delete_transient( SubscriptionStats::CACHE_KEY );
		delete_transient( 'activelayer_exhausted_scope' );
		delete_option( 'activelayer_protection_rejected_scope' );
	}

	/**
	 * Close on a successful check; count explicit quota or authentication failures.
	 *
	 * @since 1.8.0
	 *
	 * @param array  $result  Parsed check result.
	 * @param string $event   Stable local submission identifier.
	 * @param string $scope   Credential fingerprint at submission processing time.
	 * @param string $slug    Native provider slug for request-local deduplication.
	 * @param mixed  $form_id Local form identity for native hook deduplication.
	 */
	public function check_result( array $result, string $event, string $scope, string $slug = '', $form_id = 0 ): void {

		if ( ! empty( $result['success'] ) ) {
			$this->recover_successful_check( $scope );
		} elseif ( in_array( $result['error_code'] ?? '', [ 'quota_exhausted', 'not_connected' ], true ) ) {
			$token = $slug . ':' . (string) $form_id;

			( new EpisodeStore() )->observe( $result['error_code'], $scope, 'api:' . $event );
			if ( $slug !== '' ) {
				$this->observed[ $token ] = true;
			}
			if ( $result['error_code'] === 'not_connected' ) {
				update_option( 'activelayer_protection_rejected_scope', $scope, false );
			} else {
				set_transient( 'activelayer_exhausted_scope', $scope, HOUR_IN_SECONDS );
			}
		}
	}

	/**
	 * Close recovered episodes and clear only matching credential state while preserving healthy stats.
	 *
	 * @since 1.8.0
	 *
	 * @param string $scope Credential fingerprint used by the successful check.
	 */
	private function recover_successful_check( string $scope ): void {

		( new EpisodeStore() )->close( $scope );

		$this->invalidate_recovered_stats( $scope );
		if ( get_transient( 'activelayer_exhausted_scope' ) === $scope ) {
			delete_transient( 'activelayer_exhausted_scope' );
		}
		if ( get_option( 'activelayer_protection_rejected_scope', '' ) === $scope ) {
			delete_option( 'activelayer_protection_rejected_scope' );
		}
	}

	/**
	 * Invalidate exhausted statistics only when they can belong to the recovered credential.
	 *
	 * @since 1.8.0
	 *
	 * @param string $scope Credential fingerprint used by the successful check.
	 */
	private function invalidate_recovered_stats( string $scope ): void {

		$stats = get_transient( SubscriptionStats::CACHE_KEY );
		// Legacy caches predate explicit ownership and use the configured credential.
		$owner = $stats['credential_scope'] ?? $this->scope();

		if ( $owner !== $scope ) {
			return;
		}
		if ( get_transient( 'activelayer_exhausted_scope' ) === $scope || ( ! empty( $stats['success'] ) && (float) ( $stats['usage_percentage'] ?? 0 ) >= 100 ) ) {
			delete_transient( SubscriptionStats::CACHE_KEY );
		}
	}

	/**
	 * Recover only the responding credential after authenticated statistics refresh.
	 *
	 * Authentication alone closes connection failures; quota recovery requires positive capacity.
	 *
	 * @since 1.8.0
	 *
	 * @param array $stats Fresh verified subscription statistics.
	 */
	public function stats_updated( array $stats ): void {

		if ( empty( $stats['success'] ) ) {
			return;
		}
		$scope = $stats['credential_scope'] ?? $this->scope();

		if ( get_option( 'activelayer_protection_rejected_scope', '' ) === $scope ) {
			delete_option( 'activelayer_protection_rejected_scope' );
		}
		( new EpisodeStore() )->close( $scope, 'not_connected' );

		if ( isset( $stats['requests_remaining'] ) && (int) $stats['requests_remaining'] > 0 ) {
			if ( get_transient( 'activelayer_exhausted_scope' ) === $scope ) {
				delete_transient( 'activelayer_exhausted_scope' );
			}
			( new EpisodeStore() )->close( $scope );
		}
	}

	/**
	 * Prune local deduplication and refresh only known authentication or quota gaps.
	 *
	 * Uses the existing authenticated statistics endpoint solely for recovery;
	 * observed counters and episode identities never leave this WordPress site.
	 *
	 * @since 1.8.0
	 */
	public function run_maintenance(): void {

		$store = new EpisodeStore();

		$store->prune_events();

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}
		$summary = $store->summary( $this->scope() );

		if ( ( $summary['active']['reason'] ?? '' ) === 'quota_exhausted' || self::known_disconnected() || UpgradeHelper::is_quota_exhausted_cached() ) {
			SubscriptionStats::get_instance()->process_refresh();
		}
	}
}

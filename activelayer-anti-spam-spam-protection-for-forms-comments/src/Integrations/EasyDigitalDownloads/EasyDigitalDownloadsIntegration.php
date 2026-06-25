<?php

namespace ActiveLayer\Integrations\EasyDigitalDownloads;

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\EasyDigitalDownloads\Registration\RegistrationIntegration;
use ActiveLayer\Integrations\EasyDigitalDownloads\Reviews\ReviewsIntegration;
use LogicException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easy Digital Downloads umbrella integration.
 *
 * Single IntegrationRegistry entry (slug 'edd') that composes the Reviews and
 * Registration sub-integrations. Each sub-integration remains a full
 * BaseFormIntegration with its own slug used for storage (`edd_reviews`,
 * `edd_registration`); only this umbrella is registered.
 *
 * The umbrella has no own settings option — its enabled state is derived
 * from the OR of its sub-integrations' enabled flags. Both the row toggle
 * (on the Integrations page) and the panel save handler write only to the
 * sub options (`activelayer_edd_reviews_settings`,
 * `activelayer_edd_registration_settings`), so there is exactly one source
 * of truth and the row badge cannot drift from the sub-flag reality.
 *
 * The umbrella's own `process_submission*` methods are unreachable in
 * practice — sub-integrations handle submissions directly via their own
 * hooks. The abstract `normalize_form_data` and `get_form_meta` are
 * implemented as empty returns to satisfy the contract.
 *
 * Slug `edd` is registry-only. The option `activelayer_edd_settings` is NEVER
 * read or written — settings live on each sub-integration. The parent's
 * `load_settings()` will populate `$this->settings` from defaults on
 * construction; that state is ignored because `get_settings()` is overridden
 * to return an aggregate.
 *
 * @since 1.5.0
 *
 * @package ActiveLayer\Integrations\EasyDigitalDownloads
 */
final class EasyDigitalDownloadsIntegration extends BaseFormIntegration {

	/**
	 * Reviews sub-integration.
	 *
	 * @since 1.5.0
	 *
	 * @var ReviewsIntegration
	 */
	private $reviews;

	/**
	 * Registration sub-integration.
	 *
	 * @since 1.5.0
	 *
	 * @var RegistrationIntegration
	 */
	private $registration;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {

		parent::__construct( 'Easy Digital Downloads', 'edd' );

		$this->reviews      = new ReviewsIntegration();
		$this->registration = new RegistrationIntegration();
	}

	/**
	 * Initialize the umbrella and any enabled sub-integration.
	 *
	 * The umbrella itself has no own `enabled` option; each sub-integration's
	 * own `is_enabled()` gate inside its `init()` decides whether it wires
	 * up its provider hooks.
	 *
	 * @since 1.5.0
	 */
	public function init(): void {

		if ( ! $this->is_active() ) {
			return;
		}

		$this->reviews->init();
		$this->registration->init();
	}

	/**
	 * Easy Digital Downloads plugin presence (both core and Pro define EDD()).
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return function_exists( 'EDD' );
	}

	/**
	 * Runtime gate for the umbrella.
	 *
	 * Returns true when the API key is configured AND at least one
	 * sub-integration is enabled. Per-sub gating happens inside each sub's
	 * own `is_enabled()` / `init()`, so the umbrella only decides whether to
	 * delegate to sub-init at all.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {

		return SettingsHelper::has_api_key() && $this->is_setting_enabled();
	}

	/**
	 * Master state derived from sub-integrations — the umbrella has no own option.
	 *
	 * True when at least one sub is enabled. Drives the row badge / toggle UI on
	 * the Integrations page so the displayed state always matches the real
	 * sub-flag reality (no separate master option that can drift apart from
	 * the subs).
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_setting_enabled(): bool {

		return $this->reviews->is_setting_enabled() || $this->registration->is_setting_enabled();
	}

	/**
	 * Aggregate settings exposed to the admin Integrations page.
	 *
	 * @since 1.5.0
	 *
	 * @return array
	 */
	public function get_settings(): array {

		return [
			'enabled'      => $this->is_setting_enabled(),
			'reviews'      => $this->reviews->get_settings(),
			'registration' => $this->registration->get_settings(),
		];
	}

	/**
	 * Reload sub-integration settings from the database.
	 *
	 * Called by IntegrationRegistry::refresh().
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function reload_settings(): void {

		// Umbrella owns no DB option — only sub-integrations have stored settings.
		$this->reviews->reload_settings();
		$this->registration->reload_settings();
	}

	/**
	 * Return the reviews sub-integration.
	 *
	 * @since 1.5.0
	 *
	 * @return ReviewsIntegration
	 */
	public function get_reviews(): ReviewsIntegration {

		return $this->reviews;
	}

	/**
	 * Return the registration sub-integration.
	 *
	 * @since 1.5.0
	 *
	 * @return RegistrationIntegration
	 */
	public function get_registration(): RegistrationIntegration {

		return $this->registration;
	}

	/**
	 * Persist both sub-integration option payloads in one call.
	 *
	 * Encapsulates the umbrella's composition so external callers (admin AJAX
	 * handler) don't reach through the `get_reviews()->get_admin_settings()`
	 * accessor chain. Each sub's AdminSettings update method applies the
	 * canonical `! empty()` checkbox semantics.
	 *
	 * @since 1.5.0
	 *
	 * @param array $reviews_settings      Posted reviews settings payload.
	 * @param array $registration_settings Posted registration settings payload.
	 *
	 * @return void
	 */
	public function save_settings( array $reviews_settings, array $registration_settings ): void {

		$this->reviews->get_admin_settings()->update_review_settings( $reviews_settings );
		$this->registration->get_admin_settings()->update_registration_settings( $registration_settings );
	}

	/**
	 * Cascade the master row toggle to both sub-integration `enabled` flags.
	 *
	 * Umbrella has no own settings option; this method writes the new state
	 * into each sub via its `AdminSettings::update_*_settings()`, preserving
	 * other keys and applying the canonical `! empty()` checkbox semantics.
	 *
	 * @since 1.5.0
	 *
	 * @param bool $enabled New enabled state from the row toggle.
	 *
	 * @return void
	 */
	public function cascade_enabled( bool $enabled ): void {

		$reviews_settings            = $this->reviews->get_settings();
		$reviews_settings['enabled'] = $enabled;

		$registration_settings            = $this->registration->get_settings();
		$registration_settings['enabled'] = $enabled;

		$this->reviews->get_admin_settings()->update_review_settings( $reviews_settings );
		$this->registration->get_admin_settings()->update_registration_settings( $registration_settings );
	}

	/**
	 * Umbrella never dispatches submissions itself — sub-integrations do.
	 * Implementations exist only to satisfy the BaseFormIntegration contract.
	 *
	 * @since 1.5.0
	 *
	 * @param array $raw_data Unused.
	 *
	 * @return array
	 */
	protected function normalize_form_data( array $raw_data ): array {

		return $raw_data;
	}

	/**
	 * Stub form meta — the umbrella does not own a form, sub-integrations do.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $form_instance Unused.
	 *
	 * @return array
	 */
	protected function get_form_meta( $form_instance ): array {

		return [];
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Both methods always throw; typed return required by BaseFormIntegration contract.

	/**
	 * Umbrella does not dispatch submissions — sub-integrations do that directly.
	 *
	 * @since 1.5.0
	 *
	 * @throws LogicException Always — sub-integrations dispatch directly.
	 *
	 * @param array $form_data Unused.
	 * @param array $meta      Unused.
	 *
	 * @return string
	 */
	public function process_submission( array $form_data, array &$meta ): string {

		self::throw_umbrella_dispatch_error( __METHOD__ );
	}

	/**
	 * Umbrella does not dispatch sync submissions — sub-integrations do that directly.
	 *
	 * @since 1.5.0
	 *
	 * @throws LogicException Always — sub-integrations dispatch directly.
	 *
	 * @param array $raw_data Unused.
	 * @param array $meta     Unused.
	 *
	 * @return array
	 */
	public function process_submission_synchronously( array $raw_data, array $meta ): array {

		self::throw_umbrella_dispatch_error( __METHOD__ );
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Fail fast when umbrella dispatch is invoked directly.
	 *
	 * @since 1.5.0
	 *
	 * @param string $method Calling method name.
	 *
	 * @throws LogicException Always.
	 *
	 * @return void
	 */
	private static function throw_umbrella_dispatch_error( string $method ): void {

		throw new LogicException(
			esc_html( $method . ' must not be called; sub-integrations dispatch directly.' )
		);
	}
}

<?php

namespace ActiveLayer\Integrations\BuddySignup;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\Traits\RegistrationProtectionTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared signup spam-protection integration for BuddyPress / BuddyBoss.
 *
 * Both BuddyPress (free) and BuddyBoss Platform expose the same `bp_signup_*`
 * hook surface and the same `buddypress()` global. The two concrete subclasses
 * differ only in `is_active()` — every other behaviour (signal field rendering,
 * payload normalisation, data type, default settings) lives here.
 *
 * Concrete subclasses must instantiate their own `SubmissionHandler` and admin
 * settings object in their constructor, and override `is_active()` with a
 * mutually exclusive platform check so the `bp_signup_validate` hook is bound
 * by at most one integration on a given site.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Integrations\BuddySignup
 */
abstract class AbstractBuddySignupIntegration extends BaseFormIntegration {

	use RegistrationProtectionTrait;

	/**
	 * Submission handler.
	 *
	 * @since 1.3.0
	 *
	 * @var SubmissionHandler
	 */
	protected $submission_handler;

	/**
	 * Initialize.
	 *
	 * @since 1.3.0
	 */
	public function init(): void {

		if ( ! $this->is_active() ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->submission_handler->init();
	}

	/**
	 * Runtime-enabled check.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {

		return SettingsHelper::has_api_key() && ! empty( $this->settings['enabled'] );
	}

	/**
	 * Setting-only check (no API-key gate).
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function is_setting_enabled(): bool {

		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Render hidden ActiveLayer signal fields inside the BP/BB signup form.
	 *
	 * @since 1.3.0
	 */
	public function output_signal_fields(): void {

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		FieldRenderer::output_all();
	}

	/**
	 * Submissions from signup integrations represent account creations.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function get_data_type(): string {

		return 'user_registration';
	}

	/**
	 * Default settings — enabled by default (opt-out); admin can disable per flavour.
	 *
	 * Sourced from AbstractBuddyAdminSettings::DEFAULT_SETTINGS so the runtime
	 * gate and the admin-display path share a single source of truth (matches
	 * the Comments / WooCommerce integrations).
	 *
	 * @since 1.3.0
	 *
	 * @return array
	 */
	protected function get_default_settings(): array {

		return AbstractBuddyAdminSettings::DEFAULT_SETTINGS;
	}

	/**
	 * Pass-through — the trait constructs the normalized payload before
	 * BaseFormIntegration::prepare_submission_data() invokes this method.
	 *
	 * @since 1.3.0
	 *
	 * @param array $raw_data Raw data already built by the trait.
	 *
	 * @return array
	 */
	protected function normalize_form_data( array $raw_data ): array {

		return $raw_data;
	}

	/**
	 * Build registration meta.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $form_instance Unused.
	 *
	 * @return array
	 */
	protected function get_form_meta( $form_instance ): array {

		return [
			'form_id' => 'bp_signup',
		];
	}
}

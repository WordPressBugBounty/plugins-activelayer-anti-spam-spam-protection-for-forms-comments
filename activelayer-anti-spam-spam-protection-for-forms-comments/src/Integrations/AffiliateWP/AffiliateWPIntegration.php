<?php

namespace ActiveLayer\Integrations\AffiliateWP;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\Traits\RegistrationProtectionTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AffiliateWP affiliate-registration spam protection.
 *
 * Single-flavour synchronous registration gate. Stores submissions with
 * provider slug `affiliatewp`. Mirrors WooCommerce\Registration\RegistrationIntegration:
 * reuses RegistrationProtectionTrait, blocks spam before the affiliate row is
 * created via affiliate_wp()->register->add_error().
 *
 * @since 1.4.0
 *
 * @package ActiveLayer\Integrations\AffiliateWP
 */
class AffiliateWPIntegration extends BaseFormIntegration {

	use RegistrationProtectionTrait;

	/**
	 * Submission handler.
	 *
	 * @since 1.4.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings.
	 *
	 * @since 1.4.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {

		parent::__construct( 'AffiliateWP', 'affiliatewp' );

		$this->admin_settings     = new AdminSettings( $this );
		$this->submission_handler = new SubmissionHandler( $this );
	}

	/**
	 * Initialize.
	 *
	 * @since 1.4.0
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
	 * AffiliateWP plugin presence.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return function_exists( 'affiliate_wp' );
	}

	/**
	 * Runtime-enabled check.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {

		return SettingsHelper::has_api_key() && ! empty( $this->settings['enabled'] );
	}

	/**
	 * Setting-only check (no API-key gate).
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public function is_setting_enabled(): bool {

		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Render hidden ActiveLayer signal fields inside the AffiliateWP register form.
	 *
	 * @since 1.4.0
	 */
	public function output_signal_fields(): void {

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		FieldRenderer::output_all();
	}

	/**
	 * Submissions from this integration represent account creations.
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	public function get_data_type(): string {

		return 'user_registration';
	}

	/**
	 * Defaults.
	 *
	 * @since 1.4.0
	 *
	 * @return array
	 */
	protected function get_default_settings(): array {

		return AdminSettings::DEFAULT_SETTINGS;
	}

	/**
	 * Pass-through — the trait builds the normalized payload before
	 * BaseFormIntegration::prepare_submission_data() invokes this method.
	 *
	 * @since 1.4.0
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
	 * @since 1.4.0
	 *
	 * @param mixed $form_instance Unused.
	 *
	 * @return array
	 */
	protected function get_form_meta( $form_instance ): array {

		return [
			'form_id' => 'affwp_register',
		];
	}

	/**
	 * Admin settings accessor.
	 *
	 * @since 1.4.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}
}

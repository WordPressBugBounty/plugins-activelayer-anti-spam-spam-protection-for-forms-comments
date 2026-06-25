<?php

namespace ActiveLayer\Integrations\EasyDigitalDownloads\Registration;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\Traits\RegistrationProtectionTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easy Digital Downloads customer registration spam protection.
 *
 * Sub-integration owned by EasyDigitalDownloadsIntegration. Stores submissions
 * with provider slug 'edd_registration'. Reuses RegistrationProtectionTrait:
 * blocks spam on the standalone EDD registration form before the WP user is
 * created via edd_set_error(). Mirrors WooCommerce\Registration\RegistrationIntegration.
 *
 * @since 1.5.0
 *
 * @package ActiveLayer\Integrations\EasyDigitalDownloads\Registration
 */
class RegistrationIntegration extends BaseFormIntegration {

	use RegistrationProtectionTrait;

	/**
	 * Submission handler.
	 *
	 * @since 1.5.0
	 *
	 * @var RegistrationSubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings.
	 *
	 * @since 1.5.0
	 *
	 * @var RegistrationAdminSettings
	 */
	private $admin_settings;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {

		parent::__construct( 'EDD Registration', 'edd_registration' );

		$this->admin_settings     = new RegistrationAdminSettings( $this );
		$this->submission_handler = new RegistrationSubmissionHandler( $this );
	}

	/**
	 * Initialize.
	 *
	 * @since 1.5.0
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
	 * Easy Digital Downloads plugin presence.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return function_exists( 'EDD' );
	}

	/**
	 * Runtime-enabled check.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {

		return SettingsHelper::has_api_key() && ! empty( $this->settings['enabled'] );
	}

	/**
	 * Setting-only check (no API-key gate).
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_setting_enabled(): bool {

		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Render hidden ActiveLayer signal fields inside the EDD register form.
	 *
	 * @since 1.5.0
	 */
	public function output_signal_fields(): void {

		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		FieldRenderer::output_all();
	}

	/**
	 * Defaults.
	 *
	 * @since 1.5.0
	 *
	 * @return array
	 */
	protected function get_default_settings(): array {

		return RegistrationAdminSettings::DEFAULT_SETTINGS;
	}

	/**
	 * Submissions from this integration represent account creations.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public function get_data_type(): string {

		return 'user_registration';
	}

	/**
	 * Pass-through — the trait constructs the normalized payload before
	 * BaseFormIntegration::prepare_submission_data() invokes this method.
	 *
	 * @since 1.5.0
	 *
	 * @param array $raw_data Raw data already built by the trait.
	 *
	 * @return array
	 */
	protected function normalize_form_data( array $raw_data ): array {

		return $raw_data;
	}

	/**
	 * Build registration meta from the EDD hook context.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $form_instance Unused.
	 *
	 * @return array
	 */
	protected function get_form_meta( $form_instance ): array {

		return [
			'form_id' => 'edd_register',
		];
	}

	/**
	 * Admin settings accessor.
	 *
	 * @since 1.5.0
	 *
	 * @return RegistrationAdminSettings
	 */
	public function get_admin_settings(): RegistrationAdminSettings {

		return $this->admin_settings;
	}
}

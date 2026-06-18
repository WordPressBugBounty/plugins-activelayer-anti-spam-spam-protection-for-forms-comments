<?php

namespace ActiveLayer\Integrations\MemberPress;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Integrations\Traits\RegistrationProtectionTrait;
use MeprProduct;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MemberPress membership-signup spam protection.
 *
 * Single-flavour synchronous registration gate. Stores submissions with
 * provider slug `memberpress`. Mirrors AffiliateWP: reuses
 * RegistrationProtectionTrait, blocks spam before the WP user is created by
 * appending to the `mepr_validate_signup` errors array.
 *
 * @since 1.4.0
 *
 * @package ActiveLayer\Integrations\MemberPress
 */
class MemberPressIntegration extends BaseFormIntegration {

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

		parent::__construct( 'MemberPress', 'memberpress' );

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
	 * MemberPress plugin presence (both Lite and Pro define MEPR_VERSION).
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return defined( 'MEPR_VERSION' );
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
	 * Whether the admin opted in to also gate real-money signups.
	 *
	 * Off by default: MemberPress creates the WordPress user before the
	 * payment is taken (MeprCheckoutCtrl::process_signup_form), so blocking a
	 * real-money signup on a spam verdict aborts the purchase and risks a lost
	 * sale on a false positive. Free, free-trial, and fully-discounted signups
	 * are always gated regardless of this flag (no payment is at stake there).
	 *
	 * @since 1.4.1
	 *
	 * @return bool
	 */
	public function should_block_paid_signups(): bool {

		return ! empty( $this->settings['block_paid_signups'] );
	}

	/**
	 * Whether the membership charges real money at signup right now.
	 *
	 * Returns false — keeping the spam gate active — for signups that create
	 * an account without taking payment: free memberships, fully-discounted
	 * (100%-off coupon) signups, and paid memberships with a free trial
	 * (`$0` charged at signup). Only signups that take real money immediately
	 * return true, since those are the ones a false-positive block would cost
	 * a real sale.
	 *
	 * @since 1.4.1
	 *
	 * @param int|string $membership_id Membership (product) post ID, or the
	 *                                  `mepr_signup` sentinel when absent.
	 * @param string     $coupon_code   Coupon code posted with the signup.
	 *
	 * @return bool
	 */
	public function signup_takes_payment_now( $membership_id, string $coupon_code = '' ): bool {

		if ( ! class_exists( 'MeprProduct' ) || ! is_int( $membership_id ) || $membership_id <= 0 ) {
			return false;
		}

		$product = new MeprProduct( $membership_id );

		if ( empty( $product->ID ) ) {
			return false;
		}

		$coupon = $coupon_code !== '' ? $coupon_code : null;

		// Free or fully discounted — no payment at stake; keep gating.
		if ( ! $product->is_payment_required( $coupon ) ) {
			return false;
		}

		// Paid membership with a free trial charges $0 at signup. Treat it as a
		// free account: it is a prime fake-account vector and blocking it costs
		// no sale, so keep the gate active.
		if ( ! empty( $product->trial ) && (float) $product->trial_amount <= 0.0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Render hidden ActiveLayer signal fields inside the MemberPress checkout form.
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
			'form_id' => 'mepr_signup',
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

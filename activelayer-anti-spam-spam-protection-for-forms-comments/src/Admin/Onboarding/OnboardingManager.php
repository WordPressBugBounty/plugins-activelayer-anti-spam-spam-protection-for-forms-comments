<?php

namespace ActiveLayer\Admin\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\AppUrlHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Storage\Storage;

/**
 * Manages onboarding state and step completion.
 *
 * Centralizes all onboarding logic: step detection, visibility rules,
 * and dismiss/completion persistence.
 *
 * @since 1.1.0
 */
class OnboardingManager {

	/**
	 * Option key for dismissed state.
	 *
	 * @since 1.1.0
	 */
	const OPTION_DISMISSED = 'activelayer_onboarding_dismissed';

	/**
	 * Option key for completed state.
	 *
	 * @since 1.1.0
	 */
	const OPTION_COMPLETED = 'activelayer_onboarding_completed';

	/**
	 * Whether the banner should be shown.
	 *
	 * Checks capability, dismissed state, and completed state.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if the onboarding banner should be displayed.
	 */
	public function should_show_banner(): bool {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return false;
		}

		if ( get_option( self::OPTION_DISMISSED, false ) ) {
			return false;
		}

		if ( get_option( self::OPTION_COMPLETED, false ) ) {
			return false;
		}

		// Auto-complete if user already has submissions.
		$steps = $this->get_steps();

		if ( ! empty( $steps['step_3']['completed'] ) ) {
			$this->mark_completed();

			return false;
		}

		return true;
	}

	/**
	 * Get onboarding step states.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Build register URL via AppUrlHelper.
	 *
	 * @return array Step data keyed by step identifier.
	 */
	public function get_steps(): array {

		$has_api_key = SettingsHelper::has_api_key();

		// Check API key validation status.
		$api_key_validation = get_option( 'activelayer_api_key_validated', [] );
		$api_key            = SettingsHelper::get_api_key();
		$is_key_validated   = $has_api_key
			&& ! empty( $api_key_validation['is_valid'] )
			&& ! empty( $api_key_validation['key'] )
			&& $api_key_validation['key'] === $api_key;

		$step_1_completed = $has_api_key && $is_key_validated;

		// Step 2: check protected forms count.
		$forms_summary    = IntegrationRegistry::get_instance()->get_protected_forms_summary();
		$step_2_completed = $forms_summary['total'] > 0;

		// Step 3: check if any submission exists.
		$stats            = Storage::get_instance()->get_queue_stats();
		$step_3_completed = ( $stats['total'] ?? 0 ) > 0;

		return [
			'step_1' => [
				'number'      => 1,
				'title'       => __( 'Create and connect your free account', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description' => sprintf(
					/* translators: %s: settings page link. */
					__( 'Sign up for a free account, copy your API key, and paste it on the %s page to connect.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=activelayer-settings' ) ) . '">' . esc_html__( 'Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</a>'
				),
				'completed'   => $step_1_completed,
				'cta_url'     => AppUrlHelper::get_register_url( 'onboarding_checklist', 'create_account' ),
				'cta_label'   => __( 'Create Account', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			],
			'step_2' => [
				'number'      => 2,
				'title'       => __( 'Enable spam protection for your forms', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description' => sprintf(
					/* translators: %s: integrations page link. */
					__( 'Enable form integrations in %s, then configure which forms to protect.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=activelayer-integrations' ) ) . '">' . esc_html__( 'Integrations', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</a>'
				),
				'completed'   => $step_2_completed,
				'cta_url'     => '',
				'cta_label'   => '',
			],
			'step_3' => [
				'number'      => 3,
				'title'       => __( 'Receive your first submission', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description' => __( 'Once protection is enabled, submissions will appear automatically as your forms receive entries.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'completed'   => $step_3_completed,
				'cta_url'     => '',
				'cta_label'   => '',
			],
		];
	}

	/**
	 * Mark onboarding as dismissed.
	 *
	 * @since 1.1.0
	 */
	public function dismiss(): void {

		update_option( self::OPTION_DISMISSED, true, false );
	}

	/**
	 * Mark onboarding as completed.
	 *
	 * @since 1.1.0
	 */
	public function mark_completed(): void {

		update_option( self::OPTION_COMPLETED, true, false );
	}
}

<?php

namespace ActiveLayer\Admin\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Connect\ConnectFlow;
use ActiveLayer\Helpers\SettingsHelper;
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
	 * @since 1.3.0 Step 2 auto-completes when API key connected; reframed as informational review; step CTA now builds a one-click Connect URL.
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

		// Step 2: auto-complete once step 1 (API key) is connected.
		// With defaults-flip to opt-out, all forms protect automatically.
		$step_2_completed = $step_1_completed;

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
				// Only mint a Connect URL (writes a pairing transient) when not yet connected;
				// a connected user cannot use it, and get_steps() runs from the read-only should_show_banner().
				'cta_url'     => $step_1_completed ? '' : ( new ConnectFlow() )->start( 'onboarding_checklist', 'create_account' ),
				'cta_label'   => __( 'Create Account', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			],
			'step_2' => [
				'number'      => 2,
				'title'       => __( 'Review your form protection settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description' => sprintf(
					/* translators: %s: integrations page link. */
					__( 'All supported forms are protected by default. Visit %s to disable protection on specific forms if needed.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=activelayer-integrations' ) ) . '">' . esc_html__( 'Integrations', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</a>'
				),
				'completed'   => $step_2_completed,
				'cta_url'     => '',
				'cta_label'   => '',
			],
			'step_3' => [
				'number'      => 3,
				'title'       => __( 'Receive your first submission', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description' => __( 'Submissions will appear automatically as your forms receive entries.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
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

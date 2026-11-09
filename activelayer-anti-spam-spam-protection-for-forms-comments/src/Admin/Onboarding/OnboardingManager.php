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
	 * Option key for successfully checked demo sample keys.
	 *
	 * @since 1.8.0
	 */
	const OPTION_DEMO_SAMPLES = 'activelayer_onboarding_demo_samples';

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

		if ( ! empty( $steps['step_4']['completed'] ) ) {
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
	 * @since 1.8.0 Separate the demo and first real submission into steps 3 and 4.
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

		// Only a real stored submission completes onboarding.
		$stats            = Storage::get_instance()->get_queue_stats();
		$step_4_completed = ( $stats['total'] ?? 0 ) > 0;
		$samples          = (array) get_option( self::OPTION_DEMO_SAMPLES, [] );
		$step_3_completed = ! array_diff( OnboardingDemo::SAMPLES, $samples );

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
			'step_3' => $this->get_step_3( $step_1_completed, $step_3_completed ),
			'step_4' => [
				'number'      => 4,
				'title'       => __( 'Receive your first submission', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'description' => __( 'Submissions will appear automatically as your forms receive entries.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'completed'   => $step_4_completed,
				'cta_url'     => '',
				'cta_label'   => '',
			],
		];
	}

	/**
	 * Build step 3: the optional demo for connected sites.
	 *
	 * Both server-owned samples must succeed to complete this step.
	 * Demo checks never create real submissions.
	 *
	 * @since 1.8.0
	 *
	 * @param bool $is_connected Whether step 1 (connected, validated key) is complete.
	 * @param bool $is_completed Whether both demo samples have succeeded.
	 *
	 * @return array Step data.
	 */
	private function get_step_3( bool $is_connected, bool $is_completed ): array {

		$step = [
			'number'      => 3,
			'title'       => __( 'See ActiveLayer in action', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'description' => __( 'Here are two example contact form messages. Can you tell which one is spam? Run the test to see how ActiveLayer handles them.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'completed'   => $is_completed,
			'cta_url'     => '',
			'cta_label'   => '',
			'demo'        => $is_connected,
		];

		return $step;
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

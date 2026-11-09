<?php

namespace ActiveLayer\Admin\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Api\ApiClient;

/**
 * Runs the onboarding banner's "See ActiveLayer in action" demo checks.
 *
 * Each check asks the API to score one of its own fixed samples. Nothing is
 * stored, queued or mailed, and the check never counts toward the plan, so
 * the first-submission onboarding step still waits for real traffic.
 *
 * @since 1.8.0
 */
class OnboardingDemo {

	/**
	 * Sample keys accepted by the API's /test-spam endpoint.
	 *
	 * @since 1.8.0
	 */
	const SAMPLES = [ 'clean', 'spam' ];

	/**
	 * Register the AJAX handler.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public static function hooks(): void {

		add_action( 'wp_ajax_activelayer_onboarding_demo', [ __CLASS__, 'ajax_check' ] );
	}

	/**
	 * Check one demo sample and return its verdict.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public static function ajax_check(): void {

		check_ajax_referer( 'activelayer_admin', 'nonce' );

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ], 403 );
		}

		$sample = isset( $_POST['sample'] ) ? sanitize_key( wp_unslash( $_POST['sample'] ) ) : '';

		if ( ! in_array( $sample, self::SAMPLES, true ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Unknown test sample.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ], 400 );
		}

		$result = ( new ApiClient() )->check_test_sample( $sample );

		if ( empty( $result['success'] ) ) {
			// 200 with success:false: an unreachable API is not a server error of this site.
			wp_send_json_error( [ 'message' => esc_html__( 'Couldn\'t run the test right now. Please try again in a minute.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ] );
		}

		// Persist only successful, distinct sample keys; demo entries are never stored.
		$samples   = (array) get_option( OnboardingManager::OPTION_DEMO_SAMPLES, [] );
		$samples[] = $sample;
		$samples   = array_values( array_unique( array_intersect( self::SAMPLES, $samples ) ) );

		update_option( OnboardingManager::OPTION_DEMO_SAMPLES, $samples, false );

		wp_send_json_success(
			[
				'verdict'        => $result['verdict'],
				'score'          => $result['score'] !== null ? (int) round( $result['score'] ) : null,
				'message'        => $result['message'],
				'demo_completed' => ! array_diff( self::SAMPLES, $samples ),
			]
		);
	}
}

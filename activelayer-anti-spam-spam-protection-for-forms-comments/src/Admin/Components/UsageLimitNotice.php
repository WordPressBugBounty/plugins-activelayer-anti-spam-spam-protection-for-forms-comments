<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Subscription\SubscriptionStats;

/**
 * Displays a dismissible admin banner when API usage is nearing the limit.
 *
 * Shows a top-of-page notice on all admin screens when the user is at or above
 * the warning threshold (80%). The notice is dismissible per billing period.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 */
class UsageLimitNotice {

	/**
	 * Initialize notice system.
	 *
	 * @since 1.1.0
	 */
	public static function init(): void {

		self::hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 */
	public static function hooks(): void {

		add_action( 'admin_notices', [ __CLASS__, 'display_usage_notice' ] );
		add_action( 'wp_ajax_activelayer_dismiss_usage_banner', [ __CLASS__, 'ajax_dismiss_banner' ] );
	}

	/**
	 * Display usage limit admin notice.
	 *
	 * @since 1.1.0
	 */
	public static function display_usage_notice(): void {

		$stats = self::get_notice_stats();

		if ( empty( $stats ) ) {
			return;
		}

		$is_exhausted = UpgradeHelper::is_usage_exhausted( $stats );

		?>
		<div class="activelayer-usage-banner<?php echo $is_exhausted ? ' activelayer-usage-banner-exhausted' : ''; ?>" id="activelayer-usage-banner">
			<p>
				<?php echo wp_kses_post( self::get_banner_message( $stats ) ); ?>
			</p>
			<button type="button" class="activelayer-usage-banner-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Determine whether the usage notice should display and return stats.
	 *
	 * @since 1.1.0
	 *
	 * @return array Subscription stats if notice should display, empty array otherwise.
	 */
	private static function get_notice_stats(): array {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return [];
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return [];
		}

		$stats = SubscriptionStats::get_instance()->get_stats();

		if ( empty( $stats['success'] ) ) {
			return [];
		}

		if ( ! UpgradeHelper::is_usage_warning( $stats ) ) {
			return [];
		}

		if ( UpgradeHelper::is_banner_dismissed( $stats ) ) {
			return [];
		}

		return $stats;
	}

	/**
	 * Build the banner message based on plan type and usage state.
	 *
	 * @since 1.1.0
	 *
	 * @param array $stats Subscription stats.
	 *
	 * @return string HTML message.
	 */
	private static function get_banner_message( array $stats ): string {

		$is_exhausted = UpgradeHelper::is_usage_exhausted( $stats );
		$is_free      = UpgradeHelper::is_free_plan( $stats );
		$plan_name    = esc_html( $stats['plan_name'] ?? __( 'current', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		$upgrade_url  = esc_url( UpgradeHelper::get_upgrade_url( 'top_banner' ) );
		$link_open    = '<a href="' . $upgrade_url . '" target="_blank" rel="noopener noreferrer">';
		$link_close   = '</a>';

		if ( $is_exhausted && $is_free ) {
			return sprintf(
				/* translators: 1: plan name, 2: opening anchor tag, 3: closing anchor tag. */
				__( "You've reached your <strong>%1\$s</strong> plan limit. %2\$sUpgrade your plan%3\$s to resume spam protection!", 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$plan_name,
				$link_open,
				$link_close
			);
		}

		if ( $is_exhausted ) {
			return sprintf(
				/* translators: 1: plan name, 2: opening anchor tag, 3: closing anchor tag. */
				__( "You've reached your <strong>%1\$s</strong> plan limit. Your usage will reset next month, or %2\$supgrade your plan%3\$s for more usage to resume spam protection!", 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$plan_name,
				$link_open,
				$link_close
			);
		}

		if ( $is_free ) {
			return sprintf(
				/* translators: 1: plan name, 2: opening anchor tag, 3: closing anchor tag. */
				__( "You're nearing your <strong>%1\$s</strong> plan limit. Consider %2\$supgrading to pro%3\$s for uninterrupted access to ActiveLayer!", 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$plan_name,
				$link_open,
				$link_close
			);
		}

		return sprintf(
			/* translators: 1: plan name, 2: opening anchor tag, 3: closing anchor tag. */
			__( "You're nearing your <strong>%1\$s</strong> plan limit for this month. Consider %2\$supgrading your plan%3\$s for uninterrupted access to ActiveLayer!", 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			$plan_name,
			$link_open,
			$link_close
		);
	}

	/**
	 * AJAX handler to dismiss the usage limit banner.
	 *
	 * @since 1.1.0
	 */
	public static function ajax_dismiss_banner(): void {

		check_ajax_referer( 'activelayer_admin', 'nonce' );

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ], 403 );
		}

		UpgradeHelper::dismiss_banner();

		wp_send_json_success();
	}
}

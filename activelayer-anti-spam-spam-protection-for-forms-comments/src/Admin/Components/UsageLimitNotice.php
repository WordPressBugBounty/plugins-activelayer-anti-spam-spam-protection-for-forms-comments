<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Subscription\SubscriptionStats;

/**
 * Displays a native WordPress admin notice when API usage nears the limit.
 *
 * Renders a standard `notice` on all admin screens to free-plan users who have
 * no payment method on file and are at or above the warning threshold (80%).
 * Both at 80% and once usage is exhausted (100%) it prompts the user to add a
 * payment method. Paid users and free users with a card auto-charge past the
 * free limit, so the notice does not apply to them. The warning notice is
 * dismissible (native `is-dismissible`, persisted per billing period via AJAX);
 * the exhausted notice is a non-dismissible `notice-error` (sticky).
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 * @since 1.3.0 Restricted to free-plan users without a payment method; rendered as a native WP notice (dismissible at warning, sticky notice-error when exhausted).
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
	 * @since 1.3.0 Rendered as a native WP notice: notice-warning + is-dismissible while nearing the limit, sticky notice-error when exhausted.
	 */
	public static function display_usage_notice(): void {

		$stats = self::get_notice_stats();

		if ( empty( $stats ) ) {
			return;
		}

		$is_exhausted = UpgradeHelper::is_usage_exhausted( $stats );

		// Warning notice is dismissible (native X, persisted via AJAX); the
		// exhausted notice is a sticky error with no dismiss control.
		$classes = $is_exhausted
			? 'notice notice-error activelayer-notice'
			: 'notice notice-warning activelayer-notice is-dismissible';

		?>
		<div class="<?php echo esc_attr( $classes ); ?>" id="activelayer-usage-banner">
			<p>
				<?php echo wp_kses_post( self::get_banner_message( $stats ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Determine whether the usage notice should display and return stats.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Restricted to free-plan users without a payment method; dismissal honored only at the warning threshold.
	 *
	 * @return array Subscription stats if notice should display, empty array otherwise.
	 */
	private static function get_notice_stats(): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

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

		// Free-plan users without a payment method only. Paid users and free
		// users with a card auto-charge past the free limit, so the banner does
		// not apply; unknown card state stays silent (safe-by-default).
		if ( ! UpgradeHelper::requires_payment_method( $stats ) ) {
			return [];
		}

		// Respect dismissal only while merely nearing the limit. Once exhausted
		// the banner is sticky, so a prior 80% dismissal must not hide it.
		if ( ! UpgradeHelper::is_usage_exhausted( $stats ) && UpgradeHelper::is_banner_dismissed( $stats ) ) {
			return [];
		}

		return $stats;
	}

	/**
	 * Build the banner message based on usage state.
	 *
	 * Only reached for free-plan users without a payment method. Both at the
	 * warning threshold and once exhausted, the message prompts the user to add
	 * a payment method, linking to the billing page.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Simplified to the free no-card flow: add-payment-method prompt at both thresholds.
	 *
	 * @param array $stats Subscription stats.
	 *
	 * @return string HTML message.
	 */
	private static function get_banner_message( array $stats ): string {

		$billing_url = esc_url( UpgradeHelper::get_billing_url( 'top_banner' ) );
		$link_open   = '<a href="' . $billing_url . '" target="_blank" rel="noopener noreferrer">';
		$link_close  = '</a>';

		if ( UpgradeHelper::is_usage_exhausted( $stats ) ) {
			return sprintf(
				/* translators: 1: opening anchor tag, 2: closing anchor tag. */
				__( 'ActiveLayer free plan limit reached. Please %1$sadd your payment method%2$s to continue receiving spam protection.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$link_open,
				$link_close
			);
		}

		return sprintf(
			/* translators: 1: opening anchor tag, 2: closing anchor tag. */
			__( 'You\'re nearing your ActiveLayer free plan limit. Please %1$sadd your payment method%2$s to continue receiving spam protection.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
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

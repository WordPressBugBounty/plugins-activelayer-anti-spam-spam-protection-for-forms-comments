<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;

/**
 * Inline notice prompting free users to connect a payment method.
 *
 * Rendered on the Settings page, directly beneath the API key field, when the
 * cached subscription stats report a free plan with no payment method on file.
 * Encourages connecting a card to avoid service disruption once the free usage
 * limit is reached. Reads cached stats only — never blocks the page on a live
 * API call, and stays hidden when the card state is unknown (safe-by-default).
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Admin\Components
 */
class PaymentMethodNotice {

	/**
	 * Render the notice when conditions are met.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function render(): void {

		if ( ! self::should_render() ) {
			return;
		}

		$billing_url = UpgradeHelper::get_billing_url( 'payment_method_notice' );

		?>
		<div class="notice notice-warning inline activelayer-notice activelayer-payment-method-notice" id="activelayer-payment-method-notice" style="margin: 12px 0;">
			<p>
				<strong><?php esc_html_e( 'Connect Your Payment Method', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( "You're on the Free plan. Please add your payment method to keep spam protection running past your free ActiveLayer usage limit. We will never charge you without notifying you first, and your card is only charged when you exceed the free usage limit.", 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $billing_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
					<?php esc_html_e( 'Connect Payment Method', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Determine whether the notice should display.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when a free user without a card should be prompted.
	 */
	private static function should_render(): bool {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return false;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return false;
		}

		return UpgradeHelper::requires_payment_method();
	}
}

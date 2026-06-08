<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Connect\ConnectFlow;
use ActiveLayer\Helpers\SettingsHelper;

/**
 * Displays a persistent admin notice when no API key is configured.
 *
 * Renders a non-dismissible standard WordPress admin notice on all
 * ActiveLayer admin pages prompting the user to connect their API key.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 *
 * @package ActiveLayer\Admin
 */
class ConnectionBar {

	/**
	 * Settings page slug.
	 *
	 * @since 1.1.0
	 */
	private const SETTINGS_PAGE_SLUG = 'activelayer-settings';

	/**
	 * Register hooks for the connection bar.
	 *
	 * @since 1.1.0
	 */
	public static function hooks(): void {

		add_action( 'admin_notices', [ __CLASS__, 'display_notice' ] );
	}

	/**
	 * Display the connection notice on ActiveLayer admin pages.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Build register URL via AppUrlHelper.
	 * @since 1.3.0 CTA now builds a one-click Connect URL.
	 */
	public static function display_notice(): void {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || strpos( $screen->id, 'activelayer' ) === false ) {
			return;
		}

		if ( SettingsHelper::has_api_key() ) {
			return;
		}

		// Hide when the onboarding banner is visible to avoid redundant prompts.
		if ( ! get_option( 'activelayer_onboarding_dismissed' ) && ! get_option( 'activelayer_onboarding_completed' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE_SLUG );
		$register_url = ( new ConnectFlow() )->start( 'connection_bar', 'create_account' );

		?>
		<div class="notice notice-warning activelayer-notice activelayer-connection-bar">
			<p>
				<strong><?php esc_html_e( 'ActiveLayer is not connected.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></strong>
				<?php esc_html_e( 'Connect your site to enable spam protection.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Connect Your Account', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
				<a href="<?php echo esc_url( $register_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Create Free Account', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}

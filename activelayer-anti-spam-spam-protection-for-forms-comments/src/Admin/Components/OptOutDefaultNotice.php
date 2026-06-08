<?php

namespace ActiveLayer\Admin\Components;

use ActiveLayer\Admin\UpgradeRunner;
use ActiveLayer\Helpers\SettingsHelper;
use WP_Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays a one-time admin notice when forms default to opt-out.
 *
 * Renders a dismissible info notice on ActiveLayer admin screens after the
 * plugin upgrades to the opt-out default (1.3.0+). The notice informs
 * existing users that all supported forms are now protected by default and
 * provides a link to the integrations page where protection can be disabled
 * per form.
 *
 * @since 1.3.0
 *
 * @package ActiveLayer\Admin\Components
 */
class OptOutDefaultNotice {

	/**
	 * Initialize notice system.
	 *
	 * @since 1.3.0
	 */
	public static function init(): void {

		self::hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.3.0
	 */
	public static function hooks(): void {

		add_action( 'admin_notices', [ __CLASS__, 'maybe_render' ] );
	}

	/**
	 * Display opt-out default notice if conditions are met.
	 *
	 * @since 1.3.0
	 */
	public static function maybe_render(): void {

		if ( ! self::should_render() ) {
			return;
		}

		self::render();
	}

	/**
	 * Determine whether the notice should display.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	private static function should_render(): bool {

		if ( ! self::is_on_activelayer_screen() ) {
			return false;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return false;
		}

		// WordPress option storage may round-trip `true` as string `'1'`
		// after cache eviction, so use a loose truthy check instead of `=== true`.
		if ( ! get_option( UpgradeRunner::OPTION_ANNOUNCE_REQUIRED ) ) {
			return false;
		}

		return SettingsHelper::has_api_key();
	}

	/**
	 * Check whether the current admin screen belongs to ActiveLayer.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	private static function is_on_activelayer_screen(): bool {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! ( $screen instanceof WP_Screen ) ) {
			return false;
		}

		return strpos( $screen->id, 'toplevel_page_activelayer' ) === 0
			|| strpos( $screen->id, 'activelayer_page_' ) === 0;
	}

	/**
	 * Render the notice.
	 *
	 * @since 1.3.0
	 */
	private static function render(): void {

		$integrations_url = esc_url( admin_url( 'admin.php?page=activelayer-integrations' ) );
		$link_open        = '<a class="activelayer-opt-out-cta" href="' . $integrations_url . '">';
		$link_close       = '</a>';

		$html = '<p>' . sprintf(
			/* translators: 1: opening anchor tag, 2: closing anchor tag. */
			esc_html__( 'ActiveLayer now protects all supported forms by default. Every supported form and WooCommerce surface is automatically protected once your API key is connected. You can disable protection per form in %1$sintegration settings%2$s. If protection didn\'t activate on a specific form, re-save it in the form builder.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			$link_open,
			$link_close
		) . '</p>';

		echo '<div class="notice notice-info is-dismissible activelayer-opt-out-notice">' . wp_kses_post( $html ) . '</div>';
	}
}

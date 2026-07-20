<?php

namespace ActiveLayer\Admin\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Connect\ConnectFlow;
use ActiveLayer\Helpers\SettingsHelper;

/**
 * Full-screen welcome screen shown once after first plugin activation.
 *
 * Renders a standalone, distraction-free page (no wp-admin chrome) whose
 * primary CTA sends the user straight into the one-click Connect flow
 * (Create Account). Never shown once an API key is connected.
 *
 * @since 1.6.0
 */
class WelcomeScreen {

	/**
	 * Page slug used in admin.php?page=... for the welcome screen.
	 *
	 * @since 1.6.0
	 */
	const PAGE_SLUG = 'activelayer-welcome';

	/**
	 * Register the hidden page and the render takeover.
	 *
	 * The document is printed on admin_init (and the request ended) before
	 * any wp-admin chrome is output.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public static function hooks(): void {

		$screen = new self();

		add_action( 'admin_menu', [ $screen, 'register_page' ] );
		add_action( 'admin_init', [ $screen, 'maybe_render' ] );
	}

	/**
	 * Register a hidden dashboard page for the welcome slug.
	 *
	 * Without a registered page, wp-admin's menu access check
	 * (user_can_access_admin_page()) 403s the slug before admin_init runs.
	 * Empty titles keep it out of the menu; the callback never renders
	 * because maybe_render() exits first.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register_page(): void {

		add_dashboard_page( '', '', 'manage_activelayer', self::PAGE_SLUG, '' );
	}

	/**
	 * Render the welcome screen for its slug, or no-op for any other request.
	 *
	 * Redirects already-connected users to the Dashboard so the screen can
	 * never resurface after setup (e.g. via browser history).
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function maybe_render(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; the page renders no state changes.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page !== self::PAGE_SLUG || wp_doing_ajax() ) {
			return;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		if ( SettingsHelper::has_api_key() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=activelayer-dashboard' ) );
			exit;
		}

		$this->render();
		exit;
	}

	/**
	 * Print the standalone welcome document.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function render(): void {

		$suffix        = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';
		$stylesheet    = add_query_arg( 'ver', ACTIVELAYER_PLUGIN_VERSION, ACTIVELAYER_PLUGIN_URL . 'assets/css/welcome' . $suffix . '.css' );
		$dashboard_url = admin_url( 'admin.php?page=activelayer-dashboard' );
		$settings_url  = admin_url( 'admin.php?page=activelayer-settings' );
		$connect_url   = ( new ConnectFlow() )->start( 'welcome_screen', 'lets_get_started' );
		$site_host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Welcome to ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></title>
			<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone document printed before wp-admin chrome; wp_enqueue_style() never fires here. ?>
			<link rel="stylesheet" href="<?php echo esc_url( $stylesheet ); ?>">
		</head>
		<body class="activelayer-welcome">
			<header class="welcome-header">
				<a class="welcome-logo" href="<?php echo esc_url( $dashboard_url ); ?>">
					<img src="<?php echo esc_url( ACTIVELAYER_PLUGIN_URL . 'assets/images/logo.svg' ); ?>" alt="<?php esc_attr_e( 'ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>">
				</a>
				<div class="welcome-header-side">
					<span class="welcome-connecting">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.9 5.7 3.9 9S14.5 18.4 12 21c-2.5-2.6-3.9-5.7-3.9-9S9.5 5.6 12 3z"/></svg>
						<?php
						printf(
							/* translators: %s: current site domain. */
							esc_html__( 'Connecting to %s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
							'<strong>' . esc_html( $site_host ) . '</strong>'
						);
						?>
					</span>
					<a class="welcome-close" href="<?php echo esc_url( $dashboard_url ); ?>" aria-label="<?php esc_attr_e( 'Skip setup and go to the dashboard', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
					</a>
				</div>
			</header>
			<main class="welcome-main">
				<div class="welcome-hero">
					<img src="<?php echo esc_url( ACTIVELAYER_PLUGIN_URL . 'assets/images/onboarding-image.webp' ); ?>" alt="">
				</div>
				<h1><?php esc_html_e( 'Welcome to ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h1>
				<p class="welcome-subtitle">
					<?php esc_html_e( 'AI-powered spam protection for your forms and comments.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?><br>
					<?php esc_html_e( 'Create your free account and be protected in minutes.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</p>
				<a class="welcome-cta" href="<?php echo esc_url( $connect_url ); ?>">
					<?php esc_html_e( 'Let\'s Get Started', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
				</a>
				<p class="welcome-alt">
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'I already have an API key', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></a>
				</p>
			</main>
		</body>
		</html>
		<?php
	}
}

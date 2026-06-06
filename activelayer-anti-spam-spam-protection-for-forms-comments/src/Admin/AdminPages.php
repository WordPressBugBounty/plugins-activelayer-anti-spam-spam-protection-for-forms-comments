<?php

namespace ActiveLayer\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Admin\Components\ConnectionBar;
use ActiveLayer\Admin\Components\PluginInstaller;
use ActiveLayer\Admin\Components\SubmissionsTable;
use ActiveLayer\Admin\Components\UsageLimitNotice;
use ActiveLayer\Admin\Onboarding\OnboardingManager;
use ActiveLayer\Admin\Pages\DashboardPage;
use ActiveLayer\Admin\Pages\IntegrationsPage;
use ActiveLayer\Admin\Pages\LogsPage;
use ActiveLayer\Admin\Pages\SettingsPage;
use ActiveLayer\Admin\Pages\SubmissionsPage;
use ActiveLayer\Admin\Pages\ToolsPage;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Helpers\AppUrlHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
/**
 * Admin Pages Controller.
 *
 * Manages all admin pages and functionality.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Admin
 */
class AdminPages {

	/**
	 * Submissions page instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionsPage
	 */
	private $submissions_page;

	/**
	 * Settings page instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SettingsPage
	 */
	private $settings_page;

	/**
	 * Logs page instance.
	 *
	 * @since 1.0.0
	 *
	 * @var LogsPage
	 */
	private $logs_page;

	/**
	 * Tools page instance.
	 *
	 * @since 1.0.0
	 *
	 * @var ToolsPage
	 */
	private $tools_page;

	/**
	 * Integrations page instance.
	 *
	 * @since 1.1.0
	 *
	 * @var IntegrationsPage
	 */
	private $integrations_page;

	/**
	 * Dashboard page instance.
	 *
	 * @since 1.1.0
	 *
	 * @var DashboardPage
	 */
	private $dashboard_page;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->submissions_page  = new SubmissionsPage();
		$this->settings_page     = new SettingsPage();
		$this->logs_page         = new LogsPage();
		$this->tools_page        = new ToolsPage();
		$this->integrations_page = new IntegrationsPage( IntegrationRegistry::get_instance() );
		$this->dashboard_page    = new DashboardPage();
	}

	/**
	 * Initialize admin functionality.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		$this->hooks();

		( new PluginInstaller() )->hooks();

		// Initialize page controllers.
		$this->submissions_page->init();

		// Initialize usage limit banner.
		UsageLimitNotice::init();

		// Initialize API connection bar.
		ConnectionBar::hooks();
	}


	/**
	 * Initialize admin hooks.
	 *
	 * @since 1.0.0
	 */
	public function hooks(): void {

		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_print_scripts', [ $this, 'hide_unrelated_notices' ] );
		add_action( 'admin_notices', [ SubmissionsTable::class, 'display_bulk_action_notices' ] );
		add_action( 'wp_ajax_activelayer_verify_api_key', [ $this->settings_page, 'ajax_verify_api_key' ] );
		add_action( 'wp_ajax_activelayer_save_integration_settings', [ $this->integrations_page, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_activelayer_dismiss_onboarding', [ $this, 'ajax_dismiss_onboarding' ] );
		add_action( 'admin_enqueue_scripts', [ $this->dashboard_page, 'enqueue_chart_assets' ] );

		add_filter(
			'plugin_action_links_' . plugin_basename( ACTIVELAYER_PLUGIN_FILE ),
			[ $this, 'add_plugin_action_links' ]
		);
	}

	/**
	 * Redirect to dashboard page after first-time plugin activation.
	 *
	 * @since 1.1.0
	 */
	public function maybe_redirect_after_activation(): void {

		if ( ! get_transient( 'activelayer_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'activelayer_activation_redirect' );

		// Do not redirect on multisite bulk activation or during AJAX/cron.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) || is_network_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=activelayer-dashboard' ) );
		exit;
	}

	/**
	 * Add action links on the Plugins list page.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Build register URL via AppUrlHelper and normalize UTM shape.
	 *
	 * @param array $links Existing plugin action links.
	 *
	 * @return array Modified plugin action links.
	 */
	public function add_plugin_action_links( array $links ): array {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return $links;
		}

		$settings_url = admin_url( 'admin.php?page=activelayer-settings' );
		$docs_url     = 'https://activelayer.com/docs/wordpress-plugin/?utm_source=plugin&utm_medium=plugins-page&utm_campaign=action-links';

		if ( SettingsHelper::has_api_key() ) {
			$account_link = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( 'https://app.activelayer.com/?utm_source=plugin&utm_medium=plugins-page&utm_campaign=action-links' ),
				esc_html__( 'Manage Account', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);
		} else {
			$account_link = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s" style="color: #008537; font-weight: 700;">%3$s</a>',
				esc_url( AppUrlHelper::get_register_url( 'plugins-page', 'create_account' ) ),
				esc_attr__( 'Create your ActiveLayer account', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				esc_html__( 'Get ActiveLayer Account', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			);
		}

		$custom_links = [
			'account'  => $account_link,
			'settings' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			),
			'docs'     => sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $docs_url ),
				esc_html__( 'Docs', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			),
		];

		return array_merge( $custom_links, $links );
	}

	/**
	 * Render admin header.
	 *
	 * @since 1.0.0
	 */
	public static function render_header(): void {

		$logo_url = ACTIVELAYER_PLUGIN_URL . 'assets/images/logo.svg';

		?>
		<div class="activelayer-admin-header">
			<div class="header-logo">
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>" />
			</div>
			<a href="https://activelayer.com/docs/api?utm_campaign=plugin&utm_source=WordPress&utm_medium=header_links&utm_content=help_button&utm_locale=en_US" target="_blank" class="header-help">
				<span class="dashicons dashicons-editor-help"></span>
				<span class="help-text"><?php esc_html_e( 'Help', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></span>
			</a>
		</div>
		<?php
	}

	/**
	 * Add admin menu pages.
	 *
	 * @since 1.0.0
	 */
	public function add_menu_pages(): void {

		// Main menu page (redirects to dashboard).
		add_menu_page(
			__( 'Dashboard', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'manage_activelayer',
			'activelayer',
			[ $this, 'redirect_to_dashboard_page' ],
			'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzkiIGhlaWdodD0iMzkiIHZpZXdCb3g9IjAgMCAzOSAzOSIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTEzLjk4NjMgMjguMzgyOUMxNC40MzUxIDI5LjIwNzYgMTQuMTYwMiAzMC4xNTUxIDEzLjQ0NjcgMzAuNTUyN0MxMi43MzMyIDMwLjk1MDMgMTEuODMwNSAzMC43NDE5IDExLjM4MDUgMjkuOTc2QzguMjYwNjMgMjQuNTYxMSA2Ljk0NzQ5IDE4LjEwOTIgNy4wNTQ5IDExLjg4ODdDNy4xNTMzNSAxMC4wNTI2IDkuODcwNDEgMTAuMDQyNCA5Ljk1NjA4IDExLjkwNDFDMTAuMDAyMSAxNy44MTI2IDExLjAzNTIgMjMuMjMxMyAxMy45ODc2IDI4LjM4NDJMMTMuOTg2MyAyOC4zODI5WiIgZmlsbD0iI2E3YWFhZCIvPgo8cGF0aCBkPSJNNC4zMzMxMSAyNy40MDczQzIuODM1ODUgMjMuMjk5MSAxLjkxMDEzIDE5LjgwNzIgMS45ODY4NSAxNS41MTM2QzEuOTkzMjQgMTUuMTc5OSAyLjEwMDY1IDE0Ljg1IDIuMzE5MjkgMTQuNTk4MUMzLjE0MjcyIDEzLjY0ODEgNC45Nzg4MSAxNC4xNDA0IDQuOTc4ODEgMTUuNjg1QzUuMTM2MDggMTkuNDMgNS43Nzc5NSAyMi44Njk1IDcuMTQ5OTEgMjYuMzYxNEM3Ljc0NzAyIDI4LjA3NDggNS4wNTkzNyAyOS4yMDYzIDQuMzMzMTEgMjcuNDA3M1oiIGZpbGw9IiNhN2FhYWQiLz4KPHBhdGggZD0iTTIzLjM3NiAzLjk4ODRDMjQuMDgxIDMuNzc4NTggMjQuODMxMSAzLjc3ODUyIDI1LjUzNjEgMy45ODg0QzI4LjQxMjIgNC44NDQ1OCAzMS45MDk3IDUuOTI2NCAzNC41NDMgNi43ODEzN0MzNS44MDI5IDcuMTkwNzUgMzYuNTI3MyA4LjcwMDYyIDM2LjUyNzMgMTAuNDI5OEMzNi41MjczIDIwLjQyMzIgMzQuNjE5MyAyOS4yODI2IDI1LjA0MSAzNC41MzA0QzI0LjY3ODIgMzQuNzI5IDI0LjIzMjkgMzQuNzI5MSAyMy44NzAxIDM0LjUzMDRDMTQuMjkxOCAyOS4yODI2IDEyLjM4MzggMjAuNDIzMyAxMi4zODM4IDEwLjQyOThDMTIuMzgzOCA4LjcwMDQyIDEzLjEwODkgNy4xOTA1NSAxNC4zNjkxIDYuNzgxMzdDMTcuMDAyNSA1LjkyNjM5IDIwLjQ5OTkgNC44NDQ1OCAyMy4zNzYgMy45ODg0Wk0zMS4yMzkzIDEzLjIzNzRDMzAuNjEzMSAxMi42OTk0IDI5LjY3MDkgMTIuNzcwOSAyOS4xMzI4IDEzLjM5NTZMMjIuNzM4MyAyMC4zODFMMjAuMzI0MiAxNy44MzUxQzE5Ljc4NjEgMTcuMjEwMiAxOC44NDI2IDE3LjEzODggMTguMjE3OCAxNy42NzY5QzE3LjU5MTYgMTguMjE1IDE3LjUyMDUgMTkuMTU4NSAxOC4wNTg2IDE5Ljc4MzNMMjEuNjA0NSAyMy42NDU2QzIxLjg4NzcgMjMuOTc1IDIyLjMwMTMgMjQuMTY1MSAyMi43MzYzIDI0LjE2NTJMMjIuNzM5MyAyNC4xNjYxQzIzLjE3NDMgMjQuMTY2MSAyMy41ODc4IDIzLjk3NSAyMy44NzExIDIzLjY0NTZMMzEuMzk3NSAxNS4zNDM5QzMxLjkzNTYgMTQuNzE3NyAzMS44NjQxIDEzLjc3NTUgMzEuMjM5MyAxMy4yMzc0WiIgZmlsbD0iI2E3YWFhZCIvPgo8L3N2Zz4K',
			30
		);

		// Dashboard page (default landing page).
		add_submenu_page(
			'activelayer',
			__( 'Dashboard', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Dashboard', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'manage_activelayer',
			'activelayer-dashboard',
			[ $this, 'render_dashboard_page' ]
		);

		// Submissions page.
		add_submenu_page(
			'activelayer',
			__( 'Submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'manage_activelayer',
			'activelayer-submissions',
			[ $this, 'render_submissions_page' ]
		);

		// Remove the autogenerated submenu that points to the redirect target.
		remove_submenu_page( 'activelayer', 'activelayer' );

		// Settings page.
		add_submenu_page(
			'activelayer',
			__( 'Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'manage_activelayer',
			'activelayer-settings',
			[ $this, 'render_settings_page' ]
		);

		// Integrations page.
		add_submenu_page(
			'activelayer',
			__( 'Integrations', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Integrations', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'manage_activelayer',
			'activelayer-integrations',
			[ $this, 'render_integrations_page' ]
		);

		// Tools page.
		add_submenu_page(
			'activelayer',
			__( 'Tools', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Tools', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'manage_activelayer',
			'activelayer-tools',
			[ $this, 'render_tools_page' ]
		);

		// Logs page (only if debug logging is enabled in settings).
		if ( SettingsHelper::is_logging_enabled() ) {
			add_submenu_page(
				'activelayer',
				__( 'Logs', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				__( 'Logs', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'manage_activelayer',
				'activelayer-logs',
				[ $this, 'render_logs_page' ]
			);
		}

		// Upgrade to Pro menu item for free plan users.
		if ( UpgradeHelper::is_free_plan() ) {
			$upgrade_url = UpgradeHelper::get_upgrade_url( 'sidebar_menu' );

			global $submenu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$submenu['activelayer'][] = [ // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				'<span class="activelayer-upgrade-menu">' . esc_html__( 'Upgrade to Pro', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</span>',
				'manage_activelayer',
				$upgrade_url,
			];
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( string $hook ): void {

		$is_activelayer_page = strpos( $hook, 'activelayer' ) !== false;

		// Use minified files in production, regular files in debug mode.
		$suffix = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';

		// Load styles only on ActiveLayer admin pages.
		if ( $is_activelayer_page ) {
			wp_enqueue_style(
				'activelayer-admin',
				ACTIVELAYER_PLUGIN_URL . 'assets/css/admin' . $suffix . '.css',
				[],
				ACTIVELAYER_PLUGIN_VERSION
			);
		}

		// Only load full JS on our pages, but provide AJAX data globally for banner dismiss.
		if ( $is_activelayer_page ) {
			wp_enqueue_script(
				'activelayer-admin',
				ACTIVELAYER_PLUGIN_URL . 'assets/js/admin' . $suffix . '.js',
				[ 'jquery' ],
				ACTIVELAYER_PLUGIN_VERSION,
				true
			);
		} else {
			wp_enqueue_script(
				'activelayer-admin-global',
				ACTIVELAYER_PLUGIN_URL . 'assets/js/admin' . $suffix . '.js',
				[ 'jquery' ],
				ACTIVELAYER_PLUGIN_VERSION,
				true
			);
		}

		$script_handle = $is_activelayer_page ? 'activelayer-admin' : 'activelayer-admin-global';

		wp_localize_script(
			$script_handle,
			'activelayerAdmin',
			[
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'activelayer_admin' ),
				'verifyKeyNonce'     => wp_create_nonce( 'activelayer_verify_api_key' ),
				'installPluginNonce' => wp_create_nonce( 'activelayer_install_plugin' ),
				'strings'            => [
					'emptyApiKey'              => __( 'Please enter an API key', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'verifying'                => __( 'Verifying...', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'verifyKey'                => __( 'Verify Key', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'connectionError'          => __( 'Connection error. Please try again.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'apiKeyValidTitle'         => __( 'API key is valid', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					/* translators: %s: number of selected submissions. */
					'bulkDeleteConfirm'        => __( 'Are you sure you want to delete %s submission(s)? This action cannot be undone.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'singleDeleteConfirm'      => __( 'Are you sure you want to delete this submission? This action cannot be undone.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'dismissOnboardingConfirm' => __( 'Are you sure you want to skip the setup guide?', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'installing'               => __( 'Installing...', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'activating'               => __( 'Activating...', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'installError'             => __( 'Installation failed. Please try again.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'copied'                   => __( 'Copied!', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				],
			]
		);

		// Enqueue integrations.js only on the integrations page.
		if ( strpos( $hook, 'activelayer-integrations' ) !== false ) {
			wp_enqueue_script(
				'activelayer-integrations',
				ACTIVELAYER_PLUGIN_URL . 'assets/js/integrations' . $suffix . '.js',
				[],
				ACTIVELAYER_PLUGIN_VERSION,
				true
			);

			wp_localize_script(
				'activelayer-integrations',
				'activelayerIntegrations',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'activelayer_integration_settings' ),
					'strings' => [
						'saving'      => __( 'Saving...', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						'saved'       => __( 'Settings saved.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						'saveError'   => __( 'Save failed. Please try again.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						'saveButton'  => __( 'Save Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						'active'      => __( 'Active', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						'paused'      => __( 'Paused', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						'selectAll'   => __( 'Select All', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						'deselectAll' => __( 'Deselect All', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					],
				]
			);
		}
	}

	/**
	 * Hide unrelated admin notices on ActiveLayer pages.
	 *
	 * Removes third-party plugin notices from ActiveLayer admin screens
	 * to keep the UI clean and focused.
	 *
	 * @since 1.1.0
	 */
	public function hide_unrelated_notices(): void {

		$screen = get_current_screen();

		if ( ! $screen || strpos( $screen->id, 'activelayer' ) === false ) {
			return;
		}

		$notice_hooks = [
			'user_admin_notices',
			'admin_notices',
			'all_admin_notices',
			'network_admin_notices',
		];

		foreach ( $notice_hooks as $hook ) {
			$this->remove_hook_callbacks( $hook, [ $this, 'is_activelayer_callback' ], true );
		}

		// Remove delayed admin notices from footer.
		$this->remove_hook_callbacks(
			'admin_footer',
			static function ( $callback ) {

				return ( $callback['function'] ?? null ) === 'render_delayed_admin_notices';
			}
		);
	}

	/**
	 * Remove callbacks from a hook based on a matcher function.
	 *
	 * When $invert is true, callbacks matching the matcher are kept (all others removed).
	 * When $invert is false (default), callbacks matching the matcher are removed.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $hook    Hook name.
	 * @param callable $matcher Receives a callback array, returns bool.
	 * @param bool     $invert  When true, keep matches and remove non-matches.
	 */
	private function remove_hook_callbacks( string $hook, callable $matcher, bool $invert = false ): void {

		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $name => $arr ) {
				$matched = $matcher( $arr );

				if ( $invert ? $matched : ! $matched ) {
					continue;
				}

				unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $name ] );
			}
		}
	}

	/**
	 * Check if a callback belongs to ActiveLayer.
	 *
	 * @since 1.1.0
	 *
	 * @param array $callback Callback array from $wp_filter.
	 *
	 * @return bool
	 */
	private function is_activelayer_callback( array $callback ): bool {

		$function = $callback['function'] ?? null;

		if ( ! $function ) {
			return false;
		}

		// Instance method call: [ $object, 'method' ].
		if ( is_array( $function ) && isset( $function[0] ) && is_object( $function[0] ) ) {
			return stripos( get_class( $function[0] ), 'activelayer' ) !== false;
		}

		// Static method call: [ 'ClassName', 'method' ].
		if ( is_array( $function ) && isset( $function[0] ) && is_string( $function[0] ) ) {
			return stripos( $function[0], 'activelayer' ) !== false;
		}

		// Static class method string: 'ClassName::method'.
		if ( is_string( $function ) && stripos( $function, 'activelayer' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Redirect main menu to dashboard page.
	 *
	 * Ensures the top-level ActiveLayer menu loads the Dashboard screen by default.
	 *
	 * @since 1.1.0
	 */
	public function redirect_to_dashboard_page(): void {

		wp_safe_redirect( admin_url( 'admin.php?page=activelayer-dashboard' ) );
		exit;
	}

	/**
	 * Handle AJAX request to dismiss the onboarding banner.
	 *
	 * @since 1.1.0
	 */
	public function ajax_dismiss_onboarding(): void {

		check_ajax_referer( 'activelayer_admin', 'nonce' );

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ], 403 );
		}

		$manager = new OnboardingManager();

		$manager->dismiss();

		wp_send_json_success();
	}

	/**
	 * Render dashboard page.
	 *
	 * @since 1.1.0
	 */
	public function render_dashboard_page(): void {

		$this->dashboard_page->render();
	}

	/**
	 * Render integrations page.
	 *
	 * @since 1.1.0
	 */
	public function render_integrations_page(): void {

		$this->integrations_page->render();
	}

	/**
	 * Render submissions page.
	 *
	 * @since 1.0.0
	 */
	public function render_submissions_page(): void {

		$this->submissions_page->render();
	}

	/**
	 * Render settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page(): void {

		$this->settings_page->render();
	}

	/**
	 * Render logs page.
	 *
	 * @since 1.0.0
	 */
	public function render_logs_page(): void {

		$this->logs_page->render();
	}

	/**
	 * Render tools page.
	 *
	 * @since 1.0.0
	 */
	public function render_tools_page(): void {

		$this->tools_page->render();
	}
}

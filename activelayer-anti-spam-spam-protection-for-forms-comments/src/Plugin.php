<?php

namespace ActiveLayer;

use ActiveLayer\ClientSignals\ScriptLoader;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Integrations\ContactForm7\ContactForm7Integration;
use ActiveLayer\Integrations\WPForms\WPFormsIntegration;
use ActiveLayer\Integrations\Comments\CommentsIntegration;
use ActiveLayer\Integrations\Comments\NativeModerationFeedback;
use ActiveLayer\Integrations\NinjaForms\NinjaFormsIntegration;
use ActiveLayer\Integrations\FormidableForms\FormidableFormsIntegration;
use ActiveLayer\Integrations\Forminator\ForminatorIntegration;
use ActiveLayer\Integrations\FluentForms\FluentFormsIntegration;
use ActiveLayer\Integrations\SureForms\SureFormsIntegration;
use ActiveLayer\Integrations\WSForm\WSFormIntegration;
use ActiveLayer\Integrations\GravityForms\GravityFormsIntegration;
use ActiveLayer\Integrations\ElementorForms\ElementorFormsIntegration;
use ActiveLayer\Integrations\WooCommerce\WooCommerceIntegration;
use ActiveLayer\Integrations\BuddyPress\BuddyPressIntegration;
use ActiveLayer\Integrations\BuddyBoss\BuddyBossIntegration;
use ActiveLayer\Integrations\AffiliateWP\AffiliateWPIntegration;
use ActiveLayer\Integrations\MemberPress\MemberPressIntegration;
use ActiveLayer\Admin\AdminPages;
use ActiveLayer\Admin\Components\DashboardWidget;
use ActiveLayer\Admin\Components\OptOutDefaultNotice;
use ActiveLayer\Admin\Components\ReviewRequestNotice;
use ActiveLayer\Admin\UpgradeRunner;
use ActiveLayer\Privacy\PrivacyManager;
use ActiveLayer\Subscription\SubscriptionStats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Class.
 *
 * Central plugin controller that manages all components.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Integration registry.
	 *
	 * @since 1.0.0
	 *
	 * @var IntegrationRegistry
	 */
	private $registry;

	/**
	 * Private constructor for singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		$this->registry = IntegrationRegistry::get_instance();
	}

	/**
	 * Get plugin instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Plugin Plugin instance.
	 */
	public static function get_instance(): Plugin {

		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Added frontend script loader initialization.
	 * @since 1.2.0 Wire NativeModerationFeedback listener for native comment moderation.
	 * @since 1.3.0 Run UpgradeRunner first to detect opt-out announce state.
	 */
	public function init(): void {

		UpgradeRunner::run();

		// Initialize integrations.
		$this->init_integrations();

		// Bridge native WP comment moderation actions to the ActiveLayer feedback pipeline.
		( new NativeModerationFeedback() )->init();

		// Arm the first-spam review request notice (listener must run in all contexts).
		ReviewRequestNotice::init();

		// Hook into WordPress.
		$this->hooks();

		// Initialize frontend script loader for environment detection.
		$this->init_frontend_scripts();

		// Load admin functionality if in admin.
		if ( is_admin() ) {
			$this->init_admin();
		}
	}

	/**
	 * Initialize form integrations.
	 *
	 * @since 1.0.0
	 */
	private function init_integrations(): void {

		// Load built-in integrations.
		$this->load_builtin_integrations();

		// Refresh registry to activate available integrations.
		$this->registry->refresh();
	}

	/**
	 * Load built-in integrations.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Registered ReviewsIntegration (WooCommerce umbrella).
	 * @since 1.2.0 Replaced WooCommerceReviews registration with WooCommerce umbrella (covers reviews + registration).
	 * @since 1.3.0 Registered BuddyPressIntegration (signup spam protection).
	 * @since 1.3.0 Split BuddyPress into BuddyPressIntegration (free) and BuddyBossIntegration (Platform), each with its own admin toggle.
	 * @since 1.4.0 Registered WSFormIntegration (WS Form sync-block anti-spam).
	 * @since 1.4.0 Registered AffiliateWPIntegration (affiliate-registration spam protection).
	 * @since 1.4.0 Registered MemberPressIntegration (membership-signup spam protection).
	 */
	private function load_builtin_integrations(): void {

		$integrations = [
			WPFormsIntegration::class,
			ContactForm7Integration::class,
			CommentsIntegration::class,
			NinjaFormsIntegration::class,
			FormidableFormsIntegration::class,
			FluentFormsIntegration::class,
			SureFormsIntegration::class,
			WSFormIntegration::class,
			WooCommerceIntegration::class,
			BuddyPressIntegration::class,
			BuddyBossIntegration::class,
			AffiliateWPIntegration::class,
			MemberPressIntegration::class,
		];

		foreach ( $integrations as $class ) {
			if ( class_exists( $class ) ) {
				$this->registry->register( new $class() );
			}
		}

		// Load Forminator integration.
		if ( class_exists( ForminatorIntegration::class ) ) {
			$forminator = new ForminatorIntegration();

			$this->registry->register( $forminator );
		}

		// Load Gravity Forms integration.
		if ( class_exists( GravityFormsIntegration::class ) ) {
			$gravityforms = new GravityFormsIntegration();

			$this->registry->register( $gravityforms );
		}

		// Load Elementor Forms integration.
		if ( class_exists( ElementorFormsIntegration::class ) ) {
			$elementor = new ElementorFormsIntegration();

			$this->registry->register( $elementor );
		}
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function hooks(): void {

		// Plugin lifecycle hooks.
		add_action( 'init', [ $this, 'on_plugins_loaded' ] );

		// Register subscription stats refresh action.
		add_action( 'activelayer_refresh_subscription_stats', [ $this, 'refresh_subscription_stats' ] );

		// Periodic refresh of integrations.
		add_action( 'wp_loaded', [ $this, 'refresh_integrations' ] );

		// Add privacy policy snippet in admin.
		add_action( 'admin_init', [ $this, 'register_privacy_policy_content' ] );

		// Initialize GDPR privacy data exporters and erasers.
		$this->init_privacy_manager();
	}

	/**
	 * Initialize the privacy manager for GDPR compliance.
	 *
	 * @since 1.0.0
	 */
	private function init_privacy_manager(): void {

		$privacy_manager = new PrivacyManager();

		$privacy_manager->hooks();
	}

	/**
	 * Initialize admin functionality.
	 *
	 * @since 1.0.0
	 */
	private function init_admin(): void {

		// Initialize admin pages controller.
		$admin = new AdminPages();

		$admin->init();

		// Initialize dashboard widget.
		$dashboard_widget = new DashboardWidget();

		$dashboard_widget->hooks();

		// Initialize opt-out default announce notice.
		OptOutDefaultNotice::init();
	}

	/**
	 * Initialize frontend script loader.
	 *
	 * Loads the environment detection script on frontend pages
	 * where forms may be present.
	 *
	 * @since 1.1.0
	 */
	private function init_frontend_scripts(): void {

		$script_loader = new ScriptLoader();

		$script_loader->init();
	}

	/**
	 * Handle plugins_loaded action.
	 *
	 * @since 1.0.0
	 */
	public function on_plugins_loaded(): void {

		// Refresh integrations when all plugins are loaded.
		$this->registry->refresh();
	}

	/**
	 * Refresh subscription stats in background.
	 *
	 * @since 1.0.0
	 */
	public function refresh_subscription_stats(): void {

		$stats = SubscriptionStats::get_instance();

		$stats->process_refresh();
	}

	/**
	 * Refresh integrations periodically.
	 *
	 * @since 1.0.0
	 */
	public function refresh_integrations(): void {

		// Only refresh on admin pages or AJAX requests.
		if ( is_admin() || wp_doing_ajax() ) {
			$this->registry->refresh();
		}
	}

	/**
	 * Register privacy policy content for the plugin.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Updated to reflect automatic protection enabled by default and per-form opt-out capability.
	 */
	public function register_privacy_policy_content(): void {

		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'ActiveLayer automatically protects form submissions on this site after an API key is connected. When a form is submitted, ActiveLayer sends submission details (such as names, email addresses, form field values, IP addresses, and user agent strings) to the ActiveLayer API for spam analysis. This data is used exclusively to determine whether a submission is spam and may be stored temporarily in accordance with the ActiveLayer Privacy Policy. Site administrators can disable protection for individual forms in the ActiveLayer integration settings if needed.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Data shared with ActiveLayer is transmitted over HTTPS. Site owners should ensure their own privacy policy explains this automatic processing and references the ActiveLayer service (https://activelayer.com/privacy) for additional information.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</p>';

		wp_add_privacy_policy_content( 'ActiveLayer', wp_kses_post( $content ) );
	}
}

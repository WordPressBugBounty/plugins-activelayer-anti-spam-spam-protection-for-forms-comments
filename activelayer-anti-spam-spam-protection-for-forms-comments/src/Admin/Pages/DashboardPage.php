<?php

namespace ActiveLayer\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Admin\AdminPages;
use ActiveLayer\Admin\Components\DashboardWidget;
use ActiveLayer\Admin\Components\PluginInstaller;
use ActiveLayer\Admin\Onboarding\OnboardingBanner;
use ActiveLayer\Admin\Onboarding\OnboardingManager;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Storage\Storage;

/**
 * Dashboard Page Controller.
 *
 * Renders the plugin Dashboard as the default landing screen. Shows submission
 * stats, a dismissible onboarding checklist, cross-promotion of sister plugins,
 * upgrade to Pro banner, and quick access links.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Pages namespace.
 *
 * @package ActiveLayer\Admin
 */
class DashboardPage {

	/**
	 * Maximum number of cross-promote cards to display.
	 *
	 * @since 1.1.0
	 */
	const CROSS_PROMOTE_MAX = 4;

	/**
	 * Get sister plugins available for cross-promotion.
	 *
	 * Returned from a method instead of a constant so descriptions are translatable.
	 * Plugin file paths (Lite and Pro variants) live in
	 * {@see PluginInstaller::get_plugin_files()} keyed by `slug`.
	 *
	 * @since 1.1.0
	 * @since 1.4.0 Moved plugin file paths to PluginInstaller as the single source of truth.
	 *
	 * @return array[]
	 */
	private function get_sister_plugins(): array {

		return [
			[
				'name'        => 'WPForms',
				'slug'        => 'wpforms-lite',
				'description' => __( 'The most beginner-friendly WordPress form plugin.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'icon'        => 'wpforms.png',
			],
			[
				'name'        => 'WP Mail SMTP',
				'slug'        => 'wp-mail-smtp',
				'description' => __( 'Fix email deliverability issues for WordPress.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'icon'        => 'wp-mail-smtp.png',
			],
			[
				'name'        => 'MonsterInsights',
				'slug'        => 'google-analytics-for-wordpress',
				'description' => __( 'The best Google Analytics plugin for WordPress.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'icon'        => 'monsterinsights.png',
			],
			[
				'name'        => 'All in One SEO',
				'slug'        => 'all-in-one-seo-pack',
				'description' => __( 'The best WordPress SEO plugin and toolkit.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'icon'        => 'all-in-one-seo.png',
			],
			[
				'name'        => 'OptinMonster',
				'slug'        => 'optinmonster',
				'description' => __( 'Powerful lead generation and conversion optimization.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'icon'        => 'optinmonster.png',
			],
			[
				'name'        => 'SeedProd',
				'slug'        => 'coming-soon',
				'description' => __( 'The best drag-and-drop landing page builder.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'icon'        => 'seedprod.png',
			],
		];
	}

	/**
	 * Get Quick Access links for the sidebar.
	 *
	 * Returned from a method instead of a constant so labels are translatable.
	 *
	 * @since 1.1.0
	 *
	 * @return array[]
	 */
	private function get_quick_access_links(): array {

		$locale = get_locale();

		return [
			[
				'label' => __( 'Getting Started', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'url'   => 'https://activelayer.com/docs/wordpress-plugin/?utm_campaign=plugin&utm_source=WordPress&utm_medium=dashboard_quick_access&utm_content=getting_started&utm_locale=' . $locale,
				'icon'  => 'dashicons-book',
			],
			[
				'label' => __( 'API Documentation', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'url'   => 'https://activelayer.com/docs/api/?utm_campaign=plugin&utm_source=WordPress&utm_medium=dashboard_quick_access&utm_content=api_docs&utm_locale=' . $locale,
				'icon'  => 'dashicons-media-code',
			],
			[
				'label' => __( 'Support', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'url'   => 'https://activelayer.com/contact/?utm_campaign=plugin&utm_source=WordPress&utm_medium=dashboard_quick_access&utm_content=support&utm_locale=' . $locale,
				'icon'  => 'dashicons-sos',
			],
			[
				'label' => __( 'Account Dashboard', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'url'   => 'https://app.activelayer.com/?utm_campaign=plugin&utm_source=WordPress&utm_medium=dashboard_quick_access&utm_content=account_dashboard&utm_locale=' . $locale,
				'icon'  => 'dashicons-admin-users',
			],
			[
				'label' => __( 'ActiveLayer Status', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'url'   => 'https://status.activelayer.com/',
				'icon'  => 'dashicons-heart',
			],
		];
	}

	/**
	 * Get Upgrade to Pro feature list.
	 *
	 * Returned from a method instead of a constant so strings are translatable.
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	private function get_pro_features(): array {

		return [
			__( '25,000 checks/month', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Full API access', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Email support', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Unlimited sites', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Priority queue', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			__( 'Advanced analytics', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
		];
	}

	/**
	 * Enqueue chart assets for the dashboard page.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_chart_assets( string $hook ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( strpos( $hook, 'activelayer-dashboard' ) === false ) {
			return;
		}

		$suffix = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';

		wp_enqueue_script(
			'activelayer-dash-widget',
			ACTIVELAYER_PLUGIN_URL . 'assets/js/dashboard-widget' . $suffix . '.js',
			[],
			ACTIVELAYER_PLUGIN_VERSION,
			true
		);

		$daily = Storage::get_instance()->get_daily_counts( DashboardWidget::DEFAULT_DAYS );
		$stats = Storage::get_instance()->get_queue_stats();

		wp_localize_script(
			'activelayer-dash-widget',
			'activelayerDashWidget',
			[
				'daily'   => $daily,
				'stats'   => [
					'total'  => $stats['total'] ?? 0,
					'clean'  => $stats['clean'] ?? 0,
					'spam'   => $stats['spam'] ?? 0,
					'failed' => $stats['failed'] ?? 0,
				],
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( DashboardWidget::NONCE_ACTION ),
			]
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @since 1.1.0
	 */
	public function render(): void {

		$has_api_key = SettingsHelper::has_api_key();

		AdminPages::render_header();

		?>
		<div class="wrap activelayer-admin-wrap activelayer-page-dashboard">
			<h1><?php esc_html_e( 'Dashboard', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h1>

			<?php $this->render_onboarding_banner(); ?>

			<div class="activelayer-dashboard-grid">
				<div class="activelayer-dashboard-main">
					<?php
					$this->render_stats_section();
					$this->render_integrations_overview( $has_api_key );
					$this->render_upgrade_banner();
					?>
				</div>
				<div class="activelayer-dashboard-sidebar">
					<?php
					$this->render_cross_promote();
					$this->render_quick_access();
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the stats cards section.
	 *
	 * Shows Total, Spam (with percentage), and Clean (with percentage) cards.
	 * When disconnected, shows zeroed stats with a connection CTA.
	 *
	 * @since 1.1.0
	 */
	private function render_stats_section(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$stats = Storage::get_instance()->get_queue_stats();

		$total = (int) ( $stats['total'] ?? 0 );
		$spam  = (int) ( $stats['spam'] ?? 0 );
		$clean = (int) ( $stats['clean'] ?? 0 );

		$spam_pct  = $total > 0 ? round( ( $spam / $total ) * 100, 1 ) : 0;
		$clean_pct = $total > 0 ? round( ( $clean / $total ) * 100, 1 ) : 0;

		?>
		<div class="activelayer-dashboard-widget activelayer-dashboard-widget--stats">
			<div class="activelayer-dashboard-chart-header">
				<select class="activelayer-dash-widget-timespan">
					<?php foreach ( DashboardWidget::ALLOWED_DAYS as $days ) : ?>
						<option value="<?php echo esc_attr( $days ); ?>"<?php selected( $days, DashboardWidget::DEFAULT_DAYS ); ?>>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of days. */
									__( 'Last %d days', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
									$days
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="activelayer-dashboard-stats">
				<?php
				$this->render_stat_card(
					esc_html__( 'Total Requests', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					$total,
					'',
					'total'
				);
				$this->render_stat_card(
					esc_html__( 'Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					$spam,
					$total > 0 ? sprintf( '%s%%', number_format_i18n( $spam_pct, 1 ) ) : '',
					'spam'
				);
				$this->render_stat_card(
					esc_html__( 'Clean', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					$clean,
					$total > 0 ? sprintf( '%s%%', number_format_i18n( $clean_pct, 1 ) ) : '',
					'clean'
				);
				?>
			</div>

			<div class="activelayer-dashboard-chart-block">
				<div class="activelayer-chart-loader">
					<span class="activelayer-chart-loader__spinner"></span>
				</div>
				<canvas class="activelayer-dash-widget-chart"></canvas>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single stat card.
	 *
	 * @since 1.1.0
	 *
	 * @param string $label      Card label text.
	 * @param int    $count      Numeric count value.
	 * @param string $percentage Percentage string (e.g. "7.2%") or empty.
	 * @param string $type       Card type used as BEM modifier (total, spam, clean).
	 */
	private function render_stat_card( string $label, int $count, string $percentage, string $type ): void {

		?>
		<div class="activelayer-dashboard-stat-card activelayer-dashboard-stat-card--<?php echo esc_attr( $type ); ?>">
			<span class="activelayer-dashboard-stat-card__label"><?php echo esc_html( $label ); ?></span>
			<span class="activelayer-dashboard-stat-card__count">
				<?php echo esc_html( number_format_i18n( $count ) ); ?>
				<?php if ( $percentage !== '' ) : ?>
					<span class="activelayer-dashboard-stat-card__pct"><?php echo esc_html( $percentage ); ?></span>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render the onboarding banner above the dashboard grid.
	 *
	 * Delegates to the shared OnboardingBanner component which handles
	 * its own visibility logic (dismissed state, completed steps).
	 *
	 * @since 1.1.0
	 */
	private function render_onboarding_banner(): void {

		$manager = new OnboardingManager();
		$banner  = new OnboardingBanner( $manager );

		$banner->render();
	}

	/**
	 * Render the integrations overview widget.
	 *
	 * Shows a compact list of available integrations with their status
	 * and links to the Integrations page for configuration.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Added BuddyPress icon mapping.
	 * @since 1.3.0 Added BuddyBoss icon mapping (assets/images/icons/BuddyBoss.png).
	 * @since 1.4.0 Added AffiliateWP icon mapping (assets/images/icons/AffiliateWP.png).
	 * @since 1.4.0 Added MemberPress and WS Form icon mappings.
	 *
	 * @param bool $has_api_key Whether an API key is configured.
	 */
	private function render_integrations_overview( bool $has_api_key ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$registry              = IntegrationRegistry::get_instance();
		$status                = $registry->get_status();
		$integrations          = $status['integrations'] ?? [];
		$integrations_page_url = admin_url( 'admin.php?page=activelayer-integrations' );

		// Icon map matching IntegrationsPage.
		$icon_map = [
			'WP Comments'      => 'WPComments.png',
			'WooCommerce'      => 'WooCommerceReviews.png',
			'WPForms'          => 'WPF.png',
			'Contact Form 7'   => 'contactform7.png',
			'Ninja Forms'      => 'NinjaForms.png',
			'Formidable Forms' => 'FormiForms.png',
			'Forminator'       => 'Forminator.png',
			'Fluent Forms'     => 'FluentForms.png',
			'SureForms'        => 'SureForms.png',
			'Gravity Forms'    => 'GravityForms.png',
			'Elementor Forms'  => 'ElementorForms.png',
			'BuddyPress'       => 'BuddyPress.png',
			'BuddyBoss'        => 'BuddyBoss.png',
			'AffiliateWP'      => 'AffiliateWP.png',
			'MemberPress'      => 'MemberPress.png',
			'WS Form'          => 'WSForm.png',
		];

		?>
		<div class="activelayer-dashboard-widget activelayer-dashboard-integrations">
			<div class="activelayer-dashboard-widget__header">
				<h2><?php esc_html_e( 'Integrations', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>
				<a href="<?php echo esc_url( $integrations_page_url ); ?>" class="activelayer-dashboard-widget__link">
					<?php esc_html_e( 'Manage', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
					<span class="dashicons dashicons-arrow-right-alt2"></span>
				</a>
			</div>

			<div class="activelayer-dashboard-integrations__list">
				<?php
				foreach ( $integrations as $data ) :
					$name          = $data['name'] ?? '';
					$slug          = $data['slug'] ?? '';
					$plugin_active = ! empty( $data['plugin_active'] );
					$enabled       = ! empty( $data['enabled'] );

					// Always show wp_comments; skip others if plugin not active.
					if ( $slug !== 'wp_comments' && ! $plugin_active ) {
						continue;
					}

					$is_active   = $enabled && $has_api_key;
					$badge_class = $is_active ? 'status-clean' : 'status-pending';
					$badge_text  = $is_active
						? __( 'Active', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
						: __( 'Paused', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

					$icon_file = $icon_map[ $name ] ?? '';
					$icon_url  = $icon_file ? plugin_dir_url( ACTIVELAYER_PLUGIN_FILE ) . 'assets/images/icons/' . $icon_file : '';
					?>
					<div class="activelayer-dashboard-integration-row">
						<div class="activelayer-dashboard-integration-row__info">
							<?php if ( $icon_url ) : ?>
								<img src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" class="activelayer-dashboard-integration-row__icon" />
							<?php endif; ?>
							<span class="activelayer-dashboard-integration-row__name"><?php echo esc_html( $name ); ?></span>
							<span class="status-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Upgrade to Pro banner.
	 *
	 * Only visible for free-plan users. Shows feature highlights and a
	 * CTA button linking to the pricing page with UTM tracking.
	 *
	 * @since 1.1.0
	 */
	private function render_upgrade_banner(): void {

		if ( ! UpgradeHelper::is_free_plan() ) {
			return;
		}

		$upgrade_url = UpgradeHelper::get_upgrade_url( 'dashboard_upgrade' );

		?>
		<div class="activelayer-dashboard-upgrade">
			<h3 class="activelayer-dashboard-upgrade__title">
				<?php esc_html_e( 'Upgrade to Pro to Unlock Full Spam Protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</h3>

			<ul class="activelayer-dashboard-upgrade__features">
				<?php foreach ( $this->get_pro_features() as $feature ) : ?>
					<li>
						<span class="activelayer-dashboard-upgrade__check">
							<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M11.5 5.5L6.75 10.5L4.5 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</span>
						<?php echo esc_html( $feature ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="activelayer-dashboard-upgrade__actions">
				<a href="<?php echo esc_url( $upgrade_url ); ?>" class="activelayer-dashboard-upgrade__cta" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Upgrade to Pro', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
				<a href="<?php echo esc_url( 'https://activelayer.com/pricing/?utm_campaign=plugin&utm_source=WordPress&utm_medium=dashboard_upgrade&utm_content=learn_more&utm_locale=' . get_locale() ); ?>" class="activelayer-dashboard-upgrade__learn-more" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Learn more about all features', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?> &rarr;
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the cross-promote sister plugins widget.
	 *
	 * Shows a 2x2 grid of AwesomeMotive plugins with install/activate buttons.
	 * Reuses the PluginInstaller AJAX handler for one-click installation.
	 * Caps at 4 cards and skips already-active plugins.
	 *
	 * @since 1.1.0
	 * @since 1.4.0 Detects Lite/Pro variants via PluginInstaller::get_plugin_files().
	 */
	private function render_cross_promote(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$all_plugins = get_plugins();
		$shown       = 0;
		$cards_html  = '';
		$plugin_url  = plugin_dir_url( ACTIVELAYER_PLUGIN_FILE );

		foreach ( $this->get_sister_plugins() as $plugin ) {
			if ( $shown >= self::CROSS_PROMOTE_MAX ) {
				break;
			}

			$is_installed = false;
			$is_active    = false;

			// Check every known variant (e.g. WPForms Lite and WPForms Pro).
			foreach ( PluginInstaller::get_plugin_files( $plugin['slug'] ) as $plugin_file ) {
				$is_installed = $is_installed || $this->is_plugin_installed( $plugin_file, $all_plugins );
				$is_active    = $is_active || is_plugin_active( $plugin_file );
			}

			$icon_url = $plugin_url . 'assets/images/icons/plugins/' . $plugin['icon'];

			ob_start();
			?>
			<div class="activelayer-dashboard-plugin-card">
				<div class="activelayer-dashboard-plugin-card__header">
					<div class="activelayer-dashboard-plugin-card__icon">
						<img src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $plugin['name'] ); ?>" width="24" height="24" />
					</div>
					<span class="activelayer-dashboard-plugin-card__name"><?php echo esc_html( $plugin['name'] ); ?></span>
				</div>
				<p class="activelayer-dashboard-plugin-card__desc"><?php echo esc_html( $plugin['description'] ); ?></p>
				<div class="activelayer-dashboard-plugin-card__action">
					<?php if ( $is_active ) : ?>
						<button type="button" class="button" disabled>
							<?php esc_html_e( 'Installed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</button>
					<?php elseif ( $is_installed ) : ?>
						<button type="button"
							class="button activelayer-install-plugin"
							data-plugin-slug="<?php echo esc_attr( $plugin['slug'] ); ?>">
							<?php esc_html_e( 'Activate', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</button>
					<?php else : ?>
						<button type="button"
							class="button activelayer-install-plugin"
							data-plugin-slug="<?php echo esc_attr( $plugin['slug'] ); ?>">
							<?php esc_html_e( 'Install & Activate', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
			<?php
			$cards_html .= ob_get_clean();

			++$shown;
		}

		if ( $shown === 0 ) {
			return;
		}

		?>
		<div class="activelayer-dashboard-widget activelayer-dashboard-cross-promote">
			<h2><?php esc_html_e( 'Extend Your Website', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>

			<div class="activelayer-dashboard-cross-promote__grid">
				<?php
				echo $cards_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above in the loop.
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Quick Access links widget.
	 *
	 * Shows a vertical list of useful links (docs, API, support, account)
	 * with dashicons.
	 *
	 * @since 1.1.0
	 */
	private function render_quick_access(): void {

		?>
		<div class="activelayer-dashboard-widget activelayer-dashboard-quick-access">
			<h2><?php esc_html_e( 'Quick Access', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>

			<div class="activelayer-dashboard-quick-access__list">
				<?php foreach ( $this->get_quick_access_links() as $link ) : ?>
					<a href="<?php echo esc_url( $link['url'] ); ?>" class="activelayer-dashboard-quick-access-item" target="_blank" rel="noopener noreferrer">
						<span class="dashicons <?php echo esc_attr( $link['icon'] ); ?>"></span>
						<span><?php echo esc_html( $link['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if a plugin is installed by its file path.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin_file Main plugin file path (e.g. "wpforms-lite/wpforms.php").
	 * @param array  $all_plugins Result of get_plugins().
	 *
	 * @return bool True if plugin directory exists in installed plugins.
	 */
	private function is_plugin_installed( string $plugin_file, array $all_plugins ): bool {

		return isset( $all_plugins[ $plugin_file ] );
	}
}

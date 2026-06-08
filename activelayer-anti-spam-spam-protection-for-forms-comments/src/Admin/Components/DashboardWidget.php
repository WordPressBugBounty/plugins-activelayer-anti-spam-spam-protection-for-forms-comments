<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Connect\ConnectFlow;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Storage\Storage;

/**
 * WordPress Dashboard widget controller.
 *
 * Registers and renders the ActiveLayer widget on the WordPress Dashboard.
 * Shows a connection prompt when no API key is configured, or submission
 * stats with a Chart.js line chart when connected.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 *
 * @package ActiveLayer\Admin
 */
class DashboardWidget {

	/**
	 * Widget ID used for wp_add_dashboard_widget().
	 *
	 * @since 1.1.0
	 */
	const WIDGET_ID = 'activelayer_dashboard_widget';

	/**
	 * Nonce action for AJAX requests.
	 *
	 * @since 1.1.0
	 */
	const NONCE_ACTION = 'activelayer_dash_widget';

	/**
	 * Default timespan in days.
	 *
	 * @since 1.1.0
	 */
	const DEFAULT_DAYS = 7;

	/**
	 * Allowed timespan values.
	 *
	 * @since 1.1.0
	 *
	 * @var int[]
	 */
	const ALLOWED_DAYS = [ 7, 30 ];

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.1.0
	 */
	public function hooks(): void {

		add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_activelayer_dash_widget_chart_data', [ $this, 'ajax_chart_data' ] );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * Skipped if the current user lacks manage_activelayer capability.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			esc_html__( 'ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			[ $this, 'render' ]
		);

		// Move widget to top of normal (left) column.
		global $wp_meta_boxes;

		if ( isset( $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ] ) ) {
			$widget = $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to reposition widget.

			unset( $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ] );

			$wp_meta_boxes['dashboard']['normal']['high'][ self::WIDGET_ID ] = $widget; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Render the dashboard widget content.
	 *
	 * @since 1.1.0
	 */
	public function render(): void {

		if ( SettingsHelper::has_api_key() ) {
			$this->render_connected();
		} else {
			$this->render_disconnected();
		}
	}

	/**
	 * Render the connected state: chart + stats.
	 *
	 * @since 1.1.0
	 */
	private function render_connected(): void {

		$settings_url    = admin_url( 'admin.php?page=activelayer-settings' );
		$submissions_url = admin_url( 'admin.php?page=activelayer-submissions' );

		?>
		<div class="activelayer-dash-widget activelayer-dash-widget--connected">
			<div class="activelayer-dash-widget-actions">
				<select class="activelayer-dash-widget-timespan">
					<?php foreach ( self::ALLOWED_DAYS as $days ) : ?>
						<option value="<?php echo esc_attr( $days ); ?>"<?php selected( $days, self::DEFAULT_DAYS ); ?>>
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
				<a href="<?php echo esc_url( $settings_url ); ?>"
					class="activelayer-dash-widget-settings-btn"
					aria-label="<?php esc_attr_e( 'ActiveLayer Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>">
					<span class="dashicons dashicons-admin-generic"></span>
				</a>
			</div>
			<?php
			$stats = Storage::get_instance()->get_queue_stats();

			$stats_block = new StatsBlock( $stats );

			$stats_block->render();
			?>
			<div class="activelayer-dash-widget-chart-block">
				<div class="activelayer-chart-loader">
					<span class="activelayer-chart-loader__spinner"></span>
				</div>
				<canvas class="activelayer-dash-widget-chart"></canvas>
			</div>
			<div class="activelayer-dash-widget-footer">
				<a href="<?php echo esc_url( $submissions_url ); ?>">
					<?php esc_html_e( 'View all submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the disconnected state: connection prompt.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Build register URL via AppUrlHelper.
	 * @since 1.3.0 CTA now builds a one-click Connect URL.
	 */
	private function render_disconnected(): void {

		$settings_url = admin_url( 'admin.php?page=activelayer-settings' );

		?>
		<div class="activelayer-dash-widget activelayer-dash-widget--disconnected">
			<p class="activelayer-dash-widget-message">
				<?php esc_html_e( 'Connect your site to ActiveLayer to start protecting your forms from spam.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</p>
			<div class="activelayer-dash-widget-cta">
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Connect Your Account', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
				<a href="<?php echo esc_url( ( new ConnectFlow() )->start( 'dashboard_widget', 'create_account' ) ); ?>"
					target="_blank"
					rel="noopener noreferrer"
					class="activelayer-dash-widget-cta__secondary">
					<?php esc_html_e( 'Create Free Account', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue widget assets on the dashboard page only.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( $hook !== 'index.php' ) {
			return;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		$suffix = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';

		wp_enqueue_style(
			'activelayer-admin',
			ACTIVELAYER_PLUGIN_URL . 'assets/css/admin' . $suffix . '.css',
			[],
			ACTIVELAYER_PLUGIN_VERSION
		);

		// Only enqueue chart JS when connected.
		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		wp_enqueue_script(
			'activelayer-dash-widget',
			ACTIVELAYER_PLUGIN_URL . 'assets/js/dashboard-widget' . $suffix . '.js',
			[],
			ACTIVELAYER_PLUGIN_VERSION,
			true
		);

		$daily = Storage::get_instance()->get_daily_counts( self::DEFAULT_DAYS );
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
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			]
		);
	}

	/**
	 * Handle AJAX request for chart data with a different timespan.
	 *
	 * @since 1.1.0
	 */
	public function ajax_chart_data(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ], 403 );
		}

		$days = isset( $_POST['days'] ) ? (int) wp_unslash( $_POST['days'] ) : self::DEFAULT_DAYS; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int after wp_unslash.

		if ( ! in_array( $days, self::ALLOWED_DAYS, true ) ) {
			$days = self::DEFAULT_DAYS;
		}

		$daily = Storage::get_instance()->get_daily_counts( $days );
		$stats = Storage::get_instance()->get_queue_stats();

		wp_send_json_success(
			[
				'daily' => $daily,
				'stats' => [
					'total'  => $stats['total'] ?? 0,
					'clean'  => $stats['clean'] ?? 0,
					'spam'   => $stats['spam'] ?? 0,
					'failed' => $stats['failed'] ?? 0,
				],
			]
		);
	}
}

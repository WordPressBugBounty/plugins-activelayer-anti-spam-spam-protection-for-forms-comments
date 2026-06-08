<?php

namespace ActiveLayer\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Admin\AdminPages;
use ActiveLayer\Admin\Components\PaymentMethodNotice;
use ActiveLayer\Admin\Settings\SettingsPersistor;
use ActiveLayer\Api\ApiClient;
use ActiveLayer\Connect\ConnectFlow;
use ActiveLayer\Helpers\NoticeHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Admin\Onboarding\OnboardingBanner;
use ActiveLayer\Admin\Onboarding\OnboardingManager;
use ActiveLayer\Subscription\SubscriptionStats;

/**
 * Settings Page Controller.
 *
 * Handles the settings page display and functionality.
 *
 * @since 1.0.0
 * @since 1.2.0 Moved to Pages namespace.
 * @since 1.2.0 Inlined ApiKeyFieldRenderer and SubscriptionStatsRenderer.
 *
 * @package ActiveLayer\Admin
 */
class SettingsPage {

	/**
	 * Integration registry.
	 *
	 * @since 1.0.0
	 *
	 * @var IntegrationRegistry
	 */
	private $registry;

	/**
	 * Pending notice to display on the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @var array|null
	 */
	private $notice;

	/**
	 * Settings persistor instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SettingsPersistor
	 */
	private $persistor;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Removed renderer instantiations after inlining.
	 */
	public function __construct() {

		$this->registry  = IntegrationRegistry::get_instance();
		$this->persistor = new SettingsPersistor( $this->registry );
	}

	/**
	 * Redirect legacy ?integration= settings URLs to the dedicated Integrations page.
	 *
	 * Runs on admin_init (before any output) so the redirect fires before headers
	 * are sent. Scoped to the settings screen so the ?integration= query arg is not
	 * hijacked on unrelated admin pages.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_integration(): void {

		if ( wp_doing_ajax() || ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query param for routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query param for routing.
		if ( ! isset( $_GET['integration'] ) || $page !== 'activelayer-settings' ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=activelayer-integrations' ) );
		exit;
	}

	/**
	 * Render settings page.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Connect return callback and legacy ?integration= redirect moved to admin_init.
	 */
	public function render(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		// Surface the one-time Connect result notice persisted across the PRG redirect.
		// The claim + redirect itself runs earlier on admin_init (ConnectFlow::hooks),
		// before any output, so headers are not yet sent. The Connect return is a
		// GET-only flow; skip the transient read on form saves.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Existence check only; the Connect flow is bound by a per-user PKCE verifier transient, not by this request.
		if ( empty( $_POST ) ) {
			$connect_notice = ( new ConnectFlow() )->take_notice();

			if ( $connect_notice !== null ) {
				$this->set_notice( $connect_notice['message'], $connect_notice['type'] );
			}
		}

		$this->handle_form_submission();

		$settings                     = SettingsHelper::get_global_settings();
		$api_key                      = SettingsHelper::get_api_key( $settings );
		$has_api_key                  = SettingsHelper::has_api_key( $settings );
		$logging_enabled              = SettingsHelper::is_logging_enabled( $settings );
		$sync_mode_enabled            = SettingsHelper::is_sync_mode_enabled( $settings );
		$environment_tracking_enabled = SettingsHelper::is_environment_tracking_enabled( $settings );
		$behavioral_tracking_enabled  = SettingsHelper::is_behavioral_tracking_enabled( $settings );
		$retention_days               = SettingsHelper::get_retention_days( $settings );

		AdminPages::render_header();
		$this->render_notice();

		?>
		<div class="wrap activelayer-admin-wrap activelayer-page-settings">
			<h1><?php esc_html_e( 'Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h1>
			<?php
			$onboarding_banner = new OnboardingBanner( new OnboardingManager() );

			$onboarding_banner->render();
			?>

			<form method="post" action="">
				<?php wp_nonce_field( 'activelayer_settings' ); ?>

				<!-- API Key Section -->
				<?php $this->render_api_key_field( $api_key, $has_api_key ); ?>

				<!-- Connect Payment Method Notice -->
				<?php PaymentMethodNotice::render(); ?>

				<!-- Subscription Stats -->
				<?php $this->render_subscription_stats(); ?>

				<hr />

				<!-- Advanced Settings -->
				<h2><?php esc_html_e( 'Advanced Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="sync_mode"><?php esc_html_e( 'Synchronous Mode', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></label>
							</th>
							<td>
								<label for="sync_mode">
									<input type="checkbox" id="sync_mode" name="<?php echo esc_attr( SettingsHelper::KEY_SYNC_MODE ); ?>" value="1" <?php checked( $sync_mode_enabled, true ); ?> />
									<?php esc_html_e( 'Enable synchronous spam checking', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
									<span class="activelayer-info-tooltip">
										<span class="dashicons dashicons-info-outline"></span>
										<span class="activelayer-info-tooltip__content">
											<?php esc_html_e( 'Applies to WPForms, Ninja Forms, and Formidable Forms. Other integrations (Contact Form 7, Gravity Forms, Fluent Forms, Elementor Forms, SureForms) always use synchronous checking.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
										</span>
									</span>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, submissions are checked for spam immediately during form submission. This blocks spam before emails are sent, but may slightly increase form submission time.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'When disabled (default), submissions are processed asynchronously in the background for faster form submissions.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="enable_environment_tracking"><?php esc_html_e( 'Environment Detection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></label>
							</th>
							<td>
								<label for="enable_environment_tracking">
									<input type="checkbox" id="enable_environment_tracking" name="<?php echo esc_attr( SettingsHelper::KEY_ENVIRONMENT_TRACKING ); ?>" value="1" <?php checked( $environment_tracking_enabled, true ); ?> />
									<?php esc_html_e( 'Enable environment detection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Detects headless browsers and automation frameworks (Puppeteer, Selenium, etc.) to identify bot submissions.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="enable_behavioral_tracking"><?php esc_html_e( 'Behavioral Analysis', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></label>
							</th>
							<td>
								<label for="enable_behavioral_tracking">
									<input type="checkbox" id="enable_behavioral_tracking" name="<?php echo esc_attr( SettingsHelper::KEY_BEHAVIORAL_TRACKING ); ?>" value="1" <?php checked( $behavioral_tracking_enabled, true ); ?> />
									<?php esc_html_e( 'Enable behavioral analysis', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Collects anonymous behavioral signals (mouse movements, typing patterns, scroll behavior) to improve spam detection accuracy. No personal data is stored.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</p>
							</td>
						</tr>
						<tr id="activelayer-retention-setting">
							<th scope="row">
								<label for="retention_days"><?php esc_html_e( 'Submission Retention', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></label>
							</th>
							<td>
								<select id="retention_days" name="<?php echo esc_attr( SettingsHelper::KEY_RETENTION_DAYS ); ?>">
									<option value="0" <?php selected( $retention_days, 0 ); ?>><?php esc_html_e( 'Never (keep all)', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></option>
									<option value="30" <?php selected( $retention_days, 30 ); ?>><?php esc_html_e( '30 days', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></option>
									<option value="60" <?php selected( $retention_days, 60 ); ?>><?php esc_html_e( '60 days', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></option>
									<option value="90" <?php selected( $retention_days, 90 ); ?>><?php esc_html_e( '90 days', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></option>
									<option value="180" <?php selected( $retention_days, 180 ); ?>><?php esc_html_e( '180 days', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></option>
									<option value="365" <?php selected( $retention_days, 365 ); ?>><?php esc_html_e( '1 year', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Automatically delete submissions older than the selected period. Runs daily. Set to "Never" to keep all submissions indefinitely.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</p>
							</td>
						</tr>
						<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
						<tr>
							<th scope="row">
								<label for="enable_logging"><?php esc_html_e( 'Debug Logging', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></label>
							</th>
							<td>
								<label for="enable_logging">
									<input type="checkbox" id="enable_logging" name="<?php echo esc_attr( SettingsHelper::KEY_ENABLE_LOGGING ); ?>" value="1" <?php checked( $logging_enabled, true ); ?> />
									<?php esc_html_e( 'Enable debug logging', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Logs plugin activity to database. Only available when WP_DEBUG is enabled. View logs in Anti-Spam → Logs menu.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</p>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle form submission for settings page.
	 *
	 * @since 1.0.0
	 */
	private function handle_form_submission(): void {

		if ( empty( $_POST ) || ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'activelayer_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		$notice_data = $this->persistor->save_settings( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.

		// Refresh registry to update cached settings.
		$this->registry->refresh();

		// Refresh subscription stats.
		SubscriptionStats::get_instance()->schedule_refresh();

		if ( ! empty( $notice_data['message'] ) ) {
			$this->set_notice( $notice_data['message'], $notice_data['type'] ?? 'success' );
		}
	}

	/**
	 * Store a notice to display on the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Notice message text.
	 * @param string $type    Notice type (success|warning|error|info).
	 */
	private function set_notice( string $message, string $type = 'success' ): void {

		$this->notice = [
			'message' => $message,
			'type'    => $type,
		];
	}

	/**
	 * Render the stored notice, if any.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_notice(): void {

		if ( empty( $this->notice['message'] ) ) {
			return;
		}

		$message = $this->notice['message'] ?? '';
		$type    = $this->notice['type'] ?? 'success';

		NoticeHelper::render( $message, $type, true );
	}

	/**
	 * Render the API key field section.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Read the validation record via SettingsHelper::OPTION_API_KEY_VALIDATED.
	 *
	 * @param string $api_key     Current API key value.
	 * @param bool   $has_api_key Whether an API key is configured.
	 *
	 * @return void
	 */
	private function render_api_key_field( string $api_key, bool $has_api_key ): void {

		// Check if current API key is validated.
		$api_key_validation = get_option( SettingsHelper::OPTION_API_KEY_VALIDATED, [] );
		$is_key_validated   = ! empty( $api_key_validation['is_valid'] ) &&
			! empty( $api_key_validation['key'] ) &&
			$api_key_validation['key'] === $api_key;

		// Check usage exhaustion for connected keys.
		$is_exhausted = $has_api_key && UpgradeHelper::is_usage_exhausted();
		$is_free      = $has_api_key && UpgradeHelper::is_free_plan();
		$upgrade_url  = UpgradeHelper::get_upgrade_url( 'api_key_field' );

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<!-- API Configuration -->
				<tr>
					<th scope="row">
						<label for="api_key"><?php esc_html_e( 'API Key', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></label>
					</th>
					<td>
						<div style="display: flex; gap: 8px; align-items: center; position: relative;">
							<input type="password" id="api_key" name="<?php echo esc_attr( SettingsHelper::KEY_API ); ?>" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Enter your API key...', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>" />
							<?php if ( $is_exhausted ) : ?>
								<span class="dashicons dashicons-warning" id="api-key-exhausted-indicator" style="color: #d63638; margin-left: -32px; z-index: 10;" title="<?php esc_attr_e( 'API usage limit reached', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>"></span>
							<?php elseif ( $is_key_validated ) : ?>
								<span class="dashicons dashicons-yes-alt" id="api-key-valid-indicator" style="color: #00a32a; margin-left: -32px; z-index: 10;" title="<?php esc_attr_e( 'API key is valid', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>"></span>
							<?php endif; ?>
							<?php if ( $has_api_key ) : ?>
								<?php if ( $is_exhausted ) : ?>
									<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
										<?php $is_free ? esc_html_e( 'Upgrade to Pro', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) : esc_html_e( 'Upgrade Plan', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
									</a>
								<?php endif; ?>
								<button type="submit" name="activelayer_remove_api_key" value="1" id="remove-api-key" class="button activelayer-btn-danger" onclick="return confirm('<?php echo esc_js( __( 'Remove the current API key?', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ); ?>');">
									<?php esc_html_e( 'Remove Key', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</button>
							<?php else : ?>
								<button type="button" id="verify-api-key" class="button button-secondary">
									<?php esc_html_e( 'Verify Key', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</button>
							<?php endif; ?>
						</div>
						<div id="api-key-verification-result"></div>
						<?php $this->render_api_key_status_message( $is_exhausted, $has_api_key ); ?>
						<p class="description">
							<?php
							$account_url = esc_url( 'https://app.activelayer.com/account?utm_campaign=plugin&utm_source=WordPress&utm_medium=settings_page&utm_content=api_key_account_link&utm_locale=' . get_locale() );

							printf(
								/* translators: 1: Account dashboard URL. */
								wp_kses_post( __( 'Your API key can be found in your <a href="%1$s" target="_blank" rel="noopener noreferrer">ActiveLayer Account Dashboard</a>.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ),
								esc_url( $account_url )
							);
							?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the API key status message.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $is_exhausted Whether API usage is exhausted.
	 * @param bool $has_api_key  Whether an API key is configured.
	 *
	 * @return void
	 */
	private function render_api_key_status_message( bool $is_exhausted, bool $has_api_key ): void {

		if ( $is_exhausted ) :
			?>
			<p class="api-status api-status-exhausted">
				<span class="dashicons dashicons-warning"></span>
				<?php
				printf(
					/* translators: %s: upgrade account URL. */
					wp_kses_post( __( 'Your API usage has run out!', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) )
				);
				?>
			</p>
		<?php elseif ( $has_api_key ) : ?>
			<p class="api-status api-status-connected">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'API key configured', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</p>
		<?php else : ?>
			<p class="api-status api-status-missing">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'API key required to enable spam protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</p>
			<?php
		endif;
	}

	/**
	 * Render subscription statistics section.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Inlined SubscriptionStatsRenderer body.
	 *
	 * @return void
	 */
	private function render_subscription_stats(): void {

		// Don't show subscription section if API key is not configured.
		if ( ! SettingsHelper::has_api_key() ) {
			return;
		}

		$stats = $this->get_subscription_stats();

		if ( empty( $stats ) ) {
			return;
		}

		$usage_percentage = $stats['usage_percentage'] ?? 0;
		$usage_class      = $this->get_subscription_usage_class( (float) $usage_percentage );
		$is_exhausted     = UpgradeHelper::is_usage_exhausted( $stats );
		$is_free          = UpgradeHelper::is_free_plan( $stats );
		$upgrade_url      = UpgradeHelper::get_upgrade_url( 'usage_box' );
		$period_label     = UpgradeHelper::get_period_label( $stats );

		?>
		<hr />
		<h2><?php esc_html_e( 'API Usage', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>
		<?php $this->render_subscription_section_description( $is_free ); ?>

		<div class="activelayer-subscription-stats">
			<?php $this->render_subscription_section_header( $is_free, $period_label, $upgrade_url ); ?>
			<div class="subscription-details">
				<div class="usage-bar-container">
					<div class="usage-info">
						<span class="usage-numbers <?php echo esc_attr( $usage_class ); ?>">
							<span class="usage-numbers-used"><?php echo esc_html( number_format( $stats['requests_used'] ?? 0 ) ); ?></span>
							<span class="usage-numbers-divider"> / <?php echo esc_html( number_format( $stats['requests_limit'] ?? 0 ) ); ?></span>
						</span>
						<div class="usage-stat">
							<?php echo esc_html( number_format( $usage_percentage, 0 ) ); ?>%
							<?php esc_html_e( 'Used', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</div>
					</div>
					<div class="usage-bar">
						<div class="usage-bar-fill <?php echo esc_attr( $usage_class ); ?>" style="width: <?php echo esc_attr( min( $usage_percentage, 100 ) ); ?>%"></div>
					</div>
				</div>
				<?php $this->render_subscription_usage_limit_message( $is_exhausted, $is_free ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the subscription section description paragraph.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $is_free Whether the user is on the free plan.
	 *
	 * @return void
	 */
	private function render_subscription_section_description( bool $is_free ): void {

		?>
		<p class="description">
			<?php if ( $is_free ) : ?>
				<?php esc_html_e( 'Usage credits allow you to access ActiveLayer\'s spam protection checks. These limits depend on your plan and can be increased by upgrading.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			<?php else : ?>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: bold text about monthly reset. */
						__( 'Usage credits allow you to access ActiveLayer\'s spam protection checks. These limits depend on your plan and can be increased by upgrading. <br />%s', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						'<strong>' . esc_html__( 'API usage resets every month on the 1st.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</strong>'
					)
				);
				?>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render the subscription header with title and upgrade link.
	 *
	 * @since 1.2.0
	 *
	 * @param bool   $is_free      Whether the user is on the free plan.
	 * @param string $period_label Billing period label.
	 * @param string $upgrade_url  Upgrade URL.
	 *
	 * @return void
	 */
	private function render_subscription_section_header( bool $is_free, string $period_label, string $upgrade_url ): void {

		?>
		<div class="subscription-header">
			<h3>
				<?php
				if ( ! $is_free && $period_label !== '' ) {
					printf(
						/* translators: %s: billing period, e.g. "February 2026". */
						esc_html__( '%s Usage', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						esc_html( $period_label )
					);
				} else {
					esc_html_e( 'Usage', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
				}
				?>
			</h3>
			<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" class="activelayer-upgrade-link">
				<?php esc_html_e( 'Upgrade for More', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Render the usage limit warning message when exhausted.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $is_exhausted Whether API usage is exhausted.
	 * @param bool $is_free      Whether the user is on the free plan.
	 *
	 * @return void
	 */
	private function render_subscription_usage_limit_message( bool $is_exhausted, bool $is_free ): void {

		if ( ! $is_exhausted ) {
			return;
		}

		?>
		<div class="usage-limit-message">
			<strong><?php esc_html_e( "You've hit your usage limit!", 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></strong>
			<?php if ( $is_free ) : ?>
				<?php esc_html_e( 'Spam protection will be temporarily disabled until your usage limit is increased.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Spam protection will be temporarily disabled until your usage resets next month or your limit is increased.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Retrieve subscription statistics when available.
	 *
	 * @since 1.2.0
	 *
	 * @return array Subscription stats data or empty array when unavailable.
	 */
	private function get_subscription_stats(): array {

		$subscription_stats = SubscriptionStats::get_instance();
		$stats              = $subscription_stats->get_stats();

		if ( ! ( $stats['success'] ?? false ) ) {
			return [];
		}

		return $stats;
	}

	/**
	 * Determine usage CSS class by percentage.
	 *
	 * @since 1.2.0
	 *
	 * @param float $usage_percentage Usage percent from 0 to 100.
	 *
	 * @return string CSS class.
	 */
	private function get_subscription_usage_class( float $usage_percentage ): string {

		if ( $usage_percentage >= 90 ) {
			return 'status-error';
		}

		if ( $usage_percentage >= 70 ) {
			return 'status-warning';
		}

		return 'status-success';
	}

	/**
	 * AJAX handler for API key verification.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Persist the verified key via SettingsHelper::persist_validated_key().
	 */
	public function ajax_verify_api_key(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'activelayer_verify_api_key' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ] );

			return;
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ] );

			return;
		}

		// Get API key from request.
		$api_key = isset( $_POST[ SettingsHelper::KEY_API ] ) ? sanitize_text_field( wp_unslash( $_POST[ SettingsHelper::KEY_API ] ) ) : '';
		$api_key = SettingsHelper::get_api_key(
			[
				SettingsHelper::KEY_API => $api_key,
			]
		);

		if ( $api_key === '' ) {
			wp_send_json_error( [ 'message' => __( 'Please enter an API key', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ] );

			return;
		}

		// Use ApiClient to verify the API key.
		$api_client = new ApiClient();
		$result     = $api_client->verify_key( $api_key );

		if ( $result['success'] ) {
			// Persist the key + validation record (shared with the Connect flow).
			SettingsHelper::persist_validated_key( $api_key );

			// Clear stale stats and schedule fresh fetch for the new key.
			SubscriptionStats::get_instance()->clear_cache();
			SubscriptionStats::get_instance()->schedule_refresh();

			$success_message = wp_strip_all_tags( __( 'API key verified and saved!', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );

			wp_send_json_success(
				[
					'message'   => $success_message,
					'key_saved' => true,
				]
			);
		} else {
			// Clear validation status on failure.
			delete_option( SettingsHelper::OPTION_API_KEY_VALIDATED );

			$error_message = $result['message'] ?? __( 'API key verification failed', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
			$error_message = wp_strip_all_tags( (string) $error_message );

			wp_send_json_error(
				[
					'message' => $error_message,
				]
			);
		}
	}
}

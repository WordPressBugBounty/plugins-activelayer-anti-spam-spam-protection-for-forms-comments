<?php

namespace ActiveLayer\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Admin\AdminPages;
use ActiveLayer\Admin\Onboarding\OnboardingBanner;
use ActiveLayer\Admin\Onboarding\OnboardingManager;
use ActiveLayer\Helpers\ArrayHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Integrations\FormAdminSettingsInterface;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Integrations\WooCommerce\Admin\AdminController;
use ActiveLayer\Integrations\EasyDigitalDownloads\Admin\AdminController as EddAdminController;

/**
 * Integrations Page Controller.
 *
 * Handles the dedicated Integrations admin page display and AJAX settings persistence.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Pages namespace.
 * @since 1.2.0 Removed WPCommentsSettingsPage wrapper.
 *
 * @package ActiveLayer\Admin
 */
class IntegrationsPage {

	/**
	 * Integration registry instance.
	 *
	 * @since 1.1.0
	 *
	 * @var IntegrationRegistry
	 */
	private $registry;

	/**
	 * WooCommerce umbrella admin controller.
	 *
	 * @since 1.2.0
	 *
	 * @var AdminController
	 */
	private $wc_admin;

	/**
	 * Easy Digital Downloads umbrella admin controller.
	 *
	 * @since 1.5.0
	 *
	 * @var EddAdminController
	 */
	private $edd_admin;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Instantiates WooCommerce admin controller (delegated WC-specific admin logic).
	 * @since 1.5.0 Instantiates Easy Digital Downloads admin controller (delegated EDD-specific admin logic).
	 *
	 * @param IntegrationRegistry $registry Integration registry instance.
	 */
	public function __construct( IntegrationRegistry $registry ) {

		$this->registry  = $registry;
		$this->wc_admin  = new AdminController( $registry );
		$this->edd_admin = new EddAdminController( $registry );
	}

	/**
	 * Render the integrations page.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render(): void {

		$this->render_page();
	}

	/**
	 * Handle the AJAX save_integration_settings request.
	 *
	 * Dispatches to the appropriate save routine based on the `type` parameter:
	 * - `enabled`: toggles an integration on or off.
	 * - `comments`: saves WP Comments-specific settings.
	 * - `woocommerce`: saves WooCommerce Reviews + Registration settings via AdminController.
	 * - `memberpress`: saves MemberPress paid-signup gating opt-in.
	 * - `forms`: saves per-form protection toggles for a form provider.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Added `woocommerce` type dispatched to the WooCommerce AdminController.
	 * @since 1.4.1 Added `memberpress` type.
	 * @since 1.5.0 Added `edd` type dispatched to the EDD AdminController.
	 *
	 * @return void
	 */
	public function ajax_save_settings(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Verify nonce.
		check_ajax_referer( 'activelayer_integration_settings', 'nonce' );

		// Check capability.
		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'Permission denied.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				403
			);

			return;
		}

		// Sanitize type and slug from POST.
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		switch ( $type ) {
			case 'enabled':
				$this->handle_enabled_toggle( $slug );
				break;

			case 'comments':
				$this->handle_comments_settings( $slug );
				break;

			case 'woocommerce':
				$this->wc_admin->handle_settings_save( $slug );
				break;

			case 'edd':
				$this->edd_admin->handle_settings_save( $slug );
				break;

			case 'memberpress':
				$this->handle_memberpress_settings( $slug );
				break;

			case 'forms':
				$this->handle_forms_settings( $slug );
				break;

			default:
				wp_send_json_error(
					[ 'message' => esc_html__( 'Invalid type.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
					400
				);

				return;
		}
	}

	/**
	 * Render the integrations admin page HTML.
	 *
	 * Lists all registered integrations with enable toggles, status badges,
	 * and inline expand/collapse settings panels.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function render_page(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$integrations_status = $this->registry->get_status();
		$integrations        = $integrations_status['integrations'] ?? [];
		$has_api_key         = SettingsHelper::has_api_key();

		// Filter and sort integrations for display.
		$display_integrations = $this->filter_installed_integrations( $integrations );

		// Add WPForms promotion row when plugin is not installed.
		if ( ! isset( $display_integrations['wpforms'] ) && current_user_can( 'install_plugins' ) ) {
			$display_integrations['wpforms_promo'] = [
				'name'          => 'WPForms',
				'slug'          => 'wpforms_promo',
				'plugin_active' => false,
				'enabled'       => false,
				'is_promo'      => true,
			];
		}

		$display_integrations = $this->sort_integrations_for_display( $display_integrations );

		AdminPages::render_header();

		?>
		<div class="wrap activelayer-admin-wrap activelayer-page-integrations">
			<h1><?php esc_html_e( 'Integrations', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h1>
			<?php
			$onboarding_banner = new OnboardingBanner( new OnboardingManager() );

			$onboarding_banner->render();
			?>
			<p class="description">
				<?php esc_html_e( 'Configure spam protection settings for different form providers and WordPress features.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</p>

			<?php if ( empty( $display_integrations ) ) : ?>
				<p><?php esc_html_e( 'No integrations available.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></p>
			<?php else : ?>
				<div class="activelayer-integrations-list">
					<?php foreach ( $display_integrations as $data ) : ?>
						<?php
						if ( ! empty( $data['is_promo'] ) ) {
							$this->render_promo_row( $data );
							continue;
						}

						$slug = $data['slug'];

						$this->render_integration_row( $data, $has_api_key );
						$this->render_panel( $slug, $data );
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single integration row with icon, name, badge, toggle, and configure button.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data        Integration data from registry status.
	 * @param bool  $has_api_key Whether an API key is configured.
	 *
	 * @return void
	 */
	private function render_integration_row( array $data, bool $has_api_key ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$name    = $data['name'];
		$slug    = $data['slug'];
		$enabled = $data['enabled'];

		$icon_url = $this->get_integration_icon_url( $name );

		// Badge: Active (green) only when enabled and API key is present; otherwise Paused.
		$is_active   = $enabled && $has_api_key;
		$badge_class = $is_active ? 'status-clean' : 'status-pending';
		$badge_text  = $is_active
			? __( 'Active', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			: __( 'Paused', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

		$form_admin_settings = $this->registry->get_form_admin_settings( $slug );
		$admin_url           = $this->get_integration_admin_url( $slug, $form_admin_settings );

		// Determine if this integration supports configuration.
		$has_panel    = in_array( $slug, [ 'wp_comments', 'woocommerce', 'memberpress', 'edd' ], true ) || $form_admin_settings;
		$panel_hidden = $has_panel && ! $enabled;

		$panel_id = 'activelayer-integration-panel-' . esc_attr( $slug );

		?>
		<div class="activelayer-integration-row">
			<div class="activelayer-integration-info">
				<div class="activelayer-integration-icon">
					<?php if ( ! empty( $icon_url ) ) : ?>
						<img src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
					<?php endif; ?>
				</div>
				<div class="activelayer-integration-name-wrap">
					<div class="activelayer-integration-name-row">
						<?php if ( $admin_url ) : ?>
							<a href="<?php echo esc_url( $admin_url ); ?>" class="activelayer-integration-name activelayer-integration-name-link"><?php echo esc_html( $name ); ?></a>
						<?php else : ?>
							<span class="activelayer-integration-name"><?php echo esc_html( $name ); ?></span>
						<?php endif; ?>
						<span class="status-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
					</div>
					</div>
			</div>
			<div class="activelayer-integration-actions">
				<?php if ( $has_panel ) : ?>
					<button
						type="button"
						class="activelayer-integration-configure"
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $panel_id ); ?>"
						data-slug="<?php echo esc_attr( $slug ); ?>"
						<?php
                        if ( $panel_hidden ) :
?>
style="display:none"<?php endif; ?>
					>
						<?php esc_html_e( 'Configure', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
					</button>
				<?php endif; ?>
				<label class="activelayer-integration-toggle" for="<?php echo esc_attr( $slug ); ?>_enabled">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $slug ); ?>_enabled"
						class="activelayer-integration-enable-toggle"
						data-slug="<?php echo esc_attr( $slug ); ?>"
						value="1"
						<?php checked( $enabled, true ); ?>
						<?php disabled( ! $has_api_key ); ?>
					/>
					<span class="screen-reader-text"><?php esc_html_e( 'Enable spam protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></span>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the inline settings panel for an integration.
	 *
	 * Dispatches to the appropriate panel renderer based on integration type.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Dispatch `woocommerce` slug to the WooCommerce AdminController.
	 * @since 1.5.0 Dispatch `edd` slug to the EDD AdminController.
	 *
	 * @param string $slug Integration registry slug.
	 * @param array  $data Integration data from registry status.
	 *
	 * @return void
	 */
	private function render_panel( string $slug, array $data ): void {

		$panel_id = 'activelayer-integration-panel-' . esc_attr( $slug );

		?>
		<div id="<?php echo esc_attr( $panel_id ); ?>" class="activelayer-integration-settings-panel">
			<div class="activelayer-integration-panel-inner">
				<?php
				if ( $slug === 'wp_comments' ) {
					$this->render_comments_panel( $data );
				} elseif ( $slug === 'woocommerce' ) {
					$this->wc_admin->render_panel( $data );
				} elseif ( $slug === 'edd' ) {
					$this->edd_admin->render_panel( $data );
				} elseif ( $slug === 'memberpress' ) {
					$this->render_memberpress_panel( $data );
				} else {
					$this->render_forms_panel( $slug );
				}
				?>
				<div class="activelayer-panel-feedback"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the WP Comments inline settings panel.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Added silent-discard toggle and discard-threshold rows.
	 *
	 * @param array $data Integration data including settings.
	 *
	 * @return void
	 */
	private function render_comments_panel( array $data ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$slug     = 'wp_comments';
		$settings = $data['settings'] ?? [];

		?>
		<form class="activelayer-integration-settings-form" data-slug="<?php echo esc_attr( $slug ); ?>" data-type="comments">
			<table class="form-table" role="presentation">
				<tbody>
					<?php
					/** This filter is documented in src/Integrations/WPForms/AdminSettings.php */
					if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || apply_filters( 'activelayer_show_tracking_mode', false ) ) :
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracking Mode', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="settings[tracking_mode]" value="1" <?php checked( $settings['tracking_mode'] ?? false, true ); ?> />
								<?php esc_html_e( 'Enable tracking mode (log only)', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Analyze and log comments without auto-blocking spam verdicts.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logged-in Users', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="settings[check_logged_in_users]" value="1" <?php checked( $settings['check_logged_in_users'] ?? false, true ); ?> />
								<?php esc_html_e( 'Check comments from logged-in users', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Also analyze comments from logged-in users (administrators are always excluded).', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-approve', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="settings[auto_approve_clean]" value="1" <?php checked( $settings['auto_approve_clean'] ?? true, true ); ?> />
								<?php esc_html_e( 'Auto-approve clean comments', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Automatically approve comments that are marked as clean by the spam filter.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="settings[auto_spam_detected]" value="1" <?php checked( $settings['auto_spam_detected'] ?? true, true ); ?> />
								<?php esc_html_e( 'Auto-spam detected comments', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Automatically move comments detected as spam to the spam folder.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></p>
						</td>
					</tr>
					<tr class="activelayer-comments-discard-row">
						<th scope="row"><?php esc_html_e( 'Silently Discard', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="settings[auto_delete_high_confidence_spam]" value="1" <?php checked( $settings['auto_delete_high_confidence_spam'] ?? false, true ); ?> />
								<?php esc_html_e( 'Silently delete high-confidence spam instead of storing it', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When the spam score is at or above the threshold below, the comment is removed from your site entirely (it cannot be recovered or un-spammed).', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></p>
						</td>
					</tr>
					<tr class="activelayer-comments-discard-row">
						<th scope="row"><?php esc_html_e( 'Discard Threshold', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<input type="number" name="settings[delete_spam_score_threshold]" value="<?php echo esc_attr( (int) ( $settings['delete_spam_score_threshold'] ?? 95 ) ); ?>" min="0" max="100" class="small-text" />
								<?php esc_html_e( 'Score 0–100 (default 95).', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Comment Length', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( 'Minimum:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								<input type="number" name="settings[min_comment_length]" value="<?php echo esc_attr( $settings['min_comment_length'] ?? 10 ); ?>" min="1" max="500" class="small-text" />
								<?php esc_html_e( 'characters', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
							<br><br>
							<label>
								<?php esc_html_e( 'Maximum:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								<input type="number" name="settings[max_comment_length]" value="<?php echo esc_attr( $settings['max_comment_length'] ?? 1000 ); ?>" min="100" max="5000" class="small-text" />
								<?php esc_html_e( 'characters', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Trackbacks & Pingbacks', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="settings[check_trackbacks]" value="1" <?php checked( $settings['check_trackbacks'] ?? true, true ); ?> />
								<?php esc_html_e( 'Check trackbacks for spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
							<br><br>
							<label>
								<input type="checkbox" name="settings[check_pingbacks]" value="1" <?php checked( $settings['check_pingbacks'] ?? true, true ); ?> />
								<?php esc_html_e( 'Check pingbacks for spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render the MemberPress inline settings panel.
	 *
	 * Surfaces the paid-signup gating opt-in only. The master enable toggle
	 * lives in the integration row and is preserved server-side on save.
	 *
	 * @since 1.4.1
	 *
	 * @param array $data Integration data including settings.
	 *
	 * @return void
	 */
	private function render_memberpress_panel( array $data ): void {

		$settings = $data['settings'] ?? [];

		?>
		<form class="activelayer-integration-settings-form" data-slug="memberpress" data-type="memberpress">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Paid Registrations', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="settings[block_paid_signups]" value="1" <?php checked( $settings['block_paid_signups'] ?? false, true ); ?> />
								<?php esc_html_e( 'Block spam on paid membership registrations', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Warning: MemberPress creates the account before payment is taken, so enabling this can block a legitimate purchase when the spam filter has a false positive. Free, free-trial, and fully-discounted registrations are always protected.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Handle the AJAX save request for the MemberPress panel.
	 *
	 * Persists the `block_paid_signups` opt-in while preserving the master
	 * `enabled` toggle (read from storage, never trusted from the client).
	 *
	 * @since 1.4.1
	 *
	 * @param string $slug Integration registry slug (must be 'memberpress').
	 *
	 * @return void
	 */
	private function handle_memberpress_settings( string $slug ): void {

		if ( $slug !== 'memberpress' ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'Invalid integration for MemberPress settings.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				400
			);

			return;
		}

		$integration = $this->registry->get_integration( 'memberpress' );

		if ( ! $integration || ! method_exists( $integration, 'get_admin_settings' ) ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'MemberPress integration is not available.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				400
			);

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_save_settings().
		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
			? wp_unslash( $_POST['settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$clean_settings = ArrayHelper::sanitize_recursive( $raw_settings );

		$admin_settings = $integration->get_admin_settings();
		$current        = $admin_settings->get_settings();

		// Preserve the master enable toggle (owned by the row switch); the panel
		// only controls the paid-signup opt-in.
		$admin_settings->update_settings(
			[
				'enabled'            => ! empty( $current['enabled'] ),
				'block_paid_signups' => ! empty( $clean_settings['block_paid_signups'] ),
			]
		);

		$this->registry->refresh();

		wp_send_json_success(
			array_merge(
				[ 'message' => esc_html__( 'Settings saved.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				$this->registry->build_onboarding_step_payload()
			)
		);
	}

	/**
	 * Render the per-form protection inline settings panel.
	 *
	 * @since 1.1.0
	 *
	 * @param string $slug Integration registry slug.
	 *
	 * @return void
	 */
	private function render_forms_panel( string $slug ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$admin_settings = $this->registry->get_form_admin_settings( $slug );

		if ( ! $admin_settings ) {
			return;
		}

		$forms             = $admin_settings->get_forms_list();
		$edit_url_template = $admin_settings->get_form_edit_url_template();
		$all_enabled       = ! empty( $forms ) && count( array_filter( array_column( $forms, 'enabled' ) ) ) === count( $forms );

		?>
		<form class="activelayer-integration-settings-form" data-slug="<?php echo esc_attr( $slug ); ?>" data-type="forms">
			<?php if ( empty( $forms ) ) : ?>
				<p><?php esc_html_e( 'No forms found.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></p>
				<?php
				$create_url = admin_url( $admin_settings->get_admin_page_url() );

				if ( $create_url ) :
					?>
					<p>
						<a href="<?php echo esc_url( $create_url ); ?>" class="button">
							<?php esc_html_e( 'Create a Form', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<div class="activelayer-form-panel-header">
					<strong><?php esc_html_e( 'Select which forms to protect', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></strong>
					<button type="button" class="activelayer-integration-configure activelayer-select-all-forms<?php echo $all_enabled ? ' hidden' : ''; ?>">
						<?php esc_html_e( 'Select All', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
					</button>
				</div>
				<div class="activelayer-form-protection-list">
					<?php foreach ( $forms as $form ) : ?>
						<?php $edit_url = $this->resolve_form_edit_url( $slug, $form, $edit_url_template ); ?>
						<div class="activelayer-form-protection-row">
							<span class="activelayer-form-name">
								<?php echo esc_html( $form['name'] ); ?>
								<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="activelayer-form-edit-link" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Edit', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</a>
								<?php endif; ?>
							</span>
							<label class="activelayer-integration-toggle" for="form_<?php echo esc_attr( $slug . '_' . $form['id'] ); ?>">
								<input
									type="checkbox"
									id="form_<?php echo esc_attr( $slug . '_' . $form['id'] ); ?>"
									name="forms[]"
									value="<?php echo esc_attr( $form['id'] ); ?>"
									<?php checked( $form['enabled'], true ); ?>
								/>
								<span class="screen-reader-text"><?php esc_html_e( 'Enable protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></button>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Resolve the admin edit URL for a form row on the integrations page.
	 *
	 * Elementor Forms uses hashed element IDs for per-form settings, so the
	 * edit URL must be built from the originating page_id when available.
	 * FunnelKit routes its editor by parent funnel ID rather than opt-in page
	 * post ID. Other providers continue using the per-provider URL template.
	 *
	 * @since 1.1.0
	 * @since 1.5.0 Added FunnelKit funnel-based deep link.
	 *
	 * @param string $slug              Integration registry slug.
	 * @param array  $form              Form row data.
	 * @param string $edit_url_template Provider edit URL template.
	 *
	 * @return string Edit URL or empty string when unavailable.
	 */
	private function resolve_form_edit_url( string $slug, array $form, string $edit_url_template ): string {

		if ( $slug === 'elementor_forms' ) {
			return $this->resolve_elementor_edit_url( $form );
		}

		if ( $slug === 'funnelkit' ) {
			return $this->resolve_funnelkit_edit_url( $form );
		}

		if ( $edit_url_template === '' ) {
			return '';
		}

		$form_id = (int) ( $form['id'] ?? 0 );

		if ( $form_id <= 0 ) {
			return '';
		}

		return admin_url( sprintf( $edit_url_template, $form_id ) );
	}

	/**
	 * Resolve the Elementor Forms edit URL from the page ID.
	 *
	 * @since 1.1.0
	 *
	 * @param array $form Form row data containing page_id.
	 *
	 * @return string Elementor edit URL or empty string.
	 */
	private function resolve_elementor_edit_url( array $form ): string {

		$page_id = (int) ( $form['page_id'] ?? 0 );

		if ( $page_id <= 0 ) {
			return '';
		}

		return admin_url( sprintf( 'post.php?post=%d&action=elementor', $page_id ) );
	}

	/**
	 * Resolve the FunnelKit opt-in edit URL from its parent funnel ID.
	 *
	 * FunnelKit's React admin routes by funnel (admin.php?page=bwf&path=/funnels/{id}),
	 * not by opt-in page post ID, so the deep link targets the parent funnel.
	 * Falls back to the funnels list when the funnel ID is unavailable.
	 *
	 * @since 1.5.0
	 *
	 * @param array $form Form row data containing funnel_id.
	 *
	 * @return string FunnelKit edit URL.
	 */
	private function resolve_funnelkit_edit_url( array $form ): string {

		$funnel_id = (int) ( $form['funnel_id'] ?? 0 );

		if ( $funnel_id <= 0 ) {
			return admin_url( 'admin.php?page=bwf&path=/funnels' );
		}

		return admin_url( 'admin.php?page=bwf&path=/funnels/' . $funnel_id );
	}

	/**
	 * Render a promotional integration row.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data Promotion row data.
	 *
	 * @return void
	 */
	private function render_promo_row( array $data ): void {

		$name     = $data['name'];
		$icon_url = $this->get_integration_icon_url( $name );

		?>
		<div class="activelayer-integration-row">
			<div class="activelayer-integration-info">
				<div class="activelayer-integration-icon">
					<?php if ( ! empty( $icon_url ) ) : ?>
						<img src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
					<?php endif; ?>
				</div>
				<div class="activelayer-integration-name-wrap">
					<div class="activelayer-integration-name-row">
						<span class="activelayer-integration-name"><?php echo esc_html( $name ); ?></span>
						<span class="status-badge status-recommended"><?php esc_html_e( 'Recommended', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></span>
					</div>
					<span class="activelayer-integration-description">
						<?php esc_html_e( 'The #1 form builder for WordPress, now with AI spam protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
					</span>
				</div>
			</div>
			<div class="activelayer-integration-actions">
				<button type="button" class="activelayer-integration-configure activelayer-install-plugin" data-plugin-slug="wpforms-lite">
					<?php esc_html_e( 'Install & Activate', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Get icon URL for an integration.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Added BuddyPress icon mapping.
	 * @since 1.3.0 Added BuddyBoss icon mapping (assets/images/icons/BuddyBoss.png).
	 * @since 1.4.0 Added AffiliateWP icon mapping (assets/images/icons/AffiliateWP.png).
	 * @since 1.4.0 Added MemberPress and WS Form icon mappings.
	 * @since 1.5.0 Added FunnelKit icon mapping.
	 *
	 * @param string $name Integration name.
	 *
	 * @return string Icon URL or empty string.
	 */
	private function get_integration_icon_url( string $name ): string {

		$icon_map = [
			'WP Comments'            => 'WPComments.png',
			'WooCommerce'            => 'WooCommerceReviews.png',
			'WPForms'                => 'WPF.png',
			'Contact Form 7'         => 'contactform7.png',
			'Ninja Forms'            => 'NinjaForms.png',
			'Formidable Forms'       => 'FormiForms.png',
			'Forminator'             => 'Forminator.png',
			'Fluent Forms'           => 'FluentForms.png',
			'SureForms'              => 'SureForms.png',
			'Gravity Forms'          => 'GravityForms.png',
			'Elementor Forms'        => 'ElementorForms.png',
			'BuddyPress'             => 'BuddyPress.png',
			'BuddyBoss'              => 'BuddyBoss.png',
			'AffiliateWP'            => 'AffiliateWP.png',
			'MemberPress'            => 'MemberPress.png',
			'WS Form'                => 'WSForm.png',
			'FunnelKit'              => 'FunnelKit.png',
			'Easy Digital Downloads' => 'EasyDigitalDownloads.png',
		];

		$icon_file = $icon_map[ $name ] ?? '';

		if ( empty( $icon_file ) ) {
			return '';
		}

		return plugin_dir_url( ACTIVELAYER_PLUGIN_FILE ) . 'assets/images/icons/' . $icon_file;
	}

	/**
	 * Get the admin page URL for an integration's plugin.
	 *
	 * @since 1.1.0
	 *
	 * @param string                          $slug           Integration slug.
	 * @param FormAdminSettingsInterface|null $admin_settings Pre-resolved admin settings.
	 *
	 * @return string Admin URL or empty string.
	 */
	private function get_integration_admin_url( string $slug, ?FormAdminSettingsInterface $admin_settings ): string {

		if ( $slug === 'wp_comments' ) {
			return admin_url( 'edit-comments.php' );
		}

		if ( ! $admin_settings ) {
			return '';
		}

		return admin_url( $admin_settings->get_admin_page_url() );
	}

	/**
	 * Filter integrations to only installed or always-available ones.
	 *
	 * @since 1.1.0
	 *
	 * @param array $integrations Integration metadata.
	 *
	 * @return array Filtered integrations list.
	 */
	private function filter_installed_integrations( array $integrations ): array {

		return array_filter(
			$integrations,
			static function ( $data ) {
				$slug = $data['slug'] ?? '';

				return $slug === 'wp_comments' || ! empty( $data['plugin_active'] );
			}
		);
	}

	/**
	 * Sort integrations: WPForms first, WP Comments last, rest alphabetically.
	 *
	 * @since 1.1.0
	 *
	 * @param array $integrations Integrations to sort.
	 *
	 * @return array Sorted integrations.
	 */
	private function sort_integrations_for_display( array $integrations ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		usort(
			$integrations,
			static function ( $a, $b ) {
				$slug_a = $a['slug'] ?? '';
				$slug_b = $b['slug'] ?? '';

				$is_wpforms_a = ( $slug_a === 'wpforms' || $slug_a === 'wpforms_promo' );
				$is_wpforms_b = ( $slug_b === 'wpforms' || $slug_b === 'wpforms_promo' );

				if ( $is_wpforms_a && $is_wpforms_b ) {
					return 0;
				}

				if ( $is_wpforms_a ) {
					return -1;
				}

				if ( $is_wpforms_b ) {
					return 1;
				}

				if ( $slug_a === 'wp_comments' ) {
					return 1;
				}

				if ( $slug_b === 'wp_comments' ) {
					return -1;
				}

				return strcasecmp( $a['name'] ?? '', $b['name'] ?? '' );
			}
		);

		return $integrations;
	}

	/**
	 * Toggle an integration's enabled state.
	 *
	 * Reads the posted `enabled` flag, merges it into the integration's stored
	 * option, persists the change, and refreshes the registry.
	 *
	 * The WooCommerce umbrella has no own settings option; its row toggle
	 * cascades the new state to both sub-integrations (Reviews and
	 * Registration) so `is_setting_enabled()` (OR of subs) stays in sync.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Cascade WooCommerce row toggle to wc_reviews + wc_registration sub options.
	 * @since 1.2.0 Delegate WooCommerce cascade to AdminController::cascade_row_toggle().
	 * @since 1.5.0 Cascade EDD row toggle to edd_reviews + edd_registration via AdminController::cascade_row_toggle().
	 *
	 * @param string $slug Integration registry slug.
	 *
	 * @return void
	 */
	private function handle_enabled_toggle( string $slug ): void {

		$enabled = ! empty( $_POST['enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified.

		$integration = $this->registry->get_integration( $slug );

		if ( ! $integration ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'Integration not found.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				400
			);

			return;
		}

		if ( $slug === 'woocommerce' ) {
			$this->wc_admin->cascade_row_toggle( $enabled );
		} elseif ( $slug === 'edd' ) {
			$this->edd_admin->cascade_row_toggle( $enabled );
		} else {
			$option_key = $integration->get_option_key();
			$settings   = get_option( $option_key, [] );

			if ( ! is_array( $settings ) ) {
				$settings = [];
			}

			$settings['enabled'] = $enabled;

			update_option( $option_key, $settings );
		}

		// Refresh registry to reflect the new enabled state.
		$this->registry->refresh();

		wp_send_json_success(
			array_merge(
				[ 'message' => esc_html__( 'Settings saved.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				$this->registry->build_onboarding_step_payload()
			)
		);
	}

	/**
	 * Save WP Comments integration settings.
	 *
	 * Only valid for the `wp_comments` slug. Passes the sanitized settings
	 * directly to the Comments AdminSettings::update_comment_settings().
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Calls Comments AdminSettings directly instead of via WPCommentsSettingsPage wrapper.
	 *
	 * @param string $slug Integration registry slug (must be 'wp_comments').
	 *
	 * @return void
	 */
	private function handle_comments_settings( string $slug ): void {

		if ( $slug !== 'wp_comments' ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'Invalid integration for comment settings.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				400
			);

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_save_settings().
		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
			? wp_unslash( $_POST['settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$integration_settings = ArrayHelper::sanitize_recursive( $raw_settings );

		$integration = $this->registry->get_integration( 'wp_comments' );

		if ( $integration && method_exists( $integration, 'get_admin_settings' ) ) {
			$integration->get_admin_settings()->update_comment_settings( $integration_settings );
		}

		// Refresh registry to apply updated settings.
		$this->registry->refresh();

		wp_send_json_success(
			array_merge(
				[ 'message' => esc_html__( 'Settings saved.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				$this->registry->build_onboarding_step_payload()
			)
		);
	}

	/**
	 * Save per-form protection settings for a form provider integration.
	 *
	 * Enumerates all forms via FormAdminSettingsInterface::get_forms_list(),
	 * marks forms present in the posted list as enabled, and marks absent
	 * forms as disabled.
	 *
	 * @since 1.1.0
	 *
	 * @param string $slug Integration registry slug.
	 *
	 * @return void
	 */
	private function handle_forms_settings( string $slug ): void {

		if ( $slug === '' ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'Integration slug is required.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				400
			);

			return;
		}

		$admin_settings = $this->registry->get_form_admin_settings( $slug );

		if ( ! $admin_settings ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'Integration not found or does not support per-form settings.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				400
			);

			return;
		}

		// Build a lookup map of posted form IDs for O(1) membership checks.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_save_settings().
		$raw_forms = isset( $_POST['forms'] ) && is_array( $_POST['forms'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['forms'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$posted_forms = [];

		foreach ( $raw_forms as $sanitized_id ) {
			$posted_forms[ $sanitized_id ] = true;
		}

		// Iterate all known forms and save each protection state.
		$all_forms = $admin_settings->get_forms_list();

		foreach ( $all_forms as $form ) {
			$form_id = (int) $form['id'];
			$enabled = isset( $posted_forms[ (string) $form_id ] );

			$admin_settings->save_form_protection( $form_id, $enabled );
		}

		// Refresh registry to apply updated per-form settings.
		$this->registry->refresh();

		wp_send_json_success(
			array_merge(
				[ 'message' => esc_html__( 'Settings saved.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				$this->registry->build_onboarding_step_payload()
			)
		);
	}
}

<?php

namespace ActiveLayer\Integrations\EasyDigitalDownloads\Admin;

use ActiveLayer\Helpers\ArrayHelper;
use ActiveLayer\Integrations\EasyDigitalDownloads\EasyDigitalDownloadsIntegration;
use ActiveLayer\Integrations\IntegrationRegistry;
use LogicException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easy Digital Downloads admin controller.
 *
 * Hosts the EDD-specific bits of the Integrations admin page: rendering the
 * Reviews + Registration panel, saving the AJAX settings payload, and
 * cascading the row toggle to both sub-options.
 *
 * Extracted from `IntegrationsPage` to keep that controller a thin AJAX
 * router; EDD admin logic lives next to the integration it serves.
 *
 * @since 1.5.0
 *
 * @package ActiveLayer\Integrations\EasyDigitalDownloads\Admin
 */
final class AdminController {

	/**
	 * Integration registry instance.
	 *
	 * @since 1.5.0
	 *
	 * @var IntegrationRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param IntegrationRegistry $registry Integration registry instance.
	 */
	public function __construct( IntegrationRegistry $registry ) {

		$this->registry = $registry;
	}

	/**
	 * Render the EDD inline settings panel (Reviews + Registration sections).
	 *
	 * Includes the silent-discard toggle and discard-threshold rows under
	 * Product Reviews.
	 *
	 * @since 1.5.0
	 *
	 * @param array $data Integration data including aggregated sub-settings.
	 *
	 * @return void
	 */
	public function render_panel( array $data ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$settings_all      = $data['settings'] ?? [];
		$reviews_settings  = $settings_all['reviews'] ?? [];
		$register_settings = $settings_all['registration'] ?? [];

		?>
		<form class="activelayer-integration-settings-form" data-slug="edd" data-type="edd">
			<div class="activelayer-wc-section activelayer-wc-section-reviews">
				<h3><?php esc_html_e( 'Product Reviews', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h3>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="settings[reviews][enabled]" value="1" <?php checked( $reviews_settings['enabled'] ?? false, true ); ?> />
									<?php esc_html_e( 'Enable spam detection for product reviews', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Logged-in Users', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="settings[reviews][check_logged_in_users]" value="1" <?php checked( $reviews_settings['check_logged_in_users'] ?? false, true ); ?> />
									<?php esc_html_e( 'Check reviews from logged-in users', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Verified Owners', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="settings[reviews][check_verified_owners]" value="1" <?php checked( $reviews_settings['check_verified_owners'] ?? false, true ); ?> />
									<?php esc_html_e( 'Check reviews from verified purchasers', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="settings[reviews][auto_spam_detected]" value="1" <?php checked( $reviews_settings['auto_spam_detected'] ?? true, true ); ?> />
									<?php esc_html_e( 'Auto-spam detected reviews', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
							</td>
						</tr>
						<tr class="activelayer-wc-reviews-discard-row">
							<th scope="row"><?php esc_html_e( 'Silently Discard', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="settings[reviews][auto_delete_high_confidence_spam]" value="1" <?php checked( $reviews_settings['auto_delete_high_confidence_spam'] ?? false, true ); ?> />
									<?php esc_html_e( 'Silently delete high-confidence spam reviews instead of storing them', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When the spam score is at or above the threshold below, the review is removed from your site entirely (it cannot be recovered or un-spammed).', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></p>
							</td>
						</tr>
						<tr class="activelayer-wc-reviews-discard-row">
							<th scope="row"><?php esc_html_e( 'Discard Threshold', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<td>
								<label>
									<input type="number" name="settings[reviews][delete_spam_score_threshold]" value="<?php echo esc_attr( (int) ( $reviews_settings['delete_spam_score_threshold'] ?? 95 ) ); ?>" min="1" max="100" class="small-text" />
									<?php esc_html_e( 'Score 0–100 (default 95).', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="activelayer-wc-section activelayer-wc-section-registration">
				<h3><?php esc_html_e( 'Customer Registration', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h3>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="settings[registration][enabled]" value="1" <?php checked( $register_settings['enabled'] ?? false, true ); ?> />
									<?php esc_html_e( 'Enable spam detection for customer registration', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Handle the AJAX save request for the EDD umbrella panel.
	 *
	 * Reads `$_POST['settings']`, sanitizes recursively, splits into
	 * `reviews` and `registration` payloads, delegates persistence to the
	 * umbrella's `save_settings()`, refreshes the registry, and emits the
	 * JSON response via `wp_send_json_*`.
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug Integration registry slug (must be 'edd').
	 *
	 * @return void
	 */
	public function handle_settings_save( string $slug ): void {

		if ( $slug !== 'edd' ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'Invalid integration for Easy Digital Downloads settings.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				400
			);

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in IntegrationsPage::ajax_save_settings().
		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
			? wp_unslash( $_POST['settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$clean_settings = ArrayHelper::sanitize_recursive( $raw_settings );

		$reviews_settings      = isset( $clean_settings['reviews'] ) && is_array( $clean_settings['reviews'] ) ? $clean_settings['reviews'] : [];
		$registration_settings = isset( $clean_settings['registration'] ) && is_array( $clean_settings['registration'] ) ? $clean_settings['registration'] : [];

		$umbrella = $this->resolve_umbrella();

		if ( ! $umbrella ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'Easy Digital Downloads integration is not available.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				400
			);

			return;
		}

		$umbrella->save_settings( $reviews_settings, $registration_settings );

		$this->registry->refresh();

		wp_send_json_success(
			array_merge(
				[ 'message' => esc_html__( 'Settings saved.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ],
				$this->registry->build_onboarding_step_payload()
			)
		);
	}

	/**
	 * Cascade the umbrella's master row toggle to both sub-options.
	 *
	 * Delegates to `EasyDigitalDownloadsIntegration::cascade_enabled()`, which
	 * owns the option-key access for both Reviews and Registration
	 * sub-integrations.
	 *
	 * @since 1.5.0
	 *
	 * @param bool $enabled New enabled state from the row toggle.
	 *
	 * @throws LogicException When the umbrella integration is not registered —
	 *                        callers are expected to gate on `wp_ajax` dispatch
	 *                        for `type=enabled, slug=edd`, which only fires when
	 *                        the umbrella is present in the registry.
	 *
	 * @return void
	 */
	public function cascade_row_toggle( bool $enabled ): void {

		$umbrella = $this->resolve_umbrella();

		if ( ! $umbrella ) {
			throw new LogicException( 'Easy Digital Downloads umbrella integration missing while cascading row toggle.' );
		}

		$umbrella->cascade_enabled( $enabled );
	}

	/**
	 * Resolve the EDD umbrella integration from the registry.
	 *
	 * @since 1.5.0
	 *
	 * @return EasyDigitalDownloadsIntegration|null
	 */
	private function resolve_umbrella(): ?EasyDigitalDownloadsIntegration {

		$integration = $this->registry->get_integration( 'edd' );

		return $integration instanceof EasyDigitalDownloadsIntegration ? $integration : null;
	}
}

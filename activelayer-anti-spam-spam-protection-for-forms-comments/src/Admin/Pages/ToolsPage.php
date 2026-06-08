<?php

namespace ActiveLayer\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Admin\AdminPages;
use ActiveLayer\Admin\Onboarding\OnboardingBanner;
use ActiveLayer\Admin\Onboarding\OnboardingManager;
use ActiveLayer\Helpers\NoticeHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Storage\Storage;

/**
 * Tools Page for maintenance operations.
 *
 * @since 1.0.0
 * @since 1.2.0 Moved to Pages namespace.
 */
class ToolsPage {

	/**
	 * Available retention period options in days.
	 *
	 * @since 1.0.0
	 *
	 * @var int[]
	 */
	private const RETENTION_DAYS = [ 7, 15, 30, 90 ];

	/**
	 * Notice to display after form submission.
	 *
	 * @since 1.0.0
	 *
	 * @var array|null
	 */
	private $notice;

	/**
	 * Render tools page.
	 *
	 * @since 1.0.0
	 */
	public function render(): void {

		$this->handle_form_submission();

		AdminPages::render_header();
		?>
		<div class="wrap activelayer-admin-wrap activelayer-page-tools">
			<h1><?php esc_html_e( 'Tools', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h1>
			<?php
			$onboarding_banner = new OnboardingBanner( new OnboardingManager() );

			$onboarding_banner->render();
			?>

			<?php $this->render_notice(); ?>

			<div class="card activelayer-tool-card">
				<h2><?php esc_html_e( 'Bulk Delete Old Submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Delete all submissions older than the selected number of days. This helps prevent database bloat for high-volume sites.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</p>

				<form method="post" class="activelayer-tool-form">
					<?php wp_nonce_field( 'activelayer_tools_bulk_delete' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="retention_days"><?php esc_html_e( 'Delete submissions older than', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></label>
							</th>
							<td>
								<select name="retention_days" id="retention_days">
									<?php foreach ( self::RETENTION_DAYS as $days ) : ?>
										<option value="<?php echo esc_attr( $days ); ?>">
											<?php
											printf(
												/* translators: %d: number of days. */
												esc_html( _n( '%d day', '%d days', $days, 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ),
												(int) $days
											);
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button
							type="submit"
							name="activelayer_bulk_delete"
							class="button button-primary"
							onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete all submissions older than the selected period? This action cannot be undone.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ); ?>')"
						>
							<?php esc_html_e( 'Delete Old Submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</button>
					</p>
				</form>

				<hr />
				<p class="description">
					<?php
					$retention_days = SettingsHelper::get_retention_days();

					if ( $retention_days > 0 ) {
						printf(
							/* translators: %d: number of days for retention policy. */
							esc_html__( 'Submissions are automatically deleted after %d days.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
							(int) $retention_days
						);
					} else {
						esc_html_e( 'Automatic deletion is disabled. Submissions are kept indefinitely.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
					}
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=activelayer-settings#activelayer-retention-setting' ) ); ?>">
						<?php esc_html_e( 'Change retention settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
					</a>
				</p>
			</div>

			<div class="card activelayer-tool-card">
				<h2><?php esc_html_e( 'Empty Trash', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Permanently delete all trashed submissions. This action cannot be undone.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</p>
				<form method="post" class="activelayer-tool-form">
					<?php wp_nonce_field( 'activelayer_tools_empty_trash' ); ?>
					<p class="submit">
						<button
							type="submit"
							name="activelayer_empty_trash"
							class="button button-secondary"
							onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to permanently delete all trashed submissions? This action cannot be undone.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ); ?>')"
						>
							<?php esc_html_e( 'Empty Trash', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</button>
					</p>
				</form>
			</div>

			<div class="card activelayer-tool-card">
				<h2><?php esc_html_e( 'Delete All Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Permanently delete all submissions marked as spam. This action cannot be undone.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</p>
				<form method="post" class="activelayer-tool-form">
					<?php wp_nonce_field( 'activelayer_tools_delete_spam' ); ?>
					<p class="submit">
						<button
							type="submit"
							name="activelayer_delete_all_spam"
							class="button button-secondary"
							onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to permanently delete all spam submissions? This action cannot be undone.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ); ?>')"
						>
							<?php esc_html_e( 'Delete All Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</button>
					</p>
				</form>
			</div>

		</div>
		<?php
	}

	/**
	 * Handle form submission for tools page actions.
	 *
	 * @since 1.0.0
	 */
	private function handle_form_submission(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; nonce verified in each handler.
		if ( empty( $_POST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; nonce verified in handle_bulk_delete().
		if ( isset( $_POST['activelayer_bulk_delete'] ) ) {
			$this->handle_bulk_delete();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; nonce verified in handle_empty_trash().
		if ( isset( $_POST['activelayer_empty_trash'] ) ) {
			$this->handle_empty_trash();

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; nonce verified in handle_delete_all_spam().
		if ( isset( $_POST['activelayer_delete_all_spam'] ) ) {
			$this->handle_delete_all_spam();

			return;
		}
	}

	/**
	 * Handle bulk delete by age action.
	 *
	 * @since 1.0.0
	 */
	private function handle_bulk_delete(): void {

		if ( ! check_admin_referer( 'activelayer_tools_bulk_delete' ) ) {
			$this->notice = [
				'type'    => 'error',
				'message' => __( 'Security check failed. Please try again.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];

			return;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			$this->notice = [
				'type'    => 'error',
				'message' => __( 'You do not have permission to perform this action.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];

			return;
		}

		$days = isset( $_POST['retention_days'] ) ? absint( wp_unslash( $_POST['retention_days'] ) ) : 0;

		if ( ! in_array( $days, self::RETENTION_DAYS, true ) ) {
			$this->notice = [
				'type'    => 'error',
				'message' => __( 'Invalid retention period selected.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];

			return;
		}

		$deleted_count = Storage::get_instance()->delete_older_than( $days );

		$this->notice = [
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %d: number of deleted submissions. */
				_n(
					'%d submission deleted successfully.',
					'%d submissions deleted successfully.',
					$deleted_count,
					'activelayer-anti-spam-spam-protection-for-forms-comments'
				),
				$deleted_count
			),
		];
	}

	/**
	 * Handle empty trash action.
	 *
	 * @since 1.1.0
	 */
	private function handle_empty_trash(): void {

		if ( ! check_admin_referer( 'activelayer_tools_empty_trash' ) ) {
			$this->notice = [
				'type'    => 'error',
				'message' => __( 'Security check failed. Please try again.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];

			return;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			$this->notice = [
				'type'    => 'error',
				'message' => __( 'You do not have permission to perform this action.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];

			return;
		}

		$deleted_count = Storage::get_instance()->delete_by_status( 'trash' );

		$this->notice = [
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %d: number of deleted submissions. */
				_n(
					'%d trashed submission deleted.',
					'%d trashed submissions deleted.',
					$deleted_count,
					'activelayer-anti-spam-spam-protection-for-forms-comments'
				),
				$deleted_count
			),
		];
	}

	/**
	 * Handle delete all spam action.
	 *
	 * @since 1.1.0
	 */
	private function handle_delete_all_spam(): void {

		if ( ! check_admin_referer( 'activelayer_tools_delete_spam' ) ) {
			$this->notice = [
				'type'    => 'error',
				'message' => __( 'Security check failed. Please try again.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];

			return;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			$this->notice = [
				'type'    => 'error',
				'message' => __( 'You do not have permission to perform this action.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];

			return;
		}

		$deleted_count = Storage::get_instance()->delete_by_status( 'spam' );

		$this->notice = [
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %d: number of deleted submissions. */
				_n(
					'%d spam submission deleted.',
					'%d spam submissions deleted.',
					$deleted_count,
					'activelayer-anti-spam-spam-protection-for-forms-comments'
				),
				$deleted_count
			),
		];
	}

	/**
	 * Render admin notice if set.
	 *
	 * @since 1.0.0
	 */
	private function render_notice(): void {

		if ( empty( $this->notice ) ) {
			return;
		}

		NoticeHelper::render(
			$this->notice['message'],
			$this->notice['type'],
			false
		);
	}
}

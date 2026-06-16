<?php

namespace ActiveLayer\Admin\Components;

use ActiveLayer\Admin\AdminPages;
use ActiveLayer\Helpers\FormDisplayResolver;
use ActiveLayer\Integrations\IntegrationRegistry;
use ActiveLayer\Storage\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single Submission View.
 *
 * Renders the detail page for a single submission, showing form data,
 * API response details, and action buttons (Mark Clean/Spam, Trash).
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 *
 * @package ActiveLayer\Admin
 */
class SingleSubmissionView {

	/**
	 * Storage instance.
	 *
	 * @since 1.1.0
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {

		$this->storage = Storage::get_instance();
	}

	/**
	 * Render single submission view.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID to view.
	 */
	public function render( string $submission_id ): void {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		$submission = $this->storage->find( $submission_id );

		if ( ! $submission ) {
			wp_die( esc_html__( 'Submission not found.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		AdminPages::render_header();
		?>
		<div class="wrap activelayer-admin-wrap activelayer-page-submissions">
			<h1><?php esc_html_e( 'View Submission', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h1>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=activelayer-submissions' ) ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back to Submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
			</p>

			<div class="activelayer-submission-details">
				<!-- Main Content -->
				<div class="activelayer-submission-main">
					<?php $this->render_form_data_section( $submission ); ?>
					<?php $this->render_api_response_section( $submission ); ?>
				</div>

				<!-- Sidebar Meta Box -->
				<div class="activelayer-submission-sidebar">
					<?php $this->render_sidebar( $submission ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render form data section.
	 *
	 * @since 1.1.0
	 *
	 * @param array $submission Submission data.
	 */
	private function render_form_data_section( array $submission ): void {

		$form_data = $submission['form_data'] ?? [];
		?>
		<div class="activelayer-detail-section">
			<div class="section-header">
				<h3><?php esc_html_e( 'Submission', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h3>
			</div>
			<div class="section-body">
				<?php
				$hidden_keys = [ 'context' ];

				foreach ( $form_data as $key => $value ) :
					if ( in_array( $key, $hidden_keys, true ) ) {
						continue;
					}

					if ( is_array( $value ) || is_object( $value ) ) {
						$value = wp_json_encode( $value, JSON_PRETTY_PRINT );
					}

					if ( empty( $value ) ) {
						continue;
					}
					?>
				<div class="detail-row">
					<div class="detail-label"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?></div>
					<div class="detail-value"><pre><?php echo esc_html( $value ); ?></pre></div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render API response section.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Read spam score from `total_score` (API field) instead of `score`.
	 * @since 1.2.0 Show error details for failed submissions.
	 *
	 * @param array $submission Submission data.
	 */
	private function render_api_response_section( array $submission ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$api_response = $submission['api_response'] ?? null;

		if ( is_string( $api_response ) ) {
			$api_response = json_decode( $api_response, true );
		}

		if ( ! is_array( $api_response ) ) {
			$api_response = [];
		}

		$is_failed = isset( $submission['status'] ) && $submission['status'] === 'failed';

		if ( empty( $api_response ) && ! $is_failed ) {
			return;
		}

		$error_message = '';

		if ( $is_failed ) {
			$error_message = (string) ( $api_response['error'] ?? $api_response['message'] ?? '' );

			if ( $error_message === '' ) {
				$error_message = __( 'The API request failed. No additional details are available for this submission.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
			}
		}

		?>
		<div class="activelayer-detail-section">
			<div class="section-header">
				<h3><?php esc_html_e( 'API Response', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h3>
			</div>
			<div class="section-body">
				<?php if ( $is_failed ) : ?>
				<div class="detail-row">
					<div class="detail-label"><?php esc_html_e( 'Verdict', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></div>
					<div class="detail-value">
						<span class="status-badge status-failed">
							<?php esc_html_e( 'Failed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
						</span>
					</div>
				</div>
				<div class="detail-row">
					<div class="detail-label"><?php esc_html_e( 'Error', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></div>
					<div class="detail-value"><code><?php echo esc_html( $error_message ); ?></code></div>
				</div>
				<?php else : ?>
					<?php if ( ! empty( $api_response['detection_id'] ) ) : ?>
					<div class="detail-row">
						<div class="detail-label"><?php esc_html_e( 'Detection ID', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></div>
						<div class="detail-value"><code><?php echo esc_html( $api_response['detection_id'] ); ?></code></div>
					</div>
					<?php endif; ?>
					<?php if ( isset( $api_response['is_spam'] ) ) : ?>
					<div class="detail-row">
						<div class="detail-label"><?php esc_html_e( 'Verdict', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></div>
						<div class="detail-value">
							<span class="status-badge <?php echo esc_attr( $api_response['is_spam'] ? 'status-spam' : 'status-clean' ); ?>">
								<?php echo $api_response['is_spam'] ? esc_html__( 'Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) : esc_html__( 'Clean', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</span>
						</div>
					</div>
					<?php endif; ?>
					<?php if ( isset( $api_response['total_score'] ) ) : ?>
					<div class="detail-row">
						<div class="detail-label"><?php esc_html_e( 'Score', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></div>
						<div class="detail-value"><?php echo esc_html( $api_response['total_score'] ); ?></div>
					</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sidebar with metadata and action buttons.
	 *
	 * @since 1.1.0
	 * @since 1.4.0 Added memberpress to the Member: provider label branch.
	 *
	 * @param array $submission Submission data.
	 */
	private function render_sidebar( array $submission ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		?>
		<div id="submitdiv" class="postbox">
			<div class="section-header">
				<h3><?php esc_html_e( 'Details', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h3>
			</div>
			<div class="inside">
				<div class="submitbox">
					<div id="minor-publishing">
						<div class="misc-pub-section">
							<span class="dashicons dashicons-admin-post"></span>
							<?php esc_html_e( 'ID:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							<strong><?php echo esc_html( '#' . $submission['id'] ); ?></strong>
						</div>
						<div class="misc-pub-section">
							<span class="dashicons dashicons-calendar-alt"></span>
							<?php esc_html_e( 'Submitted:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							<strong><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission['created_at'] ) ); ?></strong>
						</div>
						<?php if ( ! empty( $submission['processed_at'] ) ) : ?>
						<div class="misc-pub-section">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Processed:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							<strong><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission['processed_at'] ) ); ?></strong>
						</div>
						<?php endif; ?>
						<div class="misc-pub-section">
							<span class="dashicons dashicons-admin-plugins"></span>
							<?php esc_html_e( 'Provider:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							<strong><?php echo esc_html( IntegrationRegistry::get_provider_display_name( $submission['provider'] ) ); ?></strong>
						</div>
						<div class="misc-pub-section">
							<span class="dashicons dashicons-forms"></span>
							<?php
							$form_display = FormDisplayResolver::resolve_name( $submission );
							$edit_url     = FormDisplayResolver::resolve_edit_url( $submission );

							if ( $submission['provider'] === 'wp_comments' ) {
								esc_html_e( 'Post:', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
							} elseif ( $submission['provider'] === 'wc_reviews' ) {
								esc_html_e( 'Product:', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
							} elseif ( $submission['provider'] === 'wc_registration' ) {
								esc_html_e( 'Customer:', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
							} elseif ( $submission['provider'] === 'buddypress' || $submission['provider'] === 'buddyboss' || $submission['provider'] === 'memberpress' ) {
								esc_html_e( 'Member:', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
							} elseif ( $submission['provider'] === 'affiliatewp' ) {
								esc_html_e( 'Affiliate:', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
							} else {
								esc_html_e( 'Form:', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
							}
							?>
							<strong>
								<?php if ( $edit_url !== '' ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $form_display ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $form_display ); ?>
								<?php endif; ?>
							</strong>
						</div>
						<div class="misc-pub-section misc-pub-section-last">
							<span class="dashicons dashicons-flag"></span>
							<?php esc_html_e( 'Status:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							<strong class="status-text status-text-<?php echo esc_attr( $submission['status'] ); ?>">
								<?php echo esc_html( ucfirst( $submission['status'] ) ); ?>
							</strong>
						</div>
					</div>

					<div id="major-publishing-actions">
						<div id="delete-action">
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=activelayer-submissions&action=trash&id=' . $submission['id'] ), 'trash_' . $submission['id'] ) ); ?>" class="submitdelete deletion">
								<?php esc_html_e( 'Trash', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
							</a>
						</div>
						<div id="publishing-action">
							<?php $this->render_action_buttons( $submission ); ?>
						</div>
						<div class="clear"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render mark action buttons based on current status.
	 *
	 * @since 1.1.0
	 *
	 * @param array $submission Submission data.
	 */
	private function render_action_buttons( array $submission ): void {

		$current_status = $submission['status'] ?? 'pending';

		if ( $current_status === 'failed' ) :
			$recheck_url = wp_nonce_url(
				add_query_arg(
					[
						'page'     => 'activelayer-submissions',
						'action'   => 'recheck',
						'id'       => $submission['id'],
						'redirect' => 'view',
					],
					admin_url( 'admin.php' )
				),
				'recheck_' . $submission['id']
			);
			?>
			<a href="<?php echo esc_url( $recheck_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Recheck', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</a>
		<?php
		elseif ( $current_status === 'clean' ) :
			$mark_spam_url = wp_nonce_url(
				add_query_arg(
					[
						'page'     => 'activelayer-submissions',
						'action'   => 'mark_spam',
						'id'       => $submission['id'],
						'redirect' => 'view',
					],
					admin_url( 'admin.php' )
				),
				'mark_spam_' . $submission['id']
			);
			?>
			<a href="<?php echo esc_url( $mark_spam_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Mark Spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</a>
		<?php elseif ( $current_status === 'spam' ) : ?>
			<?php
			$mark_clean_url = wp_nonce_url(
				add_query_arg(
					[
						'page'     => 'activelayer-submissions',
						'action'   => 'mark_clean',
						'id'       => $submission['id'],
						'redirect' => 'view',
					],
					admin_url( 'admin.php' )
				),
				'mark_clean_' . $submission['id']
			);
			?>
			<a href="<?php echo esc_url( $mark_clean_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Mark Clean', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</a>
		<?php
		endif;
	}
}

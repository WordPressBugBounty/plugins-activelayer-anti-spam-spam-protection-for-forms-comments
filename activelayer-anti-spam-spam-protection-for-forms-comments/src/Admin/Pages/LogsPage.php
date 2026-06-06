<?php

namespace ActiveLayer\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Admin\AdminPages;
use ActiveLayer\Helpers\NoticeHelper;
use ActiveLayer\Logger\Logger;

/**
 * Simple Logs Page.
 *
 * @since 1.0.0
 * @since 1.2.0 Moved to Pages namespace.
 */
class LogsPage {

	/**
	 * Render logs page.
	 *
	 * @since 1.0.0
	 */
	public function render(): void {

		// Handle clear.
		if ( isset( $_POST['clear_logs'] ) && current_user_can( 'manage_activelayer' ) && check_admin_referer( 'activelayer_clear_logs' ) ) {
			Logger::clear();
			NoticeHelper::render( __( 'Logs cleared.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), NoticeHelper::TYPE_SUCCESS, false );
		}

		$logs = Logger::get_logs();

		AdminPages::render_header();
		?>
		<div class="wrap activelayer-admin-wrap activelayer-page-logs">
			<h1><?php esc_html_e( 'ActiveLayer Logs', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h1>

			<div style="margin: 20px 0;">
				<p>
					<strong><?php esc_html_e( 'Total:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></strong>
					<?php
					printf(
						/* translators: %d: number of log entries. */
						esc_html__( '%d entries (last 200)', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
						count( $logs )
					);
					?>
				</p>
				<form method="post" style="display: inline-block;">
					<?php wp_nonce_field( 'activelayer_clear_logs' ); ?>
					<button type="submit" name="clear_logs" class="button" onclick="return confirm('<?php echo esc_js( __( 'Clear all logs?', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ); ?>')">
						<?php esc_html_e( 'Clear Logs', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
					</button>
				</form>
			</div>

			<?php if ( empty( $logs ) ) : ?>
				<?php NoticeHelper::render( __( 'No logs. Enable logging in Settings.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), NoticeHelper::TYPE_INFO, false ); ?>
			<?php else : ?>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th style="width: 160px;"><?php esc_html_e( 'Time', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<th><?php esc_html_e( 'Message', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
							<th style="width: 80px;"><?php esc_html_e( 'Data', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['time'] ); ?></td>
								<td><?php echo esc_html( $log['message'] ); ?></td>
								<td>
									<?php if ( ! empty( $log['data'] ) ) : ?>
										<button type="button" class="button button-small" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'"><?php esc_html_e( 'View', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></button>
										<pre style="display: none; margin-top: 10px; padding: 10px; background: #f0f0f1; overflow-x: auto;"><?php echo esc_html( wp_json_encode( $log['data'], JSON_PRETTY_PRINT ) ); ?></pre>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

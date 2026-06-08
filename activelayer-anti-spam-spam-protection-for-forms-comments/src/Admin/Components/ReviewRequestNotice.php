<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time admin notice asking for a 5-star review after the first spam catch.
 *
 * Arms on the first submission that transitions to 'spam' (sync or async),
 * renders on all admin screens, and dismisses permanently and globally on any
 * button click. Rendered as a standard WP notice (text-only, WPForms parity).
 *
 * @since 1.3.0
 */
class ReviewRequestNotice {

	/**
	 * Option key holding the notice state.
	 *
	 * @since 1.3.0
	 */
	public const OPTION_KEY = 'activelayer_review_request';

	/**
	 * AJAX action slug for dismissal.
	 *
	 * @since 1.3.0
	 */
	public const AJAX_ACTION = 'activelayer_dismiss_review_request';

	/**
	 * State: not yet triggered (default).
	 *
	 * @since 1.3.0
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * State: armed and awaiting display/dismissal.
	 *
	 * @since 1.3.0
	 */
	public const STATUS_SHOW = 'show';

	/**
	 * State: dismissed permanently.
	 *
	 * @since 1.3.0
	 */
	public const STATUS_DISMISSED = 'dismissed';

	/**
	 * WordPress.org review link (5-star filter), opened in a new tab.
	 *
	 * @since 1.3.0
	 */
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/activelayer-anti-spam-spam-protection-for-forms-comments/reviews/#new-post?filter=5';

	/**
	 * Initialize the notice.
	 *
	 * Wired from Plugin::init() in every context — the status listener must
	 * observe verdicts on front-end and cron requests, while the admin_notices
	 * and AJAX hooks are simply no-ops outside admin/admin-ajax requests.
	 *
	 * @since 1.3.0
	 */
	public static function init(): void {

		self::hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.3.0
	 */
	public static function hooks(): void {

		add_action( 'activelayer_submission_status_changed', [ __CLASS__, 'maybe_arm' ], 10, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'render' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_dismiss' ] );
	}

	/**
	 * Arm the notice the first time a submission becomes spam.
	 *
	 * @since 1.3.0
	 *
	 * @param string $id         Submission identifier.
	 * @param string $new_status The newly applied status.
	 */
	public static function maybe_arm( string $id, string $new_status ): void {

		if ( $new_status !== 'spam' ) {
			return;
		}

		if ( self::get_status() !== self::STATUS_PENDING ) {
			return;
		}

		update_option(
			self::OPTION_KEY,
			[
				'status'       => self::STATUS_SHOW,
				'triggered_at' => time(),
			]
		);
	}

	/**
	 * Get the current notice state.
	 *
	 * @since 1.3.0
	 *
	 * @return string One of STATUS_PENDING|STATUS_SHOW|STATUS_DISMISSED.
	 */
	public static function get_status(): string {

		$state = get_option( self::OPTION_KEY );

		if ( ! is_array( $state ) || ! isset( $state['status'] ) ) {
			return self::STATUS_PENDING;
		}

		$known = [ self::STATUS_PENDING, self::STATUS_SHOW, self::STATUS_DISMISSED ];
		$value = (string) $state['status'];

		return in_array( $value, $known, true ) ? $value : self::STATUS_PENDING;
	}

	/**
	 * Render the review request notice on admin screens.
	 *
	 * @since 1.3.0
	 */
	public static function render(): void {

		if ( self::get_status() !== self::STATUS_SHOW ) {
			return;
		}

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		?>
		<div class="notice notice-info activelayer-review-notice" id="activelayer-review-notice">
			<p>
				<?php
				esc_html_e(
					"Hey there! It looks like ActiveLayer is catching spam submissions for you. Would you do us a favor and take a few seconds to give us a 5-star review? We'd love to hear from you.",
					'activelayer-anti-spam-spam-protection-for-forms-comments'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( self::REVIEW_URL ); ?>" class="activelayer-review-dismiss activelayer-review-out" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Ok, you deserve it', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>&nbsp;&bull;&nbsp;
				<a href="#" class="activelayer-review-dismiss">
					<?php esc_html_e( 'Nope, maybe later', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>&nbsp;&bull;&nbsp;
				<a href="#" class="activelayer-review-dismiss">
					<?php esc_html_e( 'I already did', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX handler to dismiss the review request notice permanently.
	 *
	 * @since 1.3.0
	 */
	public static function ajax_dismiss(): void {

		check_ajax_referer( 'activelayer_admin', 'nonce' );

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ], 403 );
		}

		self::dismiss();

		wp_send_json_success();
	}

	/**
	 * Mark the notice dismissed, preserving the trigger timestamp.
	 *
	 * @since 1.3.0
	 */
	public static function dismiss(): void {

		$state        = get_option( self::OPTION_KEY );
		$triggered_at = is_array( $state ) ? ( $state['triggered_at'] ?? null ) : null;

		update_option(
			self::OPTION_KEY,
			[
				'status'       => self::STATUS_DISMISSED,
				'triggered_at' => $triggered_at,
			]
		);
	}
}

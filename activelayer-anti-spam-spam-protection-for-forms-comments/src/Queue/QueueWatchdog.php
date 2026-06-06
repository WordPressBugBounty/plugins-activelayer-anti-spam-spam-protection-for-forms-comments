<?php

namespace ActiveLayer\Queue;

use ActiveLayer\Helpers\NoticeHelper;
use ActiveLayer\Storage\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple watchdog for monitoring Action Scheduler health.
 *
 * @since 1.0.0
 */
class QueueWatchdog {

	/**
	 * Cron hook name.
	 *
	 * @since 1.0.0
	 */
	private const CRON_HOOK = 'activelayer_queue_watchdog';

	/**
	 * Option storing the last successful queue run timestamp.
	 *
	 * @since 1.0.0
	 */
	private const OPTION_LAST_RUN = 'activelayer_last_queue_run';

	/**
	 * Option storing watchdog notice data.
	 *
	 * @since 1.0.0
	 */
	private const OPTION_NOTICE = 'activelayer_queue_watchdog_notice';

	/**
	 * Default threshold (in seconds) before watchdog warns about stalled queue.
	 *
	 * @since 1.0.0
	 */
	private const DEFAULT_THRESHOLD = 900; // 15 minutes.

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init(): void {

		self::hooks();

		/**
		 * Fires once the Queue Watchdog registers its hooks.
		 *
		 * @since 1.0.0
		 */
		do_action( 'activelayer_queue_watchdog_initialized' );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public static function hooks(): void {

		add_filter( 'cron_schedules', [ __CLASS__, 'register_cron_schedule' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule_watchdog' ] );
		add_action( 'admin_init', [ __CLASS__, 'run_watchdog' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_watchdog' ] );
		add_action( 'admin_notices', [ __CLASS__, 'display_admin_notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'display_admin_notice' ] );
	}

	/**
	 * Add custom cron interval.
	 *
	 * @since 1.0.0
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array Modified schedules.
	 */
	public static function register_cron_schedule( array $schedules ): array {

		if ( ! isset( $schedules['activelayer_fifteen_minutes'] ) ) {
			$schedules['activelayer_fifteen_minutes'] = [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes (ActiveLayer)', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			];
		}

		return $schedules;
	}

	/**
	 * Ensure watchdog cron is scheduled.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_schedule_watchdog(): void {

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'activelayer_fifteen_minutes', self::CRON_HOOK );
	}

	/**
	 * Clear watchdog cron.
	 *
	 * @since 1.0.0
	 */
	public static function unschedule_watchdog(): void {

		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Mark queue as healthy – called when jobs run.
	 *
	 * @since 1.0.0
	 */
	public static function mark_queue_healthy(): void {

		update_option( self::OPTION_LAST_RUN, time(), false );
		delete_option( self::OPTION_NOTICE );
	}

	/**
	 * Run watchdog logic to detect stalled queue.
	 *
	 * @since 1.0.0
	 */
	public static function run_watchdog(): void {

		/**
		 * Filters the threshold (in seconds) before pending submissions trigger the watchdog warning.
		 *
		 * @since 1.0.0
		 *
		 * @param int $threshold Current threshold in seconds.
		 */
		$threshold   = (int) apply_filters( 'activelayer_queue_watchdog_threshold', self::DEFAULT_THRESHOLD );
		$storage     = Storage::get_instance();
		$pending_old = $storage->count_pending_older_than( $threshold );

		$queue_available = QueueManager::is_available();
		/**
		 * Allow overriding queue availability detection.
		 *
		 * Primarily used for automated tests.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $available Current availability state.
		 */
		$queue_available = (bool) apply_filters( 'activelayer_queue_watchdog_queue_available', $queue_available );

		if ( ! $queue_available ) {
			if ( $pending_old > 0 ) {
				self::store_notice(
					[
						'reason'    => 'queue_unavailable',
						'pending'   => $pending_old,
						'detected'  => time(),
						'threshold' => $threshold,
					]
				);
			}

			return;
		}

		if ( $pending_old === 0 ) {
			delete_option( self::OPTION_NOTICE );

			return;
		}

		$last_run = (int) get_option( self::OPTION_LAST_RUN, 0 );

		if ( $last_run > 0 && ( time() - $last_run ) <= $threshold ) {
			delete_option( self::OPTION_NOTICE );

			return;
		}

		self::store_notice(
			[
				'reason'    => 'stalled',
				'pending'   => $pending_old,
				'detected'  => time(),
				'last_run'  => $last_run,
				'threshold' => $threshold,
			]
		);
	}

	/**
	 * Persist watchdog notice data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $notice Notice payload.
	 *
	 * @return void
	 */
	private static function store_notice( array $notice ): void {

		update_option( self::OPTION_NOTICE, $notice, false );
	}

	/**
	 * Display admin warning when queue is stalled.
	 *
	 * @since 1.0.0
	 */
	public static function display_admin_notice(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}

		// Only show notice on ActiveLayer admin pages (Guideline 11 compliance).
		$screen = get_current_screen();

		if ( ! $screen || strpos( $screen->id, 'activelayer' ) === false ) {
			return;
		}

		$notice = get_option( self::OPTION_NOTICE );

		if ( empty( $notice ) || ! is_array( $notice ) ) {
			return;
		}

		$pending       = (int) ( $notice['pending'] ?? 0 );
		$last_run      = (int) ( $notice['last_run'] ?? 0 );
		$reason        = $notice['reason'] ?? 'stalled';
		$threshold     = (int) ( $notice['threshold'] ?? self::DEFAULT_THRESHOLD );
		$last_run_text = $last_run ? human_time_diff( $last_run, time() ) : esc_html__( 'unknown', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		$pending_text  = number_format_i18n( max( 0, $pending ) );

		if ( $reason === 'queue_unavailable' ) {
			$message = __( 'Background processing is unavailable. Please ensure Action Scheduler is installed and WP Cron can run.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		} else {
			$message = sprintf(
				/* translators: 1: number of pending submissions, 2: watchdog threshold duration, 3: time since last queue run. */
				__( '%1$s submission(s) have been pending for more than %2$s. Last successful queue run: %3$s ago.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$pending_text,
				human_time_diff( 0, $threshold ),
				$last_run_text
			);
		}

		$html_content = sprintf(
			'<p><strong>%s</strong> %s</p><p>%s</p>',
			esc_html__( 'ActiveLayer queue warning:', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			esc_html( $message ),
			esc_html__( 'Check that WP Cron is enabled and the Action Scheduler queue is processing. Pending submissions will stay blocked until background jobs resume.', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
		);

		NoticeHelper::render_html( $html_content, NoticeHelper::TYPE_ERROR, false );
	}

	/**
	 * Expose last run option name for other classes.
	 *
	 * @since 1.0.0
	 *
	 * @return string Option key.
	 */
	public static function get_last_run_option(): string {

		return self::OPTION_LAST_RUN;
	}

	/**
	 * Expose notice option name for other classes.
	 *
	 * @since 1.0.0
	 *
	 * @return string Option key.
	 */
	public static function get_notice_option(): string {

		return self::OPTION_NOTICE;
	}
}

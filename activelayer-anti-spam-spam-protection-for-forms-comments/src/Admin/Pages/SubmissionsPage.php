<?php

namespace ActiveLayer\Admin\Pages;

use ActiveLayer\Admin\AdminPages;
use ActiveLayer\Admin\Components\SingleSubmissionView;
use ActiveLayer\Admin\Components\SubmissionActionHandler;
use ActiveLayer\Admin\Components\SubmissionsTable;
use ActiveLayer\Admin\Onboarding\OnboardingBanner;
use ActiveLayer\Admin\Onboarding\OnboardingManager;
use ActiveLayer\Helpers\NoticeHelper;
use ActiveLayer\Storage\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submissions Page Controller.
 *
 * Handles the submissions list page, single submission view, and row-level actions.
 *
 * @since 1.0.0
 * @since 1.2.0 Moved to Pages namespace.
 *
 * @package ActiveLayer\Admin
 */
class SubmissionsPage {

	/**
	 * Storage instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Action handler for submission business logic.
	 *
	 * @since 1.1.0
	 *
	 * @var SubmissionActionHandler
	 */
	private $action_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->storage        = Storage::get_instance();
		$this->action_handler = new SubmissionActionHandler( $this->storage );
	}

	/**
	 * Initialize submission page functionality.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function hooks(): void {

		add_action( 'admin_init', [ $this, 'handle_single_actions' ] );
		// WP_List_Table bulk actions fire on the screen load hook for the submenu page.
		add_action( 'load-activelayer_page_activelayer-submissions', [ $this, 'handle_bulk_actions' ] );
		add_action( 'admin_notices', [ $this, 'display_single_action_notice' ] );
	}

	/**
	 * Render submissions page.
	 *
	 * @since 1.0.0
	 */
	public function render(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$requested_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
		$requested_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		// Check if we're viewing a single submission.
		if ( $requested_action === 'view' ) {
			if ( $requested_id <= 0 ) {
				wp_die( esc_html__( 'Submission ID is invalid.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
			}

			$this->render_single_submission( (string) $requested_id );

			return;
		}

		$table = new SubmissionsTable();

		$table->prepare_items();

		AdminPages::render_header();

		?>
		<div class="wrap activelayer-admin-wrap activelayer-page-submissions">
			<h1><?php esc_html_e( 'Submissions', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?></h1>
			<?php
			$onboarding_banner = new OnboardingBanner( new OnboardingManager() );

			$onboarding_banner->render();
			?>

		<form method="get">
			<input type="hidden" name="page" value="activelayer-submissions" />
			<?php
			$table->search_box( __( 'Search emails', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), 'activelayer-submissions-search' );
			$table->views();
			$table->display();
			?>
		</form>
	</div>
	<?php
	}

	/**
	 * Render single submission view via dedicated class.
	 *
	 * @since 1.1.0
	 *
	 * @param string $submission_id Submission ID to view.
	 */
	private function render_single_submission( string $submission_id ): void {

		$view = new SingleSubmissionView();

		$view->render( $submission_id );
	}

	/**
	 * Handle bulk actions (called on page load before any output).
	 *
	 * @since 1.0.0
	 */
	public function handle_bulk_actions(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Check user permissions.
		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		// Get current action.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Sanitized immediately, validated below.
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? $_REQUEST['action2'] ?? '' ) );

		$allowed_actions = [ 'recheck', 'mark_clean', 'mark_spam', 'trash', 'restore', 'delete_permanently' ];

		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return;
		}

		// Get submission IDs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Sanitized immediately.
		$submission_ids = array_map( 'absint', (array) wp_unslash( $_REQUEST['submission'] ?? [] ) );
		$submission_ids = array_filter( $submission_ids );

		if ( empty( $submission_ids ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-submissions' ) ) {
			wp_die( esc_html__( 'Security check failed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		// Processing bulk action for submitted IDs.
		$count = 0;

		foreach ( $submission_ids as $submission_id ) {
			$submission_id = absint( $submission_id );

			if ( $submission_id <= 0 ) {
				continue;
			}

			$submission_id_str = (string) $submission_id;
			$action_performed  = false;

			switch ( $action ) {
				case 'recheck':
					$action_performed = $this->action_handler->recheck( $submission_id_str );
					break;

				case 'mark_clean':
					$action_performed = $this->action_handler->correct( $submission_id_str, 'clean' );
					break;

				case 'mark_spam':
					$action_performed = $this->action_handler->correct( $submission_id_str, 'spam' );
					break;

				case 'delete':
					$action_performed = $this->storage->delete( $submission_id_str );
					break;

				case 'trash':
					$action_performed = $this->storage->move_to_trash( $submission_id_str );
					break;

				case 'restore':
					$action_performed = $this->storage->restore_from_trash( $submission_id_str );
					break;

				case 'delete_permanently':
					$action_performed = $this->storage->delete( $submission_id_str );
					break;
			}

			if ( $action_performed ) {
				++$count;
			}
		}

		// Clean redirect.
		$redirect_url = admin_url( 'admin.php?page=activelayer-submissions' );
		$redirect_url = add_query_arg(
			[
				'bulk_action' => $action,
				'count'       => $count,
			],
			$redirect_url
		);

		// Preserve filters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- URL parameters for redirect only
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( $status_filter !== '' ) {
			$redirect_url = add_query_arg( 'status', $status_filter, $redirect_url );
		}

		// Redirecting to submissions page.
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle single row actions.
	 *
	 * @since 1.0.0
	 */
	public function handle_single_actions(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized immediately, validated below.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		$allowed_actions = [ 'view', 'recheck', 'mark_clean', 'mark_spam', 'trash', 'restore', 'delete_permanently', 'delete' ];

		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized immediately.
		$submission_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( $submission_id <= 0 ) {
			return;
		}

		// Check if we're on the submissions page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Check only, no action taken yet
		$page_slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page_slug !== 'activelayer-submissions' ) {
			return;
		}

		$submission_id_str = (string) $submission_id;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect hint.
		$redirect_target = isset( $_GET['redirect'] ) ? sanitize_key( wp_unslash( $_GET['redirect'] ) ) : 'list';
		$redirect_target = $redirect_target === 'view' ? 'view' : 'list';

		// View action doesn't need nonce verification.
		if ( $action === 'view' ) {
			// Let it proceed to render the view.
			return;
		}

		// Check user permissions.
		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		// Verify nonce for modifying actions.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action . '_' . $submission_id_str ) ) {
			wp_die( esc_html__( 'Security check failed', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		switch ( $action ) {
			case 'recheck':
				if ( ! $this->action_handler->recheck( $submission_id_str ) ) {
					$this->redirect_with_message( __( 'Recheck is only available for failed submissions.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), 'warning', $redirect_target, $submission_id_str );
					break;
				}

				$this->redirect_with_message( __( 'Submission queued for recheck.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), 'success', $redirect_target, $submission_id_str );
				break;

			case 'mark_clean':
				if ( ! $this->action_handler->correct( $submission_id_str, 'clean' ) ) {
					$this->redirect_with_message( __( 'Status change not allowed for this submission.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), 'warning', $redirect_target, $submission_id_str );
					break;
				}

				$this->redirect_with_message( __( 'Submission marked as clean.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), 'success', $redirect_target, $submission_id_str );
				break;

			case 'mark_spam':
				if ( ! $this->action_handler->correct( $submission_id_str, 'spam' ) ) {
					$this->redirect_with_message( __( 'Status change not allowed for this submission.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), 'warning', $redirect_target, $submission_id_str );
					break;
				}

				$this->redirect_with_message( __( 'Submission marked as spam.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), 'success', $redirect_target, $submission_id_str );
				break;

			case 'delete':
				$this->storage->delete( $submission_id_str );
				$this->redirect_with_message( __( 'Submission deleted.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
				break;

			case 'delete_permanently':
				$this->storage->delete( $submission_id_str );
				$this->redirect_with_message( __( 'Submission deleted permanently.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
				break;

			case 'trash':
				$this->storage->move_to_trash( $submission_id_str );
				$this->redirect_with_message( __( 'Submission moved to trash.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
				break;

			case 'restore':
				$this->storage->restore_from_trash( $submission_id_str );
				$this->redirect_with_message( __( 'Submission restored.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
				break;
		}
	}

	/**
	 * Redirect with admin notice message.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $message       Notice message text.
	 * @param string      $type          Notice type (success|warning|error|info).
	 * @param string      $redirect      Redirect target (list|view).
	 * @param string|null $submission_id Optional submission ID for view redirect.
	 */
	private function redirect_with_message( string $message, string $type = 'success', string $redirect = 'list', ?string $submission_id = null ): void {

		if ( $redirect === 'view' && $submission_id ) {
			$redirect_url = add_query_arg(
				[
					'page'        => 'activelayer-submissions',
					'action'      => 'view',
					'id'          => $submission_id,
					'message'     => rawurlencode( $message ),
					'notice_type' => $type,
				],
				admin_url( 'admin.php' )
			);
		} else {
			$redirect_url = remove_query_arg( [ 'action', 'id', '_wpnonce', 'redirect' ] );
			$redirect_url = add_query_arg(
				[
					'message'     => rawurlencode( $message ),
					'notice_type' => $type,
				],
				$redirect_url
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Display admin notice for single actions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function display_single_action_notice(): void {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$current_screen = get_current_screen();

		if ( ! $current_screen || $current_screen->id !== 'activelayer_page_activelayer-submissions' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only for displaying notice.
		if ( empty( $_GET['message'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized immediately.
		$message = sanitize_text_field( wp_unslash( $_GET['message'] ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only param for styling.
		$type          = isset( $_GET['notice_type'] ) ? sanitize_key( wp_unslash( $_GET['notice_type'] ) ) : 'success';
		$allowed_types = [ 'success', 'warning', 'error', 'info' ];

		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'success';
		}

		NoticeHelper::render( $message, $type, true );
	}
}

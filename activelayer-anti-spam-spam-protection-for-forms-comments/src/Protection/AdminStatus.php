<?php

namespace ActiveLayer\Protection;

use ActiveLayer\Helpers\UpgradeHelper;
use ActiveLayer\Helpers\SettingsHelper;
use ActiveLayer\Subscription\SubscriptionStats;
use WP_Admin_Bar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local-only administrator presentation of observations and interruptions.
 *
 * @since 1.8.0
 */
class AdminStatus {

	/**
	 * Current credential scope resolver.
	 *
	 * @since 1.8.0
	 *
	 * @var ProtectionMonitor
	 */
	private $monitor;

	/**
	 * Inject the local monitor.
	 *
	 * @since 1.8.0
	 *
	 * @param ProtectionMonitor $monitor Current observer.
	 */
	public function __construct( ProtectionMonitor $monitor ) {

		$this->monitor = $monitor;
	}

	/**
	 * Register administrator interfaces without outbound requests.
	 *
	 * @since 1.8.0
	 */
	public function hooks(): void {

		add_action( 'admin_notices', [ $this, 'notice' ] );
		add_action( 'admin_bar_menu', [ $this, 'toolbar' ], 90 );
		add_filter( 'site_status_tests', [ $this, 'site_tests' ] );
		add_action( 'admin_post_activelayer_dismiss_protection_gap', [ $this, 'dismiss' ] );
	}

	/**
	 * Read the active local interruption for the current scope.
	 *
	 * @since 1.8.0
	 *
	 * @return array|null Current episode.
	 */
	public static function active(): ?array {

		return ( new EpisodeStore() )->active( ( new ProtectionMonitor() )->scope() );
	}

	/**
	 * Describe the current episode count and its specific cause.
	 *
	 * @since 1.8.0
	 *
	 * @param array $episode Current interruption.
	 *
	 * @return string Translated cause and count.
	 */
	private function episode_message( array $episode ): string {

		$count = (int) $episode['count'];

		if ( $episode['reason'] === 'quota_exhausted' ) {
			$lead = $this->quota_message();
		} else {
			$lead = SettingsHelper::has_api_key()
				? __( 'There was a problem with your ActiveLayer connection. Please repeat the connection steps to start checking new submissions for spam.', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
				: __( 'Connect ActiveLayer to start checking new submissions for spam.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		return $lead . ' ' . sprintf(
			/* translators: %s: number of submissions missed while protection was unavailable. */
			_n( '%s submission not checked by ActiveLayer.', '%s submissions not checked by ActiveLayer.', $count, 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			number_format_i18n( $count )
		);
	}

	/**
	 * Explain exhausted checks using the cached plan size when it is known.
	 *
	 * @since 1.8.0
	 *
	 * @return string Plain-language plan message.
	 */
	private function quota_message(): string {

		$stats = get_transient( SubscriptionStats::CACHE_KEY );
		$limit = (int) ( $stats['requests_limit'] ?? 0 );

		if ( $limit > 0 ) {
			return sprintf(
				/* translators: %s: total checks on the plan. */
				__( 'You\'ve used all %s checks on your plan. New submissions are not being checked for spam by ActiveLayer.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				number_format_i18n( $limit )
			);
		}

		return __( 'You\'ve used all checks on your plan. New submissions are not being checked for spam by ActiveLayer.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}

	/**
	 * Render one dismissible-per-user episode notice after its first observation.
	 *
	 * @since 1.8.0
	 */
	public function notice(): void {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}
		$episode = self::active();

		if ( ! $episode || get_user_meta( get_current_user_id(), $this->dismissal_key(), true ) === $episode['episode_id'] ) {
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( 'episode', $episode['episode_id'], admin_url( 'admin-post.php?action=activelayer_dismiss_protection_gap' ) ), 'activelayer_dismiss_protection_gap_' . $episode['episode_id'] );

		echo '<div class="notice notice-warning activelayer-protection-gap"><p>' . esc_html( $this->episode_message( $episode ) ) . ' ';
		$this->cta( $episode );
		echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</a></p></div>';
	}

	/**
	 * Render current missed checks or available bonus checks; hide historical misses after recovery.
	 *
	 * @since 1.8.0
	 */
	public function dashboard(): void {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}
		$episode = self::active();

		if ( ! $episode ) {
			$this->bonus_summary();

			return;
		}

		echo '<div class="activelayer-protection-summary"><p>';
		echo esc_html( $this->episode_message( $episode ) ) . ' ';
		$this->cta( $episode );
		echo '</p></div>';
	}

	/**
	 * Highlight explicitly granted bonus checks while some remain available.
	 *
	 * @since 1.8.0
	 */
	private function bonus_summary(): void {

		$stats = get_transient( SubscriptionStats::CACHE_KEY );

		if ( ! SettingsHelper::has_api_key() || empty( $stats['success'] ) ) {
			return;
		}
		$granted   = (int) ( $stats['bonus_requests_granted'] ?? 0 );
		$remaining = (int) ( $stats['bonus_requests_remaining'] ?? 0 );

		if ( $granted <= 0 || $remaining <= 0 ) {
			return;
		}
		$message = sprintf(
			/* translators: 1: bonus checks granted, 2: bonus checks remaining. */
			__( 'We\'ve added %1$s bonus checks to your account. %2$s bonus checks left.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			number_format_i18n( $granted ),
			number_format_i18n( $remaining )
		);

		echo '<div class="activelayer-protection-summary activelayer-bonus-summary"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Render a state-appropriate recovery action.
	 *
	 * @since 1.8.0
	 *
	 * @param array $episode Current interruption.
	 */
	private function cta( array $episode ): void {

		$connect = $episode['reason'] === 'not_connected';
		$url     = $connect ? admin_url( 'admin.php?page=activelayer-settings' ) : UpgradeHelper::get_billing_url( 'protection_gap' );
		$label   = __( 'See plans', 'activelayer-anti-spam-spam-protection-for-forms-comments' );

		if ( $connect ) {
			$label = SettingsHelper::has_api_key() ? __( 'Reconnect ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) : __( 'Connect ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Add a compact badge only for administrators during a current interruption.
	 *
	 * @since 1.8.0
	 *
	 * @param WP_Admin_Bar $bar Administrator toolbar.
	 */
	public function toolbar( $bar ): void {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return;
		}
		$episode = self::active();

		if ( ! $episode ) {
			return;
		}
		$bar->add_node(
            [
				'id'    => 'activelayer-protection-gap',
				'title' => sprintf(
				/* translators: %s: unchecked submission count. */
                    __( 'ActiveLayer: %s unchecked', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
                    number_format_i18n( (int) $episode['count'] )
                ),
				'href'  => admin_url( 'admin.php?page=' . ( $episode['reason'] === 'not_connected' ? 'activelayer-settings' : 'activelayer-dashboard' ) ),
				'meta'  => [ 'title' => $this->episode_message( $episode ) ],
            ]
        );
	}

	/**
	 * Add the local recommended Site Health test.
	 *
	 * @since 1.8.0
	 *
	 * @param array $tests Existing tests.
	 *
	 * @return array Tests including local observations.
	 */
	public function site_tests( array $tests ): array {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			return $tests;
		}

		$tests['direct']['activelayer_protection_gap'] = [
			'label' => __( 'ActiveLayer spam protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'test'  => [ $this, 'site_health' ],
		];

		return $tests;
	}

	/**
	 * Evaluate local observations and confirmed quota exhaustion, including before the first miss.
	 *
	 * @since 1.8.0
	 *
	 * @return array WordPress Site Health result.
	 */
	public function site_health(): array {

		$episode   = self::active();
		$exhausted = ! $episode && UpgradeHelper::is_quota_exhausted_cached();
		$action    = $episode ? $episode : ( $exhausted ? [ 'reason' => 'quota_exhausted' ] : null );

		ob_start();
		if ( $action ) {
			$this->cta( $action );
		}
		$actions = ob_get_clean();

		return [
			'label'       => $episode ? __( 'Review your spam protection - some submissions not checked', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) : $this->connection_status_label(),
			'status'      => ( $action || ! SettingsHelper::has_api_key() ) ? 'recommended' : 'good',
			'badge'       => [
				'label' => 'ActiveLayer',
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $this->health_description( $episode, $exhausted ) ) . '</p>',
			'actions'     => $actions,
			'test'        => 'activelayer_protection_gap',
		];
	}

	/**
	 * Explain known failures without claiming missed submissions before any were observed.
	 *
	 * @since 1.8.0
	 *
	 * @param array|null $episode   Current observed interruption.
	 * @param bool       $exhausted Whether cached quota confirms exhaustion.
	 *
	 * @return string Translated Site Health description.
	 */
	private function health_description( ?array $episode, bool $exhausted ): string {

		if ( $episode ) {
			return $this->episode_message( $episode );
		}
		if ( $exhausted ) {
			return $this->quota_message();
		}

		return __( 'This status covers supported forms with ActiveLayer protection turned on. You can review your connection and protected forms in ActiveLayer settings.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}

	/**
	 * Use the positive Site Health label only when no quota exhaustion is known.
	 *
	 * @since 1.8.0
	 *
	 * @return string Site Health connection label.
	 */
	private function connection_status_label(): string {

		if ( UpgradeHelper::is_quota_exhausted_cached() ) {
			return __( 'ActiveLayer plan limit reached', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		return SettingsHelper::has_api_key()
			? __( 'ActiveLayer spam blocking is fully operational', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
			: __( 'Connect ActiveLayer to check for spam', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}

	/**
	 * Scope user dismissal state to this blog on multisite.
	 *
	 * @since 1.8.0
	 *
	 * @return string User metadata key.
	 */
	private function dismissal_key(): string {

		return 'activelayer_protection_dismissed_' . get_current_blog_id();
	}

	/**
	 * Save a capability-checked, nonce-protected per-user episode dismissal.
	 *
	 * @since 1.8.0
	 */
	public function dismiss(): void {

		if ( ! current_user_can( 'manage_activelayer' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ), '', [ 'response' => 403 ] );
		}
		$requested = isset( $_GET['episode'] ) ? sanitize_text_field( wp_unslash( $_GET['episode'] ) ) : '';

		check_admin_referer( 'activelayer_dismiss_protection_gap_' . $requested );
		$episode = self::active();

		if ( $episode && hash_equals( $episode['episode_id'], $requested ) ) {
			update_user_meta( get_current_user_id(), $this->dismissal_key(), $episode['episode_id'] );
		}
		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : admin_url( 'index.php' ) );
		exit;
	}
}

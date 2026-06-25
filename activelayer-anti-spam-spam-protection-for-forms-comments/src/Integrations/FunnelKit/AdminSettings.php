<?php

namespace ActiveLayer\Integrations\FunnelKit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Integrations\FormAdminSettingsInterface;
use WP_Post;

/**
 * FunnelKit admin settings.
 *
 * Manages per-form protection settings. A "form" is a FunnelKit opt-in page
 * (post type wffn_optin) — each opt-in funnel step owns one.
 *
 * @since 1.5.0
 */
class AdminSettings implements FormAdminSettingsInterface {

	/**
	 * Opt-in page post type registered by FunnelKit.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	const OPTIN_POST_TYPE = 'wffn_optin';

	/**
	 * Parent integration instance.
	 *
	 * @since 1.5.0
	 *
	 * @var FunnelKitIntegration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param FunnelKitIntegration $integration Parent integration instance.
	 */
	public function __construct( FunnelKitIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Get all FunnelKit opt-in pages with their protection status.
	 *
	 * @since 1.5.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		$optin_pages = get_posts(
			[
				'post_type'        => self::OPTIN_POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			]
		);

		if ( empty( $optin_pages ) ) {
			return [];
		}

		$result = [];

		foreach ( $optin_pages as $optin_page ) {
			$settings = $this->get_form_settings( (int) $optin_page->ID );

			$result[] = [
				'id'        => (int) $optin_page->ID,
				'name'      => $optin_page->post_title !== ''
					? $optin_page->post_title
					: __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'enabled'   => ! empty( $settings['enabled'] ),
				// FunnelKit stores the parent funnel ID on each step post; the editor
				// deep link routes by funnel, not by the opt-in page post ID.
				'funnel_id' => (int) get_post_meta( (int) $optin_page->ID, '_bwf_in_funnel', true ),
			];
		}

		return $result;
	}

	/**
	 * Save protection status for a specific opt-in page.
	 *
	 * @since 1.5.0
	 *
	 * @param int  $form_id Opt-in page post ID.
	 * @param bool $enabled Whether protection is enabled.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_protection( int $form_id, bool $enabled ): bool {

		$current            = $this->get_form_settings( $form_id );
		$current['enabled'] = $enabled;

		return $this->save_form_settings( $form_id, $current );
	}

	/**
	 * Get the URL-friendly slug for FunnelKit.
	 *
	 * @since 1.5.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'funnelkit';
	}

	/**
	 * Get the admin page URL for FunnelKit.
	 *
	 * @since 1.5.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=bwf&path=/funnels';
	}

	/**
	 * Get the form edit URL template for FunnelKit.
	 *
	 * FunnelKit's admin is a React app routed by funnel, not by opt-in page
	 * post ID, so there is no per-form deep link; the funnels list is the
	 * closest navigation target (no %d placeholder — sprintf leaves it as-is).
	 *
	 * @since 1.5.0
	 *
	 * @return string URL template.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=bwf&path=/funnels';
	}

	/**
	 * Get per-form settings (opt-out default).
	 *
	 * @since 1.5.0
	 *
	 * @param int $form_id Opt-in page post ID.
	 *
	 * @return array Form settings.
	 */
	public function get_form_settings( int $form_id ): array {

		$defaults = [
			'enabled' => true,
		];

		$saved = get_option( 'activelayer_funnelkit_form_' . $form_id, [] );

		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save per-form settings.
	 *
	 * @since 1.5.0
	 *
	 * @param int   $form_id  Opt-in page post ID.
	 * @param array $settings Settings to save.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_settings( int $form_id, array $settings ): bool {

		$sanitized = [
			'enabled' => ! empty( $settings['enabled'] ),
		];

		return (bool) update_option( 'activelayer_funnelkit_form_' . $form_id, $sanitized );
	}

	/**
	 * Clean up per-form settings when an opt-in page is deleted.
	 *
	 * Hooked to deleted_post, which fires for every post type — the WP_Post
	 * guard limits cleanup to FunnelKit opt-in pages.
	 *
	 * @since 1.5.0
	 *
	 * @param int          $post_id Deleted post ID.
	 * @param WP_Post|null $post    Deleted post object.
	 */
	public function cleanup_form_settings( $post_id, $post = null ): void {

		if ( ! $post instanceof WP_Post || $post->post_type !== self::OPTIN_POST_TYPE ) {
			return;
		}

		delete_option( 'activelayer_funnelkit_form_' . (int) $post_id );
	}
}

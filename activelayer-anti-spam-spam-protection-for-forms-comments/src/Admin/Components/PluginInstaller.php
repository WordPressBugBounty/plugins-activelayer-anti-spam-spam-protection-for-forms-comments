<?php

namespace ActiveLayer\Admin\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;
use WP_Error;

/**
 * Handles AJAX plugin installation and activation.
 *
 * Installs allowlisted plugins from WordPress.org and activates them
 * in a single AJAX request. Used by the WPForms promotion row on the
 * Settings → Integrations page.
 *
 * @since 1.1.0
 * @since 1.2.0 Moved to Components namespace.
 */
class PluginInstaller {

	/**
	 * Plugins that are allowed to be installed via this handler.
	 *
	 * @since 1.1.0
	 *
	 * @var string[]
	 */
	private const ALLOWED_PLUGINS = [
		'wpforms-lite',
		'wp-mail-smtp',
		'google-analytics-for-wordpress',
		'optinmonster',
		'coming-soon',
		'all-in-one-seo-pack',
	];

	/**
	 * Register hooks.
	 *
	 * @since 1.1.0
	 */
	public function hooks(): void {

		add_action( 'wp_ajax_activelayer_install_plugin', [ $this, 'ajax_install_plugin' ] );
	}

	/**
	 * Handle AJAX plugin installation and activation.
	 *
	 * @since 1.1.0
	 */
	public function ajax_install_plugin(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		check_ajax_referer( 'activelayer_install_plugin', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ], 403 );
		}

		$plugin_slug = isset( $_POST['plugin_slug'] )
			? sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ) )
			: '';

		if ( $plugin_slug === '' ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Plugin slug is required.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ] );
		}

		if ( ! in_array( $plugin_slug, self::ALLOWED_PLUGINS, true ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Plugin not allowed.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ] );
		}

		$result = $this->install_and_activate( $plugin_slug );

		if ( is_wp_error( $result ) ) {
			$data = [ 'message' => $result->get_error_message() ];

			if ( $result->get_error_code() === 'activation_failed' ) {
				$data['installed'] = true;
			}

			wp_send_json_error( $data );
		}

		wp_send_json_success(
			[
				'message' => esc_html__( 'Plugin installed and activated successfully.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			]
		);
	}

	/**
	 * Install and activate a plugin from WordPress.org.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin_slug Plugin slug on WordPress.org.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function install_and_activate( string $plugin_slug ) {

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Check if already installed — skip the wordpress.org API call.
		$plugin_file = $this->find_plugin_file( $plugin_slug );

		if ( ! $plugin_file ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

			$api = plugins_api(
				'plugin_information',
				[
					'slug'   => $plugin_slug,
					'fields' => [ 'sections' => false ],
				]
			);

			if ( is_wp_error( $api ) ) {
				return $api;
			}

			$plugin_file = $this->download_and_install( $api, $plugin_slug );

			if ( is_wp_error( $plugin_file ) ) {
				return $plugin_file;
			}
		}

		$activated = activate_plugin( $plugin_file );

		if ( is_wp_error( $activated ) ) {
			return new WP_Error( 'activation_failed', $activated->get_error_message() );
		}

		return true;
	}

	/**
	 * Download and install a plugin using the WordPress upgrader.
	 *
	 * @since 1.1.0
	 *
	 * @param object $api         Plugin API response with download_link.
	 * @param string $plugin_slug Plugin directory slug.
	 *
	 * @return string|WP_Error Plugin file path on success, WP_Error on failure.
	 */
	private function download_and_install( $api, string $plugin_slug ) {

		$skin      = new WP_Ajax_Upgrader_Skin();
		$upgrader  = new Plugin_Upgrader( $skin );
		$installed = $upgrader->install( $api->download_link );

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		if ( ! $installed ) {
			return new WP_Error( 'install_failed', esc_html__( 'Installation failed.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		// Re-scan for the plugin file after install.
		wp_cache_delete( 'plugins', 'plugins' );
		$plugin_file = $this->find_plugin_file( $plugin_slug );

		if ( ! $plugin_file ) {
			return new WP_Error( 'plugin_not_found', esc_html__( 'Plugin installed but could not be located.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) );
		}

		return $plugin_file;
	}

	/**
	 * Find a plugin's main file by its directory slug.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin_slug Plugin directory slug.
	 *
	 * @return string|false Plugin file path (e.g. "wpforms-lite/wpforms.php") or false.
	 */
	private function find_plugin_file( string $plugin_slug ) {

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			if ( strpos( $plugin_file, $plugin_slug . '/' ) === 0 ) {
				return $plugin_file;
			}
		}

		return false;
	}
}

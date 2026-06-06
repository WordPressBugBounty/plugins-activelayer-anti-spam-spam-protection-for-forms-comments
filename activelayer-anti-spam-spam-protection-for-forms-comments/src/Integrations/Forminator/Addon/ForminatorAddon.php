<?php
/**
 * Forminator Addon Registration for ActiveLayer.
 *
 * Registers ActiveLayer as a Forminator integration addon so it appears
 * in the Integrations tab of the Forminator form builder.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Integrations\Forminator
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Forminator addon naming convention.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SettingsHelper;

/**
 * Class Forminator_Activelayer extends Forminator_Integration.
 *
 * Naming convention required by Forminator: Forminator_{Slug} where slug is 'activelayer'.
 *
 * @since 1.1.0
 */
final class Forminator_Activelayer extends Forminator_Integration {

	/**
	 * Singleton instance.
	 *
	 * @since 1.1.0
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Addon slug.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $_slug = 'activelayer'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Version.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $_version = '1.0'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Minimum Forminator version.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $_min_forminator_version = '1.1'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Short title.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $_short_title = 'ActiveLayer'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Title.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $_title = 'ActiveLayer'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {

		$this->_description = esc_html__( 'AI-powered spam protection for your forms.', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}

	/**
	 * Get addon icon URL.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_icon() {

		return plugin_dir_url( ACTIVELAYER_PLUGIN_FILE ) . 'assets/images/logo-icon.png';
	}

	/**
	 * Get retina icon URL.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_icon_x2() {

		return $this->get_icon();
	}

	/**
	 * Get addon banner URL.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_image() {

		return plugin_dir_url( ACTIVELAYER_PLUGIN_FILE ) . 'assets/images/logo-icon.png';
	}

	/**
	 * Get retina banner URL.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_image_x2() {

		return $this->get_image();
	}

	/**
	 * Global settings wizard.
	 *
	 * Shows connection status based on whether API key is configured.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function settings_wizards() {

		return [
			[
				'callback'     => [ $this, 'setup_connect' ],
				'is_completed' => [ $this, 'is_connected' ],
			],
		];
	}

	/**
	 * Setup connect wizard step.
	 *
	 * @since 1.1.0
	 *
	 * @param array $submitted_data Submitted data.
	 * @param int   $form_id        Form ID.
	 *
	 * @return array
	 */
	public function setup_connect( $submitted_data, $form_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		$is_connected = $this->is_connected();
		$is_submit    = ! empty( $submitted_data );
		$has_errors   = false;
		$buttons      = [];
		$notification = [];

		if ( $is_submit && ! empty( $submitted_data['connect'] ) ) {
			if ( ! $is_connected ) {
				$has_errors = true;
			} else {
				if ( ! forminator_addon_is_active( $this->_slug ) ) {
					Forminator_Integration_Loader::get_instance()->activate_addon( $this->_slug );
				}

				$notification = [
					'type' => 'success',
					'text' => '<strong>' . esc_html( $this->get_title() ) . '</strong> '
						. esc_html__( 'is connected successfully.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				];
			}
		}

		if ( $is_connected ) {
			$connect_html = '<div class="forminator-integration-popup__header">'
				. '<h3 class="sui-box-title sui-lg" style="overflow: initial; white-space: normal; text-overflow: initial;">'
				. esc_html__( 'ActiveLayer is connected', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
				. '</h3>'
				. '<p>' . esc_html__( 'AI spam protection is ready. Enable it per form in the Integrations tab.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</p>'
				. '</div>';

			$buttons['next']['markup'] = '<div class="sui-actions-right">'
				. '<input type="hidden" name="connect" value="1">'
				. Forminator_Integration::get_button_markup(
					esc_html__( 'Activate', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'sui-button-primary forminator-addon-finish'
				)
				. '</div>';
		} else {
			$settings_url = admin_url( 'admin.php?page=activelayer-settings' );
			$connect_html = '<div class="forminator-integration-popup__header">'
				. '<h3 class="sui-box-title sui-lg" style="overflow: initial; white-space: normal; text-overflow: initial;">'
				. esc_html__( 'Connect ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' )
				. '</h3>'
				. '<p>'
				. sprintf(
					/* translators: %1$s opening a tag, %2$s closing a tag. */
					esc_html__( 'Please configure your API key in %1$sActiveLayer Settings%2$s first.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
					'<a href="' . esc_url( $settings_url ) . '" target="_blank">',
					'</a>'
				)
				. '</p>'
				. '</div>';
		}

		return [
			'html'         => $connect_html,
			'redirect'     => false,
			'has_errors'   => $has_errors,
			'buttons'      => $buttons,
			'notification' => $notification,
		];
	}

	/**
	 * Check if addon is globally connected (API key configured).
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_connected() {

		return SettingsHelper::has_api_key();
	}

	/**
	 * Check if addon is authorized.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_authorized() {

		return $this->is_connected();
	}

	/**
	 * Check if addon is connected to a specific form.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $module_id   Form ID.
	 * @param string $module_slug Module type.
	 * @param bool   $check_lead  Check lead connection.
	 *
	 * @return bool
	 */
	public function is_module_connected( $module_id, $module_slug = 'form', $check_lead = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( ! $this->is_connected() ) {
			return false;
		}

		$settings = $this->get_activelayer_form_settings( (int) $module_id );

		return ! empty( $settings['enabled'] );
	}

	/**
	 * Get ActiveLayer form settings via our AdminSettings.
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array
	 */
	private function get_activelayer_form_settings( int $form_id ): array {

		// Read directly from the option to avoid infinite recursion:
		// AdminSettings::get_form_settings() falls back to is_addon_connected_for_form()
		// which calls this method, creating a loop for unconfigured forms.
		$all_settings = get_option( 'activelayer_forminator_form_settings', [] );

		return isset( $all_settings[ $form_id ] ) ? $all_settings[ $form_id ] : [ 'enabled' => false ];
	}
}

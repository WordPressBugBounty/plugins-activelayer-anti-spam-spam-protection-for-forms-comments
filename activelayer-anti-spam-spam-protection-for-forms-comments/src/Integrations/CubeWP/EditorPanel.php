<?php

namespace ActiveLayer\Integrations\CubeWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add a protection checkbox to CubeWP's native form settings sidebar.
 *
 * The central ActiveLayer per-form option is authoritative. Reading it on
 * every render keeps the builder in sync with the Integrations screen.
 *
 * @since 1.8.0
 */
class EditorPanel {

	/**
	 * Parent integration.
	 *
	 * @since 1.8.0
	 *
	 * @var CubeWPIntegration
	 */
	private $integration;

	/**
	 * Construct the native editor panel.
	 *
	 * @since 1.8.0
	 *
	 * @param CubeWPIntegration $integration Parent integration.
	 */
	public function __construct( CubeWPIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Register field rendering and the provider's native post save hook.
	 *
	 * @since 1.8.0
	 */
	public function hooks(): void {

		add_filter( 'cubewp/admin/text/customfield', [ $this, 'render_protection_field' ], 20, 2 );
		add_action( 'save_post_cwp_forms', [ $this, 'save_protection' ] );
	}

	/**
	 * Append the checkbox after the form ID setting, which is always rendered.
	 *
	 * @since 1.8.0
	 *
	 * @param string $output Existing CubeWP field HTML.
	 * @param array  $args   Native field configuration.
	 *
	 * @return string Existing field and optional ActiveLayer setting.
	 */
	public function render_protection_field( string $output, array $args ): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor context; CubeWP verifies its edit nonce.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

		if ( $page !== 'cubewp-form-fields' || ( $args['name'] ?? '' ) !== 'cwp[form][settings][form_id]' ) {
			return $output;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only form settings lookup.
		$form_id = isset( $_GET['groupid'] ) && is_scalar( $_GET['groupid'] ) ? absint( $_GET['groupid'] ) : 0;
		$enabled = $this->integration->get_admin_settings()->get_form_settings( $form_id )['enabled'];

		$output .= '<div class="cwp-field-container activelayer-cubewp-setting">';
		$output .= '<input type="hidden" name="cwp[form][settings][activelayer_enabled]" value="0">';
		$output .= '<label for="activelayer-cubewp-enabled"><input type="checkbox" id="activelayer-cubewp-enabled" name="cwp[form][settings][activelayer_enabled]" value="1" ' . checked( $enabled, true, false ) . '> ';
		$output .= esc_html__( 'Enable ActiveLayer spam protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</label></div>';

		return $output;
	}

	/**
	 * Save the shared option during CubeWP's nonce-verified native form save.
	 *
	 * Reading the submitted checkbox rather than watching post-meta updates
	 * also handles unchanged metadata after a central toggle was changed.
	 *
	 * @since 1.8.0
	 *
	 * @param int $post_id Saved form ID.
	 */
	public function save_protection( int $post_id ): void {

		if ( ! isset( $_POST['cwp_form_nonce'] ) || ! is_string( $_POST['cwp_form_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cwp_form_nonce'] ) ), 'class-cubewp-forms-ui.php' ) ) {
			return;
		}

		if ( get_post_type( $post_id ) !== 'cwp_forms' || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['cwp']['form']['settings']['activelayer_enabled'] ) || ! is_string( $_POST['cwp']['form']['settings']['activelayer_enabled'] ) ) {
			return;
		}

		$this->integration->get_admin_settings()->save_form_protection( $post_id, $_POST['cwp']['form']['settings']['activelayer_enabled'] === '1' );
	}
}

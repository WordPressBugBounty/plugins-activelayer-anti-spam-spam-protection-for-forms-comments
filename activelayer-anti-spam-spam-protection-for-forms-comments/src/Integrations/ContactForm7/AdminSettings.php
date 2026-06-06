<?php

namespace ActiveLayer\Integrations\ContactForm7;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\NoticeHelper;
use ActiveLayer\Helpers\SettingsHelper;
use WPCF7_ContactForm;

/**
 * Contact Form 7 Admin Settings.
 *
 * Manages per-form settings for Contact Form 7 integration.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\ContactForm7
 */
class AdminSettings implements \ActiveLayer\Integrations\FormAdminSettingsInterface {

	/**
	 * Parent integration instance.
	 *
	 * @since 1.0.0
	 *
	 * @var ContactForm7Integration
	 */
	private $integration;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param ContactForm7Integration $integration Parent integration instance.
	 */
	public function __construct( ContactForm7Integration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Add ActiveLayer panel to CF7 form editor.
	 *
	 * @since 1.0.0
	 *
	 * @param array $panels Existing panels.
	 *
	 * @return array Modified panels.
	 */
	public function add_editor_panel( array $panels ): array {

		$panels['activelayer-panel'] = [
			'title'    => __( 'ActiveLayer', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'callback' => [ $this, 'render_editor_panel' ],
		];

		return $panels;
	}

	/**
	 * Render ActiveLayer settings panel in CF7 editor.
	 *
	 * @since 1.0.0
	 *
	 * @param WPCF7_ContactForm $contact_form Contact form instance.
	 */
	public function render_editor_panel( WPCF7_ContactForm $contact_form ): void {

		$form_id       = $contact_form->id();
		$form_settings = $this->get_form_settings( $form_id );

		?>
		<h2><?php echo esc_html( __( 'ActiveLayer Spam Protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ); ?></h2>

		<fieldset>
			<legend>
				<?php echo esc_html( __( 'Enable ActiveLayer anti-spam protection for this form', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ); ?>
			</legend>

			<label>
				<input
					type="checkbox"
					name="activelayer[enabled]"
					value="1"
					<?php checked( $form_settings['enabled'] ); ?>
				/>
				<?php echo esc_html( __( 'Enable ActiveLayer spam protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) ); ?>
			</label>

			<p class="description">
				<?php
				echo esc_html(
					__(
						'When enabled, form submissions will be checked for spam using ActiveLayer AI before sending email notifications.',
						'activelayer-anti-spam-spam-protection-for-forms-comments'
					)
				);
				?>
			</p>

		</fieldset>

		<?php
		// Show warning if API key is not configured.
		if ( ! SettingsHelper::has_api_key() ) {
			$settings_url  = admin_url( 'admin.php?page=activelayer-settings' );
			$settings_link = '<a href="' . esc_url( $settings_url ) . '">' .
				esc_html__( 'ActiveLayer Settings', 'activelayer-anti-spam-spam-protection-for-forms-comments' ) . '</a>';

			$message = sprintf(
				/* translators: %s: settings page URL. */
				__( 'ActiveLayer API key is not configured. Please configure it in %s to enable spam protection.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				$settings_link
			);

			NoticeHelper::render_inline_html( $message, NoticeHelper::TYPE_WARNING );
		}
	}

	/**
	 * Save form settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WPCF7_ContactForm $contact_form Contact form instance.
	 */
	public function save_form_settings( WPCF7_ContactForm $contact_form ): void {

		$form_id = $contact_form->id();

		if ( ! $form_id ) {
			return;
		}

		// Nonce is verified by Contact Form 7 in WPCF7_ContactForm::save() before firing wpcf7_save_contact_form.
		// CF7 checks 'wpcf7-save-contact-form-nonce' via check_admin_referer() before this hook runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by CF7 core.
		$activelayer_settings = isset( $_POST['activelayer'] ) ? wp_unslash( $_POST['activelayer'] ) : [];

		$settings = [
			'enabled' => ! empty( $activelayer_settings['enabled'] ),
		];

		// Save to post meta.
		update_post_meta( $form_id, '_activelayer_settings', $settings );
	}

	/**
	 * Get form settings.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array Form settings.
	 */
	public function get_form_settings( int $form_id ): array {

		$default_settings = [
			'enabled' => false,
		];

		$saved_settings = get_post_meta( $form_id, '_activelayer_settings', true );

		if ( ! is_array( $saved_settings ) ) {
			$saved_settings = [];
		}

		return wp_parse_args( $saved_settings, $default_settings );
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		$forms = get_posts(
			[
				'post_type'      => 'wpcf7_contact_form',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			]
		);

		if ( empty( $forms ) ) {
			return [];
		}

		$result = [];

		foreach ( $forms as $form ) {
			$settings = $this->get_form_settings( (int) $form->ID );

			$result[] = [
				'id'      => (int) $form->ID,
				'name'    => $form->post_title ? $form->post_title : __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'enabled' => ! empty( $settings['enabled'] ),
			];
		}

		return $result;
	}

	/**
	 * Save protection status for a specific form.
	 *
	 * @since 1.1.0
	 *
	 * @param int  $form_id Form ID.
	 * @param bool $enabled Whether protection is enabled.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_protection( int $form_id, bool $enabled ): bool {

		$current = get_post_meta( $form_id, '_activelayer_settings', true );

		if ( ! is_array( $current ) ) {
			$current = [];
		}

		$current['enabled'] = $enabled;

		return (bool) update_post_meta( $form_id, '_activelayer_settings', $current );
	}

	/**
	 * Get the URL-friendly slug for Contact Form 7.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'contact-form-7';
	}

	/**
	 * Get the admin page URL for Contact Form 7.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=wpcf7';
	}

	/**
	 * Get the form edit URL template for Contact Form 7.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=wpcf7&post=%d&action=edit';
	}
}

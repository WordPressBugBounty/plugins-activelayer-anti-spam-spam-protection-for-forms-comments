<?php

namespace ActiveLayer\Integrations\FormidableForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Helpers\SafeUnserializer;
use FrmForm;

/**
 * Formidable Forms Admin Settings.
 *
 * Adds per-form toggle for enabling ActiveLayer protection.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Integrations\FormidableForms
 */
class AdminSettings implements \ActiveLayer\Integrations\FormAdminSettingsInterface {

	/**
	 * Options array key used to store the toggle.
	 *
	 * @since 1.0.0
	 */
	private const OPTION_KEY = 'activelayer_enabled';

	/**
	 * Whether hooks have been registered.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Initialize admin settings hooks.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {

		if ( $this->initialized || ! is_admin() ) {
			return;
		}

		$this->hooks();
		$this->initialized = true;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function hooks(): void {

		add_filter( 'frm_setup_new_form_vars', [ $this, 'ensure_option_on_form_vars' ] );
		add_filter( 'frm_setup_edit_form_vars', [ $this, 'ensure_option_on_form_vars' ] );
		add_action( 'frm_additional_form_options', [ $this, 'render_settings_row' ] );
		add_filter( 'frm_form_options_before_update', [ $this, 'persist_option' ], 10, 3 );
	}

	/**
	 * Ensure option value is present while editing or creating a form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Form values passed to the builder.
	 *
	 * @return array
	 */
	public function ensure_option_on_form_vars( array $values ): array {

		$values = $this->ensure_option_value_present( $values, self::OPTION_KEY );

		return $values;
	}

	/**
	 * Ensure a specific option key is populated in the builder payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $values Form builder values.
	 * @param string $key    Option key to populate.
	 *
	 * @return array
	 */
	private function ensure_option_value_present( array $values, string $key ): array {

		$values[ $key ] = $this->resolve_option_value( $values, $key );

		if ( ! isset( $values['options'] ) || ! is_array( $values['options'] ) ) {
			$values['options'] = [];
		}

		$values['options'][ $key ] = $values[ $key ];

		return $values;
	}

	/**
	 * Normalize the boolean option value for builder payloads.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Default flipped to opt-out — protection enabled when no explicit toggle stored.
	 *
	 * @param array  $values Form builder values.
	 * @param string $key    Option key to inspect.
	 *
	 * @return int
	 */
	private function resolve_option_value( array $values, string $key ): int {

		if ( isset( $values[ $key ] ) ) {
			return (int) (bool) $values[ $key ];
		}

		$form_id = isset( $values['id'] ) ? (int) $values['id'] : 0;

		if ( $form_id <= 0 ) {
			// New / unsaved form (no ID yet) — use opt-out default so the builder
			// checkbox renders ON and the first save persists `enabled = 1`.
			return 1;
		}

		$option_exists = false;
		$stored_value  = $this->get_option_value( $form_id, $key, $option_exists );

		if ( $stored_value === null ) {
			return 1;
		}

		return (int) (bool) $stored_value;
	}

	/**
	 * Render checkbox in the Formidable form settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Form builder values.
	 */
	public function render_settings_row( array $values ): void {

		$enabled = ! empty( $values[ self::OPTION_KEY ] );
		?>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'ActiveLayer Protection', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
			</th>
			<td>
				<input type="hidden" name="activelayer_toggle_present" value="1" />
				<label for="activelayer_enabled">
					<input type="checkbox" id="activelayer_enabled" name="options[<?php echo esc_attr( self::OPTION_KEY ); ?>]" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Enable ActiveLayer spam filtering for this form.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Submissions stay pending until ActiveLayer marks them as clean.', 'activelayer-anti-spam-spam-protection-for-forms-comments' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist option value when the form is saved.
	 *
	 * @since 1.0.0
	 *
	 * @param array $options   Current options payload.
	 * @param array $values    Raw form values passed by Formidable.
	 * @param bool  $is_update Whether an existing form is being updated.
	 *
	 * @return array
	 */
	public function persist_option( array $options, array $values, $is_update ): array {

		unset( $values, $is_update );

		// Nonce is verified by Formidable Forms in FrmFormsController::update() before this filter runs.
		// This hook (frm_form_options_before_update) is only called after Formidable validates the request.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by Formidable Forms core.
		if ( ! isset( $_POST['activelayer_toggle_present'] ) ) {
			return $options;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by Formidable Forms core.
		$options[ self::OPTION_KEY ] = isset( $_POST['options'][ self::OPTION_KEY ] ) ? 1 : 0;

		return $options;
	}

	/**
	 * Get form settings for runtime checks.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Default flipped to opt-out — protection enabled when no explicit toggle stored.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array
	 */
	public function get_form_settings( int $form_id ): array {

		$enabled_exists = false;
		$enabled_value  = $this->get_option_value( $form_id, self::OPTION_KEY, $enabled_exists );

		if ( ! $enabled_exists ) {
			return [
				'enabled' => true,
			];
		}

		return [
			'enabled' => (bool) $enabled_value,
		];
	}

	/**
	 * Get all forms with their protection status.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Array of arrays with 'id', 'name', and 'enabled' keys.
	 */
	public function get_forms_list(): array {

		if ( ! class_exists( FrmForm::class ) || ! method_exists( FrmForm::class, 'getAll' ) ) {
			return [];
		}

		$forms = FrmForm::getAll(
			[
				'is_template' => 0,
				'status'      => 'published',
			]
		);

		if ( empty( $forms ) || ! is_array( $forms ) ) {
			return [];
		}

		$result = [];

		foreach ( $forms as $form ) {
			$form_id  = isset( $form->id ) ? (int) $form->id : 0;
			$settings = $this->get_form_settings( $form_id );

			$result[] = [
				'id'      => $form_id,
				'name'    => isset( $form->name ) ? $form->name : __( 'Untitled', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
				'enabled' => ! empty( $settings['enabled'] ),
			];
		}

		return $result;
	}

	/**
	 * Save protection status for a specific form.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Replaced maybe_unserialize with allowed_classes=>false to block PHP object injection via Formidable form options.
	 *
	 * @param int  $form_id Form ID.
	 * @param bool $enabled Whether protection is enabled.
	 *
	 * @return bool Whether the save was successful.
	 */
	public function save_form_protection( int $form_id, bool $enabled ): bool {

		if ( ! class_exists( FrmForm::class ) ) {
			return false;
		}

		$form = FrmForm::getOne( $form_id );

		if ( ! $form ) {
			return false;
		}

		$options = SafeUnserializer::unserialize( $form->options );

		if ( ! is_array( $options ) ) {
			$options = [];
		}

		$options[ self::OPTION_KEY ] = $enabled ? '1' : '0';

		FrmForm::update(
			$form_id,
			[ 'options' => $options ]
		);

		return true;
	}

	/**
	 * Fetch stored toggle value from the form record.
	 *
	 * @since 1.0.0
	 *
	 * @param int       $form_id Form identifier.
	 * @param string    $key     Option key.
	 * @param bool|null $exists  Pass-by-reference flag tracking if the option exists.
	 *
	 * @return int|null
	 */
	private function get_option_value( int $form_id, string $key, ?bool &$exists = null ): ?int {

		if ( $form_id <= 0 || ! class_exists( FrmForm::class ) ) {
			$exists = false;

			return null;
		}

		$form = FrmForm::getOne( $form_id );

		if ( ! $form || empty( $form->options ) || ! is_array( $form->options ) || ! array_key_exists( $key, $form->options ) ) {
			$exists = false;

			return null;
		}

		$exists = true;

		return (int) ( ! empty( $form->options[ $key ] ) );
	}

	/**
	 * Get the URL-friendly slug for Formidable Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL slug.
	 */
	public function get_url_slug(): string {

		return 'formidable-forms';
	}

	/**
	 * Get the admin page URL for Formidable Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string Admin URL path.
	 */
	public function get_admin_page_url(): string {

		return 'admin.php?page=formidable';
	}

	/**
	 * Get the form edit URL template for Formidable Forms.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL template with %d placeholder.
	 */
	public function get_form_edit_url_template(): string {

		return 'admin.php?page=formidable&frm_action=settings&id=%d';
	}
}

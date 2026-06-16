<?php

namespace ActiveLayer\Integrations\WSForm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Logger\Logger;
use Exception;
use WS_Form_Common;
use WS_Form_Meta;

/**
 * Surfaces the ActiveLayer protection toggle inside WS Form's per-form Spam tab.
 *
 * WS Form exposes its form-settings editor through the same config filters
 * Akismet uses: `wsf_config_settings_form_admin` (which fieldset shows in the
 * Spam tab) and `wsf_config_meta_keys` (the field definitions). This class
 * injects a single "Enable" checkbox into the Spam tab and keeps it in sync
 * with the plugin's own per-form option, which stays the single source of
 * truth read by SubmissionHandler and the central Integrations screen.
 *
 * The option stays authoritative in both directions:
 * - On save, the editor checkbox is mirrored back into the option
 *   (`wsf_form_update`).
 * - When the editor is opened (`admin_init` on the WS Form edit screen), the
 *   stored value for our key is reconciled to the option, so a toggle changed
 *   on the Integrations screen is reflected even after the form's own meta has
 *   been stored (the editor embeds meta at server-render time, bypassing the
 *   dynamic default).
 *
 * Only our own meta key (`activelayer_enabled`) is ever written - never via a
 * form-wide write - so no other WS Form form setting (honeypot, spam threshold,
 * actions, fields, published state) is touched. See push_to_form_meta().
 *
 * @since 1.4.0
 */
class EditorPanel {

	/**
	 * WS Form meta key backing the toggle in the editor.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	private const META_KEY = 'activelayer_enabled';

	/**
	 * Parent integration.
	 *
	 * @since 1.4.0
	 *
	 * @var WSFormIntegration
	 */
	private $integration;

	/**
	 * Set up the editor panel.
	 *
	 * @since 1.4.0
	 *
	 * @param WSFormIntegration $integration Parent integration reference.
	 */
	public function __construct( WSFormIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Register the editor-panel hooks.
	 *
	 * @since 1.4.0
	 */
	public function hooks(): void {

		add_filter( 'wsf_config_settings_form_admin', [ $this, 'inject_spam_fieldset' ] );
		add_filter( 'wsf_config_meta_keys', [ $this, 'register_meta_key' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'reconcile_editor_form_meta' ] );
		add_action( 'wsf_form_update', [ $this, 'sync_from_editor' ] );
	}

	/**
	 * Inject the ActiveLayer fieldset into WS Form's Spam settings tab.
	 *
	 * Hooked to wsf_config_settings_form_admin. The fieldset is appended after
	 * WS Form's own spam fieldsets (Honeypot, IP throttling, etc.).
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $config WS Form admin settings config (array).
	 *
	 * @return mixed Possibly modified config.
	 */
	public function inject_spam_fieldset( $config ) {

		if (
			! is_array( $config ) ||
			! isset( $config['sidebars']['form']['meta']['fieldsets']['spam']['fieldsets'] ) ||
			! is_array( $config['sidebars']['form']['meta']['fieldsets']['spam']['fieldsets'] )
		) {
			return $config;
		}

		$fieldset = [
			// Brand name, not translated.
			'label'     => 'ActiveLayer',
			'meta_keys' => [ self::META_KEY ],
		];

		$spam_fieldsets = $config['sidebars']['form']['meta']['fieldsets']['spam']['fieldsets'];

		$config['sidebars']['form']['meta']['fieldsets']['spam']['fieldsets'] = WS_Form_Common::array_inject_element(
			$spam_fieldsets,
			$fieldset,
			count( $spam_fieldsets )
		);

		return $config;
	}

	/**
	 * Declare the toggle field with a per-form default sourced from our option.
	 *
	 * Hooked to wsf_config_meta_keys. The default reflects the option (which
	 * defaults to enabled - protection is opt-out), so a brand-new form whose
	 * stored meta does not yet exist still shows the box checked. Once meta is
	 * stored, reconcile_editor_form_meta() keeps it aligned with the option.
	 *
	 * Labels are passed to WS Form's config (serialized to JSON and rendered by
	 * its editor JS), so they use __() rather than esc_html__() to avoid
	 * double-escaped entities in the UI - matching WS Form's own convention.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $meta_keys WS Form meta-key definitions (array).
	 * @param mixed $form_id   Form ID the config is being built for.
	 *
	 * @return mixed Meta-key definitions with the ActiveLayer toggle added.
	 */
	public function register_meta_key( $meta_keys = [], $form_id = 0 ) {

		if ( ! is_array( $meta_keys ) ) {
			$meta_keys = [];
		}

		$meta_keys[ self::META_KEY ] = [
			'label'   => __( 'Enable', 'activelayer-anti-spam-spam-protection-for-forms-comments' ),
			'type'    => 'checkbox',
			'default' => $this->is_form_enabled( (int) $form_id ) ? 'on' : '',
			'help'    => __(
				'Protect this form from spam with ActiveLayer. Mirrors the per-form toggle on the ActiveLayer Integrations screen.',
				'activelayer-anti-spam-spam-protection-for-forms-comments'
			),
		];

		return $meta_keys;
	}

	/**
	 * Reconcile the stored value for our key to the option when the editor opens.
	 *
	 * Hooked to admin_init. The WS Form editor embeds the form object at
	 * server-render time via WS_Form_Form::db_read() (no filter), so a value
	 * stored for our key wins over the dynamic `default`. When the option was
	 * changed elsewhere (e.g. the Integrations screen) the editor would show a
	 * stale checkbox. Rewriting our stored value to match the option just before
	 * the edit screen renders keeps the option authoritative.
	 *
	 * Only the WS Form edit screen is targeted.
	 *
	 * @since 1.4.0
	 */
	public function reconcile_editor_form_meta(): void {

		// Read-only screen detection; no state is mutated based on this request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page !== 'ws-form-edit' ) {
			return;
		}

		// Capability gate: admin_init runs for any logged-in user, so require the
		// same WS Form capability the editor itself needs before writing anything.
		// 'edit_form' is a custom capability WS Form registers on activation.
		if ( ! current_user_can( 'edit_form' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WS Form custom capability.
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $form_id <= 0 ) {
			return;
		}

		$this->push_to_form_meta( $form_id, $this->is_form_enabled( $form_id ) );
	}

	/**
	 * Align the stored value for OUR key with the option.
	 *
	 * Writes ONLY the `activelayer_enabled` meta key via WS_Form_Meta - a single
	 * point upsert, never a form-wide write ($replace_meta stays false) - so no
	 * other form setting (honeypot, spam threshold, actions, fields) is altered
	 * and the form's published checksum is untouched (verified). The write keeps
	 * WS Form's own capability check (no bypass), on top of the edit_form gate in
	 * the caller. Writing the meta table directly also avoids firing
	 * wsf_form_update, so it cannot recurse into sync_from_editor().
	 *
	 * WS Form stores a checked box as 'on' and an unchecked box as an absent row,
	 * so the comparison is on the enabled state; a write is skipped when the
	 * stored value already matches, avoiding needless work.
	 *
	 * @since 1.4.0
	 *
	 * @param int  $form_id Form ID.
	 * @param bool $enabled Desired protection state.
	 */
	private function push_to_form_meta( int $form_id, bool $enabled ): void {

		if ( $form_id <= 0 || ! class_exists( 'WS_Form_Meta' ) ) {
			return;
		}

		try {
			$meta            = new WS_Form_Meta();
			$meta->object    = 'form';
			$meta->parent_id = $form_id;

			$current_on = ( $meta->db_read( self::META_KEY ) === 'on' );

			if ( $current_on === $enabled ) {
				return;
			}

			$meta->db_update_from_array( [ self::META_KEY => $enabled ? 'on' : '' ] );
		} catch ( Exception $exception ) {
			Logger::log(
				'WS Form editor: failed to reconcile form meta',
				[
					'form_id' => $form_id,
					'error'   => $exception->getMessage(),
				]
			);
		}
	}

	/**
	 * Mirror the editor checkbox back into our per-form option on save.
	 *
	 * Hooked to wsf_form_update. Reads the submitted checkbox state from the
	 * saved form (read-only) and writes only our option. When the key has no
	 * stored value (the user never touched the checkbox) the current option
	 * value is used as the fallback so an untouched save never flips protection
	 * off.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $form_id Saved form ID.
	 */
	public function sync_from_editor( $form_id ): void {

		$form_id = (int) $form_id;

		if ( $form_id <= 0 || ! function_exists( 'wsf_form_get_object' ) ) {
			return;
		}

		try {
			$form = wsf_form_get_object( $form_id, true, false );
		} catch ( Exception $exception ) {
			return;
		}

		// Defensive: wsf_form_get_object is an external boundary; do not rely on
		// get_object_meta_value() guarding a non-object internally.
		if ( ! is_object( $form ) ) {
			return;
		}

		$current  = $this->is_form_enabled( $form_id );
		$fallback = $current ? 'on' : '';
		$value    = WS_Form_Common::get_object_meta_value( $form, self::META_KEY, $fallback );

		$this->integration->get_admin_settings()->save_form_protection( $form_id, $value === 'on' );
	}

	/**
	 * Read the current ActiveLayer protection state for a form.
	 *
	 * @since 1.4.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return bool Whether protection is enabled.
	 */
	private function is_form_enabled( int $form_id ): bool {

		$settings = $this->integration->get_admin_settings()->get_form_settings( $form_id );

		return ! empty( $settings['enabled'] );
	}
}

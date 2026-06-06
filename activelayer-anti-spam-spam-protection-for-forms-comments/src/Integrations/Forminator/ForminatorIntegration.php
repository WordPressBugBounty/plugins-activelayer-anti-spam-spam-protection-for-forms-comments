<?php

namespace ActiveLayer\Integrations\Forminator;

use ActiveLayer\ClientSignals\Fields\FieldRenderer;
use ActiveLayer\Integrations\BaseFormIntegration;
use ActiveLayer\Helpers\RequestHelper;
use ActiveLayer\Helpers\SettingsHelper;
use Forminator_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forminator Integration (synchronous block only).
 *
 * Checks submissions via `forminator_spam_protection` filter before entry
 * creation. Spam is blocked; clean and API-failure submissions pass through.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Integrations\Forminator
 */
class ForminatorIntegration extends BaseFormIntegration {

	/**
	 * Submission handler.
	 *
	 * @since 1.1.0
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Admin settings helper.
	 *
	 * @since 1.1.0
	 *
	 * @var AdminSettings
	 */
	private $admin_settings;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {

		parent::__construct( 'Forminator' );

		$this->submission_handler = new SubmissionHandler( $this );
		$this->admin_settings     = new AdminSettings();
	}

	/**
	 * Boot integration hooks.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {

		$this->hooks();
		$this->admin_settings->init();
		$this->register_forminator_addon();
	}

	/**
	 * Register ActiveLayer as a Forminator addon.
	 *
	 * Uses `forminator_addons_loaded` hook because Forminator registers its
	 * internal addons during `init_addons()` and external addons must hook
	 * into this action to appear in the Integrations tab.
	 *
	 * If the hook has already fired (late registration), register immediately.
	 *
	 * @since 1.1.0
	 */
	private function register_forminator_addon(): void { // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Forminator addon registration requires hook.

		if ( did_action( 'forminator_addons_loaded' ) ) {
			$this->do_register_forminator_addon();
		} else {
			add_action( 'forminator_addons_loaded', [ $this, 'do_register_forminator_addon' ] );
		}
	}

	/**
	 * Perform the actual Forminator addon registration.
	 *
	 * @since 1.1.0
	 */
	public function do_register_forminator_addon(): void {

		if ( ! class_exists( 'Forminator_Integration_Loader' ) ) {
			return;
		}

		require_once __DIR__ . '/Addon/ForminatorAddon.php';
		require_once __DIR__ . '/Addon/ForminatorAddonFormSettings.php';
		require_once __DIR__ . '/Addon/ForminatorAddonFormHooks.php';

		\Forminator_Integration_Loader::get_instance()->register( new \Forminator_Activelayer() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WPForms.PHP.BackSlash.RemoveBackslash -- Forminator addon API requires global classes.
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.1.0
	 */
	private function hooks(): void { // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Integration registration requires hooks in init().

		// Sync: intercept via spam protection filter before entry creation.
		add_filter(
			'forminator_spam_protection',
			[ $this->submission_handler, 'handle_spam_protection' ],
			10,
			4
		);

		// Add hidden fields for client signals to protected forms.
		add_filter(
			'forminator_render_form_submit_markup',
			[ $this, 'output_client_signals_fields' ],
			10,
			5
		);

		// Suppress email notifications for spam submissions in save mode.
		add_action(
			'forminator_custom_form_mail_before_send_mail',
			[ $this, 'maybe_suppress_spam_emails' ],
			10,
			4
		);

		add_action(
			'forminator_custom_form_mail_after_send_mail',
			[ $this, 'restore_email_sending' ],
			10,
			3
		);
	}

	/**
	 * Output hidden client signals fields in the form.
	 *
	 * Only outputs for forms that have ActiveLayer protection enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param string $html     Submit markup HTML.
	 * @param int    $form_id  Form identifier.
	 * @param int    $post_id  Post identifier.
	 * @param string $nonce    Nonce value.
	 * @param array  $settings Form settings.
	 *
	 * @return string
	 */
	public function output_client_signals_fields( $html, $form_id, $post_id, $nonce, $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( ! $this->is_enabled() ) {
			return $html;
		}

		if ( ! SettingsHelper::has_api_key() ) {
			return $html;
		}

		$form_id = (int) $form_id;

		if ( $form_id <= 0 ) {
			return $html;
		}

		$form_settings = $this->admin_settings->get_form_settings( $form_id );

		if ( empty( $form_settings['enabled'] ) ) {
			return $html;
		}

		ob_start();
		FieldRenderer::output_all();
		$signals_html = ob_get_clean();

		return $signals_html . $html;
	}

	/**
	 * Check if Forminator is active.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		return class_exists( 'Forminator' );
	}

	/**
	 * Normalize submission data for API payload.
	 *
	 * @since 1.1.0
	 *
	 * @param array $raw_data Raw data bundle.
	 *
	 * @return array
	 */
	protected function normalize_form_data( array $raw_data ): array {

		$fields = $raw_data['fields'] ?? [];

		return [
			'name'        => $this->find_field_value( $fields, [ 'name', 'text' ] ),
			'email'       => $this->find_field_value( $fields, [ 'email' ] ),
			'website_url' => $this->find_field_value( $fields, [ 'url' ] ),
			'message'     => $this->find_field_value( $fields, [ 'textarea' ] ),
			'ip'          => RequestHelper::get_user_ip(),
			'user_agent'  => RequestHelper::get_user_agent(),
		];
	}

	/**
	 * Retrieve form metadata.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $form_instance Form ID.
	 *
	 * @return array
	 */
	public function get_form_meta( $form_instance ): array {

		$form_id = (int) $form_instance;

		$form_name = 'Forminator Form';

		if ( class_exists( 'Forminator_API' ) && method_exists( 'Forminator_API', 'get_form' ) ) {
			$form = Forminator_API::get_form( $form_id );

			if ( ! is_wp_error( $form ) && is_object( $form ) && isset( $form->name ) ) {
				$form_name = $form->name;
			}
		}

		return [
			'form_id'    => $form_id,
			'form_title' => $form_name,
		];
	}

	/**
	 * Get admin settings helper.
	 *
	 * @since 1.1.0
	 *
	 * @return AdminSettings
	 */
	public function get_admin_settings(): AdminSettings {

		return $this->admin_settings;
	}

	/**
	 * Check if a Forminator form has Store Submissions enabled.
	 *
	 * When enabled, entries are saved to the Forminator database.
	 * Defaults to true (Forminator's own default).
	 *
	 * @since 1.1.0
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return bool
	 */
	public function has_store_submissions( int $form_id ): bool {

		if ( $form_id <= 0 ) {
			return true;
		}

		if ( ! class_exists( 'Forminator_API' ) || ! method_exists( 'Forminator_API', 'get_form' ) ) {
			return true;
		}

		$form = Forminator_API::get_form( $form_id );

		if ( is_wp_error( $form ) || ! is_object( $form ) ) {
			return true;
		}

		$settings = $form->settings ?? [];

		if ( ! isset( $settings['store_submissions'] ) ) {
			return true;
		}

		return filter_var( $settings['store_submissions'], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Suppress email notifications when a spam verdict is pending in save mode.
	 *
	 * Hooked to `forminator_custom_form_mail_before_send_mail`. When the
	 * submission handler flagged a form for spam-save, this adds a
	 * `pre_wp_mail` filter that prevents `wp_mail()` from sending.
	 *
	 * @since 1.1.0
	 *
	 * @param object $mailer Forminator mail instance.
	 * @param object $form   Form model.
	 * @param array  $data   Prepared data.
	 * @param object $entry  Entry model.
	 */
	public function maybe_suppress_spam_emails( $mailer, $form, $data, $entry ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Runtime email suppression.

		$spam_form_id = $this->submission_handler->get_spam_save_form_id();

		if ( $spam_form_id === null ) {
			return;
		}

		$current_form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;

		if ( $current_form_id !== $spam_form_id ) {
			return;
		}

		add_filter( 'pre_wp_mail', '__return_false' ); // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Runtime email suppression for spam-save mode.
	}

	/**
	 * Restore email sending after Forminator's mail loop completes.
	 *
	 * Hooked to `forminator_custom_form_mail_after_send_mail`. Always
	 * removes the `pre_wp_mail` filter to prevent leaking into other
	 * emails sent later in the request.
	 *
	 * @since 1.1.0
	 *
	 * @param object $mailer Forminator mail instance.
	 * @param object $form   Form model.
	 * @param array  $data   Prepared data.
	 */
	public function restore_email_sending( $mailer, $form, $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Cleanup for runtime email suppression.

		remove_filter( 'pre_wp_mail', '__return_false' ); // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks -- Cleanup for runtime email suppression.
		$this->submission_handler->reset();
	}

	/**
	 * Reset cached settings and synchronous state.
	 *
	 * @since 1.1.0
	 */
	public function reload_settings(): void {

		parent::reload_settings();

		$this->admin_settings->clear_cache();
		$this->submission_handler->reset();
	}

	/**
	 * Build a simplified field set from the Forminator field_data_array.
	 *
	 * @since 1.1.0
	 *
	 * @param array $field_data Forminator field data array.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function build_fields_from_field_data( array $field_data ): array {

		$fields = [];

		foreach ( $field_data as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name  = isset( $field['name'] ) ? (string) $field['name'] : '';
			$value = isset( $field['value'] ) ? $field['value'] : '';

			$type = $this->guess_field_type_from_name( $name );

			$fields[] = [
				'id'    => $name,
				'key'   => $name,
				'type'  => $type,
				'label' => $name,
				'value' => is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '',
			];
		}

		return $fields;
	}

	/**
	 * Guess field type from Forminator element ID.
	 *
	 * Forminator uses IDs like 'email-1', 'name-1', 'textarea-1', 'url-1'.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name Field element ID.
	 *
	 * @return string
	 */
	public function guess_field_type_from_name( string $name ): string {

		$prefix = strtolower( explode( '-', $name )[0] ?? '' );

		$type_map = [
			'email'    => 'email',
			'name'     => 'name',
			'textarea' => 'textarea',
			'url'      => 'url',
			'text'     => 'text',
			'phone'    => 'phone',
		];

		return $type_map[ $prefix ] ?? 'text';
	}

	/**
	 * Find field value by key/type hints.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields  Field structures.
	 * @param array $needles Keywords.
	 *
	 * @return string
	 */
	private function find_field_value( array $fields, array $needles ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh, Generic.Metrics.CyclomaticComplexity.MaxExceeded, Generic.Metrics.NestingLevel.MaxExceeded

		foreach ( $fields as $field ) {
			$key   = strtolower( (string) ( $field['key'] ?? '' ) );
			$type  = strtolower( (string) ( $field['type'] ?? '' ) );
			$value = $field['value'] ?? '';

			if ( $value === '' || $value === null ) {
				continue;
			}

			if ( in_array( $key, $needles, true ) || in_array( $type, $needles, true ) ) {
				if ( is_array( $value ) ) {
					$sanitized_parts = [];

					foreach ( $value as $item ) {
						if ( is_scalar( $item ) && $item !== '' && $item !== null ) {
							$sanitized_parts[] = sanitize_text_field( (string) $item );
						}
					}

					if ( $type === 'name' || $key === 'name' ) {
						return trim( implode( ' ', $sanitized_parts ) );
					}

					return implode( ', ', $sanitized_parts );
				}

				return sanitize_text_field( (string) $value );
			}
		}

		return '';
	}
}

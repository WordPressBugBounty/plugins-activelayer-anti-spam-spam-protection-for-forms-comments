<?php

namespace ActiveLayer\Integrations\NinjaForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ninja Forms action that performs synchronous ActiveLayer spam check.
 *
 * Registered via `ninja_forms_register_actions` and injected into protected
 * forms through the `ninja_forms_submission_actions` filter. Uses the
 * official NF action processing pipeline so that errors halt submission
 * via `maybe_halt()` — no access to protected controller internals needed.
 *
 * Does not extend `NF_Abstracts_Action` to avoid constructor side-effects
 * when Ninja Forms has not yet been fully loaded. Instead it implements the
 * duck-type interface expected by the NF action processing loop: `process()`,
 * `get_timing()`, `get_priority()`, `get_name()`, and `get_settings()`.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Integrations\NinjaForms
 */
class SpamCheckAction {

	/**
	 * NinjaFormsIntegration reference.
	 *
	 * @since 1.1.0
	 *
	 * @var NinjaFormsIntegration|null
	 */
	private $integration;

	/**
	 * Inject the integration instance.
	 *
	 * @since 1.1.0
	 *
	 * @param NinjaFormsIntegration $integration Integration instance.
	 */
	public function set_integration( NinjaFormsIntegration $integration ): void {

		$this->integration = $integration;
	}

	/**
	 * Process the spam check action.
	 *
	 * Called by the NF action processing loop with access to `$data`,
	 * the submission controller's internal state. Adding errors to
	 * `$data['errors']['form']` causes `maybe_halt()` to stop further
	 * action processing and return the error to the client.
	 *
	 * @since 1.1.0
	 *
	 * @param array $action_settings Action settings (unused — injected programmatically).
	 * @param int   $form_id         Form identifier.
	 * @param array $data            Submission controller state.
	 *
	 * @return array Modified controller state.
	 */
	public function process( $action_settings, $form_id, $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, Generic.Metrics.CyclomaticComplexity.TooHigh

		if ( ! $this->integration ) {
			return $data;
		}

		$form_id = (int) $form_id;

		if ( $form_id <= 0 ) {
			return $data;
		}

		$form_data = $data['fields'] ?? [];

		if ( empty( $form_data ) ) {
			return $data;
		}

		// Build raw data structure expected by process_submission_synchronously().
		$raw_data = [
			'fields' => $this->extract_field_values( $form_data ),
		];

		$meta             = $this->integration->get_form_meta( $form_id );
		$meta['entry_id'] = null;

		$result = $this->integration->process_submission_synchronously( $raw_data, $meta );

		if ( empty( $result['success'] ) ) {
			return $data;
		}

		if ( 'spam' !== ( $result['verdict'] ?? 'clean' ) ) {
			return $data;
		}

		$data['errors']['form']['activelayer'] = $this->integration->get_sync_block_message();

		return $data;
	}

	/**
	 * Return action timing.
	 *
	 * The NF action sorter calls this to determine execution order.
	 * Early (-1) ensures spam check runs before save/email actions.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function get_timing() {

		return -1; // 'early' timing.
	}

	/**
	 * Return action priority.
	 *
	 * Lower runs first within the same timing group.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function get_priority() {

		return 1;
	}

	/**
	 * Return action name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {

		return 'activelayer_spam_check';
	}

	/**
	 * Return action settings.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_settings() {

		return [];
	}

	/**
	 * Return the nicename for the NF builder UI.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_nicename() {

		return 'ActiveLayer Spam Check';
	}

	/**
	 * Return the drawer section for the NF builder UI.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_section() {

		return 'installed';
	}

	/**
	 * Return the drawer group for the NF builder UI.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_group() {

		return '';
	}

	/**
	 * Return the branded image URL.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_image() {

		return '';
	}

	/**
	 * Return the documentation URL.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_doc_url() {

		return '';
	}

	/**
	 * Extract field id/key/value arrays from the NF data structure.
	 *
	 * The `$data['fields']` array in the action processing loop contains
	 * field settings merged with submitted values. This normalises them
	 * to the `[ id, key, value ]` shape expected by the integration.
	 *
	 * @since 1.1.0
	 *
	 * @param array $fields Fields from `$data['fields']`.
	 *
	 * @return array Normalised field array.
	 */
	private function extract_field_values( array $fields ): array {

		$extracted = [];

		foreach ( $fields as $id => $field ) {
			$extracted[ $id ] = [
				'id'    => $id,
				'key'   => $field['key'] ?? '',
				'type'  => $field['type'] ?? '',
				'value' => $field['value'] ?? '',
			];
		}

		return $extracted;
	}
}

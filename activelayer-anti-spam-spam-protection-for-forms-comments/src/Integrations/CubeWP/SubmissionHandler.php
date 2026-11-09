<?php

namespace ActiveLayer\Integrations\CubeWP;

use ActiveLayer\Logger\Logger;
use Exception;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check CubeWP submissions before its AJAX or REST callback runs.
 *
 * @since 1.8.0
 */
class SubmissionHandler {

	/**
	 * Parent integration.
	 *
	 * @since 1.8.0
	 *
	 * @var CubeWPIntegration
	 */
	private $integration;

	/**
	 * Construct the submission service.
	 *
	 * @since 1.8.0
	 *
	 * @param CubeWPIntegration $integration Parent integration.
	 */
	public function __construct( CubeWPIntegration $integration ) {

		$this->integration = $integration;
	}

	/**
	 * Verify CubeWP's nonce before inspecting an AJAX submission.
	 *
	 * Direct HTTP requests to admin-ajax.php use the same guarded hooks.
	 *
	 * @since 1.8.0
	 */
	public function handle_submission(): void {

		if ( ! isset( $_POST['security_nonce'] ) || ! is_string( $_POST['security_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security_nonce'] ) ), 'cubewp_forms_submit' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated and normalized in should_block_submission().
		if ( $this->should_block_submission( wp_unslash( $_POST ) ) ) {
			$this->abort_submission();
		}
	}

	/**
	 * Guard CubeWP's REST route, which calls the provider directly without AJAX hooks.
	 *
	 * @since 1.8.0
	 *
	 * @param mixed           $response Existing short-circuit response.
	 * @param array           $handler  REST callback definition (unused).
	 * @param WP_REST_Request $request  Incoming REST request.
	 *
	 * @return mixed Existing response, or a spam error.
	 */
	public function handle_rest_submission( $response, array $handler, WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( $response !== null || strcasecmp( untrailingslashit( $request->get_route() ), '/cubewp-custom-form/v1/submit' ) !== 0 ) {
			return $response;
		}

		$data = $request->get_param( 'cwp_query' );

		if ( ! is_array( $data ) ) {
			return $response;
		}

		// CubeWP unslashes the REST form ID but passes the body fields directly to its sanitizer.
		if ( isset( $data['cwp_custom_form']['form_id'] ) && is_scalar( $data['cwp_custom_form']['form_id'] ) ) {
			$data['cwp_custom_form']['form_id'] = wp_unslash( $data['cwp_custom_form']['form_id'] );
		}

		// Shared signal enrichers consume WordPress's slashed POST data.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- CubeWP exposes this public REST route without a nonce.
		$previous_post = $_POST;

		try {
			$_POST   = wp_slash( $data );
			$blocked = $this->should_block_submission( $data );
		} finally {
			$_POST = $previous_post;
		}

		return $blocked ? new WP_Error( 'activelayer_spam', $this->integration->get_sync_block_message(), [ 'status' => 403 ] ) : $response;
	}

	/**
	 * Validate the form, resolve allowed fields and run the shared spam pipeline.
	 *
	 * @since 1.8.0
	 *
	 * @param array $data Provider request with an unslashed form ID.
	 *
	 * @return bool Whether unsupported fields or a spam verdict require blocking.
	 */
	private function should_block_submission( array $data ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$form_id = 0;

		try {
			$form = $data['cwp_custom_form'] ?? null;

			if ( ! $this->integration->is_enabled() || ! is_array( $form ) || ! isset( $form['form_id'] ) || ! is_scalar( $form['form_id'] ) ) {
				return false;
			}

			// Match CubeWP before resolving the protected form, including HTML and percent escapes.
			$form_id = absint( sanitize_text_field( $form['form_id'] ) );

			if ( $form_id <= 0 || get_post_type( $form_id ) !== 'cwp_forms' || empty( $this->integration->get_admin_settings()->get_form_settings( $form_id )['enabled'] ) ) {
				return false;
			}

			$definitions = function_exists( 'CWP' ) ? CWP()->get_custom_fields( 'custom_forms' ) : [];
			$field_names = explode( ',', (string) get_post_meta( $form_id, '_cwp_group_fields', true ) );
			$options     = $this->get_field_options( $field_names, is_array( $definitions ) ? $definitions : [] );
			$fields      = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];

			// CubeWP also delivers unknown fields; reject them before any provider side effects.
			if ( $this->has_unsupported_fields( $fields, $options ) ) {
				return true;
			}

			$meta   = $this->integration->get_form_meta( $form_id );
			$result = $this->integration->process_submission_synchronously(
				[
					'fields'        => $fields,
					'field_options' => $options,
				],
				$meta
			);

			return ! empty( $result['success'] ) && ( $result['verdict'] ?? 'clean' ) === 'spam';
		} catch ( Exception $exception ) {
			Logger::log(
				'CubeWP Forms sync mode: unexpected error',
				[
					'form_id' => $form_id,
					'error'   => $exception->getMessage(),
				]
			);

			return false;
		}
	}

	/**
	 * Resolve native fields in form order, including address companions and repeater children.
	 *
	 * @since 1.8.0
	 *
	 * @param array $names       Selected field names.
	 * @param array $definitions Server-side CubeWP field registry.
	 * @param array $parents     Ancestors used to stop cyclic repeater definitions.
	 *
	 * @return array Allowed field definitions for this form or repeater.
	 */
	private function get_field_options( array $names, array $definitions, array $parents = [] ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Resolves native field shapes with a cycle guard.

		$options = [];

		foreach ( $names as $name ) {
			if ( ! isset( $definitions[ $name ] ) || ! is_array( $definitions[ $name ] ) ) {
				continue;
			}

			$field = $definitions[ $name ];

			if ( ( $field['type'] ?? '' ) === 'repeating_field' ) {
				$children            = in_array( $name, $parents, true ) ? [] : explode( ',', (string) ( $field['sub_fields'] ?? '' ) );
				$field['sub_fields'] = $this->get_field_options( $children, $definitions, array_merge( $parents, [ $name ] ) );
			}

			$options[ $name ] = $field;

			if ( ( $field['type'] ?? '' ) === 'google_address' ) {
				// CubeWP generates these siblings; explicit selected definitions retain their privacy rules.
				$address_name = $field['name'] ?? $name;
				$options     += [
					$address_name . '_lat' => [ 'type' => 'textarea' ],
					$address_name . '_lng' => [ 'type' => 'textarea' ],
				];
			}
		}

		return $options;
	}

	/**
	 * Reject field names that CubeWP would deliver outside the selected native schema.
	 *
	 * @since 1.8.0
	 *
	 * @param array $fields  Submitted field values.
	 * @param array $options Resolved server-side definitions.
	 *
	 * @return bool Whether any top-level or repeater field is unsupported.
	 */
	private function has_unsupported_fields( array $fields, array $options ): bool {

		if ( array_diff_key( $fields, $options ) ) {
			return true;
		}

		foreach ( $fields as $name => $value ) {
			if ( ( $options[ $name ]['type'] ?? '' ) === 'repeating_field' && ( ! is_array( $value ) || $this->has_unsupported_fields( $value, $options[ $name ]['sub_fields'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the response shape expected by CubeWP's own frontend script.
	 *
	 * @since 1.8.0
	 */
	protected function abort_submission(): void {

		wp_send_json(
			[
				'type' => 'error',
				'msg'  => wp_kses_post( $this->integration->get_sync_block_message() ),
			]
		);
	}
}

<?php

namespace ActiveLayer\Integrations\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds form/post context from submission metadata.
 *
 * Extracted from BaseFormIntegration to keep context-building
 * logic isolated from form integrations.
 *
 * @since 1.2.0
 */
class FormContextBuilder {

	/**
	 * Append context derived from $meta to $normalized_data['context'].
	 *
	 * Prefers post-meta (post_id, post_title, etc.) when present;
	 * falls back to form-meta (form_id, form_name, page_id). A provider-supplied
	 * `payment` map is merged in when present.
	 *
	 * @since 1.2.0
	 * @since 1.4.0 Pass through provider payment signals.
	 *
	 * @param array $normalized_data Normalized submission data.
	 * @param array $meta            Form/post metadata.
	 *
	 * @return array Submission data with merged context.
	 */
	public function build( array $normalized_data, array $meta ): array {

		$context = $this->from_post_meta( $meta );

		if ( empty( $context ) ) {
			$context = $this->from_form_meta( $meta );
		}

		$context = array_merge( $context, $this->from_payment_meta( $meta ) );

		if ( empty( $context ) ) {
			return $normalized_data;
		}

		$existing = ( isset( $normalized_data['context'] ) && is_array( $normalized_data['context'] ) )
			? $normalized_data['context']
			: [];

		$normalized_data['context'] = array_merge( $existing, $context );

		return $normalized_data;
	}

	/**
	 * Build context from post-related metadata.
	 *
	 * @since 1.2.0
	 *
	 * @param array $meta Submission metadata.
	 *
	 * @return array
	 */
	private function from_post_meta( array $meta ): array {

		$post_id = isset( $meta['post_id'] ) ? (int) $meta['post_id'] : 0;

		if ( $post_id <= 0 ) {
			return [];
		}

		$context = [
			'post_id' => sanitize_text_field( (string) $post_id ),
		];

		foreach ( [ 'post_title', 'post_url', 'post_type', 'comment_type' ] as $key ) {
			if ( ! empty( $meta[ $key ] ) ) {
				$context[ $key ] = sanitize_text_field( (string) $meta[ $key ] );
			}
		}

		return $context;
	}

	/**
	 * Build context from form-related metadata.
	 *
	 * @since 1.2.0
	 *
	 * @param array $meta Submission metadata.
	 *
	 * @return array
	 */
	private function from_form_meta( array $meta ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$form_id          = (string) ( $meta['form_id'] ?? '' );
		$form_name_source = (string) ( $meta['form_title'] ?? ( $meta['form_name'] ?? '' ) );
		$context          = [];

		if ( $form_id !== '' ) {
			$context['form_id'] = sanitize_text_field( $form_id );
		}

		if ( $form_name_source !== '' ) {
			$context['form_name'] = sanitize_text_field( $form_name_source );
		}

		if ( ! empty( $meta['page_id'] ) ) {
			$context['page_id'] = (int) $meta['page_id'];
		}

		return $context;
	}

	/**
	 * Build a sanitized payment context from a provider-supplied `payment` map.
	 *
	 * Providers compute their own payment signals and expose them via
	 * `$meta['payment']`. The builder stays provider-agnostic: it only knows the
	 * two recognised boolean keys and casts them to bool.
	 *
	 * @since 1.4.0
	 *
	 * @param array $meta Submission metadata.
	 *
	 * @return array
	 */
	private function from_payment_meta( array $meta ): array {

		if ( empty( $meta['payment'] ) || ! is_array( $meta['payment'] ) ) {
			return [];
		}

		$context = [];

		foreach ( [ 'has_payment', 'payment_provided' ] as $key ) {
			if ( isset( $meta['payment'][ $key ] ) ) {
				$context[ $key ] = (bool) $meta['payment'][ $key ];
			}
		}

		return $context;
	}
}

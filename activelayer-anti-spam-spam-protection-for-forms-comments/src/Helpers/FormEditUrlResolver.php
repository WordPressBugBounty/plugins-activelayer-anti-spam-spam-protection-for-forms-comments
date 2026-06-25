<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ActiveLayer\Integrations\IntegrationRegistry;

/**
 * Resolves admin edit URLs for form submissions.
 *
 * Centralises the logic for building edit-links from a provider slug,
 * form ID and optional submission data, handling special cases such as
 * WordPress Comments and Elementor Forms.
 *
 * @since 1.1.0
 *
 * @package ActiveLayer\Helpers
 */
class FormEditUrlResolver {

	/**
	 * Resolve the admin edit URL for a given form submission.
	 *
	 * @since 1.1.0
	 * @since 1.5.0 Added EDD Reviews (`edd_reviews`) download edit-link branch.
	 *
	 * @param string $provider_slug Provider identifier (e.g. 'wp_comments', 'elementor_forms').
	 * @param string $form_id       Form identifier; interpretation varies by provider.
	 * @param array  $submission    Full submission row data (optional, used for entry_id, form_data, etc.).
	 *
	 * @return string Edit URL or empty string if unavailable.
	 */
	public static function resolve( string $provider_slug, string $form_id, array $submission = [] ): string {

		if ( $provider_slug === 'wp_comments' ) {
			return self::resolve_comment_url( $form_id, $submission );
		}

		if ( $provider_slug === 'wc_reviews' ) {
			return self::resolve_product_url( $form_id );
		}

		if ( $provider_slug === 'edd_reviews' ) {
			return self::resolve_download_url( $form_id );
		}

		if ( $provider_slug === 'elementor_forms' ) {
			return self::resolve_elementor_url( $submission );
		}

		return self::resolve_via_registry( $provider_slug, $form_id );
	}

	/**
	 * Resolve edit URL for WooCommerce product reviews.
	 *
	 * @since 1.2.0
	 *
	 * @param string $form_id Product ID used as form identifier.
	 *
	 * @return string Edit URL.
	 */
	private static function resolve_product_url( string $form_id ): string {

		$post_id = (int) $form_id;

		if ( $post_id <= 0 || get_post_type( $post_id ) !== 'product' ) {
			return '';
		}

		return admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );
	}

	/**
	 * Resolve edit URL for Easy Digital Downloads download reviews.
	 *
	 * @since 1.5.0
	 *
	 * @param string $form_id Download ID used as form identifier.
	 *
	 * @return string Edit URL or empty string if the download no longer exists.
	 */
	private static function resolve_download_url( string $form_id ): string {

		$post_id = (int) $form_id;

		if ( $post_id <= 0 || get_post_type( $post_id ) !== 'download' ) {
			return '';
		}

		return admin_url( sprintf( 'post.php?post=%d&action=edit', $post_id ) );
	}

	/**
	 * Resolve edit URL for WordPress comments.
	 *
	 * @since 1.1.0
	 *
	 * @param string $form_id    Post ID used as form identifier.
	 * @param array  $submission Submission data with optional entry_id.
	 *
	 * @return string Edit URL.
	 */
	private static function resolve_comment_url( string $form_id, array $submission ): string {

		$entry_id = isset( $submission['entry_id'] ) ? (string) $submission['entry_id'] : '';

		if ( $entry_id !== '' ) {
			return admin_url( sprintf( 'comment.php?action=editcomment&c=%s', rawurlencode( $entry_id ) ) );
		}

		return admin_url( sprintf( 'post.php?post=%s&action=edit', rawurlencode( $form_id ) ) );
	}

	/**
	 * Resolve edit URL for Elementor Forms.
	 *
	 * @since 1.1.0
	 *
	 * @param array $submission Submission data with form_data context.
	 *
	 * @return string Edit URL or empty string.
	 */
	private static function resolve_elementor_url( array $submission ): string {

		$form_data = $submission['form_data'] ?? [];
		$page_id   = $form_data['context']['page_id'] ?? '';

		if ( $page_id !== '' ) {
			return admin_url( sprintf( 'post.php?post=%s&action=elementor', rawurlencode( (string) $page_id ) ) );
		}

		return '';
	}

	/**
	 * Resolve edit URL via IntegrationRegistry dynamic lookup.
	 *
	 * @since 1.1.0
	 *
	 * @param string $provider_slug Provider identifier.
	 * @param string $form_id       Form identifier.
	 *
	 * @return string Edit URL or empty string.
	 */
	private static function resolve_via_registry( string $provider_slug, string $form_id ): string {

		$admin_settings = IntegrationRegistry::get_instance()->get_form_admin_settings( $provider_slug );

		if ( $admin_settings === null ) {
			return '';
		}

		$template = $admin_settings->get_form_edit_url_template();

		if ( $template === '' ) {
			return '';
		}

		return admin_url( sprintf( $template, (int) $form_id ) );
	}
}

<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Display Resolver.
 *
 * Resolves human-readable display names for form/post references across
 * submissions. Used by both the submissions table and single submission view.
 *
 * @since 1.1.0
 * @since 1.2.0 Added WooCommerce Registration (`wc_registration`) branch resolving to customer login or email.
 *
 * @package ActiveLayer\Helpers
 */
class FormDisplayResolver {

	/**
	 * Resolve the display name for a form/post reference.
	 *
	 * Checks form_data context first, then handles WooCommerce Registration
	 * (no per-form concept — display the customer login/email), then falls
	 * back to post title lookup for providers that store post IDs.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Added `wc_registration` branch (customer login/email).
	 * @since 1.3.0 Added `buddypress` branch (signup login/email).
	 * @since 1.3.0 Added `buddyboss` branch (BuddyBoss Platform signup, same shape as BuddyPress).
	 * @since 1.4.0 Added `affiliatewp` branch (affiliate registration login/email).
	 * @since 1.4.0 Added `memberpress` branch (membership signup login/email).
	 * @since 1.5.0 Added `edd_registration` (customer login/email) and `edd_reviews` (download title) branches.
	 *
	 * @param array $submission Submission data.
	 *
	 * @return string Human-readable form name.
	 */
	public static function resolve_name( array $submission ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$form_data = $submission['form_data'] ?? [];
		$form_name = $form_data['context']['form_name'] ?? '';

		if ( $form_name !== '' ) {
			return $form_name;
		}

		$provider_slug = $submission['provider'] ?? '';

		if ( $provider_slug === 'wc_registration' || $provider_slug === 'buddypress' || $provider_slug === 'buddyboss' || $provider_slug === 'affiliatewp' || $provider_slug === 'memberpress' || $provider_slug === 'edd_registration' ) {
			$login = $form_data['name'] ?? '';
			$email = $form_data['email'] ?? '';

			if ( $login !== '' ) {
				return (string) $login;
			}

			if ( $email !== '' ) {
				return (string) $email;
			}

			return __( 'Unknown Registration', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		return self::resolve_from_post( $submission );
	}

	/**
	 * Fallback: resolve display name from the WP post title (Comments, CF7).
	 *
	 * @since 1.1.0
	 * @since 1.5.0 Added `edd_reviews` (download post title) branch.
	 *
	 * @param array $submission Submission data.
	 *
	 * @return string Post title or fallback label.
	 */
	public static function resolve_from_post( array $submission ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh

		$provider_slug     = $submission['provider'] ?? '';
		$post_id_providers = [ 'wp_comments', 'contact_form_7', 'wc_reviews', 'edd_reviews' ];

		if ( in_array( $provider_slug, $post_id_providers, true ) ) {
			$title = get_the_title( (int) ( $submission['form_id'] ?? 0 ) );

			if ( $title !== '' ) {
				return $title;
			}
		}

		if ( $provider_slug === 'wc_reviews' ) {
			return __( 'Unknown Product', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		if ( $provider_slug === 'edd_reviews' ) {
			return __( 'Unknown Download', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
		}

		return __( 'Unknown Form', 'activelayer-anti-spam-spam-protection-for-forms-comments' );
	}

	/**
	 * Get the admin edit URL for a form based on the provider.
	 *
	 * @since 1.1.0
	 *
	 * @param array $submission Submission data.
	 *
	 * @return string Edit URL or empty string if unavailable.
	 */
	public static function resolve_edit_url( array $submission ): string {

		$provider_slug = $submission['provider'] ?? '';
		$form_id       = (string) ( $submission['form_id'] ?? '' );

		return FormEditUrlResolver::resolve( $provider_slug, $form_id, $submission );
	}
}

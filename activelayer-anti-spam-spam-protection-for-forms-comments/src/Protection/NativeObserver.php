<?php

namespace ActiveLayer\Protection;

use ActiveLayer\Integrations\IntegrationRegistry;

use ActiveLayer\Integrations\MemberPress\SubmissionHandler as MembershipHandler;

use ActiveLayer\Integrations\EasyDigitalDownloads\Reviews\ReviewsIntegration as EddReviews;

use ActiveLayer\Integrations\WooCommerce\Reviews\ReviewsIntegration as WooReviews;

use ActiveLayer\Integrations\WooCommerce\Registration\RegistrationSubmissionHandler as WooRegistrationHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native eligible submission hooks remain active before a key is connected.
 *
 * @since 1.8.0
 */
class NativeObserver {

	/**
	 * Local interruption observer.
	 *
	 * @since 1.8.0
	 *
	 * @var ProtectionMonitor
	 */
	private $monitor;

	/**
	 * Gravity Forms IDs validated as final native submissions in this request.
	 *
	 * @since 1.8.0
	 *
	 * @var array
	 */
	private $gravity_forms = [];

	/**
	 * Inject the request-local observer.
	 *
	 * @since 1.8.0
	 *
	 * @param ProtectionMonitor $monitor Local observer.
	 */
	public function __construct( ProtectionMonitor $monitor ) {

		$this->monitor = $monitor;
	}

	/**
	 * Register provider-native hooks without initializing spam protection.
	 *
	 * @since 1.8.0
	 */
	public function hooks(): void {

		foreach ( [
			'wpforms_process_complete',
			'wpcf7_before_send_mail',
			'ninja_forms_after_submission',
			'frm_after_create_entry',
			'fluentform/before_insert_submission',
			'srfm_before_submission',
			'wsf_submit_validate',
			'gform_validation',
			'gform_after_submission',
			'elementor_pro/forms/new_record',
			'forminator_spam_protection',
			'comment_post',
			'woocommerce_created_customer',
			'edd_reviews_after_insert_review',
			'affwp_process_register_form',
			'mepr_validate_signup',
			'bp_signup_validate',
			'edd_process_register_form',
			'wffn_optin_form_submit',
		] as $hook ) {
			add_filter( $hook, [ $this, 'observe_native' ], 99, 4 );
		}
	}

	/**
	 * Read identifiers/configuration only and preserve every native filter value.
	 *
	 * The request-local identity merges provider and shared quota-guard callbacks.
	 * Accepted registration hooks avoid counting failed native validation.
	 *
	 * @since 1.8.0
	 *
	 * @param mixed ...$args Native hook arguments.
	 *
	 * @return mixed Original filter value.
	 */
	public function observe_native( ...$args ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Explicit native provider argument adapters.

		$first = $args[0] ?? null;

		if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) !== 'POST' ) {
			return $first;
		}

		switch ( current_filter() ) {
			case 'wpforms_process_complete':
				$form = $args[2] ?? [];

				$this->monitor->observe( 'wpforms', $form['id'] ?? 0, $form );
				break;

			case 'wpcf7_before_send_mail':
				if ( is_object( $first ) && method_exists( $first, 'id' ) && empty( $args[1] ) ) {
					$this->monitor->observe( 'contact_form_7', $first->id() );
				}
				break;

			case 'ninja_forms_after_submission':
				if ( empty( $first['errors'] ) ) {
					$this->monitor->observe( 'ninja_forms', $first['form_id'] ?? 0 );
				}
				break;

			case 'frm_after_create_entry':
				$this->monitor->observe( 'formidable_forms', $args[1] ?? 0 );
				break;

			case 'fluentform/before_insert_submission':
				$this->monitor->observe( 'fluent_forms', $args[2]->id ?? 0 );
				break;

			case 'srfm_before_submission':
				$this->monitor->observe( 'sureforms', $first['form_id'] ?? 0 );
				break;

			case 'wsf_submit_validate':
				if ( empty( $first ) && in_array( $args[1] ?? '', [ 'submit', 'action' ], true ) ) {
					$this->monitor->observe( 'ws_form', $args[2]->form_id ?? 0 );
				}
				break;

			case 'gform_validation':
				$id = (int) ( $first['form']['id'] ?? 0 );
				// GF already validated the form; only inspect its numeric page marker.
				$page                       = isset( $_POST[ 'gform_target_page_number_' . $id ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'gform_target_page_number_' . $id ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- GF owns native submission validation.
				$this->gravity_forms[ $id ] = ! empty( $first['is_valid'] ) && in_array( $args[1] ?? '', [ '', 'form-submit' ], true ) && in_array( $page, [ '', '0' ], true );
				break;

			case 'gform_after_submission':
				$id = (int) ( $args[1]['id'] ?? 0 );

				if ( ! empty( $this->gravity_forms[ $id ] ) ) {
					$this->monitor->observe( 'gravity_forms', $id );
					unset( $this->gravity_forms[ $id ] );
				}
				break;

			case 'elementor_pro/forms/new_record':
				if ( is_object( $first ) && empty( $args[1]->errors ) ) {
					$this->monitor->observe( 'elementor_forms', abs( crc32( (string) $first->get_form_settings( 'id' ) ) ) );
				}
				break;

			case 'forminator_spam_protection':
				if ( ! $first && ( $args[3] ?? '' ) === 'form' ) {
					$this->monitor->observe( 'forminator', $args[2] ?? 0 );
				}
				break;

			case 'comment_post':
				if ( in_array( (string) ( $args[1] ?? '' ), [ 'spam', 'trash' ], true ) ) {
					break;
				}
				$comment = $args[2] ?? [];
				$type    = $comment['comment_type'] ?? '';

				if ( ! in_array( $type, [ '', 'comment', 'review', 'edd_review' ], true ) || sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) !== 'POST' ) {
					break;
				}
				$slug = $type === 'edd_review' ? 'edd_reviews' : ( $type === 'review' ? 'wc_reviews' : 'wp_comments' );

				$this->observe_comment( $slug, $comment );
				break;

			case 'wffn_optin_form_submit':
				$this->monitor->observe( 'funnelkit', $first );
				break;

			case 'edd_reviews_after_insert_review':
				$comment = $args[1] ?? [];

				if ( ! is_wp_error( $comment['review_approved'] ?? null ) && ! in_array( (string) ( $comment['review_approved'] ?? '' ), [ 'spam', 'trash' ], true ) ) {
					$this->observe_comment( 'edd_reviews', $comment );
				}
				break;

			case 'woocommerce_created_customer':
				if ( isset( $_POST['register'], $_POST['woocommerce-register-nonce'] ) && ! ( function_exists( 'is_checkout' ) && is_checkout() ) && ! WooRegistrationHandler::is_store_api_checkout_context() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce has completed registration and nonce validation.
					$this->monitor->observe( 'wc_registration', 'wc_registration' );
				}
				break;

			case 'affwp_process_register_form':
				$register = function_exists( 'affiliate_wp' ) ? affiliate_wp()->register : null;

				if ( is_object( $register ) && method_exists( $register, 'get_errors' ) && ! $register->get_errors() ) {
					$this->monitor->observe( 'affiliatewp', 'affwp_register' );
				}
				break;

			case 'mepr_validate_signup':
				$this->observe_membership( $first );
				break;

			case 'bp_signup_validate':
				$bp = function_exists( 'buddypress' ) ? buddypress() : null;

				if ( is_object( $bp ) && isset( $bp->signup ) && empty( $bp->signup->errors ) ) {
					$slug = defined( 'BP_PLATFORM_VERSION' ) ? 'buddyboss' : 'buddypress';

					$this->monitor->observe( $slug, 'bp_signup' );
				}
				break;

			case 'edd_process_register_form':
				if ( function_exists( 'edd_get_errors' ) && ! edd_get_errors() ) {
					$this->monitor->observe( 'edd_registration', 'edd_register' );
				}
				break;
		}

		return $first;
	}
	/**
	 * Mirror review and comment exclusions without storing submitted content.
	 *
	 * @since 1.8.0
	 *
	 * @param string $slug    Comment integration slug.
	 * @param array  $comment Native comment context, used only for eligibility.
	 */
	private function observe_comment( string $slug, array $comment ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity, Generic.Metrics.NestingLevel -- Mirrors provider review exclusions.

		$post_id = (int) ( $comment['comment_post_ID'] ?? 0 );

		if ( $slug !== 'wp_comments' ) {
			$woo         = $slug === 'wc_reviews';
			$class       = $woo ? WooReviews::class : EddReviews::class;
			$integration = new $class();
			$settings    = $integration->get_admin_settings()->get_review_settings();

			if ( get_post_type( $post_id ) !== ( $woo ? 'product' : 'download' ) || ( ! $woo && ! empty( $comment['comment_parent'] ) ) ) {
				return;
			}
			if ( is_user_logged_in() ) {
				if ( empty( $settings['check_logged_in_users'] ) ) {
					return;
				}
				if ( empty( $settings['check_verified_owners'] ) ) {
					$user_id = (int) ( $comment['user_id'] ?? 0 );
					$bought  = $woo
						? ( function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( $comment['comment_author_email'] ?? '', $user_id, $post_id ) )
						: ( function_exists( 'edd_has_user_purchased' ) && edd_has_user_purchased( $user_id, $post_id ) );

					if ( $bought ) {
						return;
					}
				}
			}
		}
		$this->monitor->observe( $slug, $post_id );
	}

	/**
	 * Mirror MemberPress registration-only and paid-signup exclusions.
	 *
	 * @since 1.8.0
	 *
	 * @param mixed $errors Existing native validation errors.
	 */
	private function observe_membership( $errors ): void {

		$integration = IntegrationRegistry::get_instance()->get_integration( 'memberpress' );

		if ( ! $integration || $errors || is_user_logged_in() ) {
			return;
		}
		$handler = new MembershipHandler( $integration );
		$id      = $handler->resolve_membership_id();

		if ( ! $integration->should_block_paid_signups() && $integration->signup_takes_payment_now( $id, $handler->resolve_coupon_code() ) ) {
			return;
		}
		$this->monitor->observe( 'memberpress', $id );
	}
}

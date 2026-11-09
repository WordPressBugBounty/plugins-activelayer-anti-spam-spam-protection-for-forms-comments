<?php

namespace ActiveLayer\Compatibility;

use Elementor\Plugin as ElementorPlugin;
use CubeWp_Frontend_Search_Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Avoid CubeWP Framework 1.1.31's missing Elementor document fatal.
 *
 * The upstream widget enqueue callback dereferences a false document on native
 * pages such as BuddyPress signup. Valid documents and preview retain the native
 * callback. This compatibility guard is independent of spam protection settings.
 *
 * @since 1.8.0
 */
class CubeWPElementorCompatibility {

	/**
	 * Check document availability immediately before CubeWP's enqueue callback.
	 *
	 * @since 1.8.0
	 */
	public function hooks(): void {

		add_action( 'wp_enqueue_scripts', [ $this, 'guard_missing_document' ], 9 );
	}

	/**
	 * Leave native Elementor rendering intact unless its document is missing.
	 *
	 * @since 1.8.0
	 */
	public function guard_missing_document(): void {

		if ( ! $this->is_affected_request() ) {
			return;
		}

		$elementor = ElementorPlugin::$instance;

		if ( $elementor->preview->is_preview_mode() ) {
			return;
		}

		if ( $elementor->documents->get_doc_for_frontend( get_the_ID() ) !== false ) {
			return;
		}

		$this->remove_unsafe_callback();
	}

	/**
	 * Limit the guard to the verified upstream version and native code path.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Whether the request can reach the known upstream fatal.
	 */
	private function is_affected_request(): bool {

		return defined( 'CUBEWP_VERSION' )
			&& CUBEWP_VERSION === '1.1.31'
			&& class_exists( ElementorPlugin::class )
			&& is_singular();
	}

	// phpcs:disable WPForms.PHP.HooksMethod -- Query-dependent removal must run after WordPress resolves its singular document.
	/**
	 * Remove only the affected native widget enqueue callback for this request.
	 *
	 * @since 1.8.0
	 */
	private function remove_unsafe_callback(): void {

		global $wp_filter;

		$callbacks = $wp_filter['wp_enqueue_scripts']->callbacks[10] ?? [];

		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];

			if ( is_array( $function ) && $function[0] instanceof CubeWp_Frontend_Search_Filter && $function[1] === 'enqueue_custom_widget_scripts' ) {
				remove_action( 'wp_enqueue_scripts', $function, 10 );
			}
		}
	}
	// phpcs:enable WPForms.PHP.HooksMethod
}

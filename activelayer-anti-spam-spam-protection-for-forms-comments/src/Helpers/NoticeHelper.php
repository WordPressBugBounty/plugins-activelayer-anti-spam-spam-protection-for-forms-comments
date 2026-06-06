<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper utilities for rendering WordPress admin notices.
 *
 * Provides consistent styling, escaping, and dismissibility handling
 * for all admin notices throughout the plugin.
 *
 * @since 1.0.0
 *
 * @package ActiveLayer\Helpers
 */
class NoticeHelper {

	/**
	 * Notice type: success.
	 *
	 * @since 1.0.0
	 */
	public const TYPE_SUCCESS = 'success';

	/**
	 * Notice type: error.
	 *
	 * @since 1.0.0
	 */
	public const TYPE_ERROR = 'error';

	/**
	 * Notice type: warning.
	 *
	 * @since 1.0.0
	 */
	public const TYPE_WARNING = 'warning';

	/**
	 * Notice type: info.
	 *
	 * @since 1.0.0
	 */
	public const TYPE_INFO = 'info';

	/**
	 * Allowed notice types.
	 *
	 * @since 1.0.0
	 */
	private const ALLOWED_TYPES = [
		self::TYPE_SUCCESS,
		self::TYPE_ERROR,
		self::TYPE_WARNING,
		self::TYPE_INFO,
	];

	/**
	 * Render a simple admin notice.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message     Notice message (will be escaped).
	 * @param string $type        Notice type (success|error|warning|info).
	 * @param bool   $dismissible Whether notice can be dismissed.
	 */
	public static function render(
		string $message,
		string $type = self::TYPE_SUCCESS,
		bool $dismissible = true
	): void {

		if ( empty( $message ) ) {
			return;
		}

		$type = self::validate_type( $type );

		printf(
			'<div class="notice notice-%1$s%2$s"><p>%3$s</p></div>',
			esc_attr( $type ),
			$dismissible ? ' is-dismissible' : '',
			esc_html( $message )
		);
	}

	/**
	 * Render a notice with a title.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title       Bold title text.
	 * @param string $message     Notice message.
	 * @param string $type        Notice type.
	 * @param bool   $dismissible Whether dismissible.
	 */
	public static function render_with_title(
		string $title,
		string $message,
		string $type = self::TYPE_SUCCESS,
		bool $dismissible = true
	): void {

		if ( empty( $title ) && empty( $message ) ) {
			return;
		}

		$type = self::validate_type( $type );

		printf(
			'<div class="notice notice-%1$s%2$s"><p><strong>%3$s</strong> %4$s</p></div>',
			esc_attr( $type ),
			$dismissible ? ' is-dismissible' : '',
			esc_html( $title ),
			esc_html( $message )
		);
	}

	/**
	 * Render a notice with HTML content.
	 *
	 * Use this for notices that need links or formatted content.
	 * Content will be sanitized with wp_kses_post.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html_content HTML content (will be sanitized with wp_kses_post).
	 * @param string $type         Notice type.
	 * @param bool   $dismissible  Whether dismissible.
	 */
	public static function render_html(
		string $html_content,
		string $type = self::TYPE_SUCCESS,
		bool $dismissible = true
	): void {

		if ( empty( $html_content ) ) {
			return;
		}

		$type = self::validate_type( $type );

		printf(
			'<div class="notice notice-%1$s%2$s">%3$s</div>',
			esc_attr( $type ),
			$dismissible ? ' is-dismissible' : '',
			wp_kses_post( $html_content )
		);
	}

	/**
	 * Render an inline notice.
	 *
	 * Inline notices are used within forms or sections, not hooked to admin_notices.
	 * They have the 'inline' class and are typically not dismissible.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Notice message (will be escaped).
	 * @param string $type    Notice type.
	 */
	public static function render_inline(
		string $message,
		string $type = self::TYPE_WARNING
	): void {

		if ( empty( $message ) ) {
			return;
		}

		$type = self::validate_type( $type );

		printf(
			'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Render an inline notice with HTML content.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html_content HTML content (will be sanitized with wp_kses_post).
	 * @param string $type         Notice type.
	 */
	public static function render_inline_html(
		string $html_content,
		string $type = self::TYPE_WARNING
	): void {

		if ( empty( $html_content ) ) {
			return;
		}

		$type = self::validate_type( $type );

		printf(
			'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
			esc_attr( $type ),
			wp_kses_post( $html_content )
		);
	}

	/**
	 * Validate and normalize notice type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Notice type to validate.
	 *
	 * @return string Validated type or default (success).
	 */
	private static function validate_type( string $type ): string {

		if ( in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return $type;
		}

		return self::TYPE_SUCCESS;
	}
}

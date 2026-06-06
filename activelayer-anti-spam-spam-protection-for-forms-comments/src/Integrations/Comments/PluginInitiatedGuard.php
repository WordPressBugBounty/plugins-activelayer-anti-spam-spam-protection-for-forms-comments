<?php

namespace ActiveLayer\Integrations\Comments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guard for plugin-initiated WordPress comment status changes.
 *
 * Used by comment-backed integrations (WordPress Comments, WooCommerce Reviews)
 * to mark the call stack while ActiveLayer itself invokes
 * `wp_spam_comment()` / `wp_set_comment_status()` / `wp_trash_comment()` so
 * that listeners on `transition_comment_status` (e.g. native moderation
 * feedback) can skip events that originate from this plugin and avoid
 * feedback loops.
 *
 * A single global depth counter is used — not a per-class static — so a
 * single listener can ask "is any ActiveLayer-initiated status change in
 * progress right now?" without knowing which integration triggered it.
 *
 * @since 1.2.0
 *
 * @package ActiveLayer\Integrations\Comments
 */
final class PluginInitiatedGuard {

	/**
	 * Re-entrancy depth counter.
	 *
	 * @since 1.2.0
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Whether a plugin-initiated comment status change is currently in progress.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public static function is_active(): bool {

		return self::$depth > 0;
	}

	/**
	 * Run a callback while flagging the current call stack as plugin-initiated.
	 *
	 * Uses a depth counter (not a boolean) so nested calls remain correct.
	 * `try`/`finally` guarantees the counter is decremented even when the
	 * callback throws.
	 *
	 * @since 1.2.0
	 *
	 * @param callable $callback Callback to execute.
	 *
	 * @return mixed Callback return value.
	 */
	public static function run( callable $callback ) {

		++self::$depth;

		try {
			return $callback();
		} finally {
			--self::$depth;
		}
	}
}

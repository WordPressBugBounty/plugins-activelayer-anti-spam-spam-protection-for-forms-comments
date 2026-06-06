<?php

namespace ActiveLayer\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SafeUnserializer.
 *
 * Drop-in replacement for maybe_unserialize() that forbids object instantiation,
 * neutralising PHP object injection POP gadget chains when reading values from
 * external data sources (third-party plugin tables, options that may transit
 * untrusted code paths, etc.).
 *
 * @since 1.2.0
 */
class SafeUnserializer {

	/**
	 * Unserialize a stored value without instantiating PHP objects.
	 *
	 * Mirrors maybe_unserialize() semantics: returns the original value when it
	 * is not a serialized string. Uses unserialize() with allowed_classes=>false
	 * so attacker-controlled `O:Class:...` payloads cannot trigger __wakeup,
	 * __destruct or other magic-method gadgets.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Raw value (typically read from a third-party DB table).
	 *
	 * @return mixed Unserialized scalar/array, or the original value when not serialized.
	 */
	public static function unserialize( $value ) {

		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged
		$result = @unserialize( $value, [ 'allowed_classes' => false ] );

		// Distinguish a legitimate serialized boolean false from a decode failure.
		if ( $result === false && $value !== 'b:0;' ) {
			return $value;
		}

		return $result;
	}
}

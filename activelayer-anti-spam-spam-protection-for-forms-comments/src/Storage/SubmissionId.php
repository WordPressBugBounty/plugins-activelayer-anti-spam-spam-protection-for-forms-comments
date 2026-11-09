<?php

namespace ActiveLayer\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate persisted submission identifiers before integer-based database access.
 *
 * @since 1.8.0
 */
final class SubmissionId {

	/**
	 * Accept only positive, canonical decimal IDs representable by this PHP runtime.
	 *
	 * Rejects request-local UUIDs, numeric prefixes, aliases and integer overflow.
	 *
	 * @since 1.8.0
	 *
	 * @param string $id Untrusted submission identifier.
	 *
	 * @return bool Whether the identifier can be converted without changing identity.
	 */
	public static function is_valid( string $id ): bool {

		return (int) $id > 0 && (string) (int) $id === $id;
	}
}

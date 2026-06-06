<?php

namespace ActiveLayer\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles cache operations for submissions and related metadata.
 *
 * @since 1.0.0
 */
class SubmissionCache {

	/**
	 * Cache group name.
	 *
	 * @since 1.0.0
	 */
	public const CACHE_GROUP = 'activelayer';

	/**
	 * Cache key used to version list caches.
	 *
	 * @since 1.0.0
	 */
	private const LIST_CACHE_VERSION_KEY = 'list_cache_version';

	/**
	 * Get the cache group used for all storage caches.
	 *
	 * @since 1.0.0
	 *
	 * @return string Cache group name.
	 */
	public function get_cache_group(): string {

		return self::CACHE_GROUP;
	}

	/**
	 * Get the current list cache version, initializing if missing.
	 *
	 * @since 1.0.0
	 *
	 * @return int Cache version number.
	 */
	public function get_list_cache_version(): int {

		$version = wp_cache_get( self::LIST_CACHE_VERSION_KEY, self::CACHE_GROUP );

		if ( $version === false ) {
			$version = 1;

			wp_cache_set( self::LIST_CACHE_VERSION_KEY, $version, self::CACHE_GROUP );
		}

		return (int) $version;
	}

	/**
	 * Increment list cache version to invalidate all list variations.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function invalidate_list_cache(): void {

		$version = $this->get_list_cache_version();

		wp_cache_set( self::LIST_CACHE_VERSION_KEY, $version + 1, self::CACHE_GROUP );
	}

	/**
	 * Retrieve a cached submission, if present.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Submission ID.
	 *
	 * @return array|null Cached submission or null if absent.
	 */
	public function get_submission( string $id ): ?array {

		$cached = wp_cache_get( "submission_$id", self::CACHE_GROUP );

		if ( $cached === false ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Store a submission in cache.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id         Submission ID.
	 * @param array  $submission Submission data.
	 *
	 * @return void
	 */
	public function set_submission( string $id, array $submission ): void {

		wp_cache_set( "submission_$id", $submission, self::CACHE_GROUP, HOUR_IN_SECONDS );
	}

	/**
	 * Clear all caches related to submissions.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Optional submission ID to clear the single-item cache.
	 *
	 * @return void
	 */
	public function clear_submission_cache( string $id = '' ): void {

		if ( ! empty( $id ) ) {
			wp_cache_delete( "submission_$id", self::CACHE_GROUP );
		}

		wp_cache_delete( 'queue_stats', self::CACHE_GROUP );
		wp_cache_delete( 'daily_counts_7', self::CACHE_GROUP );
		wp_cache_delete( 'daily_counts_30', self::CACHE_GROUP );
		wp_cache_delete( 'table_exists', self::CACHE_GROUP );
		wp_cache_delete( 'column_exists_retry_count', self::CACHE_GROUP );
		wp_cache_delete( 'column_exists_previous_status', self::CACHE_GROUP );

		$this->invalidate_list_cache();
	}
}

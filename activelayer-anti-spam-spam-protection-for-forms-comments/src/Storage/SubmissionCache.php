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
	 * Cache key identifying the current generation of individual submissions.
	 *
	 * @since 1.7.0
	 */
	private const SUBMISSION_CACHE_GENERATION_KEY = 'submission_cache_generation';

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
	 * @since 1.7.0 Use the current detail-cache generation.
	 *
	 * @param string      $id         Submission ID.
	 * @param string|null $generation Optional generation captured before a database read.
	 *
	 * @return array|null Cached submission or null if absent.
	 */
	public function get_submission( string $id, ?string $generation = null ): ?array {

		$cached = wp_cache_get( $this->get_submission_cache_key( $id, $generation ), self::CACHE_GROUP );

		if ( $cached === false ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Store a submission in cache.
	 *
	 * @since 1.0.0
	 * @since 1.7.0 Use the current detail-cache generation.
	 *
	 * @param string      $id         Submission ID.
	 * @param array       $submission Submission data.
	 * @param string|null $generation Optional generation captured before the database read.
	 *
	 * @return void
	 */
	public function set_submission( string $id, array $submission, ?string $generation = null ): void {

		wp_cache_set( $this->get_submission_cache_key( $id, $generation ), $submission, self::CACHE_GROUP, HOUR_IN_SECONDS );
	}

	/**
	 * Build an individual key using the reader's generation when supplied.
	 *
	 * @since 1.7.0
	 *
	 * @param string      $id         Submission ID.
	 * @param string|null $generation Optional generation captured before the database read.
	 *
	 * @return string Generation-specific detail key.
	 */
	private function get_submission_cache_key( string $id, ?string $generation = null ): string {

		$generation = $generation ?? $this->get_submission_cache_generation();

		return "submission_{$generation}_{$id}";
	}

	/**
	 * Capture the generation before reading a submission from the database.
	 *
	 * Keep the token through the cache fill so a concurrent Delete All cannot
	 * receive stale data in its new generation. Random tokens also prevent old
	 * details reviving when only the marker is evicted; atomic add preserves the
	 * first token created by concurrent initializers.
	 *
	 * @since 1.7.0
	 *
	 * @return string Current generation token.
	 */
	public function get_submission_cache_generation(): string {

		$generation = wp_cache_get( self::SUBMISSION_CACHE_GENERATION_KEY, self::CACHE_GROUP );

		if ( $generation === false ) {
			$generation = wp_generate_uuid4();

			if ( ! wp_cache_add( self::SUBMISSION_CACHE_GENERATION_KEY, $generation, self::CACHE_GROUP ) ) {
				$stored_generation = wp_cache_get( self::SUBMISSION_CACHE_GENERATION_KEY, self::CACHE_GROUP );

				if ( $stored_generation !== false ) {
					$generation = $stored_generation;
				}
			}
		}

		return $generation;
	}

	/**
	 * Invalidate every detail and aggregate cache after bulk deletion.
	 *
	 * Existing detail entries expire normally but cannot be read in the new
	 * generation. Unrelated cache entries and groups are preserved.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function clear_all_submission_cache(): void {

		wp_cache_set( self::SUBMISSION_CACHE_GENERATION_KEY, wp_generate_uuid4(), self::CACHE_GROUP );
		$this->clear_submission_cache();
	}

	/**
	 * Clear aggregate caches and, when specified, one submission detail.
	 *
	 * @since 1.0.0
	 * @since 1.7.0 Invalidate the individual key in its current generation.
	 *
	 * @param string $id Optional submission ID to clear the single-item cache.
	 *
	 * @return void
	 */
	public function clear_submission_cache( string $id = '' ): void {

		if ( ! empty( $id ) ) {
			wp_cache_delete( $this->get_submission_cache_key( $id ), self::CACHE_GROUP );
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

<?php

namespace ActiveLayer\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages schema creation and upgrades for submissions storage.
 *
 * @since 1.0.0
 */
class SchemaManager {

	/**
	 * Current schema version for submissions table.
	 *
	 * @since 1.0.0
	 */
	public const SCHEMA_VERSION = '1.2.0';

	/**
	 * Option key storing schema version.
	 *
	 * @since 1.0.0
	 */
	public const OPTION_SCHEMA_VERSION = 'activelayer_storage_schema_version';

	/**
	 * Base table name without prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $base_table_name;

	/**
	 * Fully qualified table name with prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Cache helper instance.
	 *
	 * @since 1.0.0
	 *
	 * @var SubmissionCache
	 */
	private $cache;

	/**
	 * Schema manager constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param SubmissionCache $cache           Cache helper.
	 * @param string          $base_table_name Base table name without prefix.
	 */
	public function __construct( SubmissionCache $cache, string $base_table_name ) {

		$this->cache           = $cache;
		$this->base_table_name = $base_table_name;

		$this->refresh_table_name();
	}

	/**
	 * Refresh the stored table name to match the current WordPress prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function refresh_table_name(): void {

		global $wpdb;

		$current = $wpdb->prefix . $this->base_table_name;

		if ( $this->table_name !== $current ) {
			$this->table_name = $current;
		}
	}

	/**
	 * Get the fully qualified table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name with prefix.
	 */
	public function get_table_name(): string {

		$this->refresh_table_name();

		return $this->table_name;
	}

	/**
	 * Ensure the submissions table schema matches the expected version.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema(): void {

		global $wpdb;

		$this->refresh_table_name();

		if ( ! $this->table_exists() ) {
			return;
		}

		$version = get_option( self::OPTION_SCHEMA_VERSION );

		if ( $version === self::SCHEMA_VERSION ) {
			return;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$previous_suppression = $wpdb->suppress_errors();

		dbDelta( $this->get_schema_sql() );
		$wpdb->suppress_errors( $previous_suppression );

		if ( ! $this->column_exists( 'retry_count' ) ) {
			$table_name = esc_sql( $this->table_name );

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE `{$table_name}` ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status" // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		$this->ensure_status_supports_trash();
		$this->ensure_previous_status_column_exists();

		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
		$this->cache->clear_submission_cache();
	}

	/**
	 * Create database table with simplified schema.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	public function create_table(): bool {

		global $wpdb;

		$this->refresh_table_name();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$previous_suppression = $wpdb->suppress_errors();

		dbDelta( $this->get_schema_sql() );
		$wpdb->suppress_errors( $previous_suppression );

		wp_cache_delete( 'table_exists', $this->cache->get_cache_group() );

		$created = $this->table_exists( false );

		if ( ! $created ) {
			$wpdb->query( $this->get_schema_sql() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			wp_cache_delete( 'table_exists', $this->cache->get_cache_group() );
			$created = $this->table_exists( false );
		}

		if ( $created && ! $this->column_exists( 'retry_count' ) ) {
			$table_name = esc_sql( $this->table_name );

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE `{$table_name}` ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status" // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		if ( $created ) {
			update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
			$this->cache->clear_submission_cache();
		}

		return $created;
	}

	/**
	 * Check if table exists.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $use_cache Whether to use cached result.
	 *
	 * @return bool True if table exists, false otherwise.
	 */
	public function table_exists( bool $use_cache = true ): bool {

		$cache_key = 'table_exists';
		$cached    = $use_cache ? wp_cache_get( $cache_key, $this->cache->get_cache_group() ) : false;

		if ( $use_cache && $cached !== false ) {
			return (bool) $cached;
		}

		global $wpdb;

		$this->refresh_table_name();

		$table_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$this->table_name
			)
		);

		$exists = ! empty( $table_exists );

		wp_cache_set( $cache_key, $exists, $this->cache->get_cache_group(), HOUR_IN_SECONDS );

		return $exists;
	}

	/**
	 * Check if a specific column exists on the submissions table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column Column name.
	 *
	 * @return bool True if column exists.
	 */
	public function column_exists( string $column ): bool {

		$cache_key = 'column_exists_' . $column;
		$cached    = wp_cache_get( $cache_key, $this->cache->get_cache_group() );

		if ( $cached !== false ) {
			return (bool) $cached;
		}

		global $wpdb;

		$this->refresh_table_name();

		$column_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SHOW COLUMNS FROM `$this->table_name` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$column
			)
		);

		$exists = ! empty( $column_exists );

		wp_cache_set( $cache_key, $exists, $this->cache->get_cache_group(), HOUR_IN_SECONDS );

		return $exists;
	}

	/**
	 * Get SQL schema definition for the submissions table.
	 *
	 * @since 1.0.0
	 *
	 * @return string SQL statement for dbDelta.
	 */
	public function get_schema_sql(): string {

		global $wpdb;

		$table_name      = esc_sql( $this->get_table_name() );
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE IF NOT EXISTS `{$table_name}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider VARCHAR(20) NOT NULL,
			form_id VARCHAR(64) NOT NULL,
			entry_id VARCHAR(64) NULL,
			status ENUM('pending','clean','spam','failed','trash') NOT NULL DEFAULT 'pending',
			previous_status VARCHAR(20) NULL,
			retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
			verdict VARCHAR(20) NULL,
			form_data LONGTEXT NOT NULL,
			api_response LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			
			PRIMARY KEY (id),
			KEY idx_status_created (status, created_at),
			KEY idx_provider_form (provider, form_id)
		) $charset_collate;";
	}

	/**
	 * Ensure the status column supports the trash state.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function ensure_status_supports_trash(): void {

		global $wpdb;

		$this->refresh_table_name();

		$table_name = esc_sql( $this->table_name );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.SchemaChange.SchemaChange
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SchemaChange.SchemaChange, WordPress.DB.DirectDatabaseQuery.SchemaChange
			"ALTER TABLE `{$table_name}` MODIFY COLUMN status ENUM('pending','clean','spam','failed','trash') NOT NULL DEFAULT 'pending'"
		);
	}

	/**
	 * Ensure previous_status column exists for trash restores.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function ensure_previous_status_column_exists(): void {

		if ( $this->column_exists( 'previous_status' ) ) {
			return;
		}

		global $wpdb;

		$this->refresh_table_name();

		$table_name = esc_sql( $this->table_name );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.SchemaChange.SchemaChange
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SchemaChange.SchemaChange, WordPress.DB.DirectDatabaseQuery.SchemaChange
			"ALTER TABLE `{$table_name}` ADD COLUMN previous_status VARCHAR(20) NULL AFTER status"
		);
	}
}

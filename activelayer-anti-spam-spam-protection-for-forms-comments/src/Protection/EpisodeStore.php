<?php

namespace ActiveLayer\Protection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Atomic counters must always read and write current database state.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic local counters containing no submission payloads.
 *
 * @since 1.8.0
 */
class EpisodeStore {

	/**
	 * Install local episode tables and preserve existing counts during schema upgrades.
	 *
	 * @since 1.8.0
	 */
	public static function install(): void {

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
            "CREATE TABLE {$wpdb->prefix}activelayer_protection_gaps (
			episode_id char(36) NOT NULL,
			scope varchar(64) NOT NULL DEFAULT '',
			active_scope varchar(100) DEFAULT NULL,
			reason varchar(32) NOT NULL,
			started_at datetime NOT NULL,
			ended_at datetime DEFAULT NULL,
			count bigint unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (episode_id),
			UNIQUE KEY active_scope (active_scope),
			KEY scope (scope)
		) ENGINE=InnoDB $charset;"
        );
		dbDelta(
            "CREATE TABLE {$wpdb->prefix}activelayer_protection_events (
			event_hash char(64) NOT NULL,
			submission_id bigint unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (event_hash)
		) ENGINE=InnoDB $charset;"
        );
		// Retire the previous reporting scheduler without deleting local episode history.
		wp_clear_scheduled_hook( 'activelayer_protection_sync' );
		delete_option( 'activelayer_protection_installation' );
		delete_option( 'activelayer_protection_retry' );
		foreach ( [ 'activelayer_protection_gaps', 'activelayer_protection_events' ] as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				set_transient( 'activelayer_protection_schema_retry', true, 5 * MINUTE_IN_SECONDS );

				return;
			}
		}
		delete_transient( 'activelayer_protection_schema_retry' );
		update_option( 'activelayer_protection_schema', 3, false );
	}

	/**
	 * Increment one logical event in the current episode transactionally.
	 *
	 * @since 1.8.0
	 *
	 * @param string $reason Known interruption reason.
	 * @param string $scope  Credential fingerprint, or empty before connection.
	 * @param string $event  Opaque local event identifier.
	 */
	public function observe( string $reason, string $scope, string $event ): void {

		global $wpdb;
		if ( ! in_array( $reason, [ 'not_connected', 'quota_exhausted' ], true ) ) {
			return;
		}
		$owned    = $this->begin_transaction();
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}activelayer_protection_events (event_hash,submission_id,created_at) VALUES (%s,NULLIF(%d,0),%s)", hash( 'sha256', $scope . ':' . $event ), strpos( $event, 'api:' ) === 0 ? absint( substr( $event, 4 ) ) : 0, gmdate( 'Y-m-d H:i:s' ) ) );

		if ( $inserted !== 1 ) {
			$this->finish_transaction( $owned, false );

			return;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}activelayer_protection_gaps (episode_id,scope,active_scope,reason,started_at,count) VALUES (%s,%s,%s,%s,%s,1) ON DUPLICATE KEY UPDATE count=LEAST(count+1,2147483647)",
				wp_generate_uuid4(),
                $scope,
                $scope . ':' . $reason,
                $reason,
                gmdate( 'Y-m-d H:i:s' )
			)
		);

		$this->finish_transaction( $owned, $result !== false );
	}

	/**
	 * Close all current interruptions for one credential scope.
	 *
	 * @since 1.8.0
	 *
	 * @param string $scope  Credential fingerprint.
	 * @param string $reason Optional interruption cause to close.
	 */
	public function close( string $scope, string $reason = '' ): void {

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}activelayer_protection_gaps SET ended_at=%s,active_scope=NULL WHERE scope=%s AND ended_at IS NULL AND (%s='' OR reason=%s)", gmdate( 'Y-m-d H:i:s' ), $scope, $reason, $reason ) );
	}

	/**
	 * Bind only history created before the first connection.
	 *
	 * @since 1.8.0
	 *
	 * @param string $scope First verified credential fingerprint.
	 */
	public function bind( string $scope ): void {

		global $wpdb;
		$this->close( '' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}activelayer_protection_gaps SET scope=%s WHERE scope=''", $scope ) );
	}

	/**
	 * Read only the latest active interruption without aggregating history.
	 *
	 * @since 1.8.0
	 *
	 * @param string $scope Credential fingerprint.
	 *
	 * @return array|null Active episode.
	 */
	public function active( string $scope ): ?array {

		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}activelayer_protection_gaps WHERE scope=%s AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1", $scope ), ARRAY_A );
	}

	/**
	 * Return local history and the current interruption without an HTTP request.
	 *
	 * @since 1.8.0
	 *
	 * @param string $scope Current credential fingerprint.
	 *
	 * @return array Count and active episode, or null when no data exists.
	 */
	public function summary( string $scope ): array {

		global $wpdb;
		$total  = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(count) FROM {$wpdb->prefix}activelayer_protection_gaps WHERE scope=%s", $scope ) );
		$active = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}activelayer_protection_gaps WHERE scope=%s AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1", $scope ), ARRAY_A );

		return [
			'count'  => $total === null ? null : (int) $total,
			'active' => $active,
		];
	}
	/**
	 * Bound ephemeral request deduplication and remove orphaned API identities.
	 *
	 * Stable API IDs are kept while their submission can still be retried.
	 * Native request IDs expire after two days; cumulative episodes are retained.
	 *
	 * @since 1.8.0
	 */
	public function prune_events(): void {

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}activelayer_protection_events WHERE submission_id IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 2 DAY" );
		$submissions = $wpdb->prefix . 'activelayer_submissions';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $submissions ) ) ) === $submissions ) {
			$wpdb->query( "DELETE e FROM {$wpdb->prefix}activelayer_protection_events e LEFT JOIN {$wpdb->prefix}activelayer_submissions s ON s.id=e.submission_id WHERE e.submission_id IS NOT NULL AND s.id IS NULL" );
		}
	}
	/**
	 * Start an owned transaction or a savepoint inside a provider transaction.
	 *
	 * RELEASE succeeds only when SAVEPOINT ran in an existing transaction.
	 * This avoids privileged transaction metadata queries and never commits a
	 * transaction owned by a form provider or another WordPress component.
	 *
	 * @since 1.8.0
	 *
	 * @return bool Whether this store owns the transaction.
	 */
	private function begin_transaction(): bool {

		global $wpdb;
		$previous = $wpdb->suppress_errors();

		$wpdb->query( 'SAVEPOINT activelayer_protection_probe' );
		$nested = $wpdb->query( 'RELEASE SAVEPOINT activelayer_protection_probe' ) !== false;

		$wpdb->suppress_errors( $previous );
		if ( $nested ) {
			$wpdb->query( 'SAVEPOINT activelayer_protection_counter' );
		} else {
			$wpdb->query( 'START TRANSACTION' );
		}

		return ! $nested;
	}

	/**
	 * Finalize only this counter operation, preserving any outer transaction.
	 *
	 * @since 1.8.0
	 *
	 * @param bool $owned   Whether the store owns the transaction.
	 * @param bool $success Whether the event and counter mutation succeeded.
	 */
	private function finish_transaction( bool $owned, bool $success ): void {

		global $wpdb;
		if ( $owned ) {
			if ( $success ) {
				$wpdb->query( 'COMMIT' );
			} else {
				$wpdb->query( 'ROLLBACK' );
			}
		} else {
			if ( ! $success ) {
				$wpdb->query( 'ROLLBACK TO SAVEPOINT activelayer_protection_counter' );
			}
			$wpdb->query( 'RELEASE SAVEPOINT activelayer_protection_counter' );
		}
	}
}

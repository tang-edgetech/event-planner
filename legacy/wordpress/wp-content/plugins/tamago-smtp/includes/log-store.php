<?php
/**
 * Email log table ({prefix}tmg_smtp_log): schema, queries, purge and the daily purge event.
 *
 * Times are stored in UTC. Bodies and transcripts can be large, so list queries leave them out.
 *
 * Used by: TMG_SMTP_Logger (insert), logs screen, dashboard widget, privacy tools, WP-CLI, uninstall.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Log_Store {

	const DB_VERSION = '1.0.0';
	const DB_OPTION  = 'tmg_smtp_db_version';
	const CRON       = 'tmg_smtp_purge_logs';

	/** Columns read by list screens (no bodies, headers or transcripts). */
	const LIST_COLUMNS = 'id, created_at, status, to_email, cc, bcc, from_email, from_name, reply_to, subject, content_type, attachments, mailer, message_id, error, duration_ms, source, source_function, context, user_id, request_url, resend_of, resend_count, body_truncated';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tmg_smtp_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			to_email text NULL,
			cc text NULL,
			bcc text NULL,
			from_email varchar(255) NOT NULL DEFAULT '',
			from_name varchar(255) NOT NULL DEFAULT '',
			reply_to text NULL,
			subject text NULL,
			headers longtext NULL,
			body_html longtext NULL,
			body_text longtext NULL,
			body_truncated tinyint(1) NOT NULL DEFAULT 0,
			content_type varchar(100) NOT NULL DEFAULT '',
			attachments text NULL,
			mailer varchar(255) NOT NULL DEFAULT '',
			message_id varchar(255) NOT NULL DEFAULT '',
			error text NULL,
			transcript longtext NULL,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			source varchar(191) NOT NULL DEFAULT '',
			source_function varchar(255) NOT NULL DEFAULT '',
			context varchar(20) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			request_url text NULL,
			resend_of bigint(20) unsigned NOT NULL DEFAULT 0,
			resend_count int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY source (source),
			KEY resend_of (resend_of)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/** Runs on init: creates or updates the table when the stored version differs (new sites, plugin updates). */
	public static function maybe_upgrade(): void {
		$version = get_option( self::DB_OPTION, '' );
		if ( self::DB_VERSION !== $version ) {
			self::install();
			if ( '' === $version ) {
				TMG_SMTP_Permissions::grant();
			}
		}
		self::schedule();
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function columns(): array {
		return array(
			'created_at', 'status', 'to_email', 'cc', 'bcc', 'from_email', 'from_name', 'reply_to', 'subject', 'headers',
			'body_html', 'body_text', 'body_truncated', 'content_type', 'attachments', 'mailer', 'message_id', 'error',
			'transcript', 'duration_ms', 'source', 'source_function', 'context', 'user_id', 'request_url', 'resend_of', 'resend_count',
		);
	}

	/** @return int New row id, 0 on failure. */
	public static function insert( array $row ): int {
		global $wpdb;
		$row = array_intersect_key( $row, array_flip( self::columns() ) );
		if ( empty( $row['created_at'] ) ) {
			$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * WHERE clause for the list filters.
	 *
	 * @param array $args search, status, source, date_from / date_to (UTC 'Y-m-d H:i:s').
	 * @return array{0:string,1:array}
	 */
	private static function where( array $args ): array {
		global $wpdb;
		$sql    = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$sql[]    = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['source'] ) ) {
			$sql[]    = 'source = %s';
			$params[] = (string) $args['source'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$sql[]    = 'created_at >= %s';
			$params[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$sql[]    = 'created_at <= %s';
			$params[] = (string) $args['date_to'];
		}
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$sql[]    = '(to_email LIKE %s OR cc LIKE %s OR bcc LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['after_id'] ) ) {
			$sql[]    = 'id > %d';
			$params[] = (int) $args['after_id'];
		}
		return array( implode( ' AND ', $sql ), $params );
	}

	/**
	 * @param array $args Filters (see where()) plus orderby, order, per_page, page, full (bool: all columns).
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$sortable               = array( 'id', 'created_at', 'status', 'to_email', 'subject', 'source', 'duration_ms', 'mailer', 'context' );
		$orderby                = in_array( $args['orderby'] ?? '', $sortable, true ) ? $args['orderby'] : 'id';
		$order                  = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$per_page               = max( 1, min( 5000, (int) ( $args['per_page'] ?? 20 ) ) );
		$page                   = max( 1, (int) ( $args['page'] ?? 1 ) );
		$columns                = empty( $args['full'] ) ? self::LIST_COLUMNS : '*';
		$table                  = self::table();

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB

		// Secondary sort on id keeps paging stable when many rows share a value.
		$list_sql = "SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/** @param int[] $ids */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB
	}

	public static function delete_all(): int {
		global $wpdb;
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	/** Deletes entries older than $days days, in batches so a huge table does not lock for long. */
	public static function purge( int $days ): int {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$total  = 0;
		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s ORDER BY id LIMIT 5000', $cutoff ) ); // phpcs:ignore WordPress.DB
			$total  += $deleted;
		} while ( 5000 === $deleted );
		return $total;
	}

	public static function increment_resend( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET resend_count = resend_count + 1 WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<int, array{id:int,created_at:string,status:string}> Resends of one entry. */
	public static function resends_of( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, status FROM ' . self::table() . ' WHERE resend_of = %d ORDER BY id', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Distinct sources, for the filter dropdown. */
	public static function sources(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( 'SELECT DISTINCT source FROM ' . self::table() . " WHERE source <> '' ORDER BY source" ) ); // phpcs:ignore WordPress.DB
	}

	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Sent / failed per day for the last $days days, in the site's time zone.
	 *
	 * @return array<string, array{sent:int,failed:int}> Keyed by local 'Y-m-d', oldest first.
	 */
	public static function daily_counts( int $days = 7 ): array {
		global $wpdb;
		$tz     = wp_timezone();
		$offset = $tz->getOffset( new DateTime( 'now', $tz ) );
		$out    = array();
		$today  = new DateTimeImmutable( 'today', $tz );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[ $today->modify( "-{$i} days" )->format( 'Y-m-d' ) ] = array(
				'sent'   => 0,
				'failed' => 0,
			);
		}
		$since = $today->modify( '-' . ( $days - 1 ) . ' days' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS day, status, COUNT(*) AS n FROM ' . self::table() . ' WHERE created_at >= %s GROUP BY day, status', // phpcs:ignore WordPress.DB
				$offset,
				$since
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['day'] ] ) && in_array( $row['status'], array( 'sent', 'failed' ), true ) ) {
				$out[ $row['day'] ][ $row['status'] ] = (int) $row['n'];
			}
		}
		return $out;
	}

	public static function latest_failure(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT id, created_at, to_email, subject, error FROM ' . self::table() . " WHERE status = 'failed' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Entries that mention an email address in to / cc / bcc / from / reply-to (exact address match).
	 *
	 * @return array{rows:array,more:bool} $more is true when another page of candidates exists.
	 */
	public static function find_by_email( string $email, int $page = 1, int $per_page = 100 ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array(
				'rows' => array(),
				'more' => false,
			);
		}
		$like       = '%' . $wpdb->esc_like( $email ) . '%';
		$candidates = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE to_email LIKE %s OR cc LIKE %s OR bcc LIKE %s OR from_email LIKE %s OR reply_to LIKE %s ORDER BY id LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB
				$like,
				$like,
				$like,
				$like,
				$like,
				$per_page,
				( max( 1, $page ) - 1 ) * $per_page
			),
			ARRAY_A
		);
		$pattern = '/(^|[\s<,;"\'])' . preg_quote( $email, '/' ) . '($|[\s>,;"\'])/i';
		$rows    = array();
		foreach ( $candidates as $row ) {
			foreach ( array( 'to_email', 'cc', 'bcc', 'from_email', 'reply_to' ) as $field ) {
				if ( preg_match( $pattern, (string) $row[ $field ] ) ) {
					$rows[] = $row;
					break;
				}
			}
		}
		return array(
			'rows' => $rows,
			'more' => count( $candidates ) === $per_page,
		);
	}

	/** Ids of every entry that mentions the address (walks the table by id, light columns only). */
	public static function ids_by_email( string $email ): array {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$like    = '%' . $wpdb->esc_like( $email ) . '%';
		$pattern = '/(^|[\s<,;"\'])' . preg_quote( $email, '/' ) . '($|[\s>,;"\'])/i';
		$ids     = array();
		$last    = 0;
		do {
			$batch = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'SELECT id, to_email, cc, bcc, from_email, reply_to FROM ' . self::table() . ' WHERE id > %d AND (to_email LIKE %s OR cc LIKE %s OR bcc LIKE %s OR from_email LIKE %s OR reply_to LIKE %s) ORDER BY id LIMIT 1000', // phpcs:ignore WordPress.DB
					$last,
					$like,
					$like,
					$like,
					$like,
					$like
				),
				ARRAY_A
			);
			foreach ( $batch as $row ) {
				$last = (int) $row['id'];
				foreach ( array( 'to_email', 'cc', 'bcc', 'from_email', 'reply_to' ) as $field ) {
					if ( preg_match( $pattern, (string) $row[ $field ] ) ) {
						$ids[] = $last;
						break;
					}
				}
			}
		} while ( 1000 === count( $batch ) );
		return $ids;
	}

	public static function drop(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB
		delete_option( self::DB_OPTION );
	}
}

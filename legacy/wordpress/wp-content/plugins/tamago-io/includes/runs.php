<?php
/**
 * Run history: one row per import / export in `{prefix}tmg_io_runs` (dbDelta, versioned by
 * `tmg_io_db_version`), with counts, options and the per-item report.
 *
 * Used by: importer (runs and reports), exporter (logging), History tab, report download.
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Runs {

	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tmg_io_runs';
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'tmg_io_db_version' ) !== TMG_IO_DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(20) NOT NULL DEFAULT 'import',
				status varchar(20) NOT NULL DEFAULT 'pending',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				dry_run tinyint(1) NOT NULL DEFAULT 0,
				source varchar(190) NOT NULL DEFAULT '',
				format varchar(10) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				finished_at datetime NULL DEFAULT NULL,
				summary text NULL,
				options longtext NULL,
				report longtext NULL,
				PRIMARY KEY  (id),
				KEY user_kind (user_id, kind),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( 'tmg_io_db_version', TMG_IO_DB_VERSION, true );
	}

	public static function create( array $data ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'kind'       => substr( (string) ( $data['kind'] ?? 'import' ), 0, 20 ),
				'status'     => substr( (string) ( $data['status'] ?? 'pending' ), 0, 20 ),
				'user_id'    => (int) ( $data['user_id'] ?? get_current_user_id() ),
				'dry_run'    => empty( $data['dry_run'] ) ? 0 : 1,
				'source'     => mb_substr( (string) ( $data['source'] ?? '' ), 0, 190 ),
				'format'     => substr( (string) ( $data['format'] ?? '' ), 0, 10 ),
				'created_at' => current_time( 'mysql', true ),
				'summary'    => wp_json_encode( $data['summary'] ?? array() ),
				'options'    => wp_json_encode( $data['options'] ?? array() ),
				'report'     => wp_json_encode( $data['report'] ?? array() ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'summary', 'options', 'report' ) as $k ) {
			$row[ $k ] = json_decode( (string) $row[ $k ], true ) ?: array();
		}
		$row['id']      = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		$row['dry_run'] = (bool) $row['dry_run'];
		return $row;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$set = array();
		foreach ( $data as $k => $v ) {
			if ( in_array( $k, array( 'summary', 'options', 'report' ), true ) ) {
				$set[ $k ] = wp_json_encode( $v );
			} elseif ( in_array( $k, array( 'status', 'source', 'format', 'finished_at' ), true ) ) {
				$set[ $k ] = $v;
			} elseif ( 'dry_run' === $k ) {
				$set[ $k ] = $v ? 1 : 0;
			}
		}
		if ( $set ) {
			$wpdb->update( self::table(), $set, array( 'id' => $id ) );
		}
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$run = self::get( $id );
		if ( $run ) {
			foreach ( array( 'file', 'state', 'work' ) as $k ) {
				if ( ! empty( $run['options'][ $k ] ) ) {
					TMG_IO_Storage::delete( (string) $run['options'][ $k ] );
				}
			}
		}
		$wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/**
	 * @return array{0: array, 1: int} rows, total
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$where = '1=1';
		$vals  = array();
		if ( ! empty( $args['user_id'] ) ) {
			$where .= ' AND user_id = %d';
			$vals[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['kind'] ) ) {
			$where .= ' AND kind = %s';
			$vals[] = (string) $args['kind'];
		}
		$per    = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$table  = self::table();
		$count  = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where;
		$list   = 'SELECT id, kind, status, user_id, dry_run, source, format, created_at, finished_at, summary FROM ' . $table . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		// phpcs:disable WordPress.DB.PreparedSQL
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count, $vals ) ) : $wpdb->get_var( $count ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list, array_merge( $vals, array( $per, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		foreach ( $rows as &$r ) {
			$r['summary'] = json_decode( (string) $r['summary'], true ) ?: array();
		}
		return array( $rows, $total );
	}

	public static function prune( int $days ): void {
		global $wpdb;
		if ( $days < 1 ) {
			return;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
	}

	/** May the current user see / resume / delete this run? */
	public static function can_access( array $run ): bool {
		return (int) $run['user_id'] === get_current_user_id() || current_user_can( TMG_IO_Permissions::MANAGE );
	}

	/** Report rows as a table (header + rows) for CSV / Excel download. */
	public static function report_table( array $run ): array {
		$rows = array( array( 'Section', 'Source ID', 'Title', 'Result', 'New ID', 'Message' ) );
		foreach ( (array) $run['report'] as $item ) {
			$rows[] = array(
				(string) ( $item['s'] ?? '' ),
				(string) ( $item['id'] ?? '' ),
				(string) ( $item['t'] ?? '' ),
				( $run['dry_run'] ? 'would ' : '' ) . (string) ( $item['a'] ?? '' ),
				(string) ( $item['n'] ?? '' ),
				(string) ( $item['m'] ?? '' ),
			);
		}
		return $rows;
	}
}

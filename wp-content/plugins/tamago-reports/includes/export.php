<?php
/**
 * CSV export (admin-post, nonce + tmg_reports_export): raw hits for the filtered range
 * (streamed in chunks, IP hash left out) and any report table in full.
 *
 * Screens: Reports → Overview (export buttons / CSV links).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Export {

	const ACTION = 'tmg_reports_export';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function url( string $type, array $st, string $table = '' ): string {
		$args = array_merge(
			TMG_Reports_Report_Screen::url_args( $st ),
			array(
				'action'   => self::ACTION,
				'type'     => $type,
				'table'    => $table,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			)
		);
		$args = array_filter( $args, fn( $v ) => '' !== $v && array() !== $v );
		return admin_url( 'admin-post.php?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) );
	}

	public static function can_export(): bool {
		return current_user_can( TMG_Reports_Permissions::EXPORT );
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! self::can_export() ) {
			wp_die( esc_html__( 'You are not allowed to export report data.', 'tamago-reports' ), 403 );
		}
		$st   = TMG_Reports_Report_Screen::state();
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'raw';
		if ( 'table' === $type ) {
			$key    = isset( $_GET['table'] ) ? sanitize_key( wp_unslash( $_GET['table'] ) ) : '';
			$tables = TMG_Reports_Report_Screen::tables();
			if ( ! isset( $tables[ $key ] ) ) {
				wp_die( esc_html__( 'Unknown table.', 'tamago-reports' ), 400 );
			}
			self::table_csv( $key, $tables[ $key ], $st );
		}
		self::raw_csv( $st );
	}

	/** Neutralise spreadsheet formulas. @param mixed $v */
	private static function cell( $v ): string {
		$v = (string) $v;
		return '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $v ) ? "'" . $v : $v;
	}

	private static function open( string $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		return $out;
	}

	private static function table_csv( string $key, array $def, array $st ): void {
		$res    = TMG_Reports_Query::table( TMG_Reports_Report_Screen::table_args( $def, $st, 0 ) );
		$labels = TMG_Reports_UI::metric_labels();
		$out    = self::open( 'report-' . $key . '-' . $st['from'] . '-to-' . $st['to'] );
		fputcsv( $out, array_merge( array( 'key', 'name' ), array_map( fn( $c ) => $labels[ $c ], $def['cols'] ) ) );
		foreach ( $res['rows'] as $r ) {
			$line = array( self::cell( $r['key'] ), self::cell( $r['label'] ) );
			foreach ( $def['cols'] as $c ) {
				$line[] = $r[ $c ];
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private static function raw_csv( array $st ): void {
		global $wpdb;
		$t     = TMG_Reports_Install::hits_table();
		$where = TMG_Reports_Query::where( TMG_Reports_Query::utc( $st['from'] ), TMG_Reports_Query::utc( $st['to'], true ), $st['filters'] );
		$cols  = array_values( array_diff( array_keys( TMG_Reports_Recorder::columns() ), array( 'ip_hash' ) ) );
		$out   = self::open( 'hits-' . $st['from'] . '-to-' . $st['to'] );
		fputcsv( $out, array_merge( array( 'id' ), $cols, array( 'page_title' ) ) );
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ' . implode( ', ', $cols ) . " FROM {$t} WHERE {$where} AND id > %d ORDER BY id LIMIT 5000", $last ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $rows as $r ) {
				$last         = (int) $r['id'];
				$r['created_at'] = get_date_from_gmt( $r['created_at'] );
				$line         = array_map( array( __CLASS__, 'cell' ), $r );
				$line[]       = self::cell( $r['post_id'] ? html_entity_decode( get_the_title( (int) $r['post_id'] ), ENT_QUOTES, 'UTF-8' ) : '' );
				fputcsv( $out, $line );
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $rows ) === 5000 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}

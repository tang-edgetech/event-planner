<?php
/**
 * WP-CLI commands: `wp tamago-reports summary|purge|seed|settings|copy-legacy`.
 *
 * Used by: WP-CLI.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tamago Reports maintenance commands.
 */
class TMG_Reports_CLI {

	/**
	 * Build daily summaries.
	 *
	 * Rebuilds the last <days> finished days (up to today − 2), then any days not yet summarised.
	 *
	 * [--days=<days>]
	 * : Number of finished days to rebuild. Default 3.
	 *
	 * ## EXAMPLES
	 *     wp tamago-reports summary --days=90
	 */
	public function summary( $args, $assoc ): void {
		$start = microtime( true );
		$days  = max( 0, (int) ( $assoc['days'] ?? 3 ) );
		$built = TMG_Reports_Summary::rebuild( $days );
		$built += TMG_Reports_Summary::build_pending( 3650 );
		WP_CLI::success( sprintf( 'Summarised %d day(s) in %.1fs. Summaries cover up to %s.', $built, microtime( true ) - $start, TMG_Reports_Summary::until() ?: '—' ) );
	}

	/**
	 * Delete raw hits.
	 *
	 * Without options, applies the retention setting. Summaries are kept unless --all.
	 *
	 * [--days=<days>]
	 * : Delete hits older than this many days instead of the retention setting.
	 *
	 * [--all]
	 * : Delete all hits and summaries.
	 *
	 * [--yes]
	 * : Skip the confirmation for --all.
	 */
	public function purge( $args, $assoc ): void {
		global $wpdb;
		if ( ! empty( $assoc['all'] ) ) {
			WP_CLI::confirm( 'Delete ALL hits and summaries?', $assoc );
			$wpdb->query( 'TRUNCATE TABLE ' . TMG_Reports_Install::hits_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'TRUNCATE TABLE ' . TMG_Reports_Install::daily_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			delete_option( TMG_Reports_Summary::UNTIL );
			WP_CLI::success( 'All report data deleted.' );
			return;
		}
		$days = isset( $assoc['days'] ) ? (int) $assoc['days'] : null;
		TMG_Reports_Summary::build_pending( 3650 );
		$n = TMG_Reports_Summary::purge( $days );
		WP_CLI::success( sprintf( 'Deleted %d raw hit(s).', $n ) );
	}

	/**
	 * Insert random test hits (development only), or remove them.
	 *
	 * Seeded rows are marked with an ip_hash starting with "seed" and can be removed with --remove.
	 *
	 * [<count>]
	 * : Number of hits to insert. Default 1000.
	 *
	 * [--days=<days>]
	 * : Spread hits over this many days up to now. Default 90.
	 *
	 * [--remove]
	 * : Delete all seeded rows and rebuild summaries.
	 *
	 * [--force]
	 * : Allow on a site whose environment type is "production".
	 */
	public function seed( $args, $assoc ): void {
		global $wpdb;
		$t = TMG_Reports_Install::hits_table();
		if ( 'production' === wp_get_environment_type() && empty( $assoc['force'] ) ) {
			WP_CLI::error( 'This site is a production environment. Use --force on a test copy only.' );
		}
		if ( ! empty( $assoc['remove'] ) ) {
			$total = 0;
			do {
				$n      = (int) $wpdb->query( "DELETE FROM {$t} WHERE ip_hash LIKE 'seed%' LIMIT 20000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$total += $n;
			} while ( 20000 === $n );
			$wpdb->query( 'TRUNCATE TABLE ' . TMG_Reports_Install::daily_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			delete_option( TMG_Reports_Summary::UNTIL );
			$built = TMG_Reports_Summary::build_pending( 3650 );
			WP_CLI::success( sprintf( 'Removed %d seeded hit(s); rebuilt %d day(s) of summaries.', $total, $built ) );
			return;
		}

		$count = max( 1, (int) ( $args[0] ?? 1000 ) );
		$days  = max( 1, (int) ( $assoc['days'] ?? 90 ) );
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);
		$posts = $posts ? $posts : array( 0 );
		$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		$pick = static function ( array $weighted ) {
			$r = wp_rand( 1, (int) array_sum( $weighted ) );
			foreach ( $weighted as $k => $w ) {
				$r -= $w;
				if ( $r <= 0 ) {
					return $k;
				}
			}
			return array_key_first( $weighted );
		};
		$sources  = array(
			'direct'   => 40,
			'google'   => 25,
			'facebook' => 12,
			'insta'    => 6,
			'email'    => 5,
			'qr'       => 3,
			'partner'  => 6,
			'paid'     => 3,
		);
		$devices  = array( 'mobile|Android|14|Chrome|124' => 30, 'mobile|iOS|17|Safari|17' => 25, 'desktop|Windows|10/11|Chrome|124' => 20, 'desktop|macOS|10.15|Safari|17' => 8, 'desktop|Windows|10/11|Edge|124' => 7, 'tablet|iPadOS|17|Safari|17' => 5, 'mobile|Android|13|Samsung Internet|25' => 5 );
		$geos     = array( 'MY|Selangor|Shah Alam' => 30, 'MY|Kuala Lumpur|Kuala Lumpur' => 25, 'MY|Penang|George Town' => 10, 'SG||Singapore' => 12, 'ID|Jakarta|Jakarta' => 6, 'US|California|San Jose' => 5, 'GB|England|London' => 4, '||' => 8 );
		$hours    = array( 0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 3, 7 => 5, 8 => 7, 9 => 9, 10 => 9, 11 => 9, 12 => 8, 13 => 8, 14 => 9, 15 => 9, 16 => 8, 17 => 7, 18 => 6, 19 => 7, 20 => 8, 21 => 7, 22 => 4, 23 => 2 );
		$visitors = max( 1, (int) ( $count / 6 ) );
		$cols     = array_keys( TMG_Reports_Recorder::columns() );
		$batch    = array();
		$made     = 0;
		$start    = microtime( true );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Seeding hits', $count );

		while ( $made < $count ) {
			$v      = wp_rand( 1, $visitors );
			$vid    = substr( md5( 'seed-v' . $v ), 0, 32 );
			$sid    = bin2hex( random_bytes( 16 ) );
			$day    = wp_rand( 0, $days - 1 );
			$ts     = time() - $day * DAY_IN_SECONDS;
			$ts     = strtotime( gmdate( 'Y-m-d', $ts ) . ' 00:00:00 UTC' ) + (int) $pick( $hours ) * 3600 + wp_rand( 0, 3599 ) - (int) TMG_Reports_Query::offset();
			$ts     = min( $ts, time() - 60 );
			$src    = $pick( $sources );
			$dev    = explode( '|', $pick( $devices ) );
			$geo    = explode( '|', $pick( $geos ) );
			$base   = array(
				'visitor_id'      => $vid,
				'session_id'      => $sid,
				'is_new'          => 0 === $v % 3 ? 1 : 0,
				'device'          => $dev[0],
				'os'              => $dev[1],
				'os_version'      => $dev[2],
				'browser'         => $dev[3],
				'browser_version' => $dev[4],
				'screen'          => 'desktop' === $dev[0] ? '1920x1080' : ( 'tablet' === $dev[0] ? '820x1180' : '390x844' ),
				'viewport'        => 'desktop' === $dev[0] ? '1680x940' : '390x700',
				'language'        => 0 === $v % 4 ? 'ms-MY' : 'en-US',
				'timezone'        => 'SG' === $geo[0] ? 'Asia/Singapore' : 'Asia/Kuala_Lumpur',
				'country'         => $geo[0],
				'country_name'    => TMG_Reports_Labels::country( $geo[0] ),
				'region'          => $geo[1],
				'city'            => $geo[2],
				'ip_hash'         => 'seed' . substr( md5( (string) $v ), 0, 28 ),
			);
			$attr   = array( 'channel' => 'direct', 'ref_domain' => '', 'referrer' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '' );
			switch ( $src ) {
				case 'google':
					$attr = array_merge( $attr, array( 'channel' => 'search', 'ref_domain' => 'google.com', 'referrer' => 'https://www.google.com/' ) );
					break;
				case 'facebook':
					$attr = array_merge( $attr, array( 'channel' => 'social', 'ref_domain' => 'm.facebook.com', 'referrer' => 'https://m.facebook.com/', 'utm_source' => 'facebook', 'utm_medium' => 'social', 'utm_campaign' => 'launch' ) );
					break;
				case 'insta':
					$attr = array_merge( $attr, array( 'channel' => 'social', 'ref_domain' => 'instagram.com', 'referrer' => 'https://instagram.com/' ) );
					break;
				case 'email':
					$attr = array_merge( $attr, array( 'channel' => 'email', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'monthly' ) );
					break;
				case 'qr':
					$attr = array_merge( $attr, array( 'channel' => 'qr', 'utm_source' => 'qr', 'utm_medium' => 'print', 'utm_campaign' => 'poster' ) );
					break;
				case 'partner':
					$attr = array_merge( $attr, array( 'channel' => 'referral', 'ref_domain' => 'partner.example', 'referrer' => 'https://partner.example/events' ) );
					break;
				case 'paid':
					$attr = array_merge( $attr, array( 'channel' => 'paid', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'brand' ) );
					break;
			}
			$pages = wp_rand( 1, 100 ) <= 45 ? 1 : wp_rand( 2, 6 );
			for ( $i = 0; $i < $pages && $made < $count; $i++ ) {
				$pid  = (int) $posts[ array_rand( $posts ) ];
				$type = $pid ? (string) get_post_type( $pid ) : '';
				$row  = array_merge(
					$base,
					$attr,
					array(
						'created_at' => gmdate( 'Y-m-d H:i:s', $ts + $i * wp_rand( 20, 180 ) ),
						'post_id'    => $pid,
						'post_type'  => $type,
						'event_type' => 'pageview',
						'path'       => $pid ? (string) wp_parse_url( (string) get_permalink( $pid ), PHP_URL_PATH ) : $home,
						'duration'   => wp_rand( 0, 100 ) < 80 ? wp_rand( 3, 400 ) : 0,
						'scroll'     => wp_rand( 0, 100 ),
					)
				);
				$batch[] = $row;
				++$made;
				if ( $made < $count && wp_rand( 1, 100 ) <= 8 ) {
					$kind    = $pick( array( 'outbound' => 4, 'download' => 2, 'click' => 4, 'tel' => 1 ) );
					$batch[] = array_merge( $row, array( 'event_type' => $kind, 'event_name' => 'click' === $kind ? 'register-button' : $kind, 'label' => 'click' === $kind ? 'Register now' : 'https://partner.example/file.pdf', 'duration' => 0, 'scroll' => 0 ) );
					++$made;
				}
				if ( $made < $count && wp_rand( 1, 100 ) <= 2 ) {
					$batch[] = array_merge( $row, array( 'event_type' => 'server', 'event_name' => 'registration', 'label' => 'Seed registration', 'goal' => 'registration', 'value' => 0, 'duration' => 0, 'scroll' => 0 ) );
					++$made;
				}
			}
			if ( count( $batch ) >= 1000 || $made >= $count ) {
				self::insert_batch( $t, $cols, $batch );
				$progress->tick( count( $batch ) );
				$batch = array();
			}
		}
		$progress->finish();
		WP_CLI::success( sprintf( 'Inserted %d seeded hit(s) in %.1fs. Run `wp tamago-reports summary --days=%d` to summarise them.', $made, microtime( true ) - $start, $days ) );
	}

	private static function insert_batch( string $t, array $cols, array $rows ): void {
		global $wpdb;
		if ( ! $rows ) {
			return;
		}
		$types  = TMG_Reports_Recorder::columns();
		$place  = '(' . implode( ',', array_map( fn( $c ) => 'int' === $types[ $c ] ? '%d' : ( 'float' === $types[ $c ] ? '%f' : '%s' ), $cols ) ) . ')';
		$values = array();
		foreach ( $rows as $r ) {
			$r        = TMG_Reports_Recorder::clean( $r );
			$values[] = $wpdb->prepare( $place, array_map( fn( $c ) => $r[ $c ], $cols ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( "INSERT INTO {$t} (" . implode( ',', $cols ) . ') VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Export or import settings as JSON.
	 *
	 * <action>
	 * : export or import.
	 *
	 * [<file>]
	 * : File to write (export; default: print) or read (import).
	 *
	 * ## EXAMPLES
	 *     wp tamago-reports settings export reports.json
	 *     wp tamago-reports settings import reports.json
	 */
	public function settings( $args, $assoc ): void {
		$action = $args[0] ?? '';
		$file   = $args[1] ?? '';
		if ( 'export' === $action ) {
			$json = (string) wp_json_encode( TMG_Reports_Settings::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( '' === $file ) {
				WP_CLI::line( $json );
				return;
			}
			if ( ! is_dir( dirname( $file ) ) || ! wp_is_writable( dirname( $file ) ) || false === file_put_contents( $file, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				WP_CLI::error( "Could not write {$file}." );
			}
			WP_CLI::success( "Settings written to {$file}." );
			return;
		}
		if ( 'import' === $action ) {
			if ( '' === $file || ! is_readable( $file ) ) {
				WP_CLI::error( 'Give a readable JSON file.' );
			}
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$res  = is_array( $data ) ? TMG_Reports_Settings::import( $data ) : new WP_Error( 'json', 'Invalid JSON.' );
			if ( is_wp_error( $res ) ) {
				WP_CLI::error( $res->get_error_message() );
			}
			WP_CLI::success( 'Settings imported.' );
			return;
		}
		WP_CLI::error( 'Use: wp tamago-reports settings export [<file>] | import <file>' );
	}

	/**
	 * Copy hits from the Event Plus `epc_visits` table (same column names and types).
	 *
	 * Registrations stored as event_type "submit" become server events "registration" and,
	 * with --goal, conversions of that goal. Summaries are rebuilt afterwards.
	 *
	 * [--table=<table>]
	 * : Source table. Default {prefix}epc_visits.
	 *
	 * [--goal=<goal>]
	 * : Goal key for copied registrations (e.g. registration).
	 *
	 * @subcommand copy-legacy
	 */
	public function copy_legacy( $args, $assoc ): void {
		global $wpdb;
		$src = sanitize_key( (string) ( $assoc['table'] ?? $wpdb->prefix . 'epc_visits' ) );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $src ) ) !== $src ) {
			WP_CLI::error( "Table {$src} not found." );
		}
		$t     = TMG_Reports_Install::hits_table();
		$cols  = array_values( array_diff( array_keys( TMG_Reports_Recorder::columns() ), array( 'event_name', 'goal', 'value' ) ) );
		$list  = implode( ', ', $cols );
		$max   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n     = (int) $wpdb->query( "INSERT INTO {$t} ({$list}) SELECT {$list} FROM {$src} ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$goal  = sanitize_key( (string) ( $assoc['goal'] ?? '' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET event_type = 'server', event_name = 'registration', goal = %s WHERE id > %d AND event_type = 'submit'", $goal, $max ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET event_name = event_type WHERE id > %d AND event_type IN ('click', 'outbound')", $max ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . TMG_Reports_Install::daily_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( TMG_Reports_Summary::UNTIL );
		$built = TMG_Reports_Summary::build_pending( 3650 );
		WP_CLI::success( sprintf( 'Copied %d row(s) from %s; summarised %d day(s).', $n, $src, $built ) );
	}
}

WP_CLI::add_command( 'tamago-reports', 'TMG_Reports_CLI' );

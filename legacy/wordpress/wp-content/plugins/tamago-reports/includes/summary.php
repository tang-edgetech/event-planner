<?php
/**
 * Daily summaries and retention. An hourly cron summarises every finished day up to
 * "today − 2" (late engagement pings can update a page view for up to 24 hours) into
 * {prefix}tmg_reports_daily, one row per day × dimension × value. A daily cron deletes raw
 * hits older than the retention period (summaries hold no personal data and are kept).
 *
 * Used by: cron, TMG_Reports_Query (until()), WP-CLI (summary, purge), tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Summary {

	const UNTIL = 'tmg_reports_summary_until';
	const LOCK  = 'tmg_reports_summary_lock';

	public static function init(): void {
		add_action( 'tmg_reports_hourly', array( __CLASS__, 'cron_hourly' ) );
		add_action( 'tmg_reports_daily', array( __CLASS__, 'cron_daily' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( 'tmg_reports_hourly' ) || ! wp_next_scheduled( 'tmg_reports_daily' ) ) {
					self::schedule();
				}
			}
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'tmg_reports_hourly' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'tmg_reports_hourly' );
		}
		if ( ! wp_next_scheduled( 'tmg_reports_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tmg_reports_daily' );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( 'tmg_reports_hourly' );
		wp_clear_scheduled_hook( 'tmg_reports_daily' );
	}

	public static function cron_hourly(): void {
		self::build_pending( 31 );
	}

	public static function cron_daily(): void {
		self::build_pending( 366 );
		self::purge();
	}

	/** Last local day that is summarised ('' = none). */
	public static function until(): string {
		$u = (string) get_option( self::UNTIL, '' );
		return TMG_Reports_Query::valid_date( $u ) ? $u : '';
	}

	public static function last_closed_day(): string {
		return TMG_Reports_Query::shift( TMG_Reports_Query::today(), -2 );
	}

	public static function earliest_day(): string {
		global $wpdb;
		$min = $wpdb->get_var( 'SELECT MIN(created_at) FROM ' . TMG_Reports_Install::hits_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $min ) {
			return '';
		}
		return wp_date( 'Y-m-d', strtotime( $min . ' UTC' ) );
	}

	/** Summarise one local day (replaces existing rows for that day). Returns rows written. */
	public static function build_day( string $day ): int {
		global $wpdb;
		$d    = TMG_Reports_Install::daily_table();
		$from = TMG_Reports_Query::utc( $day );
		$to   = TMG_Reports_Query::utc( $day, true );
		$wpdb->delete( $d, array( 'day' => $day ), array( '%s' ) );

		$values = array();
		foreach ( array_merge( array( '' ), TMG_Reports_Query::summary_dims() ) as $dim ) {
			foreach ( TMG_Reports_Query::raw_rows( $dim, $from, $to, array() ) as $key => $b ) {
				$vals = array( $day, '' === $dim ? '_total' : $dim, '' === $dim ? '' : mb_substr( (string) $key, 0, 190 ) );
				foreach ( TMG_Reports_Query::BASE as $m ) {
					$vals[] = 'value' === $m ? round( (float) $b[ $m ], 2 ) : (int) $b[ $m ];
				}
				$values[] = $vals;
			}
		}
		$cols    = 'day, dim, val, ' . implode( ', ', TMG_Reports_Query::BASE );
		$place   = '(%s, %s, %s, ' . implode( ', ', array_map( fn( $m ) => 'value' === $m ? '%f' : '%d', TMG_Reports_Query::BASE ) ) . ')';
		$update  = implode( ', ', array_map( fn( $m ) => "{$m} = {$m} + VALUES({$m})", TMG_Reports_Query::BASE ) );
		foreach ( array_chunk( $values, 200 ) as $chunk ) {
			$sql = array();
			foreach ( $chunk as $vals ) {
				$sql[] = $wpdb->prepare( $place, $vals ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			$wpdb->query( "INSERT INTO {$d} ({$cols}) VALUES " . implode( ',', $sql ) . " ON DUPLICATE KEY UPDATE {$update}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}
		TMG_Reports_Query::drop_temp();
		return count( $values );
	}

	/** Summarise finished days after the last summarised one. Returns the number of days built. */
	public static function build_pending( int $max_days = 31 ): int {
		if ( get_transient( self::LOCK ) ) {
			return 0;
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		$last  = self::last_closed_day();
		$until = self::until();
		$start = '' !== $until ? TMG_Reports_Query::shift( $until, 1 ) : self::earliest_day();
		$built = 0;
		if ( '' === $start ) {
			update_option( self::UNTIL, $last, false ); // No data yet: nothing to summarise.
		} else {
			for ( $day = $start; $day <= $last && $built < $max_days; $day = TMG_Reports_Query::shift( $day, 1 ) ) {
				self::build_day( $day );
				update_option( self::UNTIL, $day, false );
				++$built;
			}
		}
		delete_transient( self::LOCK );
		return $built;
	}

	/**
	 * Rebuild summaries for a window of finished days, then continue any pending days.
	 * When nothing has been summarised yet, everything from the earliest hit is built.
	 */
	public static function rebuild( int $days ): int {
		$last  = self::last_closed_day();
		$from  = TMG_Reports_Query::shift( $last, - max( 0, $days - 1 ) );
		$until = self::until();
		$first = self::earliest_day();
		if ( '' === $first ) {
			return self::build_pending( 0 );
		}
		if ( '' === $until ) {
			$from = $first;
		} elseif ( $until < $from ) {
			$from = TMG_Reports_Query::shift( $until, 1 ); // Never leave unsummarised gaps.
		}
		$from  = max( $from, $first );
		$built = 0;
		for ( $day = $from; $day <= $last; $day = TMG_Reports_Query::shift( $day, 1 ) ) {
			self::build_day( $day );
			++$built;
		}
		if ( '' === $until || $until < $last ) {
			update_option( self::UNTIL, $last, false );
		}
		return $built;
	}

	/** Delete raw hits older than the retention period. Returns rows deleted. */
	public static function purge( ?int $days = null ): int {
		global $wpdb;
		$days = null === $days ? (int) TMG_Reports_Settings::get( 'retention_days' ) : $days;
		if ( $days <= 0 ) {
			return 0;
		}
		$cut   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$total = 0;
		do {
			$n      = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TMG_Reports_Install::hits_table() . ' WHERE created_at < %s LIMIT 10000', $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total += $n;
		} while ( 10000 === $n );
		return $total;
	}
}

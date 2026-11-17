<?php
/**
 * Report query engine. Reads daily summaries for days the cron has summarised and raw hits for
 * the rest (today / yesterday), or raw hits only when filters are set. Returns plain arrays.
 *
 * Visitor counts: the totals "visitors" figure is always an exact distinct count. In tables and
 * week / month charts served from summaries, visitors are the sum of daily unique visitors.
 *
 * Used by: tmg_reports_query(), report screen, CSV export, post columns, meta box, dashboard widget, summaries.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Query {

	/** Additive base metrics (stored in the summary table). */
	const BASE = array( 'views', 'visitors', 'sessions', 'new_visitors', 'entries', 'exits', 'bounces', 'dur_sum', 'dur_n', 'scroll_sum', 'scroll_n', 'events', 'conversions', 'value' );

	/** Public metrics. */
	const METRICS = array( 'views', 'visitors', 'sessions', 'new_visitors', 'views_per_session', 'avg_time', 'avg_scroll', 'bounce_rate', 'entries', 'exits', 'events', 'conversions', 'conversion_rate', 'value' );

	const FILTERS = array( 'post_type', 'post', 'path', 'channel', 'ref_domain', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'device', 'browser', 'os', 'country', 'region', 'city', 'language', 'goal' );

	const RANGES = array( 'today', 'yesterday', '7d', '30d', '90d', 'this_month', 'last_month', 'this_year', 'custom' );

	/** Dimensions: SQL expression ({local} = created_at in site time), kind, and the filter a row click sets. */
	public static function dims(): array {
		return array(
			'page'            => array( 'expr' => "IF(post_id>0, CAST(post_id AS CHAR), path)", 'kind' => 'page', 'filter' => 'post' ),
			'post_type'       => array( 'expr' => 'post_type', 'kind' => 'page', 'filter' => 'post_type' ),
			'ref_domain'      => array( 'expr' => 'ref_domain', 'kind' => 'page', 'filter' => 'ref_domain' ),
			'channel'         => array( 'expr' => 'channel', 'kind' => 'page', 'filter' => 'channel' ),
			'utm_source'      => array( 'expr' => 'utm_source', 'kind' => 'page', 'filter' => 'utm_source' ),
			'utm_medium'      => array( 'expr' => 'utm_medium', 'kind' => 'page', 'filter' => 'utm_medium' ),
			'utm_campaign'    => array( 'expr' => 'utm_campaign', 'kind' => 'page', 'filter' => 'utm_campaign' ),
			'utm_term'        => array( 'expr' => 'utm_term', 'kind' => 'page', 'filter' => 'utm_term' ),
			'utm_content'     => array( 'expr' => 'utm_content', 'kind' => 'page', 'filter' => 'utm_content' ),
			'device'          => array( 'expr' => 'device', 'kind' => 'page', 'filter' => 'device' ),
			'browser'         => array( 'expr' => 'browser', 'kind' => 'page', 'filter' => 'browser' ),
			'browser_version' => array( 'expr' => "CONCAT(browser,' ',browser_version)", 'kind' => 'page', 'filter' => '' ),
			'os'              => array( 'expr' => 'os', 'kind' => 'page', 'filter' => 'os' ),
			'os_version'      => array( 'expr' => "CONCAT(os,' ',os_version)", 'kind' => 'page', 'filter' => '' ),
			'screen'          => array( 'expr' => 'screen', 'kind' => 'page', 'filter' => '' ),
			'country'         => array( 'expr' => 'country', 'kind' => 'page', 'filter' => 'country' ),
			'region'          => array( 'expr' => "CONCAT(country,'|',region)", 'kind' => 'page', 'filter' => 'region' ),
			'city'            => array( 'expr' => "CONCAT(country,'|',region,'|',city)", 'kind' => 'page', 'filter' => 'city' ),
			'language'        => array( 'expr' => 'language', 'kind' => 'page', 'filter' => 'language' ),
			'timezone'        => array( 'expr' => 'timezone', 'kind' => 'page', 'filter' => '' ),
			'new_returning'   => array( 'expr' => "IF(is_new=1,'new','returning')", 'kind' => 'page', 'filter' => '' ),
			'hour_weekday'    => array( 'expr' => "CONCAT(WEEKDAY({local}),'|',HOUR({local}))", 'kind' => 'page', 'filter' => '' ),
			'event'           => array( 'expr' => "CONCAT(event_type,'|',event_name,'|',label)", 'kind' => 'event', 'filter' => '' ),
			'goal'            => array( 'expr' => 'goal', 'kind' => 'goal', 'filter' => 'goal' ),
			'day'             => array( 'expr' => 'DATE({local})', 'kind' => 'page', 'filter' => '' ),
		);
	}

	/** Dimensions kept in the daily summary table ('_total' holds the day totals). */
	public static function summary_dims(): array {
		return array_values( array_diff( array_keys( self::dims() ), array( 'day' ) ) );
	}

	/* ---------------------------------------------------------------- dates */

	public static function offset(): int {
		$tz = wp_timezone();
		return (int) $tz->getOffset( new DateTime( 'now', $tz ) );
	}

	public static function today(): string {
		return wp_date( 'Y-m-d' );
	}

	public static function valid_date( string $d ): bool {
		$o = DateTime::createFromFormat( '!Y-m-d', $d );
		return $o && $o->format( 'Y-m-d' ) === $d;
	}

	public static function shift( string $day, int $days ): string {
		$d = new DateTime( $day . ' 12:00:00', new DateTimeZone( 'UTC' ) );
		$d->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' );
		return $d->format( 'Y-m-d' );
	}

	/** @return array{0:string,1:string} Local from / to dates for a named range. */
	public static function range_dates( string $range, string $from = '', string $to = '' ): array {
		$today = self::today();
		switch ( $range ) {
			case 'today':
				return array( $today, $today );
			case 'yesterday':
				$y = self::shift( $today, -1 );
				return array( $y, $y );
			case '7d':
				return array( self::shift( $today, -6 ), $today );
			case '90d':
				return array( self::shift( $today, -89 ), $today );
			case 'this_month':
				return array( substr( $today, 0, 8 ) . '01', $today );
			case 'last_month':
				$first = new DateTime( substr( $today, 0, 8 ) . '01 12:00:00', new DateTimeZone( 'UTC' ) );
				$first->modify( '-1 month' );
				return array( $first->format( 'Y-m-01' ), $first->format( 'Y-m-t' ) );
			case 'this_year':
				return array( substr( $today, 0, 4 ) . '-01-01', $today );
			case 'custom':
				if ( self::valid_date( $from ) && self::valid_date( $to ) ) {
					return $from <= $to ? array( $from, $to ) : array( $to, $from );
				}
				break;
		}
		return array( self::shift( $today, -29 ), $today );
	}

	/** Previous period of the same length. */
	public static function previous( string $from, string $to ): array {
		$days = self::days_between( $from, $to ) + 1;
		return array( self::shift( $from, -$days ), self::shift( $from, -1 ) );
	}

	public static function days_between( string $from, string $to ): int {
		return (int) round( ( strtotime( $to . ' 12:00:00 UTC' ) - strtotime( $from . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS );
	}

	/** UTC datetime for the start (or end) of a local day. */
	public static function utc( string $day, bool $end = false ): string {
		$d = new DateTime( $day . ( $end ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
		$d->setTimezone( new DateTimeZone( 'UTC' ) );
		return $d->format( 'Y-m-d H:i:s' );
	}

	/* ---------------------------------------------------------------- args */

	public static function normalize( array $args ): array {
		$range = (string) ( $args['range'] ?? '' );
		if ( '' === $range ) {
			$range = ! empty( $args['from'] ) || ! empty( $args['to'] ) ? 'custom' : '30d';
		}
		list( $from, $to ) = self::range_dates( $range, (string) ( $args['from'] ?? '' ), (string) ( $args['to'] ?? ( $args['from'] ?? '' ) ) );

		$dim = $args['dimension'] ?? '';
		if ( '' === $dim && ! empty( $args['dimensions'] ) ) {
			$dim = is_array( $args['dimensions'] ) ? (string) reset( $args['dimensions'] ) : (string) $args['dimensions'];
		}
		$dim = isset( self::dims()[ $dim ] ) ? $dim : '';

		$metrics = array_values( array_intersect( (array) ( $args['metrics'] ?? self::METRICS ), self::METRICS ) );
		$kind    = $dim ? self::dims()[ $dim ]['kind'] : 'page';
		$default = 'goal' === $kind ? 'conversions' : ( 'event' === $kind ? 'events' : 'views' );
		$order   = in_array( $args['order'] ?? '', self::METRICS, true ) ? $args['order'] : $default;

		return array(
			'range'      => $range,
			'from'       => $from,
			'to'         => $to,
			'dimension'  => $dim,
			'metrics'    => $metrics ? $metrics : self::METRICS,
			'filters'    => self::clean_filters( (array) ( $args['filters'] ?? array() ) ),
			'order'      => $order,
			'limit'      => max( 0, (int) ( $args['limit'] ?? 10 ) ),
			'offset'     => max( 0, (int) ( $args['offset'] ?? 0 ) ),
			'source'     => 'raw' === ( $args['source'] ?? 'auto' ) ? 'raw' : 'auto',
			'having'     => in_array( $args['having'] ?? '', self::BASE, true ) ? $args['having'] : '',
			'skip_empty' => ! empty( $args['skip_empty'] ),
		);
	}

	public static function clean_filters( array $in ): array {
		$out = array();
		foreach ( self::FILTERS as $key ) {
			if ( ! isset( $in[ $key ] ) || ! is_scalar( $in[ $key ] ) || '' === trim( (string) $in[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = 'post' === $key ? (string) absint( $in[ $key ] ) : mb_substr( sanitize_text_field( (string) $in[ $key ] ), 0, 255 );
			if ( 'post' === $key && '0' === $out[ $key ] ) {
				unset( $out[ $key ] );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- SQL pieces */

	public static function where( string $from_utc, string $to_utc, array $filters ): string {
		global $wpdb;
		$t   = TMG_Reports_Install::hits_table();
		$sql = $wpdb->prepare( 'created_at BETWEEN %s AND %s', $from_utc, $to_utc );
		foreach ( $filters as $key => $val ) {
			switch ( $key ) {
				case 'post':
					$sql .= $wpdb->prepare( ' AND post_id = %d', (int) $val );
					break;
				case 'region':
					$p    = explode( '|', $val, 2 );
					$sql .= $wpdb->prepare( ' AND country = %s AND region = %s', $p[0], $p[1] ?? '' );
					break;
				case 'city':
					$p    = explode( '|', $val, 3 );
					$sql .= $wpdb->prepare( ' AND country = %s AND region = %s AND city = %s', $p[0], $p[1] ?? '', $p[2] ?? '' );
					break;
				case 'goal':
					$sql .= $wpdb->prepare( " AND visitor_id IN (SELECT g.visitor_id FROM {$t} g WHERE g.goal = %s AND g.created_at BETWEEN %s AND %s AND g.visitor_id <> '')", $val, $from_utc, $to_utc ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					break;
				default:
					if ( in_array( $key, self::FILTERS, true ) ) {
						$sql .= $wpdb->prepare( " AND {$key} = %s", $val ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $key is whitelisted.
					}
			}
		}
		return $sql;
	}

	private static function expr( string $dim ): string {
		$local = 'DATE_ADD(created_at, INTERVAL ' . self::offset() . ' SECOND)';
		return str_replace( '{local}', $local, self::dims()[ $dim ]['expr'] );
	}

	private static function zero(): array {
		return array_fill_keys( self::BASE, 0 );
	}

	/** Base metrics each public metric needs. */
	public static function needs( array $metrics ): array {
		$map  = array(
			'views'             => array( 'views' ),
			'visitors'          => array( 'visitors' ),
			'sessions'          => array( 'sessions' ),
			'new_visitors'      => array( 'new_visitors' ),
			'views_per_session' => array( 'views', 'sessions' ),
			'avg_time'          => array( 'dur_sum', 'dur_n' ),
			'avg_scroll'        => array( 'scroll_sum', 'scroll_n' ),
			'bounce_rate'       => array( 'bounces', 'entries' ),
			'entries'           => array( 'entries' ),
			'exits'             => array( 'exits' ),
			'events'            => array( 'events' ),
			'conversions'       => array( 'conversions' ),
			'conversion_rate'   => array( 'conversions', 'visitors' ),
			'value'             => array( 'value' ),
		);
		$need = array();
		foreach ( $metrics as $m ) {
			$need = array_merge( $need, $map[ $m ] ?? ( in_array( $m, self::BASE, true ) ? array( $m ) : array() ) );
		}
		return array_values( array_unique( $need ) );
	}

	/** @var array<string,string> Session sources built in this request, keyed by WHERE hash. */
	private static $session_tables = array();

	/**
	 * Sessions in a filtered range (first hit, last hit, page views), built once per request
	 * as a temporary table and reused by every table. Falls back to a derived table when the
	 * database user cannot create temporary tables.
	 */
	private static function sessions_source( string $w ): string {
		global $wpdb;
		$t   = TMG_Reports_Install::hits_table();
		$sub = "SELECT MIN(id) sf, MAX(id) sl, COUNT(*) sn FROM {$t} WHERE {$w} AND event_type = 'pageview' AND session_id <> '' GROUP BY session_id";
		$key = md5( $w );
		if ( isset( self::$session_tables[ $key ] ) ) {
			return self::$session_tables[ $key ];
		}
		$name     = 'tmg_rs_' . substr( $key, 0, 12 );
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->query( "CREATE TEMPORARY TABLE {$name} (PRIMARY KEY (sf)) AS {$sub}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors( $suppress );
		self::$session_tables[ $key ] = false === $ok ? "({$sub})" : $name;
		return self::$session_tables[ $key ];
	}

	/** Drop session temp tables (long CLI runs). */
	public static function drop_temp(): void {
		global $wpdb;
		foreach ( self::$session_tables as $name ) {
			if ( 0 === strpos( $name, 'tmg_rs_' ) ) {
				$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		self::$session_tables = array();
	}

	/**
	 * Raw base metrics grouped by a dimension ('' = totals, keyed '_').
	 *
	 * @param array|null $need Base metrics to compute (null = all); the others stay 0.
	 * @return array<string,array> key => base metrics
	 */
	public static function raw_rows( string $dim, string $from_utc, string $to_utc, array $filters, ?array $need = null ): array {
		global $wpdb;
		$t     = TMG_Reports_Install::hits_table();
		$w     = self::where( $from_utc, $to_utc, $filters );
		$kind  = '' === $dim ? 'page' : self::dims()[ $dim ]['kind'];
		$k     = '' === $dim ? "'_'" : self::expr( $dim );
		$group = '' === $dim ? '' : ' GROUP BY k';
		$need  = null === $need ? self::BASE : $need;
		$has   = fn( $m ) => in_array( $m, $need, true );
		$out   = array();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- WHERE built with prepare(); expressions are whitelisted.
		if ( 'event' === $kind ) {
			$rows = $wpdb->get_results( "SELECT {$k} k, COUNT(*) events, COUNT(DISTINCT IF(visitor_id<>'', visitor_id, NULL)) visitors, SUM(goal<>'') conversions, SUM(IF(goal<>'', value, 0)) value FROM {$t} WHERE {$w} AND event_type <> 'pageview'{$group}", ARRAY_A );
		} elseif ( 'goal' === $kind ) {
			$rows = $wpdb->get_results( "SELECT {$k} k, COUNT(*) conversions, SUM(event_type<>'pageview') events, COUNT(DISTINCT IF(visitor_id<>'', visitor_id, NULL)) visitors, SUM(value) value FROM {$t} WHERE {$w} AND goal <> ''{$group}", ARRAY_A );
		} else {
			$cols   = array(
				'views'        => "SUM(event_type='pageview')",
				'visitors'     => "COUNT(DISTINCT IF(event_type='pageview' AND visitor_id<>'', visitor_id, NULL))",
				'sessions'     => "COUNT(DISTINCT IF(event_type='pageview' AND session_id<>'', session_id, NULL))",
				'new_visitors' => "COUNT(DISTINCT IF(event_type='pageview' AND is_new=1 AND visitor_id<>'', visitor_id, NULL))",
				'dur_sum'      => "SUM(IF(event_type='pageview' AND duration>0, duration, 0))",
				'dur_n'        => "SUM(event_type='pageview' AND duration>0)",
				'scroll_sum'   => "SUM(IF(event_type='pageview' AND scroll>0, scroll, 0))",
				'scroll_n'     => "SUM(event_type='pageview' AND scroll>0)",
				'events'       => "SUM(event_type<>'pageview')",
				'conversions'  => "SUM(goal<>'')",
				'value'        => "SUM(IF(goal<>'', value, 0))",
			);
			$select = array();
			foreach ( $cols as $m => $sql ) {
				// Views, events and conversions are cheap and decide whether a row is shown.
				if ( $has( $m ) || in_array( $m, array( 'views', 'events', 'conversions' ), true ) ) {
					$select[] = "{$sql} {$m}";
				}
			}
			$rows = $wpdb->get_results( "SELECT {$k} k, " . implode( ', ', $select ) . " FROM {$t} WHERE {$w}{$group}", ARRAY_A );
		}
		foreach ( (array) $rows as $r ) {
			$key = (string) $r['k'];
			unset( $r['k'] );
			if ( '' === $dim && 0 === (int) array_sum( array_map( 'floatval', $r ) ) ) {
				continue;
			}
			$out[ $key ] = array_merge( self::zero(), array_map( 'floatval', $r ) );
		}

		$want_entries = $has( 'entries' ) || $has( 'bounces' ) || ( '' === $dim && $has( 'exits' ) );
		$want_exits   = 'page' === $dim && $has( 'exits' );
		if ( 'page' === $kind && ( $want_entries || $want_exits ) ) {
			// Session metrics: entries / bounces by the session's first page view, exits by its last.
			$src = self::sessions_source( $w );
			$kh  = '' === $dim ? "'_'" : $k;
			if ( $want_entries ) {
				foreach ( (array) $wpdb->get_results( "SELECT {$kh} k, COUNT(*) entries, SUM(s.sn = 1) bounces FROM {$src} s JOIN {$t} ON {$t}.id = s.sf{$group}", ARRAY_A ) as $r ) {
					$key = (string) $r['k'];
					if ( ! isset( $out[ $key ] ) ) {
						$out[ $key ] = self::zero();
					}
					$out[ $key ]['entries'] = (float) $r['entries'];
					$out[ $key ]['bounces'] = (float) $r['bounces'];
					$out[ $key ]['exits']   = '' === $dim ? (float) $r['entries'] : $out[ $key ]['exits'];
				}
			}
			if ( $want_exits ) {
				foreach ( (array) $wpdb->get_results( "SELECT {$kh} k, COUNT(*) exits FROM {$src} s JOIN {$t} ON {$t}.id = s.sl GROUP BY k", ARRAY_A ) as $r ) {
					if ( ! isset( $out[ (string) $r['k'] ] ) ) {
						$out[ (string) $r['k'] ] = self::zero();
					}
					$out[ (string) $r['k'] ]['exits'] = (float) $r['exits'];
				}
			}
		}
		// phpcs:enable
		return $out;
	}

	/** Base metrics from the daily summary table. */
	public static function summary_rows( string $dim, string $from, string $to ): array {
		global $wpdb;
		$d    = TMG_Reports_Install::daily_table();
		$sums = implode( ', ', array_map( fn( $m ) => "SUM({$m}) {$m}", self::BASE ) );
		if ( '' === $dim ) {
			$sql = $wpdb->prepare( "SELECT '_' k, {$sums} FROM {$d} WHERE dim = '_total' AND day BETWEEN %s AND %s", $from, $to ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( 'day' === $dim ) {
			$sql = $wpdb->prepare( "SELECT day k, {$sums} FROM {$d} WHERE dim = '_total' AND day BETWEEN %s AND %s GROUP BY day", $from, $to ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$sql = $wpdb->prepare( "SELECT val k, {$sums} FROM {$d} WHERE dim = %s AND day BETWEEN %s AND %s GROUP BY val", $dim, $from, $to ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( null === $r['views'] ) {
				continue;
			}
			$key = (string) $r['k'];
			unset( $r['k'] );
			$out[ $key ] = array_map( 'floatval', $r );
		}
		return $out;
	}

	/** Split a range into summary and raw parts. */
	public static function plan( array $a ): array {
		if ( 'raw' === $a['source'] || $a['filters'] ) {
			return array( array( 'raw', $a['from'], $a['to'] ) );
		}
		$until = TMG_Reports_Summary::until();
		$parts = array();
		if ( '' !== $until && $until >= $a['from'] ) {
			$parts[] = array( 'summary', $a['from'], min( $a['to'], $until ) );
		}
		if ( '' === $until || $a['to'] > $until ) {
			$start   = '' === $until || $until < $a['from'] ? $a['from'] : self::shift( $until, 1 );
			$parts[] = array( 'raw', $start, $a['to'] );
		}
		return $parts;
	}

	/** Merged base metrics keyed by dimension value. */
	public static function base( array $a ): array {
		$acc  = array();
		$need = self::needs( array_merge( $a['metrics'], array( $a['order'], $a['having'] ) ) );
		foreach ( self::plan( $a ) as $part ) {
			$rows = 'summary' === $part[0]
				? self::summary_rows( $a['dimension'], $part[1], $part[2] )
				: self::raw_rows( $a['dimension'], self::utc( $part[1] ), self::utc( $part[2], true ), $a['filters'], $need );
			foreach ( $rows as $key => $r ) {
				if ( ! isset( $acc[ $key ] ) ) {
					$acc[ $key ] = $r;
					continue;
				}
				foreach ( self::BASE as $m ) {
					$acc[ $key ][ $m ] += $r[ $m ];
				}
			}
		}
		return $acc;
	}

	public static function derive( array $b ): array {
		$b = array_merge( self::zero(), $b );
		return array(
			'views'             => (int) $b['views'],
			'visitors'          => (int) $b['visitors'],
			'sessions'          => (int) $b['sessions'],
			'new_visitors'      => (int) $b['new_visitors'],
			'views_per_session' => $b['sessions'] > 0 ? round( $b['views'] / $b['sessions'], 2 ) : 0,
			'avg_time'          => $b['dur_n'] > 0 ? (int) round( $b['dur_sum'] / $b['dur_n'] ) : 0,
			'avg_scroll'        => $b['scroll_n'] > 0 ? (int) round( $b['scroll_sum'] / $b['scroll_n'] ) : 0,
			'bounce_rate'       => $b['entries'] > 0 ? round( $b['bounces'] / $b['entries'] * 100, 1 ) : 0,
			'entries'           => (int) $b['entries'],
			'exits'             => (int) $b['exits'],
			'events'            => (int) $b['events'],
			'conversions'       => (int) $b['conversions'],
			'conversion_rate'   => $b['visitors'] > 0 ? round( min( 100, $b['conversions'] / $b['visitors'] * 100 ), 1 ) : 0,
			'value'             => round( (float) $b['value'], 2 ),
		);
	}

	/* ---------------------------------------------------------------- public entry points */

	/** Totals for a range (all metrics), visitors counted exactly. */
	public static function totals( array $args ): array {
		global $wpdb;
		$a          = self::normalize( array_merge( $args, array( 'dimension' => '' ) ) );
		$a['dimension'] = '';
		$base       = self::base( $a );
		$b          = $base['_'] ?? self::zero();
		$parts      = self::plan( $a );
		$from_sum   = 'summary' === $parts[0][0];
		if ( $from_sum && ( count( $parts ) > 1 || $parts[0][1] !== $parts[0][2] ) ) {
			$b['visitors'] = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT visitor_id) FROM ' . TMG_Reports_Install::hits_table() . " WHERE event_type = 'pageview' AND created_at BETWEEN %s AND %s AND visitor_id <> ''", self::utc( $a['from'] ), self::utc( $a['to'], true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$b['new_visitors'] = min( $b['new_visitors'], $b['visitors'] );
		}
		$all = self::derive( $b );
		return array_intersect_key( $all, array_flip( $a['metrics'] ) );
	}

	/** A dimension table: rows (key, label, filter, metrics…) sorted, sliced, plus the total row count. */
	public static function table( array $args ): array {
		$a = self::normalize( $args );
		if ( '' === $a['dimension'] ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$rows = array();
		foreach ( self::base( $a ) as $key => $b ) {
			$key = (string) $key;
			if ( $a['skip_empty'] && ( '' === trim( $key ) || preg_match( '/^\|+$/', $key ) ) ) {
				continue;
			}
			if ( '' !== $a['having'] && $b[ $a['having'] ] <= 0 ) {
				continue;
			}
			$row = self::derive( $b );
			if ( 0 === $row['views'] && 0 === $row['events'] && 0 === $row['conversions'] && 0 === $row['entries'] ) {
				continue;
			}
			$rows[] = array( 'key' => $key ) + $row;
		}
		$order = $a['order'];
		usort(
			$rows,
			function ( $x, $y ) use ( $order ) {
				return $y[ $order ] <=> $x[ $order ] ?: $y['visitors'] <=> $x['visitors'] ?: strcmp( $x['key'], $y['key'] );
			}
		);
		$total = count( $rows );
		$rows  = $a['limit'] > 0 ? array_slice( $rows, $a['offset'], $a['limit'] ) : array_slice( $rows, $a['offset'] );
		if ( 'page' === $a['dimension'] ) {
			$ids = array_filter( array_map( fn( $r ) => ctype_digit( $r['key'] ) ? (int) $r['key'] : 0, $rows ) );
			if ( $ids ) {
				_prime_post_caches( $ids, false, false );
			}
		}
		$keep = array_flip( array_merge( array( 'key', 'label' ), $a['metrics'] ) );
		foreach ( $rows as &$r ) {
			$r['label'] = TMG_Reports_Labels::label( $a['dimension'], $r['key'] );
			$r          = array_intersect_key( $r, $keep );
		}
		unset( $r );
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Time series for the range: labels + views + visitors (+ conversions) grouped by day, week or month.
	 */
	public static function series( array $args, string $group = 'day' ): array {
		global $wpdb;
		$a              = self::normalize( array_merge( $args, array( 'dimension' => 'day', 'metrics' => array( 'views', 'visitors', 'conversions' ) ) ) );
		$a['dimension'] = 'day';
		$days           = self::base( $a );
		$group          = in_array( $group, array( 'day', 'week', 'month' ), true ) ? $group : 'day';

		$buckets = array();
		for ( $d = $a['from']; $d <= $a['to']; $d = self::shift( $d, 1 ) ) {
			$key = self::bucket( $d, $group );
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array( 'views' => 0, 'visitors' => 0, 'conversions' => 0 );
			}
			$b                               = $days[ $d ] ?? self::zero();
			$buckets[ $key ]['views']       += (int) $b['views'];
			$buckets[ $key ]['visitors']    += (int) $b['visitors'];
			$buckets[ $key ]['conversions'] += (int) $b['conversions'];
		}

		if ( 'day' !== $group ) {
			// Exact visitors per week / month.
			$local  = 'DATE_ADD(created_at, INTERVAL ' . self::offset() . ' SECOND)';
			$expr   = 'week' === $group ? "DATE_FORMAT(DATE_SUB({$local}, INTERVAL WEEKDAY({$local}) DAY), '%Y-%m-%d')" : "DATE_FORMAT({$local}, '%Y-%m-01')";
			$w      = self::where( self::utc( $a['from'] ), self::utc( $a['to'], true ), $a['filters'] );
			$counts = $wpdb->get_results( "SELECT {$expr} k, COUNT(DISTINCT visitor_id) n FROM " . TMG_Reports_Install::hits_table() . " WHERE {$w} AND event_type = 'pageview' AND visitor_id <> '' GROUP BY k", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $buckets as &$b ) {
				$b['visitors'] = 0;
			}
			unset( $b );
			foreach ( (array) $counts as $c ) {
				if ( isset( $buckets[ $c['k'] ] ) ) {
					$buckets[ $c['k'] ]['visitors'] = (int) $c['n'];
				}
			}
		}

		$out = array( 'keys' => array(), 'labels' => array(), 'short' => array(), 'views' => array(), 'visitors' => array(), 'conversions' => array(), 'group' => $group );
		foreach ( $buckets as $key => $b ) {
			$ts               = strtotime( $key . ' 12:00:00 UTC' );
			$out['keys'][]    = $key;
			$out['labels'][]  = 'month' === $group ? gmdate( 'M Y', $ts ) : ( 'week' === $group ? sprintf( /* translators: %s: date */ __( 'Week of %s', 'tamago-reports' ), gmdate( 'j M', $ts ) ) : gmdate( 'D j M', $ts ) );
			$out['short'][]   = 'month' === $group ? gmdate( 'M Y', $ts ) : gmdate( 'j M', $ts );
			$out['views'][]   = $b['views'];
			$out['visitors'][] = $b['visitors'];
			$out['conversions'][] = $b['conversions'];
		}
		return $out;
	}

	private static function bucket( string $day, string $group ): string {
		if ( 'month' === $group ) {
			return substr( $day, 0, 8 ) . '01';
		}
		if ( 'week' === $group ) {
			$dow = (int) gmdate( 'N', strtotime( $day . ' 12:00:00 UTC' ) );
			return self::shift( $day, 1 - $dow );
		}
		return $day;
	}

	/**
	 * Generic entry point behind tmg_reports_query(): totals when no dimension is given,
	 * a list of rows otherwise ('day' gives a daily series as rows).
	 */
	public static function run( array $args ): array {
		$a = self::normalize( $args );
		if ( '' === $a['dimension'] ) {
			return self::totals( $args );
		}
		if ( 'day' === $a['dimension'] ) {
			$s    = self::series( $args, 'day' );
			$rows = array();
			foreach ( $s['keys'] as $i => $key ) {
				$rows[] = array(
					'key'         => $key,
					'label'       => $key,
					'views'       => $s['views'][ $i ],
					'visitors'    => $s['visitors'][ $i ],
					'conversions' => $s['conversions'][ $i ],
				);
			}
			return $rows;
		}
		return self::table( $args )['rows'];
	}
}

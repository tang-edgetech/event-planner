<?php
/**
 * Analytics report screen (Event Plus → Reports).
 *
 * Screens: Event Plus → Reports.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Analytics_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_epc_analytics_csv', array( __CLASS__, 'export_csv' ) );
	}

	public static function menu(): void {
		$hook = add_submenu_page( 'epc', 'Reports', 'Reports', 'epc_view_reports', 'epc-analytics', array( __CLASS__, 'render' ) );
		add_action( 'admin_print_scripts-' . $hook, function () {
			wp_enqueue_script( 'chartjs', EPC_URL . 'assets/vendor/chart.umd.min.js', array(), '4.4.1', true );
		} );
	}

	/** Parse filters from request into a reusable SQL WHERE clause. */
	public static function filters(): array {
		$tz   = wp_timezone();
		$to   = sanitize_text_field( $_GET['to'] ?? '' ) ?: wp_date( 'Y-m-d' ); // phpcs:ignore
		$from = sanitize_text_field( $_GET['from'] ?? '' ) ?: wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ); // phpcs:ignore
		// Anything that is not a real Y-m-d date falls back to the default range.
		$valid = fn( $d ) => ( $o = DateTime::createFromFormat( '!Y-m-d', $d ) ) && $o->format( 'Y-m-d' ) === $d;
		if ( ! $valid( $to ) ) {
			$to = wp_date( 'Y-m-d' );
		}
		if ( ! $valid( $from ) ) {
			$from = wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		}
		$pid  = absint( $_GET['post_id'] ?? 0 ); // phpcs:ignore
		$with = ! isset( $_GET['solo'] ); // phpcs:ignore -- include linked event by default

		$from_utc = ( new DateTime( $from . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$to_utc   = ( new DateTime( $to . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		global $wpdb;
		$where = $wpdb->prepare( 'created_at BETWEEN %s AND %s', $from_utc, $to_utc );
		$ids   = array();
		if ( $pid ) {
			$ids = array( $pid );
			if ( $with && EPC_Post_Types::MICROSITE === get_post_type( $pid ) ) {
				$linked = (int) get_field( 'linked_event', $pid );
				if ( $linked ) {
					$ids[] = $linked;
				}
			}
			$where .= ' AND post_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		return array(
			'from'   => $from,
			'to'     => $to,
			'pid'    => $pid,
			'ids'    => $ids,
			'where'  => $where,
			'offset' => $tz->getOffset( new DateTime( 'now', $tz ) ),
		);
	}

	public static function render(): void {
		global $wpdb;
		$t = EPC_Analytics::table();
		$f = self::filters();
		$w = $f['where'];

		$k = $wpdb->get_row(
			"SELECT
				SUM(event_type='pageview') views,
				COUNT(DISTINCT CASE WHEN event_type='pageview' THEN visitor_id END) visitors,
				COUNT(DISTINCT CASE WHEN event_type='pageview' THEN session_id END) sessions,
				SUM(event_type='pageview' AND is_new=1) new_views,
				AVG(CASE WHEN event_type='pageview' AND duration>0 THEN duration END) avg_dur,
				AVG(CASE WHEN event_type='pageview' AND duration>0 THEN scroll END) avg_scroll,
				SUM(event_type IN ('click','outbound')) clicks,
				SUM(event_type='submit') submits
			FROM {$t} WHERE {$w}",
			ARRAY_A
		);
		$conv = $k['visitors'] ? round( $k['submits'] / $k['visitors'] * 100, 1 ) : 0;

		$off    = (int) $f['offset'];
		$series = $wpdb->get_results(
			"SELECT DATE(DATE_ADD(created_at, INTERVAL {$off} SECOND)) d,
				SUM(event_type='pageview') v,
				COUNT(DISTINCT CASE WHEN event_type='pageview' THEN visitor_id END) u,
				SUM(event_type='submit') s
			FROM {$t} WHERE {$w} GROUP BY d ORDER BY d",
			ARRAY_A
		);
		// Fill empty days.
		$by    = array_column( $series, null, 'd' );
		$chart = array( 'labels' => array(), 'views' => array(), 'visitors' => array(), 'submits' => array() );
		for ( $d = strtotime( $f['from'] ); $d <= strtotime( $f['to'] ); $d += DAY_IN_SECONDS ) {
			$key                 = gmdate( 'Y-m-d', $d );
			$chart['labels'][]   = gmdate( 'j M', $d );
			$chart['views'][]    = (int) ( $by[ $key ]['v'] ?? 0 );
			$chart['visitors'][] = (int) ( $by[ $key ]['u'] ?? 0 );
			$chart['submits'][]  = (int) ( $by[ $key ]['s'] ?? 0 );
		}

		$dim = function ( string $col, int $limit = 10, string $type = 'pageview' ) use ( $wpdb, $t, $w ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT {$col} k, COUNT(*) n, COUNT(DISTINCT visitor_id) u FROM {$t} WHERE {$w} AND event_type = %s GROUP BY k ORDER BY n DESC LIMIT %d", $type, $limit ),
				ARRAY_A
			);
		};

		$devices  = $dim( 'device' );
		$channels = $dim( 'channel' );
		$hours    = $wpdb->get_results( "SELECT HOUR(DATE_ADD(created_at, INTERVAL {$off} SECOND)) k, COUNT(*) n FROM {$t} WHERE {$w} AND event_type='pageview' GROUP BY k", ARRAY_A );
		$hour_map = array_fill( 0, 24, 0 );
		foreach ( $hours as $h ) {
			$hour_map[ (int) $h['k'] ] = (int) $h['n'];
		}

		$posts = get_posts( array( 'post_type' => array( EPC_Post_Types::EVENT, EPC_Post_Types::MICROSITE ), 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'date', 'order' => 'DESC' ) );
		?>
		<div class="wrap epc-report">
			<h1 class="wp-heading-inline">Reports</h1>
			<form method="get" class="epc-report-filters">
				<input type="hidden" name="page" value="epc-analytics">
				<select name="post_id">
					<option value="">All pages</option>
					<?php foreach ( array( EPC_Post_Types::MICROSITE => 'Microsites', EPC_Post_Types::EVENT => 'Events' ) as $pt => $group ) : ?>
						<optgroup label="<?php echo esc_attr( $group ); ?>">
							<?php foreach ( $posts as $p ) : if ( $p->post_type !== $pt ) { continue; } ?>
								<option value="<?php echo (int) $p->ID; ?>" <?php selected( $f['pid'], $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
				<label>From <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
				<label>To <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
				<?php if ( $f['pid'] && EPC_Post_Types::MICROSITE === get_post_type( $f['pid'] ) ) : ?>
					<label><input type="checkbox" name="solo" value="1" <?php checked( isset( $_GET['solo'] ) ); // phpcs:ignore ?>> Exclude linked event</label>
				<?php endif; ?>
				<span class="epc-quick">
					<?php foreach ( array( 7 => '7d', 30 => '30d', 90 => '90d', 365 => '12m' ) as $days => $lbl ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'from' => wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ), 'to' => wp_date( 'Y-m-d' ) ) ) ); ?>"><?php echo esc_html( $lbl ); ?></a>
					<?php endforeach; ?>
				</span>
				<button class="button button-primary">Apply</button>
				<?php if ( current_user_can( 'epc_export_reports' ) ) : ?><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'epc_analytics_csv', 'from' => $f['from'], 'to' => $f['to'], 'post_id' => $f['pid'] ), admin_url( 'admin-post.php' ) ), 'epc_analytics_csv' ) ); ?>">Export raw data (CSV)</a><?php endif; ?>
			</form>

			<div class="epc-kpis">
				<?php
				$kpis = array(
					'Page views'         => number_format_i18n( (int) $k['views'] ),
					'Unique visitors'    => number_format_i18n( (int) $k['visitors'] ),
					'Sessions'           => number_format_i18n( (int) $k['sessions'] ),
					'Avg. time on page'  => self::dur( (float) $k['avg_dur'] ),
					'Avg. scroll depth'  => round( (float) $k['avg_scroll'] ) . '%',
					'Button clicks'      => number_format_i18n( (int) $k['clicks'] ),
					'Registrations'      => number_format_i18n( (int) $k['submits'] ),
					'Conversion rate'    => $conv . '%',
				);
				foreach ( $kpis as $label => $val ) {
					echo '<div class="epc-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $val ) . '</strong></div>';
				}
				?>
			</div>

			<div class="epc-card epc-card--wide">
				<h2>Traffic over time</h2>
				<div class="epc-chart"><canvas id="epc-chart-time"></canvas></div>
			</div>

			<div class="epc-grid-3">
				<div class="epc-card"><h2>Devices</h2><div class="epc-chart epc-chart--sm"><canvas id="epc-chart-device"></canvas></div></div>
				<div class="epc-card"><h2>Channels</h2><div class="epc-chart epc-chart--sm"><canvas id="epc-chart-channel"></canvas></div></div>
				<div class="epc-card"><h2>Hour of day</h2><div class="epc-chart epc-chart--sm"><canvas id="epc-chart-hour"></canvas></div></div>
			</div>

			<div class="epc-grid-3">
				<?php
				if ( ! $f['pid'] ) {
					$top = $wpdb->get_results( "SELECT post_id k, COUNT(*) n, COUNT(DISTINCT visitor_id) u FROM {$t} WHERE {$w} AND event_type='pageview' GROUP BY post_id ORDER BY n DESC LIMIT 10", ARRAY_A );
					foreach ( $top as &$r ) {
						$r['k'] = $r['k'] ? get_the_title( (int) $r['k'] ) : '(other pages)';
					}
					self::table_card( 'Top pages', $top );
				}
				self::table_card( 'Countries', $dim( 'country_name' ) );
				self::table_card( 'Regions / states', $dim( 'region' ) );
				self::table_card( 'Cities', $dim( 'city' ) );
				self::table_card( 'Timezones', $dim( 'timezone' ) );
				self::table_card( 'Languages', $dim( 'language' ) );
				self::table_card( 'Referring sites', $dim( 'ref_domain' ) );
				self::table_card( 'UTM source', $dim( 'utm_source' ) );
				self::table_card( 'UTM medium', $dim( 'utm_medium' ) );
				self::table_card( 'UTM campaign', $dim( 'utm_campaign' ) );
				self::table_card( 'Browsers', $dim( 'browser' ) );
				self::table_card( 'Operating systems', $dim( 'os' ) );
				self::table_card( 'Screen sizes', $dim( 'screen' ) );
				self::table_card( 'Top clicks', $wpdb->get_results( "SELECT label k, COUNT(*) n, COUNT(DISTINCT visitor_id) u FROM {$t} WHERE {$w} AND event_type IN ('click','outbound') GROUP BY label ORDER BY n DESC LIMIT 10", ARRAY_A ) );
				self::table_card( 'Registrations by channel', $dim( 'channel', 10, 'submit' ) );
				self::table_card( 'Registrations by campaign', $dim( 'utm_campaign', 10, 'submit' ) );
				?>
			</div>
			<p class="description">Location comes from <?php echo esc_html( epc_setting( 'epc_geo_provider', 'headers' ) ); ?> (change in Settings → Tracking). Visitors are counted by an anonymous browser ID; raw IP addresses are never stored.</p>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			if (!window.Chart) return;
			const css = getComputedStyle(document.documentElement);
			const C = ['#7a0f3d', '#d98a2b', '#2b7a78', '#5560c4', '#c44f6a', '#6b8f3a', '#8a6bb8', '#999'];
			const time = <?php echo wp_json_encode( $chart ); ?>;
			new Chart(document.getElementById('epc-chart-time'), {
				type: 'line',
				data: { labels: time.labels, datasets: [
					{ label: 'Page views', data: time.views, borderColor: C[0], backgroundColor: C[0] + '22', fill: true, tension: .3 },
					{ label: 'Visitors', data: time.visitors, borderColor: C[1], tension: .3 },
					{ label: 'Registrations', data: time.submits, borderColor: C[2], backgroundColor: C[2], type: 'bar', yAxisID: 'y' }
				] },
				options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
			});
			const pie = (id, rows) => new Chart(document.getElementById(id), {
				type: 'doughnut',
				data: { labels: rows.map(r => r.k || '(none)'), datasets: [{ data: rows.map(r => +r.n), backgroundColor: C, borderWidth: 0 }] },
				options: { maintainAspectRatio: false, plugins: { legend: { position: 'right' } }, cutout: '60%' }
			});
			pie('epc-chart-device', <?php echo wp_json_encode( $devices ); ?>);
			pie('epc-chart-channel', <?php echo wp_json_encode( $channels ); ?>);
			new Chart(document.getElementById('epc-chart-hour'), {
				type: 'bar',
				data: { labels: [...Array(24).keys()].map(h => h + ':00'), datasets: [{ label: 'Views', data: <?php echo wp_json_encode( array_values( $hour_map ) ); ?>, backgroundColor: C[0] }] },
				options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
			});
		});
		</script>
		<?php
	}

	private static function table_card( string $title, array $rows ): void {
		$max = $rows ? max( array_map( fn( $r ) => (int) $r['n'], $rows ) ) : 0;
		echo '<div class="epc-card"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="epc-muted">No data yet.</p></div>';
			return;
		}
		echo '<table class="epc-dim"><thead><tr><th></th><th>Hits</th><th>Visitors</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$pct = $max ? round( $r['n'] / $max * 100 ) : 0;
			echo '<tr><td><div class="epc-dim-bar" style="--w:' . (int) $pct . '%"></div><span>' . esc_html( '' === (string) $r['k'] ? '(unknown)' : (string) $r['k'] ) . '</span></td><td>' . (int) $r['n'] . '</td><td>' . (int) $r['u'] . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function dur( float $s ): string {
		$s = (int) round( $s );
		return $s >= 60 ? floor( $s / 60 ) . 'm ' . ( $s % 60 ) . 's' : $s . 's';
	}

	public static function export_csv(): void {
		check_admin_referer( 'epc_analytics_csv' );
		if ( ! current_user_can( 'epc_export_reports' ) ) {
			wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		global $wpdb;
		$f    = self::filters();
		$rows = $wpdb->get_results( 'SELECT * FROM ' . EPC_Analytics::table() . ' WHERE ' . $f['where'] . ' ORDER BY id', ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['page_title'] = $r['post_id'] ? get_the_title( (int) $r['post_id'] ) : '';
			unset( $r['ip_hash'] );
		}
		EPC_Import_Export::send_rows( $rows, 'visits-' . $f['from'] . '-to-' . $f['to'], 'csv' );
	}
}

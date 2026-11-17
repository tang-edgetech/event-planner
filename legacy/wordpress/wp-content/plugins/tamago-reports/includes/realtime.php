<?php
/**
 * Real-time panel: visitors active in the last 5 and 30 minutes and the page each one is on
 * now (their latest page view). Rendered server-side, then refreshed every 15 seconds by
 * admin.js through admin-ajax (nonce + view capability).
 *
 * Screens: Reports → Overview ("Right now" card), Reports → Real-time.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Realtime {

	public static function init(): void {
		add_action( 'wp_ajax_tmg_reports_realtime', array( __CLASS__, 'ajax' ) );
	}

	public static function data( int $limit = 10 ): array {
		global $wpdb;
		$t    = TMG_Reports_Install::hits_table();
		$m5   = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );
		$m30  = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$a5   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) FROM {$t} WHERE created_at >= %s AND visitor_id <> ''", $m5 ) );
		$a30  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) FROM {$t} WHERE created_at >= %s AND visitor_id <> ''", $m30 ) );
		$v30  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s AND event_type = 'pageview'", $m30 ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT IF(h.post_id>0, CAST(h.post_id AS CHAR), h.path) k, COUNT(*) n, SUM(h.created_at >= %s) n5
				FROM {$t} h JOIN (SELECT MAX(id) m FROM {$t} WHERE created_at >= %s AND event_type = 'pageview' AND visitor_id <> '' GROUP BY visitor_id) x ON h.id = x.m
				GROUP BY k ORDER BY n DESC LIMIT %d",
				$m5,
				$m30,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		$pages = array();
		foreach ( (array) $rows as $r ) {
			$pages[] = array(
				'label' => TMG_Reports_Labels::label( 'page', (string) $r['k'] ),
				'url'   => ctype_digit( (string) $r['k'] ) ? TMG_Reports_Admin_Screen::url( array( 'f' => array( 'post' => $r['k'] ), 'range' => 'today' ) ) : '',
				'n'     => (int) $r['n'],
				'n5'    => (int) $r['n5'],
			);
		}
		return array(
			'm5'    => $a5,
			'm30'   => $a30,
			'v30'   => $v30,
			'pages' => $pages,
			'time'  => wp_date( get_option( 'time_format' ) ),
		);
	}

	public static function ajax(): void {
		check_ajax_referer( 'tmg_reports_realtime' );
		if ( ! current_user_can( TMG_Reports_Permissions::VIEW ) ) {
			wp_send_json_error( null, 403 );
		}
		$limit = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 10;
		wp_send_json_success( self::data( $limit ) );
	}

	public static function panel( int $limit = 10 ): void {
		$d = self::data( $limit );
		?>
		<div class="tmg-rt" data-tmg-realtime data-limit="<?php echo (int) $limit; ?>">
			<div class="tmg-rt-nums">
				<div><strong data-k="m5"><?php echo esc_html( number_format_i18n( $d['m5'] ) ); ?></strong><span><?php esc_html_e( 'active in last 5 min', 'tamago-reports' ); ?></span></div>
				<div><strong data-k="m30"><?php echo esc_html( number_format_i18n( $d['m30'] ) ); ?></strong><span><?php esc_html_e( 'in last 30 min', 'tamago-reports' ); ?></span></div>
				<div><strong data-k="v30"><?php echo esc_html( number_format_i18n( $d['v30'] ) ); ?></strong><span><?php esc_html_e( 'page views (30 min)', 'tamago-reports' ); ?></span></div>
			</div>
			<table class="widefat striped tmg-table tmg-rt-pages">
				<thead><tr><th><?php esc_html_e( 'Current page', 'tamago-reports' ); ?></th><th class="num"><?php esc_html_e( 'Visitors', 'tamago-reports' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $d['pages'] ) : ?>
					<tr><td colspan="2" class="tmg-empty"><?php esc_html_e( 'Nobody is on the site right now.', 'tamago-reports' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $d['pages'] as $p ) : ?>
					<tr><td><?php echo $p['url'] ? '<a href="' . esc_url( $p['url'] ) . '">' . esc_html( $p['label'] ) . '</a>' : esc_html( $p['label'] ); ?></td><td class="num"><?php echo (int) $p['n']; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Updated', 'tamago-reports' ); ?> <span data-k="time"><?php echo esc_html( $d['time'] ); ?></span></p>
		</div>
		<?php
	}

	public static function render_tab(): void {
		echo '<div class="tmg-card tmg-card-full"><div class="tmg-card-head"><h2>' . esc_html__( 'Real-time', 'tamago-reports' ) . '</h2></div>';
		self::panel( 50 );
		echo '<p class="description">' . esc_html__( 'Refreshes every 15 seconds. A visitor counts as active when they loaded a page or sent an event in the period.', 'tamago-reports' ) . '</p></div>';
	}
}

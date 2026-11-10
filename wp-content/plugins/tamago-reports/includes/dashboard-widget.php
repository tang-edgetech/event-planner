<?php
/**
 * Dashboard widget: visitors and page views for the last 7 days, with a sparkline and deltas
 * against the 7 days before.
 *
 * Screens: Dashboard (wp-admin/index.php).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Dashboard_Widget {

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		if ( current_user_can( TMG_Reports_Permissions::VIEW ) ) {
			wp_add_dashboard_widget( 'tmg_reports_widget', __( 'Visitors (last 7 days)', 'tamago-reports' ), array( __CLASS__, 'render' ) );
		}
	}

	public static function render(): void {
		$args            = array( 'range' => '7d' );
		$now             = TMG_Reports_Query::totals( $args );
		list( $f, $t )   = TMG_Reports_Query::range_dates( '7d' );
		list( $pf, $pt ) = TMG_Reports_Query::previous( $f, $t );
		$before          = TMG_Reports_Query::totals( array( 'from' => $pf, 'to' => $pt ) );
		$series          = TMG_Reports_Query::series( $args, 'day' );
		$labels          = TMG_Reports_UI::metric_labels();
		echo '<div class="tmg-widget">';
		foreach ( array( 'visitors', 'views' ) as $m ) {
			echo '<div class="tmg-kpi"><span class="tmg-kpi-label">' . esc_html( $labels[ $m ] ) . '</span><strong>' . esc_html( TMG_Reports_UI::format( $m, $now[ $m ] ) ) . '</strong>';
			echo TMG_Reports_UI::delta( $m, (float) $now[ $m ], (float) $before[ $m ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';
		}
		echo '</div>';
		echo TMG_Reports_UI::sparkline( $series['views'], 400, 48, __( 'Daily page views', 'tamago-reports' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<p><a href="%s">%s</a></p>', esc_url( TMG_Reports_Admin_Screen::url( array( 'range' => '7d' ) ) ), esc_html__( 'Open reports →', 'tamago-reports' ) );
	}
}

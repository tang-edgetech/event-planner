<?php
/**
 * Per-content numbers in wp-admin: a sortable "Views (30 days)" column on list tables of
 * tracked post types, and a small report box on the post edit screen. Visible to users with
 * tmg_reports_view.
 *
 * Screens: Posts / Pages / custom post type lists, post edit screen.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Post_Columns {

	const COLUMN = 'tmg_views';

	/** @var array<int,int>|null */
	private static $views = null;

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'orderby' ) );
	}

	public static function tracked_types(): array {
		$off = (array) TMG_Reports_Settings::get( 'untracked_types' );
		return array_values( array_diff( get_post_types( array( 'public' => true ) ), $off, array( 'attachment' ) ) );
	}

	public static function register(): void {
		if ( ! current_user_can( TMG_Reports_Permissions::VIEW ) ) {
			return;
		}
		foreach ( self::tracked_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'cell' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( __CLASS__, 'sortable' ) );
		}
	}

	public static function columns( array $cols ): array {
		$cols[ self::COLUMN ] = __( 'Views (30 days)', 'tamago-reports' );
		return $cols;
	}

	public static function sortable( array $cols ): array {
		$cols[ self::COLUMN ] = array( self::COLUMN, true );
		return $cols;
	}

	/** Views per post for the last 30 days: summaries + raw recent days, one query each. */
	public static function views_subquery(): string {
		global $wpdb;
		$from  = TMG_Reports_Query::shift( TMG_Reports_Query::today(), -29 );
		$until = TMG_Reports_Summary::until();
		$d     = TMG_Reports_Install::daily_table();
		$t     = TMG_Reports_Install::hits_table();
		$parts = array();
		$raw   = $from;
		if ( '' !== $until && $until >= $from ) {
			$parts[] = $wpdb->prepare( "SELECT CAST(val AS UNSIGNED) pid, views v FROM {$d} WHERE dim = 'page' AND day BETWEEN %s AND %s AND val REGEXP '^[0-9]+$'", $from, $until ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$raw     = TMG_Reports_Query::shift( $until, 1 );
		}
		$parts[] = $wpdb->prepare( "SELECT post_id pid, 1 v FROM {$t} WHERE event_type = 'pageview' AND post_id > 0 AND created_at >= %s", TMG_Reports_Query::utc( $raw ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 'SELECT pid, SUM(v) views FROM (' . implode( ' UNION ALL ', $parts ) . ') u GROUP BY pid';
	}

	private static function load( array $ids ): void {
		global $wpdb;
		self::$views = array();
		$ids         = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$rows = $wpdb->get_results( 'SELECT pid, views FROM (' . self::views_subquery() . ') x WHERE pid IN (' . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- subquery prepared, IDs are ints.
		foreach ( (array) $rows as $r ) {
			self::$views[ (int) $r['pid'] ] = (int) $r['views'];
		}
	}

	public static function cell( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		if ( null === self::$views ) {
			global $wp_query;
			self::load( wp_list_pluck( (array) ( $wp_query->posts ?? array() ), 'ID' ) );
		}
		$n = self::$views[ $post_id ] ?? 0;
		printf( '<a href="%s">%s</a>', esc_url( TMG_Reports_Admin_Screen::url( array( 'f' => array( 'post' => $post_id ) ) ) ), esc_html( number_format_i18n( $n ) ) );
	}

	public static function orderby( WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || self::COLUMN !== $q->get( 'orderby' ) || ! current_user_can( TMG_Reports_Permissions::VIEW ) ) {
			return;
		}
		$order = 'ASC' === strtoupper( (string) $q->get( 'order' ) ) ? 'ASC' : 'DESC';
		add_filter(
			'posts_clauses',
			function ( array $c, WP_Query $query ) use ( $q, $order ) {
				global $wpdb;
				if ( $query !== $q ) {
					return $c;
				}
				$c['join']   .= ' LEFT JOIN (' . self::views_subquery() . ") tmg_v ON tmg_v.pid = {$wpdb->posts}.ID";
				$c['orderby'] = "COALESCE(tmg_v.views, 0) {$order}, {$wpdb->posts}.post_date DESC";
				return $c;
			},
			10,
			2
		);
	}

	public static function meta_box(): void {
		if ( ! current_user_can( TMG_Reports_Permissions::VIEW ) ) {
			return;
		}
		foreach ( self::tracked_types() as $type ) {
			add_meta_box( 'tmg-reports-box', __( 'Reports (30 days)', 'tamago-reports' ), array( __CLASS__, 'render_box' ), $type, 'side', 'default' );
		}
	}

	public static function render_box( WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'No data yet.', 'tamago-reports' ) . '</p>';
			return;
		}
		$args   = array(
			'range'   => '30d',
			'filters' => array( 'post' => $post->ID ),
		);
		$tot    = TMG_Reports_Query::totals( $args );
		$series = TMG_Reports_Query::series( $args, 'day' );
		$labels = TMG_Reports_UI::metric_labels();
		echo TMG_Reports_UI::sparkline( $series['views'], 240, 40, __( 'Daily page views', 'tamago-reports' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from numbers and escaped attributes.
		echo '<dl class="tmg-box">';
		foreach ( array( 'views', 'visitors', 'avg_time', 'avg_scroll', 'conversions' ) as $m ) {
			echo '<dt>' . esc_html( $labels[ $m ] ) . '</dt><dd>' . esc_html( TMG_Reports_UI::format( $m, $tot[ $m ] ) ) . '</dd>';
		}
		echo '</dl>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( TMG_Reports_Admin_Screen::url( array( 'f' => array( 'post' => $post->ID ) ) ) ), esc_html__( 'Full report →', 'tamago-reports' ) );
	}
}

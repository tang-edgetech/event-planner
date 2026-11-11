<?php
/**
 * Reports admin page: menu (own top-level "Reports" by default; parent filterable through
 * `tamago_menu_parent`), tabs (Overview, Real-time, Settings), assets, and URL helpers.
 *
 * Screens: Reports (all tabs).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Admin_Screen {

	const SLUG = 'tamago-reports';

	/** @var string */
	private static $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		$parent = (string) apply_filters( 'tamago_menu_parent', '', 'reports' );
		$title  = __( 'Reports', 'tamago-reports' );
		if ( '' === $parent ) {
			$position   = apply_filters( 'tamago_menu_position', 3.1, 'reports' );
			self::$hook = (string) add_menu_page( $title, $title, TMG_Reports_Permissions::VIEW, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-chart-area', $position );
		} else {
			self::$hook = (string) add_submenu_page( $parent, $title, $title, TMG_Reports_Permissions::VIEW, self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	public static function assets( string $hook ): void {
		$ours = $hook === self::$hook || in_array( $hook, array( 'index.php', 'post.php', 'post-new.php', 'edit.php' ), true );
		if ( ! $ours ) {
			return;
		}
		wp_enqueue_style( 'tamago-reports-admin', TMG_REPORTS_URL . 'assets/css/admin.css', array(), TMG_REPORTS_VERSION );
		if ( $hook === self::$hook ) {
			wp_enqueue_script( 'tamago-reports-admin', TMG_REPORTS_URL . 'assets/js/admin.js', array(), TMG_REPORTS_VERSION, true );
			wp_localize_script(
				'tamago-reports-admin',
				'tamagoReportsAdmin',
				array(
					'ajax'          => admin_url( 'admin-ajax.php' ),
					'realtimeNonce' => wp_create_nonce( 'tmg_reports_realtime' ),
					'searchNonce'   => current_user_can( TMG_Reports_Permissions::MANAGE ) ? wp_create_nonce( 'tmg_reports_find_posts' ) : '',
					'i18n'          => array(
						'views'    => __( 'Page views', 'tamago-reports' ),
						'visitors' => __( 'Visitors', 'tamago-reports' ),
						'remove'   => __( 'Remove', 'tamago-reports' ),
						'noPages'  => __( 'Nobody is on the site right now.', 'tamago-reports' ),
						'noMatch'  => __( 'No matching content.', 'tamago-reports' ),
						'loadError' => __( 'Could not load this section.', 'tamago-reports' ),
					),
				)
			);
		}
	}

	public static function tabs(): array {
		$tabs = array(
			'overview' => __( 'Overview', 'tamago-reports' ),
			'realtime' => __( 'Real-time', 'tamago-reports' ),
		);
		if ( current_user_can( TMG_Reports_Permissions::MANAGE ) ) {
			$tabs['settings'] = __( 'Settings', 'tamago-reports' );
		}
		return $tabs;
	}

	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
	}

	public static function url( array $args = array() ): string {
		$args = array_filter( $args, fn( $v ) => '' !== $v && null !== $v && false !== $v && array() !== $v );
		$qs   = http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
		return admin_url( 'admin.php?page=' . self::SLUG . ( '' !== $qs ? '&' . $qs : '' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( TMG_Reports_Permissions::VIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to view reports.', 'tamago-reports' ), 403 );
		}
		$tab = self::current_tab();
		echo '<div class="wrap tmg-reports">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Reports', 'tamago-reports' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper wp-clearfix">';
		foreach ( self::tabs() as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( array( 'tab' => 'overview' === $key ? '' : $key ) ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		switch ( $tab ) {
			case 'realtime':
				TMG_Reports_Realtime::render_tab();
				break;
			case 'settings':
				TMG_Reports_Settings_Screen::render();
				break;
			default:
				TMG_Reports_Report_Screen::render();
		}
		echo '</div>';
	}
}

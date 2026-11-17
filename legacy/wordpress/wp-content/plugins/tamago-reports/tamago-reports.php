<?php
/**
 * Plugin Name:       Tamago Reports
 * Description:       First-party, privacy-friendly visitor analytics with targeting, events, goals and reports. No third-party scripts, no raw IP addresses stored.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Tamago
 * License:           GPL-2.0-or-later
 * Text Domain:       tamago-reports
 *
 * Main file: constants, requires, activation and deactivation.
 *
 * Used by: WordPress (plugin bootstrap).
 */

defined( 'ABSPATH' ) || exit;

define( 'TMG_REPORTS_VERSION', '1.0.0' );
define( 'TMG_REPORTS_DB_VERSION', '1.0.0' );
define( 'TMG_REPORTS_FILE', __FILE__ );
define( 'TMG_REPORTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TMG_REPORTS_URL', plugin_dir_url( __FILE__ ) );

$tmg_reports_files = array(
	'settings',
	'crypto',
	'install',
	'permissions',
	'user-agent',
	'channels',
	'geo',
	'visitor',
	'targeting',
	'goals',
	'recorder',
	'collect',
	'tracker',
	'labels',
	'query',
	'summary',
	'api',
	'ui',
	'admin-screen',
	'report-screen',
	'settings-screen',
	'realtime',
	'export',
	'post-columns',
	'dashboard-widget',
);
foreach ( $tmg_reports_files as $tmg_reports_file ) {
	require_once TMG_REPORTS_DIR . 'includes/' . $tmg_reports_file . '.php';
}
unset( $tmg_reports_files, $tmg_reports_file );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TMG_REPORTS_DIR . 'includes/cli.php';
}

register_activation_hook( __FILE__, array( 'TMG_Reports_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TMG_Reports_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'TMG_Reports_Install', 'maybe_upgrade' ) );
add_action( 'init', array( 'TMG_Reports_Install', 'load_textdomain' ) );

TMG_Reports_Permissions::init();
TMG_Reports_Collect::init();
TMG_Reports_Tracker::init();
TMG_Reports_Summary::init();

if ( is_admin() ) {
	TMG_Reports_Admin_Screen::init();
	TMG_Reports_Report_Screen::init();
	TMG_Reports_Settings_Screen::init();
	TMG_Reports_Realtime::init();
	TMG_Reports_Export::init();
	TMG_Reports_Post_Columns::init();
	TMG_Reports_Dashboard_Widget::init();
}

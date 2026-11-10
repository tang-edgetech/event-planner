<?php
/**
 * Plugin Name:       Tamago Import & Export
 * Description:       Import and export any WordPress content: posts of every type, custom fields, ACF values and ACF structure, terms, users, options and menus. JSON, ZIP (with media), CSV and Excel.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Tamago
 * License:           GPL-2.0-or-later
 * Text Domain:       tamago-io
 *
 * Main file: constants, includes, activation / deactivation.
 * Used by: WordPress (plugin loader).
 */

defined( 'ABSPATH' ) || exit;

define( 'TMG_IO_VERSION', '1.0.0' );
define( 'TMG_IO_FILE', __FILE__ );
define( 'TMG_IO_DIR', plugin_dir_path( __FILE__ ) );
define( 'TMG_IO_URL', plugin_dir_url( __FILE__ ) );
define( 'TMG_IO_DB_VERSION', '1' );

require_once TMG_IO_DIR . 'includes/functions.php';
require_once TMG_IO_DIR . 'includes/settings.php';
require_once TMG_IO_DIR . 'includes/permissions.php';
require_once TMG_IO_DIR . 'includes/storage.php';
require_once TMG_IO_DIR . 'includes/runs.php';
require_once TMG_IO_DIR . 'includes/xlsx.php';
require_once TMG_IO_DIR . 'includes/files.php';
require_once TMG_IO_DIR . 'includes/acf.php';
require_once TMG_IO_DIR . 'includes/convert.php';
require_once TMG_IO_DIR . 'includes/media.php';
require_once TMG_IO_DIR . 'includes/exporter.php';
require_once TMG_IO_DIR . 'includes/flatten.php';
require_once TMG_IO_DIR . 'includes/importer.php';
require_once TMG_IO_DIR . 'includes/mapping.php';
require_once TMG_IO_DIR . 'includes/presets.php';
require_once TMG_IO_DIR . 'includes/sources.php';

if ( is_admin() ) {
	require_once TMG_IO_DIR . 'includes/history-table.php';
	require_once TMG_IO_DIR . 'includes/admin-screen.php';
	require_once TMG_IO_DIR . 'includes/admin-actions.php';
	TMG_IO_Admin_Screen::init();
	TMG_IO_Admin_Actions::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TMG_IO_DIR . 'includes/cli.php';
}

TMG_IO_Permissions::init();
TMG_IO_Storage::init();
TMG_IO_Runs::init();

register_activation_hook( __FILE__, 'tmg_io_activate' );
register_deactivation_hook( __FILE__, 'tmg_io_deactivate' );

/**
 * Activation: capabilities for administrators, history table, protected folder, cleanup cron.
 */
function tmg_io_activate(): void {
	TMG_IO_Permissions::grant_defaults();
	TMG_IO_Runs::install();
	TMG_IO_Storage::dir();
	if ( ! wp_next_scheduled( 'tmg_io_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tmg_io_cleanup' );
	}
}

/**
 * Deactivation: stop the cleanup cron. Data stays until the plugin is deleted.
 */
function tmg_io_deactivate(): void {
	wp_clear_scheduled_hook( 'tmg_io_cleanup' );
}

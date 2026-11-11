<?php
/**
 * Plugin Name:       Tamago SMTP
 * Description:       Sends every WordPress email through your SMTP server, with a test tool, a connection check and a detailed email log.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Tamago
 * License:           GPL-2.0-or-later
 * Text Domain:       tamago-smtp
 *
 * Main file: constants, includes, activation and deactivation, bootstrap.
 * Used by: WordPress (plugin loader).
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

define( 'TMG_SMTP_VERSION', '1.0.0' );
define( 'TMG_SMTP_FILE', __FILE__ );
define( 'TMG_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TMG_SMTP_URL', plugin_dir_url( __FILE__ ) );

require_once TMG_SMTP_DIR . 'includes/crypto.php';
require_once TMG_SMTP_DIR . 'includes/providers.php';
require_once TMG_SMTP_DIR . 'includes/settings.php';
require_once TMG_SMTP_DIR . 'includes/permissions.php';
require_once TMG_SMTP_DIR . 'includes/mailer.php';
require_once TMG_SMTP_DIR . 'includes/log-store.php';
require_once TMG_SMTP_DIR . 'includes/transcript.php';
require_once TMG_SMTP_DIR . 'includes/hints.php';
require_once TMG_SMTP_DIR . 'includes/logger.php';
require_once TMG_SMTP_DIR . 'includes/tester.php';
require_once TMG_SMTP_DIR . 'includes/alerts.php';
require_once TMG_SMTP_DIR . 'includes/privacy.php';

if ( is_admin() ) {
	require_once TMG_SMTP_DIR . 'includes/admin-screen.php';
	require_once TMG_SMTP_DIR . 'includes/logs-screen.php';
	require_once TMG_SMTP_DIR . 'includes/dashboard-widget.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TMG_SMTP_DIR . 'includes/cli.php';
}

/**
 * URL of the plugin screen (its parent menu is filterable through tamago_menu_parent).
 *
 * @param string $tab  settings | test | logs | tools, or '' for the default tab.
 * @param array  $args Extra query arguments.
 */
function tmg_smtp_screen_url( string $tab = '', array $args = array() ): string {
	$parent = (string) apply_filters( 'tamago_menu_parent', '', 'smtp' );
	$base   = preg_match( '/\.php(\?|$)/', $parent ) ? $parent : 'admin.php';
	$query  = array( 'page' => 'tamago-smtp' );
	if ( '' !== $tab ) {
		$query['tab'] = $tab;
	}
	return add_query_arg( array_merge( $query, $args ), admin_url( $base ) );
}

/**
 * Creates the log table, grants the capabilities and schedules the daily purge on one site.
 */
function tmg_smtp_install_site(): void {
	TMG_SMTP_Log_Store::install();
	TMG_SMTP_Permissions::grant();
	TMG_SMTP_Log_Store::schedule();
}

/**
 * Activation: install on this site, or on every site when network-activated.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function tmg_smtp_activate( $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
			switch_to_blog( (int) $site_id );
			tmg_smtp_install_site();
			restore_current_blog();
		}
		return;
	}
	tmg_smtp_install_site();
}

/**
 * Deactivation: stop the daily purge. Data stays until the plugin is deleted.
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 */
function tmg_smtp_deactivate( $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
			switch_to_blog( (int) $site_id );
			wp_clear_scheduled_hook( TMG_SMTP_Log_Store::CRON );
			restore_current_blog();
		}
		return;
	}
	wp_clear_scheduled_hook( TMG_SMTP_Log_Store::CRON );
}

register_activation_hook( __FILE__, 'tmg_smtp_activate' );
register_deactivation_hook( __FILE__, 'tmg_smtp_deactivate' );

TMG_SMTP_Mailer::init();
TMG_SMTP_Logger::init();
TMG_SMTP_Tester::init();
TMG_SMTP_Alerts::init();
TMG_SMTP_Permissions::init();
TMG_SMTP_Privacy::init();

if ( is_admin() ) {
	TMG_SMTP_Admin::init();
	TMG_SMTP_Logs_Screen::init();
	TMG_SMTP_Dashboard_Widget::init();
}

// Sites added to a network later, and updates that change the table, are handled lazily here.
add_action( 'init', array( 'TMG_SMTP_Log_Store', 'maybe_upgrade' ) );
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'tamago-smtp', false, dirname( plugin_basename( TMG_SMTP_FILE ) ) . '/languages' );
	}
);

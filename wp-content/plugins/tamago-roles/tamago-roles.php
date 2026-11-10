<?php
/**
 * Plugin Name:       Tamago Roles
 * Description:       Roles, ranks, permissions, per-user overrides, admin menu / toolbar / dashboard rules, front-end content access and staff privacy.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Tamago
 * Text Domain:       tamago-roles
 * License:           GPL-2.0-or-later
 *
 * Bootstrap: constants, file loading, activation and the safe-mode switch.
 * Used by: WordPress (plugin loader).
 */

defined( 'ABSPATH' ) || exit;

define( 'TMG_ROLES_VERSION', '1.0.0' );
define( 'TMG_ROLES_FILE', __FILE__ );
define( 'TMG_ROLES_DIR', plugin_dir_path( __FILE__ ) );
define( 'TMG_ROLES_URL', plugin_dir_url( __FILE__ ) );

foreach ( array(
	'plugin',
	'settings',
	'logger',
	'ranks',
	'permissions',
	'role-manager',
	'capabilities',
	'hierarchy',
	'admin-experience',
	'content-access',
	'content-admin',
	'nav-menus',
	'shortcode',
	'privacy',
	'transfer',
	'user-profile',
	'admin-screen',
	'screen-roles',
	'screen-permissions',
	'screen-users',
	'screen-menus',
	'screen-content',
	'screen-settings',
	'screen-log',
	'functions',
) as $tmg_roles_file ) {
	require_once TMG_ROLES_DIR . 'includes/' . $tmg_roles_file . '.php';
}
unset( $tmg_roles_file );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TMG_ROLES_DIR . 'includes/cli.php';
}

register_activation_hook( __FILE__, array( 'TMG_Roles', 'activate' ) );

TMG_Roles::init();

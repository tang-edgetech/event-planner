<?php
/**
 * Uninstall: removes data only when "Delete all data when the plugin is deleted" is on.
 * Removes settings, defaults, snapshots, the log table, overrides, content and menu-item rules and
 * this plugin's capabilities. Roles created with the plugin stay (users would otherwise lose access).
 *
 * Used by: WordPress when the plugin is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$tmg_roles_settings = get_option( 'tmg_roles_settings', array() );
$tmg_roles_delete   = defined( 'TMG_ROLES_DELETE_DATA' ) ? (bool) TMG_ROLES_DELETE_DATA : ! empty( $tmg_roles_settings['delete_data'] );
if ( ! $tmg_roles_delete ) {
	return;
}

global $wpdb;
foreach ( array( 'tmg_roles_settings', 'tmg_roles_role_defaults', 'tmg_roles_menu_snapshot', 'tmg_roles_widget_snapshot', 'tmg_roles_db_version' ) as $tmg_roles_opt ) {
	delete_option( $tmg_roles_opt );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tmg_roles_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
delete_metadata( 'user', 0, 'tmg_roles_overrides', '', true );
delete_metadata( 'post', 0, '_tmg_roles_access', '', true );
delete_metadata( 'post', 0, '_tmg_roles_visibility', '', true );
delete_metadata( 'term', 0, '_tmg_roles_access', '', true );
foreach ( wp_roles()->role_objects as $tmg_roles_role ) {
	$tmg_roles_role->remove_cap( 'tmg_roles_manage' );
	$tmg_roles_role->remove_cap( 'tmg_roles_manage_users' );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_tmg_roles_%', '_transient_timeout_tmg_roles_%' ) );

<?php
/**
 * Uninstall: removes the plugin's data only when "Delete all data when the plugin is deleted"
 * is on: options, history table, capabilities, cron, working folder, attachment markers.
 *
 * Used by: WordPress when the plugin is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$tmg_io_settings = get_option( 'tmg_io_settings', array() );
$tmg_io_delete   = defined( 'TMG_IO_DELETE_DATA' ) ? (bool) TMG_IO_DELETE_DATA : ! empty( $tmg_io_settings['delete_data'] );
if ( ! $tmg_io_delete ) {
	return;
}

global $wpdb;

foreach ( array( 'tmg_io_settings', 'tmg_io_presets', 'tmg_io_templates', 'tmg_io_db_version' ) as $tmg_io_opt ) {
	delete_option( $tmg_io_opt );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tmg_io_runs" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_tmg\_io\_%' OR option_name LIKE '\_transient\_timeout\_tmg\_io\_%'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_tmg_io_src', '_tmg_io_sha1')" ); // phpcs:ignore WordPress.DB

foreach ( wp_roles()->role_objects as $tmg_io_role ) {
	foreach ( array( 'tmg_io_export', 'tmg_io_import', 'tmg_io_manage' ) as $tmg_io_cap ) {
		$tmg_io_role->remove_cap( $tmg_io_cap );
	}
}
wp_clear_scheduled_hook( 'tmg_io_cleanup' );

$tmg_io_up  = wp_upload_dir( null, false );
$tmg_io_dir = trailingslashit( $tmg_io_up['basedir'] ) . 'tamago-io/';
if ( is_dir( $tmg_io_dir ) ) {
	foreach ( (array) scandir( $tmg_io_dir ) as $tmg_io_file ) {
		if ( is_file( $tmg_io_dir . $tmg_io_file ) ) {
			wp_delete_file( $tmg_io_dir . $tmg_io_file );
		}
	}
	@rmdir( $tmg_io_dir ); // phpcs:ignore
}

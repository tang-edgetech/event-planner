<?php
/**
 * Uninstall: removes tables, options, capabilities, cron events and transients (the plugin stores no user meta) — only when
 * the "Delete all data when the plugin is deleted" setting is on (or TMG_REPORTS_DELETE_DATA is true).
 *
 * Used by: WordPress (plugin deletion).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function tmg_reports_uninstall_site(): void {
	global $wpdb;
	$settings = get_option( 'tmg_reports_settings', array() );
	$delete   = defined( 'TMG_REPORTS_DELETE_DATA' ) ? (bool) TMG_REPORTS_DELETE_DATA : ! empty( $settings['delete_data'] );
	if ( ! $delete ) {
		return;
	}
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tmg_reports_hits" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tmg_reports_daily" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( array( 'tmg_reports_settings', 'tmg_reports_db_version', 'tmg_reports_summary_until', 'tmg_reports_daily_salt' ) as $opt ) {
		delete_option( $opt );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_tmg\_reports\_%' OR option_name LIKE '\_transient\_timeout\_tmg\_reports\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	wp_clear_scheduled_hook( 'tmg_reports_hourly' );
	wp_clear_scheduled_hook( 'tmg_reports_daily' );
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( array( 'tmg_reports_view', 'tmg_reports_export', 'tmg_reports_manage' ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $tmg_site_id ) {
		switch_to_blog( (int) $tmg_site_id );
		tmg_reports_uninstall_site();
		restore_current_blog();
	}
} else {
	tmg_reports_uninstall_site();
}

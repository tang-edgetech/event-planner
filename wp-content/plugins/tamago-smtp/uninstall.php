<?php
/**
 * Uninstall: removes the plugin's data only when "Delete all data when the plugin is deleted" is on
 * (or TMG_SMTP_DELETE_DATA is true in wp-config.php). On multisite each site follows its own setting.
 *
 * Removes: tmg_smtp_settings, tmg_smtp_db_version, tmg_smtp_alert_last, the {prefix}tmg_smtp_log table,
 * the tmg_smtp_manage / tmg_smtp_view_logs capabilities, the tmg_smtp_logs_per_page user setting and the
 * daily purge event. The purge event is always removed.
 *
 * Used by: WordPress, when the plugin is deleted from the Plugins screen.
 *
 * @package TamagoSMTP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans up the current site.
 *
 * @return bool Whether the data was deleted.
 */
function tmg_smtp_uninstall_site(): bool {
	global $wpdb;
	wp_clear_scheduled_hook( 'tmg_smtp_purge_logs' );

	$settings = get_option( 'tmg_smtp_settings', array() );
	$delete   = defined( 'TMG_SMTP_DELETE_DATA' )
		? filter_var( TMG_SMTP_DELETE_DATA, FILTER_VALIDATE_BOOLEAN )
		: ( is_array( $settings ) && ! empty( $settings['delete_data'] ) );
	if ( ! $delete ) {
		return false;
	}

	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tmg_smtp_log' ); // phpcs:ignore WordPress.DB
	delete_option( 'tmg_smtp_settings' );
	delete_option( 'tmg_smtp_db_version' );
	delete_option( 'tmg_smtp_alert_last' );
	foreach ( wp_roles()->role_objects as $role ) {
		$role->remove_cap( 'tmg_smtp_manage' );
		$role->remove_cap( 'tmg_smtp_view_logs' );
	}
	return true;
}

$tmg_smtp_deleted_main = false;
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $tmg_smtp_site_id ) {
		switch_to_blog( (int) $tmg_smtp_site_id );
		$tmg_smtp_done = tmg_smtp_uninstall_site();
		if ( (int) $tmg_smtp_site_id === get_main_site_id() ) {
			$tmg_smtp_deleted_main = $tmg_smtp_done;
		}
		restore_current_blog();
	}
} else {
	$tmg_smtp_deleted_main = tmg_smtp_uninstall_site();
}

// User meta is shared by the whole network: it follows the main site's choice.
if ( $tmg_smtp_deleted_main ) {
	delete_metadata( 'user', 0, 'tmg_smtp_logs_per_page', '', true );
}

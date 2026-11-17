<?php
/**
 * Capabilities (view, export, manage), their friendly permission entries for
 * Tamago Roles (`tamago_permissions` filter), and the administrator grant.
 *
 * Used by: every screen and handler (capability checks), activation, uninstall.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Permissions {

	const VIEW   = 'tmg_reports_view';
	const EXPORT = 'tmg_reports_export';
	const MANAGE = 'tmg_reports_manage';

	public static function caps(): array {
		return array( self::VIEW, self::EXPORT, self::MANAGE );
	}

	public static function init(): void {
		add_filter( 'tamago_permissions', array( __CLASS__, 'register' ) );
	}

	public static function register( array $p ): array {
		$p['reports_view']   = array(
			'group'       => __( 'Reports', 'tamago-reports' ),
			'label'       => __( 'View reports', 'tamago-reports' ),
			'description' => __( 'See visitor reports, the real-time panel, the dashboard widget and post view counts.', 'tamago-reports' ),
			'caps'        => array( self::VIEW ),
		);
		$p['reports_export'] = array(
			'group'       => __( 'Reports', 'tamago-reports' ),
			'label'       => __( 'Export report data', 'tamago-reports' ),
			'description' => __( 'Download raw hits and report tables as CSV.', 'tamago-reports' ),
			'caps'        => array( self::EXPORT ),
		);
		$p['reports_manage'] = array(
			'group'       => __( 'Reports', 'tamago-reports' ),
			'label'       => __( 'Manage report settings', 'tamago-reports' ),
			'description' => __( 'Change what is tracked, consent, events, goals, location and data retention.', 'tamago-reports' ),
			'caps'        => array( self::MANAGE ),
		);
		return $p;
	}

	public static function grant_admin(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::caps() as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	public static function remove_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}

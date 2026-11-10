<?php
/**
 * Capabilities: tmg_io_export, tmg_io_import, tmg_io_manage. Registered as friendly permissions
 * through `tamago_permissions` and granted to administrators on activation.
 *
 * Used by: every screen and handler; Tamago Roles (shows the permissions).
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Permissions {

	const EXPORT = 'tmg_io_export';
	const IMPORT = 'tmg_io_import';
	const MANAGE = 'tmg_io_manage';

	public static function init(): void {
		add_filter( 'tamago_permissions', array( __CLASS__, 'register' ) );
	}

	public static function caps(): array {
		return array( self::EXPORT, self::IMPORT, self::MANAGE );
	}

	public static function register( array $p ): array {
		$group             = __( 'Import & Export', 'tamago-io' );
		$p['io_export']    = array(
			'group'       => $group,
			'label'       => __( 'Export content', 'tamago-io' ),
			'description' => __( 'Download content, custom fields and media as JSON, ZIP, CSV or Excel. Only content the user may read is offered.', 'tamago-io' ),
			'caps'        => array( self::EXPORT ),
		);
		$p['io_import']    = array(
			'group'       => $group,
			'label'       => __( 'Import content', 'tamago-io' ),
			'description' => __( 'Upload files to create or update content. Each item still needs the matching create / edit / publish right.', 'tamago-io' ),
			'caps'        => array( self::IMPORT ),
		);
		$p['io_manage']    = array(
			'group'       => $group,
			'label'       => __( 'Manage import & export', 'tamago-io' ),
			'description' => __( 'Change settings, export and import site options and ACF structure (field groups, post types, taxonomies, options pages).', 'tamago-io' ),
			'caps'        => array( self::MANAGE ),
		);
		return $p;
	}

	public static function grant_defaults(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::caps() as $cap ) {
				$role->add_cap( $cap );
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

	/** Any of our capabilities (used for the menu entry). */
	public static function any(): bool {
		foreach ( self::caps() as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/** Options and ACF structure touch the whole site: our manage cap plus WordPress' own. */
	public static function can_site_settings(): bool {
		return current_user_can( self::MANAGE ) && current_user_can( 'manage_options' );
	}

	/**
	 * A role may be given only when it is editable by the importer and holds no capability
	 * the importer lacks (never import a role above your own).
	 */
	public static function can_assign_role( string $role ): bool {
		if ( ! current_user_can( 'promote_users' ) ) {
			return false;
		}
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$editable = get_editable_roles();
		if ( ! isset( $editable[ $role ] ) ) {
			return false;
		}
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		// Compare with the importer's own (raw) capabilities: current_user_can() maps some caps
		// to "do not allow" on purpose (for example manage_links when the link manager is off).
		$mine = (array) wp_get_current_user()->allcaps;
		$lvl  = function ( array $caps ): int {
			$max = 0;
			foreach ( $caps as $cap => $granted ) {
				if ( $granted && preg_match( '/^level_(\d+)$/', (string) $cap, $m ) ) {
					$max = max( $max, (int) $m[1] );
				}
			}
			return $max;
		};
		$caps = (array) ( $editable[ $role ]['capabilities'] ?? array() );
		if ( $lvl( $caps ) > $lvl( $mine ) ) {
			return false;
		}
		foreach ( $caps as $cap => $granted ) {
			if ( $granted && empty( $mine[ $cap ] ) && ! current_user_can( $cap ) ) {
				return false;
			}
		}
		return true;
	}
}

<?php
/**
 * Plugin core: starts every module, activation, safe mode and the plugin's own capabilities.
 *
 * Used by: tamago-roles.php (bootstrap), every other module (TMG_Roles::active()).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles {

	const CAP_MANAGE       = 'tmg_roles_manage';
	const CAP_MANAGE_USERS = 'tmg_roles_manage_users';
	const PAGE             = 'tamago-roles';

	public static function init(): void {
		TMG_Roles_Logger::init();
		TMG_Roles_Permissions::init();
		TMG_Roles_Role_Manager::init();
		TMG_Roles_Capabilities::init();
		TMG_Roles_Hierarchy::init();
		TMG_Roles_Admin_Experience::init();
		TMG_Roles_Content_Access::init();
		TMG_Roles_Content_Admin::init();
		TMG_Roles_Nav_Menus::init();
		TMG_Roles_Shortcode::init();
		TMG_Roles_Privacy::init();
		TMG_Roles_User_Profile::init();
		TMG_Roles_Admin_Screen::init();

		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_notices', array( __CLASS__, 'safe_mode_notice' ) );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'tamago-roles', false, dirname( plugin_basename( TMG_ROLES_FILE ) ) . '/languages' );
	}

	/**
	 * Safe mode: TMG_ROLES_SAFE_MODE in wp-config.php switches every restriction off
	 * (rank rules, protected capabilities, overrides, menu/toolbar/dashboard rules, redirects,
	 * content access, staff privacy). Role capabilities stored by WordPress itself stay as they are.
	 */
	public static function safe_mode(): bool {
		$on = defined( 'TMG_ROLES_SAFE_MODE' ) && TMG_ROLES_SAFE_MODE;
		return (bool) apply_filters( 'tmg_roles_safe_mode', $on );
	}

	/** Are restrictions running? Checked at run time so safe mode can also be switched by the filter. */
	public static function active(): bool {
		return ! self::safe_mode();
	}

	public static function activate(): void {
		TMG_Roles_Logger::install();
		foreach ( array( 'administrator' ) as $role_key ) {
			$role = get_role( $role_key );
			if ( $role ) {
				$role->add_cap( self::CAP_MANAGE );
				$role->add_cap( self::CAP_MANAGE_USERS );
			}
		}
		TMG_Roles_Settings::maybe_seed_ranks();
		TMG_Roles_Role_Manager::snapshot_new_roles();
	}

	/** URL of the plugin screen (parent menu is filterable through tamago_menu_parent). */
	public static function screen_url( array $args = array() ): string {
		$parent = TMG_Roles_Admin_Screen::parent();
		$base   = ( false !== strpos( $parent, '.php' ) ) ? $parent : 'admin.php';
		$base   = strtok( $base, '?' );
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( $base ) );
	}

	public static function safe_mode_notice(): void {
		if ( ! self::safe_mode() || ! current_user_can( 'read' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP_MANAGE ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Tamago Roles safe mode is on.', 'tamago-roles' ) . '</strong> ' . esc_html__( 'All role restrictions (ranks, protected capabilities, overrides, menu and content rules) are switched off. Remove TMG_ROLES_SAFE_MODE from wp-config.php to turn them back on.', 'tamago-roles' ) . '</p></div>';
	}
}

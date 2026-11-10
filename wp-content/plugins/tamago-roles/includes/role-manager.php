<?php
/**
 * Role storage operations: create, clone, rename, delete (with reassignment), ranks, capabilities,
 * matrix grant/deny, the default role, and per-role defaults (snapshot / reset / save as defaults).
 * These are low-level: permission checks happen in the screens, CLI and import.
 *
 * Used by: screen-roles.php, screen-permissions.php, transfer.php, cli.php, tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Role_Manager {

	const DEFAULTS_OPTION = 'tmg_roles_role_defaults';

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'snapshot_new_roles' ), 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Defaults                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults(): array {
		$d = get_option( self::DEFAULTS_OPTION, array() );
		return is_array( $d ) ? $d : array();
	}

	/** Remember the state of every role the first time it is seen. */
	public static function snapshot_new_roles(): void {
		$defaults = self::defaults();
		$changed  = false;
		foreach ( wp_roles()->roles as $key => $data ) {
			if ( ! isset( $defaults[ $key ] ) ) {
				$defaults[ $key ] = array( 'name' => $data['name'], 'caps' => (array) $data['capabilities'] );
				$changed          = true;
			}
		}
		if ( $changed ) {
			update_option( self::DEFAULTS_OPTION, $defaults, false );
		}
	}

	public static function save_as_defaults( string $role ): bool {
		$r = get_role( $role );
		if ( ! $r ) {
			return false;
		}
		$defaults          = self::defaults();
		$defaults[ $role ] = array( 'name' => wp_roles()->roles[ $role ]['name'], 'caps' => (array) $r->capabilities );
		update_option( self::DEFAULTS_OPTION, $defaults, false );
		TMG_Roles_Logger::log( 'defaults_saved', $role, array( 'caps' => count( $r->capabilities ) ) );
		return true;
	}

	/** @return true|WP_Error */
	public static function reset( string $role ) {
		$defaults = self::defaults();
		if ( ! get_role( $role ) || ! isset( $defaults[ $role ] ) ) {
			return new WP_Error( 'tmg_roles_no_defaults', __( 'No defaults stored for this role.', 'tamago-roles' ) );
		}
		$res = self::set_caps( $role, (array) $defaults[ $role ]['caps'], 'reset' );
		return is_wp_error( $res ) ? $res : true;
	}

	/* ------------------------------------------------------------------ */
	/* Create / clone / rename / delete                                    */
	/* ------------------------------------------------------------------ */

	/** @return true|WP_Error */
	public static function create( string $key, string $name, int $rank = 0, array $caps = array( 'read' => true ), string $log = 'role_created' ) {
		$key  = sanitize_key( $key );
		$name = sanitize_text_field( $name );
		if ( '' === $key || '' === $name ) {
			return new WP_Error( 'tmg_roles_invalid', __( 'A role needs a key and a name.', 'tamago-roles' ) );
		}
		if ( get_role( $key ) ) {
			return new WP_Error( 'tmg_roles_exists', __( 'A role with this key already exists.', 'tamago-roles' ) );
		}
		$caps = array_map( 'boolval', $caps );
		add_role( $key, $name, $caps );
		self::set_rank( $key, $rank, false );
		$defaults         = self::defaults();
		$defaults[ $key ] = array( 'name' => $name, 'caps' => $caps );
		update_option( self::DEFAULTS_OPTION, $defaults, false );
		TMG_Roles_Capabilities::flush();
		TMG_Roles_Logger::log( $log, $key, array( 'name' => $name, 'rank' => $rank, 'caps' => count( $caps ) ) );
		return true;
	}

	/** @return true|WP_Error */
	public static function clone_role( string $from, string $key, string $name, ?int $rank = null ) {
		$src = get_role( $from );
		if ( ! $src ) {
			return new WP_Error( 'tmg_roles_missing', __( 'The role to copy does not exist.', 'tamago-roles' ) );
		}
		$caps = (array) $src->capabilities;
		return self::create( $key, $name, null === $rank ? TMG_Roles_Ranks::role_rank( $from ) : $rank, $caps, 'role_cloned' );
	}

	/** @return true|WP_Error */
	public static function rename( string $key, string $name ) {
		$name     = sanitize_text_field( $name );
		$wp_roles = wp_roles();
		if ( ! isset( $wp_roles->roles[ $key ] ) || '' === $name ) {
			return new WP_Error( 'tmg_roles_invalid', __( 'Unknown role or empty name.', 'tamago-roles' ) );
		}
		$old = $wp_roles->roles[ $key ]['name'];
		if ( $old === $name ) {
			return true;
		}
		$wp_roles->roles[ $key ]['name'] = $name;
		$wp_roles->role_names[ $key ]    = $name;
		if ( $wp_roles->use_db ) {
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}
		TMG_Roles_Logger::log( 'role_renamed', $key, array( 'from' => $old, 'to' => $name ) );
		return true;
	}

	/**
	 * Delete a role after moving its users to another role.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( string $key, string $reassign ) {
		if ( 'administrator' === $key ) {
			return new WP_Error( 'tmg_roles_admin', __( 'The Administrator role cannot be deleted.', 'tamago-roles' ) );
		}
		if ( ! get_role( $key ) ) {
			return new WP_Error( 'tmg_roles_missing', __( 'Unknown role.', 'tamago-roles' ) );
		}
		if ( $reassign === $key || ( '' !== $reassign && ! get_role( $reassign ) ) ) {
			return new WP_Error( 'tmg_roles_reassign', __( 'Choose another existing role for the users of this role.', 'tamago-roles' ) );
		}
		$users = get_users( array( 'role' => $key, 'fields' => 'all', 'tmg_roles_all' => true, 'blog_id' => get_current_blog_id() ) );
		if ( $users && '' === $reassign ) {
			return new WP_Error( 'tmg_roles_reassign', __( 'This role still has users: choose a role to move them to.', 'tamago-roles' ) );
		}
		foreach ( $users as $user ) {
			$user->remove_role( $key );
			if ( ! in_array( $reassign, (array) $user->roles, true ) ) {
				$user->add_role( $reassign );
			}
		}
		if ( get_option( 'default_role' ) === $key ) {
			update_option( 'default_role', '' !== $reassign ? $reassign : 'subscriber' );
		}
		remove_role( $key );
		$settings = TMG_Roles_Settings::all();
		$changes  = array();
		foreach ( array( 'menu_rules', 'adminbar_rules', 'widget_rules', 'login_redirect', 'logout_redirect', 'ranks' ) as $k ) {
			if ( isset( $settings[ $k ][ $key ] ) ) {
				unset( $settings[ $k ][ $key ] );
				$changes[ $k ] = $settings[ $k ];
			}
		}
		foreach ( array( 'toolbar_front_hidden', 'no_admin', 'public_name_roles' ) as $k ) {
			if ( in_array( $key, (array) $settings[ $k ], true ) ) {
				$changes[ $k ] = array_values( array_diff( (array) $settings[ $k ], array( $key ) ) );
			}
		}
		if ( $changes ) {
			TMG_Roles_Settings::update( $changes, 'role_deleted_cleanup' );
		}
		$defaults = self::defaults();
		unset( $defaults[ $key ] );
		update_option( self::DEFAULTS_OPTION, $defaults, false );
		TMG_Roles_Capabilities::flush();
		TMG_Roles_Logger::log( 'role_deleted', $key, array( 'reassigned_to' => $reassign, 'users' => count( $users ) ) );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Rank, default role                                                  */
	/* ------------------------------------------------------------------ */

	public static function set_rank( string $role, int $rank, bool $log = true ): void {
		$ranks          = (array) TMG_Roles_Settings::get( 'ranks' );
		$old            = $ranks[ $role ] ?? null;
		$ranks[ $role ] = max( 0, $rank );
		if ( $old === $ranks[ $role ] ) {
			return;
		}
		TMG_Roles_Settings::update( array( 'ranks' => $ranks ), 'ranks' );
		if ( $log ) {
			TMG_Roles_Logger::log( 'role_rank', $role, array( 'from' => $old, 'to' => $rank ) );
		}
	}

	/** @return true|WP_Error */
	public static function set_default_role( string $role ) {
		if ( ! get_role( $role ) ) {
			return new WP_Error( 'tmg_roles_missing', __( 'Unknown role.', 'tamago-roles' ) );
		}
		$old = get_option( 'default_role' );
		if ( $old !== $role ) {
			update_option( 'default_role', $role );
			TMG_Roles_Logger::log( 'default_role', $role, array( 'from' => $old ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Capabilities                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Replace a role's capabilities. The Administrator role can only gain capabilities.
	 *
	 * @param array $caps cap => bool (false = explicit deny is kept).
	 * @return array|WP_Error [ added[], removed[] ]
	 */
	public static function set_caps( string $role_key, array $caps, string $log = 'role_caps' ) {
		$role = get_role( $role_key );
		if ( ! $role ) {
			return new WP_Error( 'tmg_roles_missing', __( 'Unknown role.', 'tamago-roles' ) );
		}
		$new = array();
		foreach ( $caps as $cap => $grant ) {
			$cap = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $cap );
			if ( '' !== $cap ) {
				$new[ $cap ] = (bool) $grant;
			}
		}
		$old = (array) $role->capabilities;
		if ( 'administrator' === $role_key ) {
			foreach ( $old as $cap => $grant ) {
				if ( $grant ) {
					$new[ $cap ] = true;
				}
			}
			$new[ TMG_Roles::CAP_MANAGE ]       = true;
			$new[ TMG_Roles::CAP_MANAGE_USERS ] = true;
		}
		$added   = array();
		$removed = array();
		foreach ( $old as $cap => $grant ) {
			if ( ! array_key_exists( $cap, $new ) ) {
				$role->remove_cap( $cap );
				$removed[] = $cap;
			}
		}
		foreach ( $new as $cap => $grant ) {
			if ( ! array_key_exists( $cap, $old ) || (bool) $old[ $cap ] !== $grant ) {
				$role->add_cap( $cap, $grant );
				$added[] = $cap;
			}
		}
		TMG_Roles_Capabilities::flush();
		if ( $added || $removed ) {
			TMG_Roles_Logger::log( $log, $role_key, array( 'added' => $added, 'removed' => $removed ) );
		}
		return array( $added, $removed );
	}

	/** Grant every capability of a permission to a role. */
	public static function grant( string $role_key, string $perm ): bool {
		if ( ! TMG_Roles_Permissions::get( $perm ) || ! get_role( $role_key ) ) {
			return false;
		}
		self::apply_matrix( $role_key, array( $perm => true ), 'grant' );
		return true;
	}

	/** Take a permission away from a role (the Administrator role is never reduced). */
	public static function deny( string $role_key, string $perm ): bool {
		if ( ! TMG_Roles_Permissions::get( $perm ) || ! get_role( $role_key ) || 'administrator' === $role_key ) {
			return false;
		}
		self::apply_matrix( $role_key, array( $perm => false ), 'deny' );
		return true;
	}

	/**
	 * Apply matrix changes for one role: perm key => bool. Only the given keys are switched.
	 * A capability is removed unless a permission switched on, or another permission the role keeps,
	 * still needs it. A kept permission whose capabilities all belong to switched-off permissions
	 * (e.g. two permissions mapping to the same capability) goes with them. "read" is never removed here.
	 */
	public static function apply_matrix( string $role_key, array $perms, string $log = 'matrix' ): void {
		$role = get_role( $role_key );
		if ( ! $role ) {
			return;
		}
		$caps   = (array) $role->capabilities;
		$all    = TMG_Roles_Permissions::all();
		$remove = array();
		$add    = array();
		foreach ( $perms as $key => $on ) {
			if ( ! isset( $all[ $key ] ) ) {
				continue;
			}
			if ( $on ) {
				$add = array_merge( $add, $all[ $key ]['caps'] );
			} else {
				$remove = array_merge( $remove, $all[ $key ]['caps'] );
			}
		}
		$remove = array_unique( $remove );
		$keep   = array();
		foreach ( $all as $key => $p ) {
			if ( array_key_exists( $key, $perms ) || ! TMG_Roles_Permissions::role_has( $role_key, $key ) ) {
				continue;
			}
			if ( array_diff( $p['caps'], $remove ) ) {
				$keep = array_merge( $keep, $p['caps'] );
			}
		}
		foreach ( array_diff( $remove, $add, $keep, array( 'read' ) ) as $cap ) {
			unset( $caps[ $cap ] );
		}
		foreach ( array_unique( $add ) as $cap ) {
			$caps[ $cap ] = true;
		}
		self::set_caps( $role_key, $caps, $log );
	}
}

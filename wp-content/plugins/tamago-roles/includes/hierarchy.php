<?php
/**
 * Rank rules applied everywhere in WordPress:
 * - nobody edits / deletes / promotes / removes a user ranked above them (equal rank: top rank or "peer management"),
 * - the role dropdowns only offer roles the user may assign,
 * - users ranked above the current user are hidden from user lists (setting "hide_higher_users").
 *
 * Used by: Users screens, user-edit.php, user-new.php, REST users endpoint, bulk role changes.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Hierarchy {

	public static function init(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'protect_users' ), 10, 4 );
		add_filter( 'editable_roles', array( __CLASS__, 'editable_roles' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'hide_higher_users' ) );
		add_filter( 'views_users', array( __CLASS__, 'user_views' ) );
	}

	public static function protect_users( $caps, $cap, $user_id, $args ) {
		if ( ! TMG_Roles::active() ) {
			return $caps;
		}
		if ( ! in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$target = (int) $args[0];
		if ( $target === (int) $user_id ) {
			return $caps;
		}
		if ( ! TMG_Roles_Ranks::can_manage_user( $target, (int) $user_id ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}

	public static function editable_roles( $roles ) {
		if ( ! TMG_Roles::active() || ! is_array( $roles ) || ! is_user_logged_in() ) {
			return $roles;
		}
		foreach ( array_keys( $roles ) as $role ) {
			if ( ! TMG_Roles_Ranks::can_assign_role( (string) $role ) ) {
				unset( $roles[ $role ] );
			}
		}
		return $roles;
	}

	private static function applies(): bool {
		if ( ! TMG_Roles::active() || ! TMG_Roles_Settings::get( 'hide_higher_users' ) || ! is_user_logged_in() ) {
			return false;
		}
		if ( TMG_Roles_Ranks::is_top() ) {
			return false;
		}
		return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/** Roles whose users the current user does not see: ranked above them (and at or above hide_higher_min_rank when set). */
	public static function hidden_roles(): array {
		$mine = TMG_Roles_Ranks::user_rank();
		$min  = (int) TMG_Roles_Settings::get( 'hide_higher_min_rank' );
		return array_values( array_filter( TMG_Roles_Ranks::roles_above( $mine ), fn( $r ) => $min <= 0 || TMG_Roles_Ranks::role_rank( $r ) >= $min ) );
	}

	public static function hide_higher_users( WP_User_Query $query ): void {
		if ( $query->get( 'tmg_roles_all' ) || ! self::applies() ) {
			return;
		}
		$above = self::hidden_roles();
		if ( ! $above ) {
			return;
		}
		$exclude = array_merge( (array) $query->get( 'role__not_in' ), $above );
		$query->set( 'role__not_in', array_values( array_unique( $exclude ) ) );
		if ( is_multisite() ) {
			$supers = array();
			foreach ( (array) get_super_admins() as $login ) {
				$u = get_user_by( 'login', $login );
				if ( $u ) {
					$supers[] = $u->ID;
				}
			}
			if ( $supers ) {
				$query->set( 'exclude', array_values( array_unique( array_merge( (array) $query->get( 'exclude' ), $supers ) ) ) );
			}
		}
	}

	/** Role filter links above the Users list: drop hidden roles and the revealing counts. */
	public static function user_views( $views ) {
		if ( ! self::applies() || ! is_array( $views ) ) {
			return $views;
		}
		$mine   = TMG_Roles_Ranks::user_rank();
		$hidden = false;
		foreach ( array_keys( $views ) as $key ) {
			if ( in_array( $key, array( 'all', 'none' ), true ) || ! wp_roles()->is_role( $key ) ) {
				continue;
			}
			$rank = TMG_Roles_Ranks::role_rank( $key );
			if ( in_array( $key, self::hidden_roles(), true ) || ( 0 === $rank && ! TMG_Roles_Ranks::can_assign_role( $key ) ) ) {
				unset( $views[ $key ] );
				$hidden = true;
			}
		}
		if ( $hidden || self::hidden_roles() ) {
			foreach ( array( 'all', 'none' ) as $key ) {
				if ( isset( $views[ $key ] ) ) {
					$views[ $key ] = preg_replace( '/\s*<span class="count">.*?<\/span>/', '', $views[ $key ] );
				}
			}
			unset( $views['none'] );
		}
		return $views;
	}
}

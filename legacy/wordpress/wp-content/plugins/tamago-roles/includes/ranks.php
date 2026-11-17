<?php
/**
 * Ranks (hierarchy numbers) of roles and users, and the "who may touch whom" rules built on them.
 * Administrator (and the multisite super admin) is always the top rank.
 *
 * Used by: hierarchy.php, capabilities.php, admin screens, user-profile.php, privacy.php, helpers.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Ranks {

	/** Top rank: at least the administrator's stored rank and never below any other role. */
	public static function top(): int {
		$ranks = (array) TMG_Roles_Settings::get( 'ranks' );
		$top   = (int) ( $ranks['administrator'] ?? TMG_Roles_Settings::DEFAULT_RANKS['administrator'] );
		foreach ( $ranks as $role => $rank ) {
			$top = max( $top, (int) $rank );
		}
		return max( 1, $top );
	}

	public static function role_rank( string $role ): int {
		if ( 'administrator' === $role ) {
			return self::top();
		}
		$ranks = (array) TMG_Roles_Settings::get( 'ranks' );
		return (int) ( $ranks[ $role ] ?? 0 );
	}

	/** @param WP_User|int|null $user */
	public static function resolve_user( $user = null ): ?WP_User {
		if ( null === $user ) {
			$user = wp_get_current_user();
		} elseif ( ! $user instanceof WP_User ) {
			$user = get_userdata( (int) $user );
		}
		return ( $user instanceof WP_User && $user->exists() ) ? $user : null;
	}

	/** Highest rank among the user's roles (0 for visitors and unranked users). */
	public static function user_rank( $user = null ): int {
		$user = self::resolve_user( $user );
		if ( ! $user ) {
			return 0;
		}
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return self::top();
		}
		$rank = 0;
		foreach ( (array) $user->roles as $role ) {
			$rank = max( $rank, self::role_rank( (string) $role ) );
		}
		return $rank;
	}

	public static function is_top( $user = null ): bool {
		return self::user_rank( $user ) >= self::top();
	}

	/**
	 * May the actor create/edit/delete/promote the target user?
	 * Strictly below the actor's rank; equal rank only for top-rank users or when peer management is on.
	 */
	public static function can_manage_user( int $target_id, $actor = null ): bool {
		$actor_user = null === $actor ? self::resolve_user() : self::resolve_user( $actor );
		$actor_rank = null === $actor ? self::user_rank() : self::user_rank( $actor );
		if ( $actor_user && $actor_user->ID === $target_id ) {
			return true;
		}
		$target_rank = self::user_rank( $target_id );
		if ( $target_rank < $actor_rank ) {
			return true;
		}
		if ( $target_rank === $actor_rank && $actor_rank > 0 ) {
			return $actor_rank >= self::top() || (bool) TMG_Roles_Settings::get( 'peer_management' );
		}
		return false;
	}

	/** Roles the actor may assign to others. */
	public static function can_assign_role( string $role, $actor = null ): bool {
		$mine = null === $actor ? self::user_rank() : self::user_rank( $actor );
		if ( $mine >= self::top() ) {
			return true;
		}
		$rank = self::role_rank( $role );
		if ( 0 === $rank && ! TMG_Roles_Settings::get( 'assign_unranked' ) ) {
			return false;
		}
		return $rank < $mine || ( $rank === $mine && $mine > 0 && TMG_Roles_Settings::get( 'peer_management' ) );
	}

	/** May the actor change this role's capabilities? Only roles ranked below them (top rank: all). */
	public static function can_edit_role( string $role, $actor = null ): bool {
		$mine = null === $actor ? self::user_rank() : self::user_rank( $actor );
		if ( $mine >= self::top() ) {
			return true;
		}
		return self::role_rank( $role ) < $mine;
	}

	/** Roles ranked strictly above the given rank. */
	public static function roles_above( int $rank ): array {
		$out = array();
		foreach ( array_keys( wp_roles()->roles ) as $role ) {
			if ( self::role_rank( $role ) > $rank ) {
				$out[] = $role;
			}
		}
		return $out;
	}

	/** All roles sorted by rank, highest first: key => name. */
	public static function sorted_roles(): array {
		$roles = array();
		foreach ( wp_roles()->roles as $key => $data ) {
			$roles[ $key ] = translate_user_role( $data['name'] );
		}
		uksort(
			$roles,
			function ( $a, $b ) {
				$d = self::role_rank( $b ) <=> self::role_rank( $a );
				return 0 !== $d ? $d : strcmp( $a, $b );
			}
		);
		return $roles;
	}
}

<?php
/**
 * Run-time capability rules through user_has_cap (nothing is written into the user's caps):
 *  1. per-user overrides (grant / deny a permission on top of the user's roles),
 *  2. protected capabilities (only roles at or above a rank may hold them, whatever grants them),
 *  3. administrators always keep this plugin's own capabilities (no lock-out).
 *
 * Used by: every capability check in WordPress; overrides are edited in screen-users.php and user-profile.php.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Capabilities {

	const META = 'tmg_roles_overrides';

	/** Per-request cache: user ID => [ cap => bool ] adjustments. */
	private static $adjust = array();

	public static function init(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'user_has_cap' ), 999, 4 );
	}

	public static function flush(): void {
		self::$adjust = array();
		TMG_Roles_Settings::flush();
	}

	/** Protected capabilities and the rank needed to hold them. */
	public static function protected_caps(): array {
		/**
		 * Capabilities only roles at or above "protected_min_rank" may hold.
		 *
		 * @param string[] $caps
		 */
		return array_values( array_unique( (array) apply_filters( 'tmg_roles_protected_caps', (array) TMG_Roles_Settings::get( 'protected_caps' ) ) ) );
	}

	public static function protected_min_rank(): int {
		return (int) TMG_Roles_Settings::get( 'protected_min_rank' );
	}

	/** Is this capability withheld from a user of this rank? */
	public static function is_protected_for( string $cap, int $rank ): bool {
		$min = self::protected_min_rank();
		if ( $min <= 0 || $rank >= $min || $rank >= TMG_Roles_Ranks::top() ) {
			return false;
		}
		return in_array( $cap, self::protected_caps(), true );
	}

	/* ------------------------------------------------------------------ */
	/* Overrides storage                                                   */
	/* ------------------------------------------------------------------ */

	/** @return array perm key => 'grant'|'deny' */
	public static function overrides( int $user_id ): array {
		$o = get_user_meta( $user_id, self::META, true );
		return is_array( $o ) ? array_filter( $o, fn( $v ) => in_array( $v, array( 'grant', 'deny' ), true ) ) : array();
	}

	/**
	 * Store overrides for a user (empty array removes them).
	 *
	 * @param array $over perm key => grant|deny|inherit.
	 */
	public static function set_overrides( int $user_id, array $over ): void {
		$old   = self::overrides( $user_id );
		$clean = array();
		foreach ( $over as $key => $v ) {
			$key = sanitize_key( (string) $key );
			if ( in_array( $v, array( 'grant', 'deny' ), true ) && TMG_Roles_Permissions::get( $key ) ) {
				$clean[ $key ] = $v;
			}
		}
		ksort( $clean );
		if ( $clean ) {
			update_user_meta( $user_id, self::META, $clean );
		} else {
			delete_user_meta( $user_id, self::META );
		}
		self::flush();
		if ( $old !== $clean ) {
			TMG_Roles_Logger::log( 'override', 'user:' . $user_id, array( 'from' => $old, 'to' => $clean ) );
		}
	}

	/** Set or clear a single override. */
	public static function set_override( int $user_id, string $perm, string $mode ): void {
		$over = self::overrides( $user_id );
		if ( 'inherit' === $mode ) {
			unset( $over[ $perm ] );
		} else {
			$over[ $perm ] = $mode;
		}
		self::set_overrides( $user_id, $over );
	}

	/* ------------------------------------------------------------------ */
	/* The filter                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array   $allcaps All capabilities of the user (role caps + user caps).
	 * @param array   $caps    Primitive caps being checked.
	 * @param array   $args    Original arguments.
	 * @param WP_User $user    The user.
	 */
	public static function user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $allcaps;
		}
		$is_admin = in_array( 'administrator', (array) $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
		if ( $is_admin ) {
			// Lock-out guard: the permissions screen always stays available to administrators.
			$allcaps[ TMG_Roles::CAP_MANAGE ]       = true;
			$allcaps[ TMG_Roles::CAP_MANAGE_USERS ] = true;
		}
		static $busy = false;
		if ( $busy || ! TMG_Roles::active() ) {
			// $busy: a tamago_permissions callback may itself check a capability while we build the list.
			return $allcaps;
		}
		$busy = true;
		$adj  = self::adjustments( $user );
		$busy = false;
		foreach ( $adj as $cap => $grant ) {
			if ( $grant ) {
				$allcaps[ $cap ] = true;
			} else {
				unset( $allcaps[ $cap ] );
			}
		}
		return $allcaps;
	}

	/**
	 * Does the user hold this capability as a stored (primitive) capability, after overrides and
	 * protection? Unlike user_can() it never runs map_meta_cap, so meta capability names such as
	 * edit_comment can be checked without notices.
	 *
	 * @param WP_User|int $user
	 */
	public static function holds( $user, string $cap ): bool {
		$user = TMG_Roles_Ranks::resolve_user( $user );
		if ( ! $user ) {
			return false;
		}
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return true;
		}
		$all = apply_filters( 'user_has_cap', $user->allcaps, array( $cap ), array( $cap, $user->ID ), $user );
		return ! empty( $all[ $cap ] );
	}

	/** Changes to apply for this user, computed once per request. */
	private static function adjustments( WP_User $user ): array {
		$key = $user->ID . ':' . implode( ',', (array) $user->roles );
		if ( isset( self::$adjust[ $key ] ) ) {
			return self::$adjust[ $key ];
		}
		$adj = array();
		foreach ( array( 'grant' => true, 'deny' => false ) as $mode => $grant ) {
			foreach ( self::overrides( $user->ID ) as $perm => $v ) {
				$p = $v === $mode ? TMG_Roles_Permissions::get( $perm ) : null;
				if ( ! $p ) {
					continue;
				}
				foreach ( $p['caps'] as $cap ) {
					if ( ! $grant && 'read' === $cap ) {
						continue; // Never take away the ability to log in through an override.
					}
					$adj[ $cap ] = $grant;
				}
			}
		}
		$rank = TMG_Roles_Ranks::user_rank( $user );
		$min  = self::protected_min_rank();
		if ( $min > 0 && $rank < $min && $rank < TMG_Roles_Ranks::top() ) {
			foreach ( self::protected_caps() as $cap ) {
				$adj[ $cap ] = false;
			}
		}
		self::$adjust[ $key ] = $adj;
		return $adj;
	}
}

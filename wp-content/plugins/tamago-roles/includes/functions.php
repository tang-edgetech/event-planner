<?php
/**
 * Public helper functions for themes and other plugins.
 *
 * Used by: themes, plugins, tests.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tmg_roles_rank' ) ) {
	/**
	 * Highest rank among a user's roles (0 = visitor / unranked). Administrators are the top rank.
	 *
	 * @param WP_User|int|null $user Defaults to the current user.
	 */
	function tmg_roles_rank( $user = null ): int {
		return null === $user ? TMG_Roles_Ranks::user_rank() : TMG_Roles_Ranks::user_rank( $user );
	}
}

if ( ! function_exists( 'tmg_roles_can_manage_user' ) ) {
	/** May the current user create/edit/delete/promote this user (rank rules)? */
	function tmg_roles_can_manage_user( int $user_id ): bool {
		return ! TMG_Roles::active() || TMG_Roles_Ranks::can_manage_user( $user_id );
	}
}

if ( ! function_exists( 'tmg_roles_public_name' ) ) {
	/**
	 * Name to show for a user to the current viewer: staff appear under the public name.
	 * Without an ID, returns the public name itself.
	 */
	function tmg_roles_public_name( int $user_id = 0 ): string {
		return $user_id ? TMG_Roles_Privacy::public_name( $user_id ) : TMG_Roles_Privacy::name();
	}
}

if ( ! function_exists( 'tmg_roles_user_can_view' ) ) {
	/**
	 * May the user view this post on the front end (content access rules)?
	 *
	 * @param WP_User|int|null $user Defaults to the current user.
	 */
	function tmg_roles_user_can_view( int $post_id, $user = null ): bool {
		return TMG_Roles_Content_Access::user_can_view( $post_id, $user );
	}
}

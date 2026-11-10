<?php
/**
 * [tamago_restrict roles="editor,author" logged_in="yes" message="…"]…[/tamago_restrict]
 * Shows the enclosed content only to matching visitors. roles = any of these roles;
 * logged_in = yes (members only) / no (visitors only). Administrators always pass a roles check.
 *
 * Used by: post content, widgets, anything that runs do_shortcode().
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Shortcode {

	public static function init(): void {
		add_shortcode( 'tamago_restrict', array( __CLASS__, 'render' ) );
	}

	public static function allowed( array $atts, ?WP_User $user ): bool {
		$logged = strtolower( trim( (string) ( $atts['logged_in'] ?? '' ) ) );
		if ( in_array( $logged, array( 'yes', '1', 'true' ), true ) && ! $user ) {
			return false;
		}
		if ( in_array( $logged, array( 'no', '0', 'false' ), true ) && $user ) {
			return false;
		}
		$roles = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) ( $atts['roles'] ?? '' ) ) ) ) );
		if ( $roles ) {
			if ( ! $user ) {
				return false;
			}
			if ( ! array_intersect( $roles, (array) $user->roles ) && ! in_array( 'administrator', (array) $user->roles, true ) ) {
				return false;
			}
		}
		return true;
	}

	public static function render( $atts, $content = '' ): string {
		$atts = shortcode_atts( array( 'roles' => '', 'logged_in' => '', 'message' => '' ), (array) $atts, 'tamago_restrict' );
		if ( ! TMG_Roles::active() || self::allowed( $atts, TMG_Roles_Ranks::resolve_user() ) ) {
			return do_shortcode( (string) $content );
		}
		return '' !== $atts['message'] ? '<div class="tmg-roles-restricted">' . esc_html( $atts['message'] ) . '</div>' : '';
	}
}

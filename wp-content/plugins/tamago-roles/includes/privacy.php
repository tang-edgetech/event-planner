<?php
/**
 * Privacy of staff identities: users with the chosen roles appear under one public name
 * (default: the site name) as authors and commenters, in oEmbed data and REST user endpoints
 * (hidden from listings; single lookups 404 or show the public name), and their author archives 404.
 * Users at or above "public_name_reveal_rank" (0 = top rank) still see real names.
 *
 * Used by: front end, REST API, oEmbed, sitemaps; configured on the Settings tab.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Privacy {

	public static function init(): void {
		add_filter( 'get_the_author_display_name', array( __CLASS__, 'author_field' ), 50, 2 );
		foreach ( array( 'nickname', 'first_name', 'last_name', 'user_url', 'description' ) as $field ) {
			add_filter( 'get_the_author_' . $field, array( __CLASS__, 'author_field_' . ( 'nickname' === $field ? 'name' : 'blank' ) ), 50, 2 );
		}
		add_filter( 'author_link', array( __CLASS__, 'author_link' ), 50, 2 );
		add_filter( 'get_comment_author', array( __CLASS__, 'comment_author' ), 50, 3 );
		add_filter( 'get_comment_author_url', array( __CLASS__, 'comment_author_url' ), 50, 3 );
		add_filter( 'rest_user_query', array( __CLASS__, 'rest_user_query' ), 50 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_single_user' ), 50, 3 );
		add_filter( 'rest_prepare_user', array( __CLASS__, 'rest_prepare_user' ), 50, 2 );
		add_filter( 'oembed_response_data', array( __CLASS__, 'oembed' ), 50, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'author_archive' ), 0 );
		add_filter( 'wp_sitemaps_users_query_args', array( __CLASS__, 'sitemap_users' ) );
	}

	public static function staff_roles(): array {
		return (array) TMG_Roles_Settings::get( 'public_name_roles' );
	}

	public static function name(): string {
		$n = (string) TMG_Roles_Settings::get( 'public_name' );
		return '' !== $n ? $n : (string) get_bloginfo( 'name' );
	}

	public static function is_staff( int $user_id ): bool {
		$roles = self::staff_roles();
		if ( ! $roles || ! $user_id ) {
			return false;
		}
		$user = get_userdata( $user_id );
		return $user && array_intersect( (array) $user->roles, $roles );
	}

	/** Does the current viewer see real names? */
	public static function viewer_sees_real(): bool {
		$need = (int) TMG_Roles_Settings::get( 'public_name_reveal_rank' );
		$need = $need > 0 ? $need : TMG_Roles_Ranks::top();
		return is_user_logged_in() && TMG_Roles_Ranks::user_rank() >= $need;
	}

	/** Should this user's identity be masked for the current viewer? */
	public static function masked( int $user_id ): bool {
		if ( ! TMG_Roles::active() || ! self::is_staff( $user_id ) ) {
			return false;
		}
		return ! self::viewer_sees_real();
	}

	public static function public_name( int $user_id ): string {
		if ( self::masked( $user_id ) ) {
			return self::name();
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}

	public static function author_field( $value, $user_id ) {
		return self::masked( (int) $user_id ) ? self::name() : $value;
	}

	public static function author_field_name( $value, $user_id ) {
		return self::author_field( $value, $user_id );
	}

	public static function author_field_blank( $value, $user_id ) {
		return self::masked( (int) $user_id ) ? '' : $value;
	}

	public static function author_link( $link, $author_id ) {
		return self::masked( (int) $author_id ) ? home_url( '/' ) : $link;
	}

	public static function comment_author( $author, $comment_id, $comment = null ) {
		$comment = $comment instanceof WP_Comment ? $comment : get_comment( $comment_id );
		return ( $comment && self::masked( (int) $comment->user_id ) ) ? self::name() : $author;
	}

	public static function comment_author_url( $url, $comment_id, $comment = null ) {
		$comment = $comment instanceof WP_Comment ? $comment : get_comment( $comment_id );
		return ( $comment && self::masked( (int) $comment->user_id ) ) ? '' : $url;
	}

	public static function rest_user_query( $args ) {
		if ( TMG_Roles::active() && self::staff_roles() && ! self::viewer_sees_real() ) {
			$args['role__not_in'] = array_values( array_unique( array_merge( (array) ( $args['role__not_in'] ?? array() ), self::staff_roles() ) ) );
		}
		return $args;
	}

	public static function rest_single_user( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || ! TMG_Roles_Settings::get( 'public_name_rest_hide' ) ) {
			return $result;
		}
		if ( preg_match( '#^/+wp/v2/+users/+(\d+)#i', $request->get_route(), $m ) ) {
			$id = (int) $m[1];
			if ( $id !== get_current_user_id() && self::masked( $id ) ) {
				return new WP_Error( 'rest_user_invalid_id', __( 'Invalid user ID.', 'tamago-roles' ), array( 'status' => 404 ) );
			}
		}
		return $result;
	}

	public static function rest_prepare_user( $response, $user ) {
		if ( ! $response instanceof WP_REST_Response || ! $user instanceof WP_User || $user->ID === get_current_user_id() || ! self::masked( $user->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		foreach ( array( 'name' => self::name(), 'slug' => '', 'url' => '', 'description' => '', 'link' => home_url( '/' ), 'avatar_urls' => array() ) as $k => $v ) {
			if ( array_key_exists( $k, $data ) ) {
				$data[ $k ] = $v;
			}
		}
		$response->set_data( $data );
		return $response;
	}

	public static function oembed( $data, $post ) {
		if ( is_array( $data ) && $post instanceof WP_Post && self::masked( (int) $post->post_author ) ) {
			$data['author_name'] = self::name();
			$data['author_url']  = home_url( '/' );
		}
		return $data;
	}

	public static function author_archive(): void {
		if ( ! is_author() ) {
			return;
		}
		$author = get_queried_object();
		if ( $author instanceof WP_User && self::masked( $author->ID ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			remove_action( 'template_redirect', 'redirect_canonical' );
		}
	}

	public static function sitemap_users( $args ) {
		if ( TMG_Roles::active() && self::staff_roles() ) {
			$args['role__not_in'] = array_values( array_unique( array_merge( (array) ( $args['role__not_in'] ?? array() ), self::staff_roles() ) ) );
		}
		return $args;
	}
}

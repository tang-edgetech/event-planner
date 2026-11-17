<?php
/**
 * Targeting rules: which content is tracked (post types, front page, archives, search, 404),
 * include / exclude by post ID and URL path wildcard, and which visitors are excluded
 * (roles, IP / CIDR). Order: exclude rules win, then include rules, then the content type.
 *
 * Used by: TMG_Reports_Tracker (page render), TMG_Reports_Collect (re-checked on every hit), tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Targeting {

	/** Trackable content types for the settings screen: key => label. */
	public static function types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			$out[ $pt->name ] = $pt->labels->name;
		}
		$out['front']   = __( 'Front page', 'tamago-reports' );
		$out['blog']    = __( 'Blog index (posts page)', 'tamago-reports' );
		$out['archive'] = __( 'Post type archives', 'tamago-reports' );
		$out['tax']     = __( 'Category, tag and taxonomy archives', 'tamago-reports' );
		$out['author']  = __( 'Author archives', 'tamago-reports' );
		$out['date']    = __( 'Date archives', 'tamago-reports' );
		$out['search']  = __( 'Search results', 'tamago-reports' );
		$out['404']     = __( 'Not found (404)', 'tamago-reports' );
		return $out;
	}

	/** Context of the current front-end request (main query). */
	public static function current_context(): array {
		$kind    = 'other';
		$post_id = 0;
		$type    = '';
		if ( is_front_page() ) {
			$kind    = 'front';
			$post_id = is_page() ? (int) get_queried_object_id() : 0;
			$type    = $post_id ? 'page' : '';
		} elseif ( is_home() ) {
			$kind = 'blog';
		} elseif ( is_singular() ) {
			$kind    = 'singular';
			$post_id = (int) get_queried_object_id();
			$type    = (string) get_post_type( $post_id );
		} elseif ( is_post_type_archive() ) {
			$kind = 'archive';
			$type = (string) get_query_var( 'post_type' );
			$type = is_array( $type ) ? (string) reset( $type ) : $type;
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$kind = 'tax';
		} elseif ( is_author() ) {
			$kind = 'author';
		} elseif ( is_date() ) {
			$kind = 'date';
		} elseif ( is_search() ) {
			$kind = 'search';
		} elseif ( is_404() ) {
			$kind = '404';
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return array(
			'kind'      => $kind,
			'post_id'   => $post_id,
			'post_type' => $type,
			'path'      => (string) wp_parse_url( $uri, PHP_URL_PATH ),
			'source'    => 'page',
		);
	}

	/** Path relative to the site home (so "/blog/*" works on sub-folder installs). */
	public static function relative_path( string $path ): string {
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return '/' . ltrim( $path, '/' );
	}

	/** Wildcard match: "*" = any characters. Case-insensitive; a trailing slash is optional. */
	public static function path_matches( string $path, array $patterns ): bool {
		$path = rtrim( strtolower( $path ), '/' );
		foreach ( $patterns as $pattern ) {
			$pattern = rtrim( strtolower( (string) $pattern ), '/' );
			if ( '' === $pattern && '' !== $path ) {
				continue;
			}
			$re = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';
			if ( preg_match( $re, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/** Content rule check (no visitor checks). */
	public static function content_allowed( array $ctx ): bool {
		$s       = TMG_Reports_Settings::all();
		$post_id = (int) ( $ctx['post_id'] ?? 0 );
		$rel     = self::relative_path( (string) ( $ctx['path'] ?? '/' ) );

		if ( ( $post_id && in_array( $post_id, (array) $s['exclude_ids'], true ) ) || self::path_matches( $rel, (array) $s['exclude_paths'] ) ) {
			return false;
		}
		if ( ( $post_id && in_array( $post_id, (array) $s['include_ids'], true ) ) || self::path_matches( $rel, (array) $s['include_paths'] ) ) {
			return true;
		}
		$kind = (string) ( $ctx['kind'] ?? 'other' );
		$off  = (array) $s['untracked_types'];
		switch ( $kind ) {
			case 'singular':
				return '' !== (string) $ctx['post_type'] && ! in_array( (string) $ctx['post_type'], $off, true );
			case 'archive':
				return ! in_array( 'archive', $off, true );
			case 'other':
				return true;
			default:
				return ! in_array( $kind, $off, true );
		}
	}

	public static function role_excluded( ?WP_User $user ): bool {
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return (bool) array_intersect( (array) $user->roles, (array) TMG_Reports_Settings::get( 'exclude_roles' ) );
	}

	public static function ip_excluded( string $ip ): bool {
		return TMG_Reports_Visitor::ip_in_list( $ip, (array) TMG_Reports_Settings::get( 'exclude_ips' ) );
	}

	/**
	 * Full decision. Context keys: kind, post_id, post_type, path, source ('page' | 'collect' | 'server'),
	 * optional user (WP_User) and ip.
	 */
	public static function should_track( array $ctx ): bool {
		$track = (bool) TMG_Reports_Settings::get( 'enabled' );
		if ( $track && 'server' !== ( $ctx['source'] ?? '' ) ) {
			$track = self::content_allowed( $ctx );
		}
		if ( $track && isset( $ctx['user'] ) && $ctx['user'] instanceof WP_User ) {
			$track = ! self::role_excluded( $ctx['user'] );
		}
		if ( $track && ! empty( $ctx['ip'] ) ) {
			$track = ! self::ip_excluded( (string) $ctx['ip'] );
		}
		return (bool) apply_filters( 'tmg_reports_should_track', $track, $ctx );
	}
}

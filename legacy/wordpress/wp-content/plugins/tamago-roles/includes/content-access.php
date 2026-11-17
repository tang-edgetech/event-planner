<?php
/**
 * Front-end content access: who may view a post (everyone / logged-in users / selected roles) and what
 * everyone else gets (login redirect, message with optional excerpt, 404, redirect to a URL).
 * Rules come from the post itself, else its terms, else its post type default.
 * Restricted posts are also removed from archives, search, feeds, REST and sitemaps for those who may not view them.
 *
 * Used by: front end, REST API, feeds, sitemaps; rules are edited in content-admin.php and screen-content.php.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Content_Access {

	const META = '_tmg_roles_access';

	/** Per-request caches. */
	private static $denied = array();
	private static $term_rules = null;

	public static function init(): void {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ), 50 );
		add_action( 'template_redirect', array( __CLASS__, 'guard_singular' ), 5 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_excerpt' ), 999, 2 );
		add_filter( 'comments_open', array( __CLASS__, 'comments_open' ), 50, 2 );
		add_filter( 'comments_array', array( __CLASS__, 'comments_array' ), 50, 2 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_single' ), 10, 3 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		foreach ( array( 'save_post', 'deleted_post', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'set_object_terms' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
		add_action( 'set_current_user', array( __CLASS__, 'flush' ) );
	}

	public static function flush(): void {
		self::$denied     = array();
		self::$term_rules = null;
	}

	/* ------------------------------------------------------------------ */
	/* Rules                                                               */
	/* ------------------------------------------------------------------ */

	public static function empty_rule(): array {
		return array(
			'mode'         => 'inherit',
			'roles'        => array(),
			'action'       => 'inherit',
			'message'      => '',
			'show_excerpt' => false,
			'redirect'     => '',
		);
	}

	public static function modes(): array {
		return array(
			'inherit'   => __( 'Default (from terms / post type)', 'tamago-roles' ),
			'everyone'  => __( 'Everyone', 'tamago-roles' ),
			'logged_in' => __( 'Logged-in users', 'tamago-roles' ),
			'roles'     => __( 'Selected roles', 'tamago-roles' ),
		);
	}

	public static function actions(): array {
		return array(
			'inherit'  => __( 'Default', 'tamago-roles' ),
			'login'    => __( 'Send to the login page', 'tamago-roles' ),
			'message'  => __( 'Show a message', 'tamago-roles' ),
			'404'      => __( 'Show "not found" (404)', 'tamago-roles' ),
			'redirect' => __( 'Redirect to a URL', 'tamago-roles' ),
		);
	}

	public static function sanitize_rule( array $r ): array {
		$out                 = self::empty_rule();
		$out['mode']         = array_key_exists( (string) ( $r['mode'] ?? '' ), self::modes() ) ? (string) $r['mode'] : 'inherit';
		$out['roles']        = array_values( array_filter( array_map( 'sanitize_key', (array) ( $r['roles'] ?? array() ) ) ) );
		$out['action']       = array_key_exists( (string) ( $r['action'] ?? '' ), self::actions() ) ? (string) $r['action'] : 'inherit';
		$out['message']      = wp_kses_post( (string) ( $r['message'] ?? '' ) );
		$out['show_excerpt'] = ! empty( $r['show_excerpt'] );
		$out['redirect']     = esc_url_raw( (string) ( $r['redirect'] ?? '' ) );
		return $out;
	}

	public static function post_rule( int $post_id ): array {
		$r = get_post_meta( $post_id, self::META, true );
		return is_array( $r ) ? self::sanitize_rule( $r ) : self::empty_rule();
	}

	public static function term_rule( int $term_id ): array {
		$r = get_term_meta( $term_id, self::META, true );
		return is_array( $r ) ? self::sanitize_rule( $r ) : self::empty_rule();
	}

	/** term_id => rule for every term with a rule. */
	public static function term_rules(): array {
		global $wpdb;
		if ( null === self::$term_rules ) {
			self::$term_rules = array();
			$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s", self::META ) );
			foreach ( (array) $rows as $row ) {
				$rule = maybe_unserialize( $row->meta_value );
				if ( is_array( $rule ) ) {
					$rule = self::sanitize_rule( $rule );
					if ( ! in_array( $rule['mode'], array( 'inherit', 'everyone' ), true ) ) {
						self::$term_rules[ (int) $row->term_id ] = $rule;
					}
				}
			}
		}
		return self::$term_rules;
	}

	/**
	 * The rules that decide access to a post: its own rule; else every rule of its terms
	 * (all must pass); else its post type default. Empty = everyone.
	 *
	 * @return array[] list of rules
	 */
	public static function effective_rules( WP_Post $post ): array {
		$own = self::post_rule( $post->ID );
		if ( 'inherit' !== $own['mode'] ) {
			return 'everyone' === $own['mode'] ? array() : array( self::fill_action( $own, $post ) );
		}
		$term_rules = self::term_rules();
		if ( $term_rules ) {
			$found = array();
			foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
				$terms = get_the_terms( $post, $tax );
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					if ( isset( $term_rules[ $term->term_id ] ) ) {
						$found[] = self::fill_action( $term_rules[ $term->term_id ], $post, $own );
					}
				}
			}
			if ( $found ) {
				return $found;
			}
		}
		$defaults = (array) TMG_Roles_Settings::get( 'content_defaults' );
		if ( isset( $defaults[ $post->post_type ] ) ) {
			$rule = self::sanitize_rule( (array) $defaults[ $post->post_type ] );
			if ( ! in_array( $rule['mode'], array( 'inherit', 'everyone' ), true ) ) {
				return array( self::fill_action( $rule, $post, $own ) );
			}
		}
		return array();
	}

	/** Fill "default" action / message from the post (own settings win) or the post type default. */
	private static function fill_action( array $rule, WP_Post $post, ?array $own = null ): array {
		if ( $own && 'inherit' !== $own['action'] ) {
			foreach ( array( 'action', 'message', 'show_excerpt', 'redirect' ) as $k ) {
				$rule[ $k ] = $own[ $k ];
			}
		}
		if ( 'inherit' === $rule['action'] ) {
			$defaults = (array) TMG_Roles_Settings::get( 'content_defaults' );
			$type     = isset( $defaults[ $post->post_type ] ) ? self::sanitize_rule( (array) $defaults[ $post->post_type ] ) : null;
			if ( $type && 'inherit' !== $type['action'] ) {
				foreach ( array( 'action', 'message', 'show_excerpt', 'redirect' ) as $k ) {
					$rule[ $k ] = ( 'message' === $k && '' !== $rule['message'] ) ? $rule['message'] : $type[ $k ];
				}
			} else {
				$rule['action'] = 'login';
			}
		}
		return $rule;
	}

	/** Does the user pass one rule? */
	public static function passes( array $rule, ?WP_User $user ): bool {
		switch ( $rule['mode'] ) {
			case 'logged_in':
				return (bool) $user;
			case 'roles':
				return $user && array_intersect( (array) $user->roles, (array) $rule['roles'] );
		}
		return true;
	}

	/**
	 * May the user view the post on the front end?
	 *
	 * @param WP_User|int|null $user Defaults to the current user.
	 */
	public static function user_can_view( int $post_id, $user = null ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$user = null === $user ? TMG_Roles_Ranks::resolve_user() : TMG_Roles_Ranks::resolve_user( $user );
		$can  = true;
		$rule = null;
		if ( TMG_Roles::active() ) {
			$rules = self::effective_rules( $post );
			if ( $rules ) {
				if ( $user && ( ( post_type_exists( $post->post_type ) && user_can( $user, 'edit_post', $post->ID ) ) || in_array( 'administrator', (array) $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) ) ) ) {
					$can = true; // People who can edit it (and administrators) always see it.
				} else {
					foreach ( $rules as $r ) {
						if ( ! self::passes( $r, $user ) ) {
							$can  = false;
							$rule = $r;
							break;
						}
					}
				}
			}
		}
		/**
		 * May this user view this post on the front end?
		 *
		 * @param bool         $can     Result of the rules.
		 * @param int          $post_id Post ID.
		 * @param WP_User|null $user    The user (null = visitor).
		 * @param array|null   $rule    The rule that denied access, if any.
		 */
		return (bool) apply_filters( 'tmg_roles_can_view_post', $can, $post_id, $user, $rule );
	}

	/** The rule that denies this user, for choosing what to show instead. */
	public static function denying_rule( WP_Post $post, ?WP_User $user ): array {
		foreach ( self::effective_rules( $post ) as $r ) {
			if ( ! self::passes( $r, $user ) ) {
				return $r;
			}
		}
		$rules = self::effective_rules( $post );
		return $rules ? $rules[0] : array_merge( self::empty_rule(), array( 'action' => 'message' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Lists: archives, search, feeds, REST, sitemaps                      */
	/* ------------------------------------------------------------------ */

	/** Candidate posts that have any rule (own, term or post type default). */
	private static function candidates(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) );
		$defaults = array_keys( (array) TMG_Roles_Settings::get( 'content_defaults' ) );
		if ( $defaults ) {
			$in  = implode( ',', array_fill( 0, count( $defaults ), '%s' ) );
			$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ('publish','private','inherit')", $defaults ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$terms = array_keys( self::term_rules() );
		if ( $terms ) {
			$in  = implode( ',', array_fill( 0, count( $terms ), '%d' ) );
			$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id IN ($in)", $terms ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** Post IDs the user may not view (cached per request and user). */
	public static function denied_ids( $user = null ): array {
		if ( ! TMG_Roles::active() ) {
			return array();
		}
		$user = null === $user ? TMG_Roles_Ranks::resolve_user() : TMG_Roles_Ranks::resolve_user( $user );
		$key  = $user ? $user->ID : 0;
		if ( isset( self::$denied[ $key ] ) ) {
			return self::$denied[ $key ];
		}
		$ids = self::candidates();
		if ( $ids ) {
			_prime_post_caches( $ids, true, true );
		}
		$denied = array();
		foreach ( $ids as $id ) {
			if ( ! self::user_can_view( $id, $user ? $user : 0 ) ) {
				$denied[] = $id;
			}
		}
		self::$denied[ $key ] = $denied;
		return $denied;
	}

	public static function filter_query( WP_Query $q ): void {
		// Queries run during init (ACF loading its internal post types, …) come before post types exist.
		if ( ! TMG_Roles::active() || $q->get( 'tmg_roles_skip' ) || ! did_action( 'wp_loaded' ) ) {
			return;
		}
		$types = array_filter( (array) $q->get( 'post_type' ) );
		if ( $types && ! in_array( 'any', $types, true ) && ! array_filter( $types, fn( $t ) => is_post_type_viewable( (string) $t ) ) ) {
			return;
		}
		$rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( is_admin() && ! wp_doing_ajax() && ! $rest ) {
			return;
		}
		// The single post itself loads normally; guard_singular decides what the visitor gets.
		if ( $q->is_main_query() && ! $rest && ( $q->is_singular() || $q->get( 'p' ) || $q->get( 'page_id' ) || $q->get( 'name' ) || $q->get( 'pagename' ) || $q->get( 'attachment' ) ) && ! $q->is_feed() ) {
			return;
		}
		$denied = self::denied_ids();
		if ( $denied ) {
			$q->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $q->get( 'post__not_in' ) ), $denied ) ) ) );
		}
	}

	/** Sitemaps are public: exclude everything a visitor may not see. */
	public static function sitemap_args( $args, $post_type ) {
		$denied = self::denied_ids( 0 );
		if ( $denied ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), $denied ) ) );
		}
		return $args;
	}

	/** Single items over REST (/wp/v2/posts/123 and friends). */
	public static function rest_single( $response, $handler, $request ) {
		if ( ! TMG_Roles::active() || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() ) {
			return $response;
		}
		$id = $request->get_param( 'id' );
		if ( ! is_numeric( $id ) || empty( $handler['callback'] ) || ! is_array( $handler['callback'] ) || ! $handler['callback'][0] instanceof WP_REST_Posts_Controller ) {
			return $response;
		}
		$post = get_post( (int) $id );
		if ( $post && ! self::user_can_view( $post->ID ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'tamago-roles' ), array( 'status' => 404 ) );
		}
		return $response;
	}

	/* ------------------------------------------------------------------ */
	/* Single view                                                         */
	/* ------------------------------------------------------------------ */

	private static function current_denied_post(): ?WP_Post {
		if ( ! is_singular() ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || self::user_can_view( $post->ID ) ) {
			return null;
		}
		return $post;
	}

	public static function guard_singular(): void {
		if ( ! TMG_Roles::active() || is_feed() ) {
			return;
		}
		$post = self::current_denied_post();
		if ( ! $post ) {
			return;
		}
		$user = TMG_Roles_Ranks::resolve_user();
		$rule = self::denying_rule( $post, $user );
		$act  = $rule['action'];
		if ( 'login' === $act && $user ) {
			$act = 'message'; // Already logged in: logging in again would not help.
		}
		switch ( $act ) {
			case 'login':
				nocache_headers();
				wp_safe_redirect( wp_login_url( get_permalink( $post ) ) );
				exit;
			case 'redirect':
				if ( $rule['redirect'] ) {
					nocache_headers();
					wp_redirect( $rule['redirect'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- URL chosen by an administrator.
					exit;
				}
				break;
			case '404':
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
				return;
		}
		// Message: the page renders, the_content is replaced.
		nocache_headers();
	}

	/** Message shown instead of restricted content. */
	public static function message_html( WP_Post $post, ?array $rule = null ): string {
		$rule = $rule ?? self::denying_rule( $post, TMG_Roles_Ranks::resolve_user() );
		$msg  = '' !== $rule['message'] ? $rule['message'] : (string) TMG_Roles_Settings::get( 'content_message' );
		if ( '' === trim( wp_strip_all_tags( $msg ) ) ) {
			$msg = is_user_logged_in()
				? __( 'This content is not available to your account.', 'tamago-roles' )
				: sprintf( /* translators: login URL */ __( 'This content is for members only. <a href="%s">Log in</a> to view it.', 'tamago-roles' ), esc_url( wp_login_url( get_permalink( $post ) ) ) );
		}
		$out = '';
		if ( ! empty( $rule['show_excerpt'] ) && has_excerpt( $post ) ) {
			$out .= '<div class="tmg-roles-excerpt">' . wpautop( esc_html( $post->post_excerpt ) ) . '</div>';
		}
		return $out . '<div class="tmg-roles-restricted">' . wpautop( wp_kses_post( $msg ) ) . '</div>';
	}

	public static function filter_content( $content ) {
		if ( is_admin() || ! TMG_Roles::active() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || self::user_can_view( $post->ID ) ) {
			return $content;
		}
		return self::message_html( $post );
	}

	public static function filter_excerpt( $excerpt, $post = null ) {
		if ( is_admin() || ! TMG_Roles::active() ) {
			return $excerpt;
		}
		$post = get_post( $post );
		if ( ! $post || self::user_can_view( $post->ID ) ) {
			return $excerpt;
		}
		$rule = self::denying_rule( $post, TMG_Roles_Ranks::resolve_user() );
		return ! empty( $rule['show_excerpt'] ) ? $post->post_excerpt : '';
	}

	public static function comments_open( $open, $post_id ) {
		return ( $open && $post_id && TMG_Roles::active() && ! self::user_can_view( (int) $post_id ) ) ? false : $open;
	}

	public static function comments_array( $comments, $post_id ) {
		return ( $post_id && TMG_Roles::active() && ! is_admin() && ! self::user_can_view( (int) $post_id ) ) ? array() : $comments;
	}

	public static function robots( array $robots ): array {
		if ( TMG_Roles::active() && self::current_denied_post() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}
}

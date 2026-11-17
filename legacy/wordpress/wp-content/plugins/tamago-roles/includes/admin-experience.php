<?php
/**
 * Admin experience per role: admin menu visibility (captured from the live menu) with direct-URL
 * blocking, admin bar items, front-end toolbar, dashboard widgets, login/logout redirects and
 * "no wp-admin access". A rule applies to a user only when every one of their roles has it
 * (roles add access, never take it away). Administrators are exempt unless "restrict_admins" is on.
 *
 * Used by: every wp-admin page load, wp-login.php, the toolbar; configured in screen-menus.php.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Admin_Experience {

	const MENU_SNAPSHOT   = 'tmg_roles_menu_snapshot';
	const WIDGET_SNAPSHOT = 'tmg_roles_widget_snapshot';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'capture_menu' ), PHP_INT_MAX - 10 );
		add_action( 'admin_menu', array( __CLASS__, 'trim_menu' ), PHP_INT_MAX - 5 );
		add_action( 'admin_init', array( __CLASS__, 'no_admin_access' ), 0 );
		// Before the menu is trimmed: WordPress refuses pages missing from the menu with a bare 403.
		add_action( 'admin_menu', array( __CLASS__, 'block_screen' ), PHP_INT_MAX - 7 );
		add_action( 'admin_notices', array( __CLASS__, 'blocked_notice' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'trim_admin_bar' ), 9999 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ), 50 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widgets' ), 9999 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 50, 3 );
		add_filter( 'logout_redirect', array( __CLASS__, 'logout_redirect' ), 50, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Which roles count                                                   */
	/* ------------------------------------------------------------------ */

	/** Roles whose rules apply to this user (empty = unrestricted). */
	public static function rule_roles( $user = null ): array {
		$user = TMG_Roles_Ranks::resolve_user( $user );
		if ( ! $user || ! TMG_Roles::active() ) {
			return array();
		}
		if ( self::exempt( $user ) ) {
			return array();
		}
		return array_values( (array) $user->roles );
	}

	/** Administrators (and multisite super admins) are exempt unless restrict_admins is on. */
	public static function exempt( WP_User $user ): bool {
		$admin = in_array( 'administrator', (array) $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
		return $admin && ! TMG_Roles_Settings::get( 'restrict_admins' );
	}

	/** Items listed for every one of the user's roles in a per-role rule set. */
	private static function common( string $setting, array $roles ): array {
		if ( ! $roles ) {
			return array();
		}
		$rules  = (array) TMG_Roles_Settings::get( $setting );
		$common = null;
		foreach ( $roles as $role ) {
			$items  = (array) ( $rules[ $role ] ?? array() );
			$common = null === $common ? $items : array_values( array_intersect( $common, $items ) );
		}
		return (array) $common;
	}

	/** Does a role-list setting contain every one of the user's roles? */
	private static function all_in( string $setting, array $roles ): bool {
		if ( ! $roles ) {
			return false;
		}
		return ! array_diff( $roles, (array) TMG_Roles_Settings::get( $setting ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin menu                                                          */
	/* ------------------------------------------------------------------ */

	public static function menu_snapshot(): array {
		$s = get_option( self::MENU_SNAPSHOT, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Record the full admin menu while a top-rank manager is looking at it (so plugin menus appear). */
	public static function capture_menu(): void {
		global $menu, $submenu;
		if ( ! is_user_logged_in() || ! TMG_Roles_Ranks::is_top() || ! current_user_can( TMG_Roles::CAP_MANAGE ) || ! is_array( $menu ) ) {
			return;
		}
		$items = array();
		ksort( $menu );
		foreach ( $menu as $m ) {
			if ( empty( $m[2] ) || ( isset( $m[4] ) && false !== strpos( (string) $m[4], 'wp-menu-separator' ) ) ) {
				continue;
			}
			$slug     = (string) $m[2];
			$children = array();
			foreach ( (array) ( $submenu[ $slug ] ?? array() ) as $s ) {
				if ( empty( $s[2] ) ) {
					continue;
				}
				$children[] = array( 'id' => $slug . '|' . $s[2], 'title' => self::clean_title( (string) $s[0] ) );
			}
			$items[] = array( 'id' => $slug, 'title' => self::clean_title( (string) $m[0] ), 'children' => $children );
		}
		if ( $items && self::menu_snapshot() !== $items ) {
			update_option( self::MENU_SNAPSHOT, $items, false );
		}
	}

	private static function clean_title( string $title ): string {
		// Menu titles carry update/comment count bubbles.
		$title = preg_replace( '#<span[^>]*class="[^"]*(count|awaiting|update-plugins|plugin-count|pending)[^"]*"[^>]*>.*?</span>(\s*</span>)?#is', '', $title );
		return trim( wp_strip_all_tags( $title ) );
	}

	/** Menu IDs hidden for the user ("slug" for top level, "parent|slug" for submenus). */
	public static function hidden_menu( $user = null ): array {
		$user   = TMG_Roles_Ranks::resolve_user( $user );
		$hidden = $user ? self::common( 'menu_rules', self::rule_roles( $user ) ) : array();
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
			// Never lock administrators out of this screen.
			$hidden = array_values( array_diff( $hidden, TMG_Roles_Admin_Screen::menu_ids() ) );
		}
		/**
		 * Admin menu items hidden (and blocked) for a user.
		 *
		 * @param string[]     $hidden Menu IDs: "slug" or "parent|slug".
		 * @param WP_User|null $user
		 */
		return array_values( array_unique( (array) apply_filters( 'tmg_roles_menu_hidden', $hidden, $user ) ) );
	}

	public static function trim_menu(): void {
		global $submenu;
		if ( ! is_user_logged_in() ) {
			return;
		}
		$hidden = self::hidden_menu();
		if ( ! $hidden ) {
			return;
		}
		foreach ( $hidden as $id ) {
			if ( false !== strpos( $id, '|' ) ) {
				list( $parent, $sub ) = explode( '|', $id, 2 );
				remove_submenu_page( $parent, $sub );
			} else {
				remove_menu_page( $id );
				unset( $submenu[ $id ] );
			}
		}
	}

	/** Every URL target (slug) blocked for the user: hidden items plus all children of hidden top-level items. */
	public static function blocked_slugs( $user = null ): array {
		$hidden = self::hidden_menu( $user );
		if ( ! $hidden ) {
			return array();
		}
		$explicit = array();
		$tops     = array();
		foreach ( $hidden as $id ) {
			if ( false !== strpos( $id, '|' ) ) {
				$explicit[] = explode( '|', $id, 2 )[1];
			} else {
				$explicit[] = $id;
				$tops[]     = $id;
			}
		}
		// Children of a hidden top-level item are blocked too, unless a visible parent also offers them.
		$implicit = array();
		$visible  = array();
		foreach ( self::menu_snapshot() as $item ) {
			$parent_hidden = in_array( $item['id'], $tops, true );
			foreach ( (array) $item['children'] as $child ) {
				$slug = explode( '|', $child['id'], 2 )[1];
				if ( $parent_hidden ) {
					$implicit[] = $slug;
				} elseif ( ! in_array( $child['id'], $hidden, true ) ) {
					$visible[] = $slug;
				}
			}
		}
		return array_values( array_unique( array_merge( $explicit, array_diff( $implicit, $visible ) ) ) );
	}

	/**
	 * Does a menu slug point at the given request?
	 *
	 * @param string $slug    Menu slug ("edit.php?post_type=x", "my-plugin-page", "index.php").
	 * @param string $pagenow Current admin file.
	 * @param array  $get     Current query args.
	 */
	public static function slug_matches( string $slug, string $pagenow, array $get ): bool {
		if ( false === strpos( $slug, '.php' ) ) {
			return isset( $get['page'] ) && (string) $get['page'] === $slug;
		}
		$path = strtok( $slug, '?' );
		$args = array();
		$q    = (string) wp_parse_url( $slug, PHP_URL_QUERY );
		parse_str( $q, $args );

		// Editing a single item counts as the list of its post type; term.php as edit-tags.php.
		if ( 'post.php' === $pagenow ) {
			$id      = absint( $get['post'] ?? ( $get['post_ID'] ?? 0 ) );
			$pagenow = 'edit.php';
			$get     = array( 'post_type' => $id ? (string) get_post_type( $id ) : 'post' );
		}
		if ( 'term.php' === $pagenow ) {
			$pagenow = 'edit-tags.php';
		}
		if ( 'post-new.php' === $pagenow && 'edit.php' === $path ) {
			$pagenow = 'edit.php';
		}
		if ( $path !== $pagenow ) {
			return false;
		}
		$keys = array( 'page', 'taxonomy' );
		if ( in_array( $path, array( 'edit.php', 'post-new.php' ), true ) ) {
			$keys[]                  = 'post_type';
			$args['post_type']       = $args['post_type'] ?? 'post';
			$get['post_type']        = ( isset( $get['post_type'] ) && '' !== $get['post_type'] ) ? $get['post_type'] : 'post';
		}
		foreach ( $keys as $k ) {
			if ( (string) ( $args[ $k ] ?? '' ) !== (string) ( $get[ $k ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Is the current admin request one of the user's hidden screens? */
	public static function is_blocked( string $pagenow, array $get, $user = null ): bool {
		foreach ( self::blocked_slugs( $user ) as $slug ) {
			if ( self::slug_matches( $slug, $pagenow, $get ) ) {
				return true;
			}
		}
		return false;
	}

	public static function block_screen(): void {
		global $pagenow;
		if ( wp_doing_ajax() || ! is_user_logged_in() || in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true ) ) {
			return;
		}
		$get = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! self::is_blocked( (string) $pagenow, (array) $get ) ) {
			return;
		}
		$target = self::fallback_url();
		if ( ! $target ) {
			wp_die( esc_html__( 'You are not allowed to open this screen.', 'tamago-roles' ), esc_html__( 'Not allowed', 'tamago-roles' ), array( 'response' => 403, 'back_link' => true ) );
		}
		wp_safe_redirect( add_query_arg( 'tmg_roles_blocked', '1', $target ) );
		exit;
	}

	/** Where a blocked user lands: role login page, dashboard, first allowed menu item, profile. */
	public static function fallback_url( $user = null ): string {
		$user  = TMG_Roles_Ranks::resolve_user( $user );
		$cands = array();
		$login = $user ? self::role_url( 'login_redirect', $user ) : '';
		if ( $login && 0 === strpos( $login, admin_url() ) ) {
			$cands[] = $login;
		}
		$cands[] = admin_url( 'index.php' );
		global $menu;
		foreach ( (array) $menu as $m ) {
			if ( empty( $m[2] ) || ( isset( $m[4] ) && false !== strpos( (string) $m[4], 'wp-menu-separator' ) ) ) {
				continue;
			}
			if ( isset( $m[1] ) && ! current_user_can( $m[1] ) ) {
				continue;
			}
			$cands[] = false !== strpos( $m[2], '.php' ) ? admin_url( $m[2] ) : admin_url( 'admin.php?page=' . $m[2] );
		}
		$cands[] = admin_url( 'profile.php' );
		foreach ( $cands as $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$file = basename( $path );
			$get  = array();
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $get );
			if ( ! self::is_blocked( $file, $get, $user ) && ! self::same_request( $url ) ) {
				return $url;
			}
		}
		return '';
	}

	private static function same_request( string $url ): bool {
		$current = set_url_scheme( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return remove_query_arg( 'tmg_roles_blocked', $current ) === remove_query_arg( 'tmg_roles_blocked', set_url_scheme( $url ) );
	}

	public static function blocked_notice(): void {
		if ( empty( $_GET['tmg_roles_blocked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'The screen you tried to open is not available to your role, so you were brought here.', 'tamago-roles' ) . '</p></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Admin bar, toolbar, dashboard widgets                               */
	/* ------------------------------------------------------------------ */

	/** Known admin bar nodes offered in the screen (any other node ID can be typed in). */
	public static function adminbar_nodes(): array {
		return array(
			'wp-logo'     => __( 'WordPress logo menu', 'tamago-roles' ),
			'site-name'   => __( 'Site name menu', 'tamago-roles' ),
			'dashboard'   => __( 'Site name → Dashboard', 'tamago-roles' ),
			'themes'      => __( 'Site name → Themes', 'tamago-roles' ),
			'widgets'     => __( 'Site name → Widgets', 'tamago-roles' ),
			'menus'       => __( 'Site name → Menus', 'tamago-roles' ),
			'site-editor' => __( 'Site name → Editor', 'tamago-roles' ),
			'customize'   => __( 'Customize', 'tamago-roles' ),
			'updates'     => __( 'Updates', 'tamago-roles' ),
			'comments'    => __( 'Comments', 'tamago-roles' ),
			'new-content' => __( '+ New (whole menu)', 'tamago-roles' ),
			'new-post'    => __( '+ New → Post', 'tamago-roles' ),
			'new-page'    => __( '+ New → Page', 'tamago-roles' ),
			'new-media'   => __( '+ New → Media', 'tamago-roles' ),
			'new-user'    => __( '+ New → User', 'tamago-roles' ),
			'edit'        => __( 'Edit this item', 'tamago-roles' ),
			'search'      => __( 'Search', 'tamago-roles' ),
		);
	}

	public static function trim_admin_bar( $bar ): void {
		if ( ! $bar instanceof WP_Admin_Bar ) {
			return;
		}
		foreach ( self::common( 'adminbar_rules', self::rule_roles() ) as $node ) {
			$bar->remove_node( $node );
		}
	}

	public static function show_admin_bar( $show ) {
		if ( $show && ! is_admin() && self::all_in( 'toolbar_front_hidden', self::rule_roles() ) ) {
			return false;
		}
		return $show;
	}

	public static function widget_snapshot(): array {
		$s = get_option( self::WIDGET_SNAPSHOT, array() );
		$s = is_array( $s ) ? $s : array();
		return $s + array(
			'welcome_panel'        => __( 'Welcome panel', 'tamago-roles' ),
			'dashboard_right_now'  => __( 'At a Glance', 'tamago-roles' ),
			'dashboard_activity'   => __( 'Activity', 'tamago-roles' ),
			'dashboard_quick_press' => __( 'Quick Draft', 'tamago-roles' ),
			'dashboard_primary'    => __( 'WordPress Events and News', 'tamago-roles' ),
			'dashboard_site_health' => __( 'Site Health Status', 'tamago-roles' ),
		);
	}

	public static function dashboard_widgets(): void {
		global $wp_meta_boxes;
		$boxes = (array) ( $wp_meta_boxes['dashboard'] ?? array() );
		if ( TMG_Roles_Ranks::is_top() && current_user_can( TMG_Roles::CAP_MANAGE ) ) {
			$found = array();
			foreach ( $boxes as $context => $prios ) {
				foreach ( (array) $prios as $prio => $widgets ) {
					foreach ( (array) $widgets as $id => $w ) {
						if ( is_array( $w ) && ! empty( $w['title'] ) ) {
							$found[ $id ] = trim( wp_strip_all_tags( (string) $w['title'] ) );
						}
					}
				}
			}
			$saved = get_option( self::WIDGET_SNAPSHOT, array() );
			if ( $found && $saved !== $found ) {
				update_option( self::WIDGET_SNAPSHOT, $found, false );
			}
		}
		$hide = self::common( 'widget_rules', self::rule_roles() );
		foreach ( $hide as $id ) {
			if ( 'welcome_panel' === $id ) {
				remove_action( 'welcome_panel', 'wp_welcome_panel' );
				continue;
			}
			foreach ( array_keys( $boxes ) as $context ) {
				remove_meta_box( $id, 'dashboard', $context );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Redirects and wp-admin access                                       */
	/* ------------------------------------------------------------------ */

	/** First redirect rule among the user's roles, highest-ranked role first. Returns an absolute URL. */
	public static function role_url( string $setting, WP_User $user ): string {
		if ( self::exempt( $user ) || ! TMG_Roles::active() ) {
			return '';
		}
		$rules = (array) TMG_Roles_Settings::get( $setting );
		$roles = (array) $user->roles;
		usort( $roles, fn( $a, $b ) => TMG_Roles_Ranks::role_rank( $b ) <=> TMG_Roles_Ranks::role_rank( $a ) );
		foreach ( $roles as $role ) {
			if ( ! empty( $rules[ $role ] ) ) {
				return self::absolute( (string) $rules[ $role ] );
			}
		}
		return '';
	}

	public static function absolute( string $url ): string {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}
		return admin_url( $url );
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect;
		}
		// Only when the visitor did not ask for a specific page.
		$generic = array( '', admin_url(), admin_url( '/' ), admin_url( 'index.php' ), home_url( '/wp-admin/' ) );
		if ( ! in_array( (string) $requested, $generic, true ) ) {
			return $redirect;
		}
		$url = self::role_url( 'login_redirect', $user );
		if ( ! $url && self::all_in( 'no_admin', self::rule_roles( $user ) ) ) {
			$url = home_url( '/' );
		}
		return $url ? $url : $redirect;
	}

	public static function logout_redirect( $redirect, $requested, $user ) {
		if ( ! $user instanceof WP_User || '' !== (string) $requested ) {
			return $redirect;
		}
		$url = self::role_url( 'logout_redirect', $user );
		return $url ? $url : $redirect;
	}

	public static function no_admin_access(): void {
		global $pagenow;
		if ( wp_doing_ajax() || ! is_user_logged_in() || in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true ) ) {
			return;
		}
		if ( self::all_in( 'no_admin', self::rule_roles() ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}
}

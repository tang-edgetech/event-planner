<?php
/**
 * Roles (Developer > Super Admin > Manager > Viewer), permission matrix and per-user overrides.
 *
 * Each "permission" is a friendly switch that maps to one or more WordPress capabilities.
 * Developer (and the WordPress Administrator) always has everything, including the technical areas
 * that are never in the matrix: plugins, themes, ACF field groups, WordPress settings, tools and updates.
 * Every other role only gets what the matrix grants, so it only sees those sections of the admin.
 * A user may only change permissions, overrides and accounts of roles ranked below their own.
 */

defined( 'ABSPATH' ) || exit;

class EPC_Roles {

	const DEVELOPER = 'ep_developer';
	const SUPER     = 'ep_super_admin';
	const MANAGER   = 'ep_manager';
	const VIEWER    = 'ep_viewer';
	const VERSION   = '3';

	/** Roles controlled by the permission matrix, highest first. */
	public static function matrix_roles(): array {
		return array(
			self::SUPER   => 'Super Admin',
			self::MANAGER => 'Manager',
			self::VIEWER  => 'Viewer',
		);
	}

	/** Rank of a role: higher outranks lower. 0 = not part of this system. */
	public static function role_rank( string $role ): int {
		$ranks = array( 'administrator' => 4, self::DEVELOPER => 4, self::SUPER => 3, self::MANAGER => 2, self::VIEWER => 1 );
		return $ranks[ $role ] ?? 0;
	}

	/** Highest rank among a user's roles. */
	public static function rank( $user = null ): int {
		if ( null === $user ) {
			$user = wp_get_current_user();
		} elseif ( ! $user instanceof WP_User ) {
			$user = get_userdata( (int) $user );
		}
		if ( ! $user || ! $user->exists() ) {
			return 0;
		}
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return 4;
		}
		$rank = 0;
		foreach ( (array) $user->roles as $r ) {
			$rank = max( $rank, self::role_rank( $r ) );
		}
		return $rank;
	}

	public static function is_developer( $user = null ): bool {
		return self::rank( $user ) >= 4;
	}

	/** Super Admin or above. */
	public static function is_super( $user = null ): bool {
		return self::rank( $user ) >= 3;
	}

	/** Matrix roles the current user may change (strictly below their own rank). */
	public static function manageable_roles(): array {
		$mine = self::rank();
		return array_filter( self::matrix_roles(), fn( $r ) => self::role_rank( $r ) < $mine, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Permission catalogue.
	 * key => [ group, label, description, caps[], default for manager, default for viewer ]
	 * Super Admin defaults to every permission.
	 */
	public static function permissions(): array {
		return array(
			// Dashboard.
			'dashboard'          => array( 'General', 'Access Event Plus dashboard', 'See the Event Plus overview (required for any access).', array( 'read', 'epc_view_dashboard' ), true, true ),

			// Events.
			'events_view'        => array( 'Events', 'View events', 'See the events list, registration counts and status.', array( 'edit_ep_events' ), true, true ),
			'events_create'      => array( 'Events', 'Create & edit events', 'Create events, build forms, change capacity and messages.', array( 'create_ep_events', 'edit_others_ep_events', 'edit_published_ep_events', 'edit_private_ep_events', 'read_private_ep_events' ), true, false ),
			'events_publish'     => array( 'Events', 'Publish events', 'Make an event live / take it offline.', array( 'publish_ep_events' ), true, false ),
			'events_status'      => array( 'Events', 'Open / close registration', 'Switch an event to Application Full or Closed manually.', array( 'epc_change_reg_status' ), true, false ),
			'events_delete'      => array( 'Events', 'Delete events', 'Move events to Trash.', array( 'delete_ep_events', 'delete_others_ep_events', 'delete_published_ep_events', 'delete_private_ep_events' ), false, false ),

			// Microsites.
			'microsites_view'    => array( 'Microsites', 'View microsites', 'See the microsite list.', array( 'edit_ep_microsites' ), true, true ),
			'microsites_create'  => array( 'Microsites', 'Create & edit microsites', 'Build microsite pages and sections.', array( 'create_ep_microsites', 'edit_others_ep_microsites', 'edit_published_ep_microsites', 'edit_private_ep_microsites', 'read_private_ep_microsites' ), true, false ),
			'microsites_publish' => array( 'Microsites', 'Publish microsites', 'Make a microsite live.', array( 'publish_ep_microsites' ), true, false ),
			'microsites_delete'  => array( 'Microsites', 'Delete microsites', 'Move microsites to Trash.', array( 'delete_ep_microsites', 'delete_others_ep_microsites', 'delete_published_ep_microsites', 'delete_private_ep_microsites' ), false, false ),

			// Applicants.
			'applicants_view'    => array( 'Applicants', 'View applicants', 'See applicant lists and details (personal data).', array( 'edit_ep_applicants', 'epc_view_applicants' ), true, true ),
			'applicants_edit'    => array( 'Applicants', 'Edit applicants & status', 'Change answers and status (confirmed, attended, cancelled…).', array( 'edit_others_ep_applicants', 'edit_published_ep_applicants', 'edit_private_ep_applicants', 'read_private_ep_applicants', 'publish_ep_applicants' ), true, false ),
			'applicants_delete'  => array( 'Applicants', 'Delete applicants', 'Move applicants to Trash / delete permanently.', array( 'delete_ep_applicants', 'delete_others_ep_applicants', 'delete_published_ep_applicants', 'delete_private_ep_applicants' ), false, false ),
			'applicants_export'  => array( 'Applicants', 'Export applicants', 'Download applicant data as Excel / CSV / JSON.', array( 'epc_export_applicants' ), true, false ),

			// Reports.
			'reports_view'       => array( 'Reports', 'View reports', 'Visitor statistics, devices, locations, sources, conversions.', array( 'epc_view_reports' ), true, true ),
			'reports_export'     => array( 'Reports', 'Export report data', 'Download raw visit data (CSV).', array( 'epc_export_reports' ), true, false ),

			// Data.
			'data_export'        => array( 'Import / Export', 'Export content', 'Export events, microsites and settings (JSON / CSV / Excel).', array( 'epc_export' ), true, false ),
			'data_import'        => array( 'Import / Export', 'Import content', 'Import events, microsites and applicants from files.', array( 'epc_import' ), false, false ),

			// Content.
			'media'              => array( 'Content', 'Upload media', 'Upload images, videos and documents.', array( 'upload_files' ), true, false ),
			'html'               => array( 'Content', 'Custom HTML & embed codes', 'Keep scripts and embeds in the Custom HTML section (without it only safe HTML and iframes are kept).', array( 'unfiltered_html' ), false, false ),


			// Settings.
			'settings'           => array( 'Settings', 'Manage settings', 'Option lists (Salutation etc.), email sender, tracking options.', array( 'epc_manage_settings' ), false, false ),

			// Users.
			'users'              => array( 'Users', 'Manage users', 'Add / edit / remove accounts at their own level or below (never higher roles).', array( 'list_users', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'remove_users', 'epc_manage_users' ), false, false ),
			'permissions'        => array( 'Users', 'Manage permissions', 'Open this page and change permissions of roles and users ranked below their own.', array( 'epc_manage_permissions' ), false, false ),
		);
	}

	/**
	 * WordPress capabilities that only Developers may hold: posts, pages, comments, appearance, plugins,
	 * tools, settings and ACF. They are stripped from every other role and user, whatever the matrix says.
	 */
	public static function developer_only_caps(): array {
		return array(
			'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'read_private_posts', 'publish_posts',
			'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'manage_categories',
			'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'read_private_pages', 'publish_pages',
			'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages',
			'moderate_comments', 'edit_comment',
			'edit_theme_options', 'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes', 'customize',
			'activate_plugins', 'edit_plugins', 'install_plugins', 'update_plugins', 'delete_plugins',
			'manage_options', 'import', 'export', 'edit_files', 'update_core', 'edit_dashboard', 'manage_links',
		);
	}

	/** Every capability managed by this class. */
	public static function all_caps(): array {
		$caps = array();
		foreach ( self::permissions() as $p ) {
			$caps = array_merge( $caps, $p[3] );
		}
		return array_values( array_unique( $caps ) );
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_menu', array( __CLASS__, 'trim_menu' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'block_screens' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'trim_admin_bar' ), 999 );
		add_action( 'admin_post_epc_save_permissions', array( __CLASS__, 'save_matrix' ) );
		add_action( 'admin_post_epc_save_user_overrides', array( __CLASS__, 'save_user' ) );
		add_action( 'admin_post_epc_reset_permissions', array( __CLASS__, 'reset' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'hide_higher_users' ) );
		add_filter( 'views_users', array( __CLASS__, 'user_views' ) );
		add_filter( 'editable_roles', array( __CLASS__, 'limit_editable_roles' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'protect_higher_users' ), 10, 4 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );

		// Developer accounts are "ghosts": invisible to everyone who is not a Developer.
		add_filter( 'get_the_author_display_name', array( __CLASS__, 'ghost_author_name' ), 10, 2 );
		add_filter( 'rest_user_query', array( __CLASS__, 'ghost_rest_users' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'ghost_rest_user' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'ghost_author_archive' ), 0 );
		add_filter( 'oembed_response_data', array( __CLASS__, 'ghost_oembed' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Ghost developers                                                    */
	/* ------------------------------------------------------------------ */

	/** Name to show for a user: Developers appear as "System" to everyone else. */
	public static function public_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'User';
		}
		return self::is_developer( $user ) && ! self::is_developer() ? 'System' : $user->display_name;
	}

	public static function ghost_author_name( $name, $user_id ) {
		return $user_id && self::is_developer( (int) $user_id ) && ! self::is_developer() ? get_bloginfo( 'name' ) : $name;
	}

	/** REST user lists (e.g. /wp/v2/users) never include Developers for non-Developers. */
	public static function ghost_rest_users( array $args ): array {
		if ( ! self::is_developer() ) {
			$args['role__not_in'] = array_values( array_unique( array_merge( (array) ( $args['role__not_in'] ?? array() ), array( self::DEVELOPER, 'administrator' ) ) ) );
		}
		return $args;
	}

	public static function ghost_rest_user( $result, $server, WP_REST_Request $request ) {
		if ( null === $result && ! self::is_developer() && preg_match( '#^/+wp/v2/+users/+(\d+)#i', $request->get_route(), $m ) && self::is_developer( (int) $m[1] ) ) {
			return new WP_Error( 'rest_user_invalid_id', 'Invalid user ID.', array( 'status' => 404 ) );
		}
		return $result;
	}

	/** Author archives (and ?author=ID lookups) of Developers are "not found". */
	public static function ghost_author_archive(): void {
		if ( ! is_author() || self::is_developer() ) {
			return;
		}
		$author = get_queried_object();
		if ( $author instanceof WP_User && self::is_developer( $author ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			remove_action( 'template_redirect', 'redirect_canonical' );
		}
	}

	public static function ghost_oembed( array $data, WP_Post $post ): array {
		if ( self::is_developer( (int) $post->post_author ) ) {
			$data['author_name'] = get_bloginfo( 'name' );
			$data['author_url']  = home_url( '/' );
		}
		return $data;
	}

	public static function maybe_install(): void {
		if ( get_option( 'epc_roles_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		$admin = get_role( 'administrator' );

		// Developer = full WordPress administrator + every Event Plus capability.
		remove_role( self::DEVELOPER );
		add_role( self::DEVELOPER, 'Developer', $admin ? $admin->capabilities : array( 'read' => true ) );
		foreach ( array( get_role( self::DEVELOPER ), $admin ) as $role ) {
			if ( $role ) {
				foreach ( self::all_caps() as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}

		// Super Admin / Manager / Viewer start empty and get exactly what the matrix grants.
		remove_role( self::SUPER );
		add_role( self::SUPER, 'Super Admin', array( 'read' => true ) );
		if ( ! get_role( self::MANAGER ) ) {
			add_role( self::MANAGER, 'Manager', array( 'read' => true ) );
		}
		if ( ! get_role( self::VIEWER ) ) {
			add_role( self::VIEWER, 'Viewer', array( 'read' => true ) );
		}

		$matrix = self::matrix();
		update_option( 'epc_permissions', $matrix );
		self::apply_matrix( $matrix );
		self::reapply_all_overrides();
		update_option( 'epc_roles_version', self::VERSION );
	}

	public static function defaults(): array {
		$m = array();
		foreach ( self::permissions() as $key => $p ) {
			$m[ self::SUPER ][ $key ]   = true;
			$m[ self::MANAGER ][ $key ] = (bool) $p[4];
			$m[ self::VIEWER ][ $key ]  = (bool) $p[5];
		}
		return $m;
	}

	/** Saved matrix, with defaults for any role / permission added since it was saved. */
	public static function matrix(): array {
		$saved = get_option( 'epc_permissions' );
		$saved = is_array( $saved ) ? $saved : array();
		$m     = self::defaults();
		foreach ( $m as $role => $perms ) {
			foreach ( $perms as $key => $def ) {
				if ( isset( $saved[ $role ][ $key ] ) ) {
					$m[ $role ][ $key ] = (bool) $saved[ $role ][ $key ];
				}
			}
		}
		return $m;
	}

	/** Grant the union of caps of all enabled permissions; remove the rest. */
	public static function apply_matrix( array $matrix ): void {
		foreach ( array_keys( self::matrix_roles() ) as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			$grant = array( 'read' );
			foreach ( self::permissions() as $key => $p ) {
				if ( ! empty( $matrix[ $role_key ][ $key ] ) ) {
					$grant = array_merge( $grant, $p[3] );
				}
			}
			$grant = array_unique( $grant );
			foreach ( self::developer_only_caps() as $cap ) {
				$role->remove_cap( $cap );
			}
			foreach ( self::all_caps() as $cap ) {
				if ( in_array( $cap, $grant, true ) ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Who may touch whom                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Roles the current user may assign: their own level or below (so a Super Admin can add another
	 * Super Admin). WordPress's default roles and Developer are only offered to Developers.
	 */
	public static function limit_editable_roles( array $roles ): array {
		$mine = self::rank();
		if ( $mine >= 4 ) {
			return $roles;
		}
		return array_filter( $roles, fn( $r ) => self::role_rank( $r ) > 0 && self::role_rank( $r ) <= $mine, ARRAY_FILTER_USE_KEY );
	}

	/** Nobody can edit, delete or promote a user ranked above them. */
	public static function protect_higher_users( array $caps, string $cap, int $user_id, array $args ): array {
		if ( in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' ), true ) && ! empty( $args[0] ) && (int) $args[0] !== $user_id ) {
			if ( self::rank( (int) $args[0] ) > self::rank( $user_id ) ) {
				$caps[] = 'do_not_allow';
			}
		}
		return $caps;
	}

	/** Developers (and Administrators) are invisible in the user list for everyone below them. */
	public static function hide_higher_users( WP_User_Query $query ): void {
		if ( ! is_admin() || self::is_developer() || ! is_user_logged_in() ) {
			return;
		}
		$exclude = array_merge( (array) $query->get( 'role__not_in' ), array( self::DEVELOPER, 'administrator' ) );
		$query->set( 'role__not_in', array_values( array_unique( $exclude ) ) );
	}

	public static function user_views( array $views ): array {
		if ( ! self::is_developer() ) {
			unset( $views[ self::DEVELOPER ], $views['administrator'] );
			foreach ( array_keys( $views ) as $k ) {
				if ( ! in_array( $k, array( 'all' ), true ) && 0 === self::role_rank( $k ) ) {
					unset( $views[ $k ] );
				}
			}
			if ( isset( $views['all'] ) ) {
				$views['all'] = preg_replace( '/\s*<span class="count">.*?<\/span>/', '', $views['all'] );
			}
		}
		return $views;
	}

	/* ------------------------------------------------------------------ */
	/* Only show what each role is allowed                                 */
	/* ------------------------------------------------------------------ */

	/** WordPress screens that are developer-only, whatever capabilities a user has. */
	private static function developer_screens(): array {
		return array(
			'tools.php', 'import.php', 'export.php', 'export-personal-data.php', 'erase-personal-data.php', 'site-health.php',
			'edit-comments.php', 'comment.php', 'customize.php', 'site-editor.php', 'widgets.php', 'font-library.php',
			'themes.php', 'theme-install.php', 'theme-editor.php', 'nav-menus.php', 'plugins.php', 'plugin-install.php', 'plugin-editor.php',
			'update-core.php', 'update.php', 'options.php', 'options-general.php', 'options-writing.php', 'options-reading.php',
			'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options-privacy.php', 'privacy.php',
			'edit-tags.php', 'term.php', 'link-manager.php',
		);
	}

	/** Post types whose screens are developer-only. */
	private static function developer_post_type( string $type ): bool {
		return in_array( $type, array( 'post', 'page', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ), true ) || str_starts_with( $type, 'acf-' );
	}

	private static function is_developer_screen( string $pagenow ): bool {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( in_array( $pagenow, self::developer_screens(), true ) ) {
			return true;
		}
		if ( in_array( $pagenow, array( 'edit.php', 'post-new.php' ), true ) ) {
			return self::developer_post_type( sanitize_key( $_GET['post_type'] ?? '' ) ?: 'post' );
		}
		if ( 'post.php' === $pagenow ) {
			$id = absint( $_GET['post'] ?? ( $_POST['post_ID'] ?? 0 ) );
			return $id && self::developer_post_type( (string) get_post_type( $id ) );
		}
		if ( 'admin.php' === $pagenow && str_starts_with( sanitize_key( $_GET['page'] ?? '' ), 'acf' ) ) {
			return true;
		}
		return false;
		// phpcs:enable
	}

	public static function trim_menu(): void {
		if ( self::is_developer() || ! is_user_logged_in() ) {
			return;
		}
		// Only Developers see WordPress's own areas: Dashboard, Posts, Pages, Comments, Appearance,
		// Plugins, Tools, Settings and ACF. Everyone else works in Event Plus, Media, Users and Profile.
		foreach ( array( 'index.php', 'edit.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'tools.php', 'options-general.php', 'edit.php?post_type=acf-field-group' ) as $slug ) {
			remove_menu_page( $slug );
		}
	}

	public static function block_screens(): void {
		if ( self::is_developer() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		global $pagenow;
		if ( 'index.php' === $pagenow ) {
			wp_safe_redirect( current_user_can( 'epc_view_dashboard' ) ? admin_url( 'admin.php?page=epc' ) : admin_url( 'profile.php' ) );
			exit;
		}
		if ( self::is_developer_screen( $pagenow ) ) {
			wp_die( 'This area is only available to Developers.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
	}

	public static function trim_admin_bar( WP_Admin_Bar $bar ): void {
		if ( self::is_developer() ) {
			return;
		}
		foreach ( array( 'comments', 'customize', 'updates', 'themes', 'widgets', 'dashboard', 'site-editor', 'menus', 'new-post', 'new-page', 'edit' ) as $node ) {
			$bar->remove_node( $node );
		}
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		if ( $user instanceof WP_User && ! self::is_developer( $user ) && self::rank( $user ) > 0 && ( ! $requested || admin_url() === $requested ) ) {
			return admin_url( 'admin.php?page=epc' );
		}
		return $redirect;
	}

	/* ------------------------------------------------------------------ */
	/* Permissions screen                                                  */
	/* ------------------------------------------------------------------ */

	public static function menu(): void {
		add_submenu_page( 'epc', 'Permissions', 'Permissions', 'epc_manage_permissions', 'epc-permissions', array( __CLASS__, 'render' ) );
	}

	/** Users currently holding a role. */
	private static function role_users( string $role ): array {
		return get_users( array( 'role' => $role, 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'epc_manage_permissions' ) ) {
			wp_die( 'You are not allowed to manage permissions.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		$tab     = sanitize_key( $_GET['tab'] ?? 'roles' ); // phpcs:ignore
		$matrix  = self::matrix();
		$can     = self::manageable_roles();
		$is_dev  = self::is_developer();
		$cols    = self::matrix_roles();
		$groups  = array();
		foreach ( self::permissions() as $key => $p ) {
			$groups[ $p[0] ][ $key ] = $p;
		}
		$base = admin_url( 'admin.php?page=epc-permissions' );
		?>
		<div class="wrap epc-perms">
			<h1>Permissions</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Permissions saved.</p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'roles' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base ); ?>">Role permissions</a>
				<a class="nav-tab <?php echo 'users' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'users', $base ) ); ?>">User overrides</a>
				<a class="nav-tab <?php echo 'people' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'people', $base ) ); ?>">Who has which role</a>
			</nav>

			<?php if ( 'roles' === $tab ) : ?>
				<p class="description">
					Tick what each role may do. Each role only sees the sections it is allowed.
					<?php if ( $is_dev ) : ?>
						<strong>Developer</strong> always has everything, plus the technical areas (plugins, themes, field groups, WordPress settings, tools, updates).
					<?php else : ?>
						You can change roles below your own; greyed-out columns are managed by a Developer.
					<?php endif; ?>
					Changes apply immediately (per-user overrides still win).
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="epc_save_permissions">
					<?php wp_nonce_field( 'epc_permissions' ); ?>
					<div class="epc-matrix-wrap">
					<table class="widefat epc-matrix">
						<thead><tr><th>Permission</th>
						<?php if ( $is_dev ) : ?><th class="c">Developer</th><?php endif; ?>
						<?php foreach ( $cols as $rk => $rl ) : ?>
							<th class="c"><?php echo esc_html( $rl ); ?>
								<?php if ( isset( $can[ $rk ] ) ) : ?><br><button type="button" class="button-link epc-col-toggle" data-role="<?php echo esc_attr( $rk ); ?>">toggle all</button><?php else : ?><br><span class="epc-muted">locked</span><?php endif; ?>
							</th>
						<?php endforeach; ?>
						</tr></thead>
						<tbody>
						<?php $span = 1 + count( $cols ) + ( $is_dev ? 1 : 0 ); ?>
						<?php foreach ( $groups as $group => $perms ) : ?>
							<tr class="epc-matrix-group"><th colspan="<?php echo (int) $span; ?>"><?php echo esc_html( $group ); ?></th></tr>
							<?php foreach ( $perms as $key => $p ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $p[1] ); ?></strong><br><span class="description"><?php echo esc_html( $p[2] ); ?></span></td>
									<?php if ( $is_dev ) : ?><td class="c"><input type="checkbox" checked disabled title="Always allowed"></td><?php endif; ?>
									<?php foreach ( array_keys( $cols ) as $rk ) : ?>
										<td class="c"><input type="checkbox" data-role="<?php echo esc_attr( $rk ); ?>" name="perm[<?php echo esc_attr( $rk ); ?>][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $matrix[ $rk ][ $key ] ) ); ?> <?php disabled( ! isset( $can[ $rk ] ) || ! self::holds( $key ) ); ?> <?php echo self::holds( $key ) ? '' : 'title="You do not have this permission yourself"'; ?>></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
						<?php if ( $is_dev ) : ?>
							<tr class="epc-matrix-group"><th colspan="<?php echo (int) $span; ?>">Developer only (never in the matrix)</th></tr>
							<tr><td><strong>Technical areas</strong><br><span class="description">Plugins, themes, ACF field groups, WordPress settings, tools, updates, comments, site health.</span></td><td class="c"><input type="checkbox" checked disabled></td>
							<?php foreach ( $cols as $rk => $rl ) : ?><td class="c"><input type="checkbox" disabled title="Developer only"></td><?php endforeach; ?></tr>
						<?php endif; ?>
						</tbody>
					</table>
					</div>
					<?php if ( $can ) : ?>
						<p class="submit">
							<button class="button button-primary">Save permissions</button>
							<a class="button" onclick="return confirm('Reset <?php echo esc_js( implode( ', ', $can ) ); ?> to default permissions?');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=epc_reset_permissions' ), 'epc_reset_permissions' ) ); ?>">Reset to defaults</a>
						</p>
					<?php endif; ?>
				</form>
				<script>
				document.querySelectorAll('.epc-col-toggle').forEach(b => b.addEventListener('click', () => {
					const boxes = [...document.querySelectorAll('input[data-role="' + b.dataset.role + '"]:not(:disabled)')];
					const on = boxes.some(x => !x.checked);
					boxes.forEach(x => x.checked = on);
				}));
				</script>

			<?php elseif ( 'users' === $tab ) : ?>
				<?php
				$uid   = absint( $_GET['user'] ?? 0 ); // phpcs:ignore
				$users = $can ? get_users( array( 'role__in' => array_keys( $can ), 'orderby' => 'display_name' ) ) : array();
				$users = array_filter( $users, fn( $u ) => self::rank( $u ) < self::rank() );
				?>
				<p class="description">Give an individual user extra permissions, or take some away, without changing their role. You can only adjust users ranked below you.</p>
				<form method="get">
					<input type="hidden" name="page" value="epc-permissions"><input type="hidden" name="tab" value="users">
					<select name="user" onchange="this.form.submit()">
						<option value="">— Choose a user (<?php echo esc_html( implode( ' / ', $can ) ); ?>) —</option>
						<?php foreach ( $users as $u ) : ?>
							<option value="<?php echo (int) $u->ID; ?>" <?php selected( $uid, $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ') — ' . implode( ', ', array_map( fn( $r ) => $cols[ $r ] ?? $r, $u->roles ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
				<?php
				$user = $uid ? get_userdata( $uid ) : null;
				if ( $user && self::rank( $user ) < self::rank() && self::rank( $user ) > 0 ) :
					$over = (array) get_user_meta( $uid, 'epc_overrides', true );
					$role = array_values( array_intersect( array_keys( $cols ), $user->roles ) )[0] ?? '';
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="epc_save_user_overrides">
						<input type="hidden" name="user" value="<?php echo (int) $uid; ?>">
						<?php wp_nonce_field( 'epc_user_overrides' ); ?>
						<div class="epc-matrix-wrap">
						<table class="widefat epc-matrix">
							<thead><tr><th>Permission</th><th class="c">From role (<?php echo esc_html( $cols[ $role ] ?? '—' ); ?>)</th><th class="c">Override</th><th class="c">Effective</th></tr></thead>
							<tbody>
							<?php foreach ( $groups as $group => $perms ) : ?>
								<tr class="epc-matrix-group"><th colspan="4"><?php echo esc_html( $group ); ?></th></tr>
								<?php
								foreach ( $perms as $key => $p ) :
									$from = ! empty( $matrix[ $role ][ $key ] );
									$o    = $over[ $key ] ?? 'inherit';
									$eff  = 'allow' === $o ? true : ( 'deny' === $o ? false : $from );
									?>
									<tr>
										<td><strong><?php echo esc_html( $p[1] ); ?></strong></td>
										<td class="c"><?php echo $from ? '<span class="epc-yes">✔</span>' : '<span class="epc-no">—</span>'; ?></td>
										<td class="c">
											<select name="ov[<?php echo esc_attr( $key ); ?>]" <?php disabled( ! self::holds( $key ) ); ?>>
												<option value="inherit" <?php selected( $o, 'inherit' ); ?>>Use role</option>
												<option value="allow" <?php selected( $o, 'allow' ); ?>>Allow</option>
												<option value="deny" <?php selected( $o, 'deny' ); ?>>Deny</option>
											</select>
										</td>
										<td class="c"><?php echo $eff ? '<span class="epc-yes">Allowed</span>' : '<span class="epc-no">Denied</span>'; ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
						<p class="submit"><button class="button button-primary">Save overrides</button></p>
					</form>
				<?php elseif ( $user ) : ?>
					<p>You can only adjust users ranked below you.</p>
				<?php endif; ?>

			<?php else : ?>
				<?php
				$cards = $cols;
				if ( $is_dev ) {
					$cards = array( self::DEVELOPER => 'Developer', 'administrator' => 'Administrator (= Developer)' ) + $cards;
				}
				?>
				<div class="epc-grid-3">
					<?php foreach ( $cards as $rk => $rl ) : ?>
						<div class="epc-card">
							<h2><?php echo esc_html( $rl ); ?></h2>
							<?php $list = self::role_users( $rk ); ?>
							<?php if ( ! $list ) : ?>
								<p class="epc-muted">Nobody yet.</p>
							<?php else : ?>
								<ul>
								<?php foreach ( $list as $u ) : ?>
									<li><a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a> <span class="epc-muted"><?php echo esc_html( $u->user_email ); ?></span>
									<?php if ( get_user_meta( $u->ID, 'epc_overrides', true ) ) : ?> <span class="epc-badge epc-badge--waitlist">overrides</span><?php endif; ?></li>
								<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( current_user_can( 'create_users' ) ) : ?>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>">Add user</a></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Does the current user hold this permission? Nobody can hand out a permission they do not have. */
	public static function holds( string $key ): bool {
		if ( self::is_developer() ) {
			return true;
		}
		$perm = self::permissions()[ $key ] ?? null;
		if ( ! $perm ) {
			return false;
		}
		foreach ( (array) $perm[3] as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return false;
			}
		}
		return true;
	}

	public static function save_matrix(): void {
		check_admin_referer( 'epc_permissions' );
		if ( ! current_user_can( 'epc_manage_permissions' ) ) {
			wp_die( 'You are not allowed to manage permissions.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		$in     = (array) ( $_POST['perm'] ?? array() );
		$matrix = self::matrix();
		foreach ( array_keys( self::manageable_roles() ) as $rk ) {
			foreach ( array_keys( self::permissions() ) as $key ) {
				if ( self::holds( $key ) ) { // Others stay as they are.
					$matrix[ $rk ][ $key ] = ! empty( $in[ $rk ][ $key ] );
				}
			}
		}
		update_option( 'epc_permissions', $matrix );
		self::apply_matrix( $matrix );
		self::reapply_all_overrides();
		wp_safe_redirect( admin_url( 'admin.php?page=epc-permissions&saved=1' ) );
		exit;
	}

	public static function reset(): void {
		check_admin_referer( 'epc_reset_permissions' );
		if ( ! current_user_can( 'epc_manage_permissions' ) ) {
			wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		$matrix   = self::matrix();
		$defaults = self::defaults();
		foreach ( array_keys( self::manageable_roles() ) as $rk ) {
			$matrix[ $rk ] = $defaults[ $rk ];
		}
		update_option( 'epc_permissions', $matrix );
		self::apply_matrix( $matrix );
		self::reapply_all_overrides();
		wp_safe_redirect( admin_url( 'admin.php?page=epc-permissions&saved=1' ) );
		exit;
	}

	public static function save_user(): void {
		check_admin_referer( 'epc_user_overrides' );
		if ( ! current_user_can( 'epc_manage_permissions' ) ) {
			wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		$uid  = absint( $_POST['user'] ?? 0 );
		$user = get_userdata( $uid );
		if ( ! $user || self::rank( $user ) < 1 || self::rank( $user ) >= self::rank() ) {
			wp_die( 'You can only adjust users ranked below you.', 'Not allowed', array( 'response' => 403, 'back_link' => true ) );
		}
		$old  = (array) get_user_meta( $uid, 'epc_overrides', true );
		$over = array();
		foreach ( (array) ( $_POST['ov'] ?? array() ) as $key => $v ) {
			$key = sanitize_key( $key );
			if ( isset( self::permissions()[ $key ] ) && in_array( $v, array( 'allow', 'deny' ), true ) ) {
				$over[ $key ] = $v;
			}
		}
		// Permissions the current user does not hold keep their previous override.
		foreach ( array_keys( self::permissions() ) as $key ) {
			if ( ! self::holds( $key ) ) {
				unset( $over[ $key ] );
				if ( isset( $old[ $key ] ) ) {
					$over[ $key ] = $old[ $key ];
				}
			}
		}
		update_user_meta( $uid, 'epc_overrides', $over );
		self::apply_user_overrides( $user, $over );
		wp_safe_redirect( admin_url( 'admin.php?page=epc-permissions&tab=users&saved=1&user=' . $uid ) );
		exit;
	}

	/** Translate overrides into user-level caps (true = grant, false = explicit deny). */
	public static function apply_user_overrides( WP_User $user, array $over ): void {
		foreach ( array_merge( self::all_caps(), self::developer_only_caps() ) as $cap ) {
			if ( array_key_exists( $cap, $user->caps ) && ! wp_roles()->is_role( $cap ) ) {
				$user->remove_cap( $cap );
			}
		}
		foreach ( array( 'allow' => true, 'deny' => false ) as $mode => $grant ) {
			foreach ( $over as $key => $v ) {
				if ( $v !== $mode || ! isset( self::permissions()[ $key ] ) ) {
					continue;
				}
				foreach ( self::permissions()[ $key ][3] as $cap ) {
					if ( 'read' === $cap ) {
						continue;
					}
					$user->add_cap( $cap, $grant );
				}
			}
		}
	}

	private static function reapply_all_overrides(): void {
		foreach ( get_users( array( 'meta_key' => 'epc_overrides', 'fields' => 'all' ) ) as $u ) {
			self::apply_user_overrides( $u, (array) get_user_meta( $u->ID, 'epc_overrides', true ) );
		}
	}
}

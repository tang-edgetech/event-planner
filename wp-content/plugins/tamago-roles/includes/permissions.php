<?php
/**
 * Permission catalogue: friendly permissions mapped to capabilities.
 * Sources: the tamago_permissions filter, built-in WordPress groups, and automatic groups for
 * post types and taxonomies that use their own capabilities (including ACF-registered ones).
 *
 * Used by: matrix screen, overrides, role manager, capabilities filter, CLI, export.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Permissions {

	private static $cache = null;

	public static function init(): void {
		add_filter( 'tamago_permissions', array( __CLASS__, 'own_permissions' ), 5 );
		// Post types and taxonomies registered late (ACF, plugins) must be included.
		add_action( 'registered_post_type', array( __CLASS__, 'flush' ) );
		add_action( 'registered_taxonomy', array( __CLASS__, 'flush' ) );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** This plugin's own capabilities, registered like any other Tamago plugin's. */
	public static function own_permissions( array $p ): array {
		$p['tmg_roles_manage']       = array(
			'group'       => __( 'Roles & Permissions', 'tamago-roles' ),
			'label'       => __( 'Manage roles & permissions', 'tamago-roles' ),
			'description' => __( 'Open the Roles & Permissions screen and change roles ranked below their own.', 'tamago-roles' ),
			'caps'        => array( TMG_Roles::CAP_MANAGE ),
		);
		$p['tmg_roles_manage_users'] = array(
			'group'       => __( 'Roles & Permissions', 'tamago-roles' ),
			'label'       => __( 'Manage per-user permissions', 'tamago-roles' ),
			'description' => __( 'Grant or deny individual permissions to users ranked below their own.', 'tamago-roles' ),
			'caps'        => array( TMG_Roles::CAP_MANAGE_USERS ),
		);
		return $p;
	}

	/**
	 * Every permission: key => [ group, label, description, caps[], source ].
	 * source: registered | wordpress | post_type | taxonomy.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$out = array();

		/**
		 * Tamago shared filter: plugins and themes register friendly permissions here.
		 *
		 * @param array $permissions key => [ group, label, description, caps[] ].
		 */
		$registered = apply_filters( 'tamago_permissions', array() );
		$covered    = array();
		foreach ( (array) $registered as $key => $p ) {
			$key  = sanitize_key( (string) $key );
			$caps = TMG_Roles_Settings::cap_list( (array) ( $p['caps'] ?? array() ) );
			if ( '' === $key || ! $caps ) {
				continue;
			}
			$out[ $key ] = array(
				'group'       => (string) ( $p['group'] ?? __( 'Other', 'tamago-roles' ) ),
				'label'       => (string) ( $p['label'] ?? $key ),
				'description' => (string) ( $p['description'] ?? '' ),
				'caps'        => $caps,
				'source'      => 'registered',
			);
			$covered = array_merge( $covered, $caps );
		}

		foreach ( self::wordpress() as $key => $p ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $p + array( 'source' => 'wordpress' );
			}
			// Post types that reuse core caps (e.g. ACF field groups use manage_options) need no group.
			$covered = array_merge( $covered, $p['caps'] );
		}
		foreach ( self::post_type_permissions( $covered ) + self::taxonomy_permissions( $covered ) as $key => $p ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $p;
			}
		}
		if ( did_action( 'init' ) ) {
			self::$cache = $out;
		}
		return $out;
	}

	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/** Permissions grouped: group => [ key => permission ]. */
	public static function grouped(): array {
		$groups = array();
		foreach ( self::all() as $key => $p ) {
			$groups[ $p['group'] ][ $key ] = $p;
		}
		return $groups;
	}

	private static function p( string $group, string $label, string $desc, array $caps ): array {
		return array( 'group' => $group, 'label' => $label, 'description' => $desc, 'caps' => $caps );
	}

	/** Built-in WordPress groups mapped to core capabilities. */
	public static function wordpress(): array {
		$g = array(
			'wp_read'               => self::p( __( 'WordPress: General', 'tamago-roles' ), __( 'Log in to the dashboard', 'tamago-roles' ), __( 'Basic "read" capability: dashboard and own profile.', 'tamago-roles' ), array( 'read' ) ),
			'wp_posts_write'        => self::p( __( 'WordPress: Posts', 'tamago-roles' ), __( 'Write posts', 'tamago-roles' ), __( 'Create and edit their own posts.', 'tamago-roles' ), array( 'edit_posts' ) ),
			'wp_posts_publish'      => self::p( __( 'WordPress: Posts', 'tamago-roles' ), __( 'Publish posts', 'tamago-roles' ), __( 'Publish and edit published posts.', 'tamago-roles' ), array( 'publish_posts', 'edit_published_posts' ) ),
			'wp_posts_others'       => self::p( __( 'WordPress: Posts', 'tamago-roles' ), __( 'Edit others\' posts', 'tamago-roles' ), __( 'Edit posts written by other users, including private ones.', 'tamago-roles' ), array( 'edit_others_posts', 'edit_private_posts', 'read_private_posts' ) ),
			'wp_posts_delete'       => self::p( __( 'WordPress: Posts', 'tamago-roles' ), __( 'Delete posts', 'tamago-roles' ), __( 'Delete own posts (published too).', 'tamago-roles' ), array( 'delete_posts', 'delete_published_posts' ) ),
			'wp_posts_delete_other' => self::p( __( 'WordPress: Posts', 'tamago-roles' ), __( 'Delete others\' posts', 'tamago-roles' ), __( 'Delete posts by other users, including private ones.', 'tamago-roles' ), array( 'delete_others_posts', 'delete_private_posts' ) ),
			'wp_categories'         => self::p( __( 'WordPress: Posts', 'tamago-roles' ), __( 'Manage categories & tags', 'tamago-roles' ), __( 'Create, edit and delete categories and tags.', 'tamago-roles' ), array( 'manage_categories' ) ),
			'wp_pages_edit'         => self::p( __( 'WordPress: Pages', 'tamago-roles' ), __( 'Edit pages', 'tamago-roles' ), __( 'Create and edit their own pages.', 'tamago-roles' ), array( 'edit_pages' ) ),
			'wp_pages_publish'      => self::p( __( 'WordPress: Pages', 'tamago-roles' ), __( 'Publish pages', 'tamago-roles' ), __( 'Publish and edit published pages.', 'tamago-roles' ), array( 'publish_pages', 'edit_published_pages' ) ),
			'wp_pages_others'       => self::p( __( 'WordPress: Pages', 'tamago-roles' ), __( 'Edit others\' pages', 'tamago-roles' ), __( 'Edit pages by other users, including private ones.', 'tamago-roles' ), array( 'edit_others_pages', 'edit_private_pages', 'read_private_pages' ) ),
			'wp_pages_delete'       => self::p( __( 'WordPress: Pages', 'tamago-roles' ), __( 'Delete pages', 'tamago-roles' ), __( 'Delete pages (own, others\', published, private).', 'tamago-roles' ), array( 'delete_pages', 'delete_published_pages', 'delete_others_pages', 'delete_private_pages' ) ),
			'wp_media'              => self::p( __( 'WordPress: Media', 'tamago-roles' ), __( 'Upload media', 'tamago-roles' ), __( 'Upload images, videos and documents.', 'tamago-roles' ), array( 'upload_files' ) ),
			'wp_unfiltered_upload'  => self::p( __( 'WordPress: Media', 'tamago-roles' ), __( 'Upload any file type', 'tamago-roles' ), __( 'Skip the file-type check on uploads.', 'tamago-roles' ), array( 'unfiltered_upload' ) ),
			'wp_unfiltered_html'    => self::p( __( 'WordPress: Media', 'tamago-roles' ), __( 'Unfiltered HTML', 'tamago-roles' ), __( 'Post scripts and embed codes without filtering.', 'tamago-roles' ), array( 'unfiltered_html' ) ),
			'wp_comments'           => self::p( __( 'WordPress: Comments', 'tamago-roles' ), __( 'Moderate comments', 'tamago-roles' ), __( 'Approve, edit and delete comments.', 'tamago-roles' ), array( 'moderate_comments' ) ),
			'wp_theme_options'      => self::p( __( 'WordPress: Appearance', 'tamago-roles' ), __( 'Menus, widgets & customizer', 'tamago-roles' ), __( 'Edit menus, widgets and theme options.', 'tamago-roles' ), array( 'edit_theme_options' ) ),
			'wp_themes'             => self::p( __( 'WordPress: Appearance', 'tamago-roles' ), __( 'Switch & manage themes', 'tamago-roles' ), __( 'Activate, install, update and delete themes.', 'tamago-roles' ), array( 'switch_themes', 'install_themes', 'update_themes', 'delete_themes' ) ),
			'wp_theme_editor'       => self::p( __( 'WordPress: Appearance', 'tamago-roles' ), __( 'Edit theme files', 'tamago-roles' ), __( 'Use the theme file editor.', 'tamago-roles' ), array( 'edit_themes' ) ),
			'wp_plugins'            => self::p( __( 'WordPress: Plugins', 'tamago-roles' ), __( 'Activate plugins', 'tamago-roles' ), __( 'Activate and deactivate plugins.', 'tamago-roles' ), array( 'activate_plugins' ) ),
			'wp_plugins_install'    => self::p( __( 'WordPress: Plugins', 'tamago-roles' ), __( 'Install & delete plugins', 'tamago-roles' ), __( 'Install, update and delete plugins.', 'tamago-roles' ), array( 'install_plugins', 'update_plugins', 'delete_plugins' ) ),
			'wp_plugin_editor'      => self::p( __( 'WordPress: Plugins', 'tamago-roles' ), __( 'Edit plugin files', 'tamago-roles' ), __( 'Use the plugin file editor.', 'tamago-roles' ), array( 'edit_plugins' ) ),
			'wp_users_list'         => self::p( __( 'WordPress: Users', 'tamago-roles' ), __( 'View users', 'tamago-roles' ), __( 'See the user list.', 'tamago-roles' ), array( 'list_users' ) ),
			'wp_users_create'       => self::p( __( 'WordPress: Users', 'tamago-roles' ), __( 'Add users', 'tamago-roles' ), __( 'Create new accounts (only with roles below their own).', 'tamago-roles' ), array( 'create_users' ) ),
			'wp_users_edit'         => self::p( __( 'WordPress: Users', 'tamago-roles' ), __( 'Edit users & change roles', 'tamago-roles' ), __( 'Edit accounts ranked below their own and change their roles.', 'tamago-roles' ), array( 'edit_users', 'promote_users' ) ),
			'wp_users_delete'       => self::p( __( 'WordPress: Users', 'tamago-roles' ), __( 'Delete users', 'tamago-roles' ), __( 'Delete accounts ranked below their own.', 'tamago-roles' ), array( 'delete_users', 'remove_users' ) ),
			'wp_tools'              => self::p( __( 'WordPress: Tools', 'tamago-roles' ), __( 'Import & export', 'tamago-roles' ), __( 'WordPress importers and the export tool.', 'tamago-roles' ), array( 'import', 'export' ) ),
			'wp_settings'           => self::p( __( 'WordPress: Settings', 'tamago-roles' ), __( 'Manage WordPress settings', 'tamago-roles' ), __( 'All Settings screens, site health and privacy tools.', 'tamago-roles' ), array( 'manage_options' ) ),
			'wp_links'              => self::p( __( 'WordPress: Settings', 'tamago-roles' ), __( 'Manage links & dashboard', 'tamago-roles' ), __( 'Legacy link manager and dashboard settings.', 'tamago-roles' ), array( 'manage_links', 'edit_dashboard' ) ),
			'wp_updates'            => self::p( __( 'WordPress: Updates', 'tamago-roles' ), __( 'Update WordPress', 'tamago-roles' ), __( 'Run core updates.', 'tamago-roles' ), array( 'update_core' ) ),
			'wp_files'              => self::p( __( 'WordPress: Updates', 'tamago-roles' ), __( 'Edit files', 'tamago-roles' ), __( 'File editing on the server.', 'tamago-roles' ), array( 'edit_files' ) ),
		);
		if ( is_multisite() ) {
			$g['wp_network']       = self::p( __( 'WordPress: Multisite', 'tamago-roles' ), __( 'Manage the network', 'tamago-roles' ), __( 'Network admin, options and upgrades.', 'tamago-roles' ), array( 'manage_network', 'manage_network_options', 'upgrade_network', 'setup_network' ) );
			$g['wp_network_sites'] = self::p( __( 'WordPress: Multisite', 'tamago-roles' ), __( 'Manage sites', 'tamago-roles' ), __( 'Create, edit and delete sites.', 'tamago-roles' ), array( 'manage_sites', 'create_sites', 'delete_sites' ) );
			$g['wp_network_users'] = self::p( __( 'WordPress: Multisite', 'tamago-roles' ), __( 'Manage network users', 'tamago-roles' ), __( 'Network users screen.', 'tamago-roles' ), array( 'manage_network_users' ) );
			$g['wp_network_ext']   = self::p( __( 'WordPress: Multisite', 'tamago-roles' ), __( 'Manage network plugins & themes', 'tamago-roles' ), __( 'Network-wide plugins and themes.', 'tamago-roles' ), array( 'manage_network_plugins', 'manage_network_themes' ) );
		}
		return $g;
	}

	/** Primitive capabilities of a post type (meta caps like edit_post are not stored on roles). */
	private static function primitive( array $caps ): array {
		return array_values( array_unique( array_filter( $caps, fn( $c ) => is_string( $c ) && '' !== $c && 'read' !== $c ) ) );
	}

	/**
	 * Automatic groups for every post type whose capabilities differ from posts/pages.
	 * Skipped when a registered permission already covers its "edit" capability.
	 */
	public static function post_type_permissions( array $covered = array() ): array {
		$out     = array();
		$post    = get_post_type_object( 'post' );
		$page    = get_post_type_object( 'page' );
		$builtin = array_merge( $post ? array_values( (array) $post->cap ) : array(), $page ? array_values( (array) $page->cap ) : array() );
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type => $pto ) {
			if ( in_array( $type, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}
			$cap = (array) $pto->cap;
			if ( empty( $cap['edit_posts'] ) || in_array( $cap['edit_posts'], array( 'edit_posts', 'edit_pages' ), true ) || in_array( $cap['edit_posts'], $covered, true ) ) {
				continue;
			}
			if ( in_array( $cap['edit_posts'], $builtin, true ) ) {
				continue;
			}
			$group = sprintf( /* translators: post type name */ __( 'Content: %s', 'tamago-roles' ), $pto->labels->name );
			$k     = 'pt_' . $type;
			$sets  = array(
				'view'        => array( __( 'View list', 'tamago-roles' ), __( 'See the list in the admin and edit their own drafts.', 'tamago-roles' ), array( $cap['edit_posts'] ) ),
				'create'      => array( __( 'Create', 'tamago-roles' ), __( 'Add new items.', 'tamago-roles' ), array( $cap['create_posts'] ?? $cap['edit_posts'], $cap['edit_posts'] ) ),
				'edit_others' => array( __( 'Edit others\' & published', 'tamago-roles' ), __( 'Edit items of other users, published and private items.', 'tamago-roles' ), array( $cap['edit_others_posts'] ?? '', $cap['edit_published_posts'] ?? '', $cap['edit_private_posts'] ?? '', $cap['read_private_posts'] ?? '' ) ),
				'publish'     => array( __( 'Publish', 'tamago-roles' ), __( 'Make items live.', 'tamago-roles' ), array( $cap['publish_posts'] ?? '' ) ),
				'delete'      => array( __( 'Delete', 'tamago-roles' ), __( 'Delete items (own, others\', published, private).', 'tamago-roles' ), array( $cap['delete_posts'] ?? '', $cap['delete_others_posts'] ?? '', $cap['delete_published_posts'] ?? '', $cap['delete_private_posts'] ?? '' ) ),
			);
			foreach ( $sets as $suffix => $s ) {
				$caps = self::primitive( $s[2] );
				if ( $caps ) {
					$out[ $k . '_' . $suffix ] = array( 'group' => $group, 'label' => $s[0], 'description' => $s[1], 'caps' => $caps, 'source' => 'post_type' );
				}
			}
		}
		return $out;
	}

	/** Automatic groups for taxonomies with their own capabilities. */
	public static function taxonomy_permissions( array $covered = array() ): array {
		$out     = array();
		$default = array( 'manage_categories', 'edit_posts', 'edit_pages', '' );
		foreach ( get_taxonomies( array( 'show_ui' => true, '_builtin' => false ), 'objects' ) as $tax => $tobj ) {
			$cap = (array) $tobj->cap;
			$own = array_diff( array_filter( array( $cap['manage_terms'] ?? '', $cap['edit_terms'] ?? '', $cap['delete_terms'] ?? '', $cap['assign_terms'] ?? '' ) ), $default );
			if ( ! $own || ! array_diff( $own, $covered ) ) {
				continue;
			}
			$group = sprintf( /* translators: taxonomy name */ __( 'Taxonomy: %s', 'tamago-roles' ), $tobj->labels->name );
			$sets  = array(
				'manage' => array( __( 'Manage terms', 'tamago-roles' ), $cap['manage_terms'] ?? '' ),
				'edit'   => array( __( 'Edit terms', 'tamago-roles' ), $cap['edit_terms'] ?? '' ),
				'delete' => array( __( 'Delete terms', 'tamago-roles' ), $cap['delete_terms'] ?? '' ),
				'assign' => array( __( 'Assign terms', 'tamago-roles' ), $cap['assign_terms'] ?? '' ),
			);
			foreach ( $sets as $suffix => $s ) {
				if ( '' === $s[1] || in_array( $s[1], $default, true ) ) {
					continue;
				}
				$out[ 'tax_' . $tax . '_' . $suffix ] = array( 'group' => $group, 'label' => $s[0], 'description' => '', 'caps' => array( $s[1] ), 'source' => 'taxonomy' );
			}
		}
		return $out;
	}

	/** Every known capability: all roles, permissions, post types, taxonomies and custom ones. Sorted. */
	public static function known_caps(): array {
		$caps = array();
		foreach ( wp_roles()->roles as $data ) {
			$caps = array_merge( $caps, array_keys( (array) $data['capabilities'] ) );
		}
		foreach ( self::all() as $p ) {
			$caps = array_merge( $caps, $p['caps'] );
		}
		foreach ( get_post_types( array(), 'objects' ) as $pto ) {
			$caps = array_merge( $caps, array_values( array_diff_key( (array) $pto->cap, array_flip( array( 'edit_post', 'read_post', 'delete_post' ) ) ) ) );
		}
		foreach ( get_taxonomies( array( '_builtin' => false ), 'objects' ) as $tobj ) {
			$caps = array_merge( $caps, array_values( (array) $tobj->cap ) );
		}
		$caps = array_merge( $caps, (array) TMG_Roles_Settings::get( 'custom_caps' ), (array) TMG_Roles_Settings::get( 'protected_caps' ) );
		$caps = array_filter(
			array_unique( array_map( 'strval', $caps ) ),
			function ( $c ) {
				// Role names stored as caps and level_N leftovers are not real capabilities.
				return '' !== $c && ! wp_roles()->is_role( $c ) && ! preg_match( '/^level_\d+$/', $c );
			}
		);
		sort( $caps );
		return array_values( $caps );
	}

	/** Does the role grant every capability of the permission? */
	public static function role_has( string $role, string $key ): bool {
		$p    = self::get( $key );
		$role = get_role( $role );
		if ( ! $p || ! $role ) {
			return false;
		}
		foreach ( $p['caps'] as $cap ) {
			if ( empty( $role->capabilities[ $cap ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Does the user (after roles, overrides and protection) hold every capability of the permission? */
	public static function user_has( $user, string $key ): bool {
		$p    = self::get( $key );
		$user = TMG_Roles_Ranks::resolve_user( $user );
		if ( ! $p || ! $user ) {
			return false;
		}
		foreach ( $p['caps'] as $cap ) {
			if ( ! TMG_Roles_Capabilities::holds( $user, $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/** Do the user's roles (without overrides) grant every capability of the permission? */
	public static function roles_grant( WP_User $user, string $key ): bool {
		foreach ( (array) $user->roles as $role ) {
			if ( self::role_has( (string) $role, $key ) ) {
				return true;
			}
		}
		return false;
	}
}

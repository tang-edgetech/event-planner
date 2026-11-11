<?php
/**
 * The Roles & Permissions screen shell: menu entry (Users by default, filterable through
 * tamago_menu_parent), tabs, assets, notices and the shared form helpers used by the tab files.
 *
 * Screens: Roles & Permissions (tabs Roles · Permissions · Users · Admin menus · Content access · Settings · Log).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Admin_Screen {

	private static $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'editor_assets' ) );
		TMG_Roles_Screen_Roles::init();
		TMG_Roles_Screen_Permissions::init();
		TMG_Roles_Screen_Users::init();
		TMG_Roles_Screen_Menus::init();
		TMG_Roles_Screen_Content::init();
		TMG_Roles_Screen_Settings::init();
		TMG_Roles_Screen_Log::init();
	}

	public static function parent(): string {
		return (string) apply_filters( 'tamago_menu_parent', '', 'roles' );
	}

	/** Menu IDs of this screen as used in menu rules: "slug" when top-level, else "parent" and "parent|slug". */
	public static function menu_ids(): array {
		$parent = self::parent();
		return '' === $parent ? array( TMG_Roles::PAGE ) : array( $parent, $parent . '|' . TMG_Roles::PAGE );
	}

	public static function tabs(): array {
		$tabs = array(
			'roles'       => array( __( 'Roles', 'tamago-roles' ), TMG_Roles::CAP_MANAGE ),
			'permissions' => array( __( 'Permissions', 'tamago-roles' ), TMG_Roles::CAP_MANAGE ),
			'users'       => array( __( 'Users', 'tamago-roles' ), TMG_Roles::CAP_MANAGE_USERS ),
			'menus'       => array( __( 'Admin menus', 'tamago-roles' ), TMG_Roles::CAP_MANAGE ),
			'content'     => array( __( 'Content access', 'tamago-roles' ), TMG_Roles::CAP_MANAGE ),
			'settings'    => array( __( 'Settings', 'tamago-roles' ), TMG_Roles::CAP_MANAGE ),
			'log'         => array( __( 'Log', 'tamago-roles' ), TMG_Roles::CAP_MANAGE ),
		);
		return array_filter( $tabs, fn( $t ) => current_user_can( $t[1] ) );
	}

	public static function menu(): void {
		$cap        = current_user_can( TMG_Roles::CAP_MANAGE ) ? TMG_Roles::CAP_MANAGE : TMG_Roles::CAP_MANAGE_USERS;
		$title = __( 'Roles & Permissions', 'tamago-roles' );
		if ( '' === self::parent() ) {
			// Own top-level menu; position filterable through tamago_menu_position.
			$position   = apply_filters( 'tamago_menu_position', 70.1, 'roles' );
			self::$hook = (string) add_menu_page( $title, $title, $cap, TMG_Roles::PAGE, array( __CLASS__, 'render' ), 'dashicons-shield', $position );
		} else {
			self::$hook = (string) add_submenu_page( self::parent(), $title, $title, $cap, TMG_Roles::PAGE, array( __CLASS__, 'render' ) );
		}
	}

	public static function assets( $hook ): void {
		if ( self::$hook !== $hook && false === strpos( (string) $hook, TMG_Roles::PAGE ) && ! in_array( $hook, array( 'user-edit.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'tmg-roles-admin', TMG_ROLES_URL . 'assets/css/admin.css', array(), TMG_ROLES_VERSION );
		wp_enqueue_script( 'tmg-roles-admin', TMG_ROLES_URL . 'assets/js/admin.js', array(), TMG_ROLES_VERSION, true );
	}

	/** Small helper script for the "Who can view" fields (post editor, term screens, menus). */
	public static function editor_assets( $hook ): void {
		if ( in_array( $hook, array( 'post.php', 'post-new.php', 'term.php', 'nav-menus.php' ), true ) ) {
			wp_enqueue_script( 'tmg-roles-admin', TMG_ROLES_URL . 'assets/js/admin.js', array(), TMG_ROLES_VERSION, true );
			wp_enqueue_style( 'tmg-roles-admin', TMG_ROLES_URL . 'assets/css/admin.css', array(), TMG_ROLES_VERSION );
		}
	}

	public static function current_tab(): string {
		$tabs = self::tabs();
		$tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = (string) array_key_first( $tabs );
		}
		return $tab;
	}

	public static function render(): void {
		$tabs = self::tabs();
		if ( ! $tabs ) {
			wp_die( esc_html__( 'You are not allowed to manage roles.', 'tamago-roles' ), '', array( 'response' => 403 ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap tmg-roles">
			<h1><?php esc_html_e( 'Roles & Permissions', 'tamago-roles' ); ?></h1>
			<?php self::notices(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $t ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( TMG_Roles::screen_url( array( 'tab' => $key ) ) ); ?>"><?php echo esc_html( $t[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="tmg-roles-body">
			<?php
			switch ( $tab ) {
				case 'roles':
					TMG_Roles_Screen_Roles::render();
					break;
				case 'permissions':
					TMG_Roles_Screen_Permissions::render();
					break;
				case 'users':
					TMG_Roles_Screen_Users::render();
					break;
				case 'menus':
					TMG_Roles_Screen_Menus::render();
					break;
				case 'content':
					TMG_Roles_Screen_Content::render();
					break;
				case 'settings':
					TMG_Roles_Screen_Settings::render();
					break;
				case 'log':
					TMG_Roles_Screen_Log::render();
					break;
			}
			?>
			</div>
		</div>
		<?php
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( TMG_Roles::safe_mode() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Safe mode is on: settings can be changed but no restriction is applied.', 'tamago-roles' ) . '</p></div>';
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['tmg_msg'] ?? '' ) );
		$err = sanitize_text_field( wp_unslash( $_GET['tmg_err'] ?? '' ) );
		// phpcs:enable
		if ( '' !== $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( '' !== $err ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers for tab handlers                                            */
	/* ------------------------------------------------------------------ */

	/** Verify nonce + capability for an admin-post handler; dies otherwise. */
	public static function guard( string $action, string $cap = TMG_Roles::CAP_MANAGE ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'tamago-roles' ), esc_html__( 'Not allowed', 'tamago-roles' ), array( 'response' => 403, 'back_link' => true ) );
		}
	}

	/** Redirect back to a tab with a message (or an error). */
	public static function back( string $tab, array $args = array(), string $msg = '', string $err = '' ): void {
		if ( '' !== $msg ) {
			$args['tmg_msg'] = rawurlencode( $msg );
		}
		if ( '' !== $err ) {
			$args['tmg_err'] = rawurlencode( $err );
		}
		wp_safe_redirect( TMG_Roles::screen_url( array_merge( array( 'tab' => $tab ), $args ) ) );
		exit;
	}

	/** Start an admin-post form. */
	public static function form_open( string $action, array $hidden = array(), string $extra = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $extra is a static attribute string from our code.
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		foreach ( $hidden as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
	}

	/** Role choice <select>. */
	public static function role_select( string $name, string $selected = '', array $roles = array(), string $empty = '' ): void {
		$roles = $roles ? $roles : TMG_Roles_Ranks::sorted_roles();
		echo '<select name="' . esc_attr( $name ) . '">';
		if ( '' !== $empty ) {
			echo '<option value="">' . esc_html( $empty ) . '</option>';
		}
		foreach ( $roles as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $selected, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
	}

	/** Note shown next to a field locked by a wp-config.php constant. */
	public static function locked_note( string $key ): string {
		if ( ! TMG_Roles_Settings::is_locked( $key ) ) {
			return '';
		}
		/* translators: constant name */
		return '<p class="description tmg-locked">' . esc_html( sprintf( __( 'Locked by %s in wp-config.php.', 'tamago-roles' ), TMG_Roles_Settings::constant_name( $key ) ) ) . '</p>';
	}
}

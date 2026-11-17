<?php
/**
 * Admin menus tab: per role, the admin menu checklist (captured from the live menu), admin bar items,
 * front-end toolbar, dashboard widgets, login / logout redirects and "no wp-admin access".
 *
 * Screens: Roles & Permissions → Admin menus (?role=<key>).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Screen_Menus {

	public static function init(): void {
		add_action( 'admin_post_tmg_roles_menus', array( __CLASS__, 'handle_save' ) );
	}

	private static function editable_roles(): array {
		$out = array();
		foreach ( TMG_Roles_Ranks::sorted_roles() as $key => $label ) {
			if ( TMG_Roles_Ranks::can_edit_role( $key ) ) {
				$out[ $key ] = $label;
			}
		}
		return $out;
	}

	public static function render(): void {
		$roles = self::editable_roles();
		$role  = sanitize_key( wp_unslash( $_GET['role'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $roles[ $role ] ) ) {
			$role = (string) ( array_key_first( array_diff_key( $roles, array( 'administrator' => 1 ) ) ) ?? array_key_first( $roles ) );
		}
		?>
		<form method="get" class="tmg-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( TMG_Roles::PAGE ); ?>"><input type="hidden" name="tab" value="menus">
			<label><?php esc_html_e( 'Role', 'tamago-roles' ); ?> <?php TMG_Roles_Admin_Screen::role_select( 'role', $role, $roles ); ?></label>
			<button class="button"><?php esc_html_e( 'Show', 'tamago-roles' ); ?></button>
		</form>
		<?php
		if ( '' === $role ) {
			echo '<p>' . esc_html__( 'There are no roles below your own.', 'tamago-roles' ) . '</p>';
			return;
		}
		if ( 'administrator' === $role && ! TMG_Roles_Settings::get( 'restrict_admins' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Administrators are never restricted. Turn on "Apply admin experience rules to administrators" on the Settings tab to change this.', 'tamago-roles' ) . '</p></div>';
		}
		$s        = TMG_Roles_Settings::all();
		$hidden   = (array) ( $s['menu_rules'][ $role ] ?? array() );
		$snapshot = TMG_Roles_Admin_Experience::menu_snapshot();
		$known    = array();
		foreach ( $snapshot as $item ) {
			$known[] = $item['id'];
			foreach ( (array) $item['children'] as $c ) {
				$known[] = $c['id'];
			}
		}
		$extra_menu = array_diff( $hidden, $known );
		$bar        = (array) ( $s['adminbar_rules'][ $role ] ?? array() );
		$bar_known  = TMG_Roles_Admin_Experience::adminbar_nodes();
		$widgets    = (array) ( $s['widget_rules'][ $role ] ?? array() );
		TMG_Roles_Admin_Screen::form_open( 'tmg_roles_menus', array( 'role' => $role ) );
		?>
		<div class="tmg-roles-columns">
			<div class="tmg-card tmg-card--wide">
				<h3><?php esc_html_e( 'Admin menu', 'tamago-roles' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Ticked items are visible. Unticked items are removed from the menu and their screens are blocked when opened directly (the user is sent to the dashboard or the first allowed page). Items the role has no capability for are hidden by WordPress anyway.', 'tamago-roles' ); ?></p>
				<?php if ( ! $snapshot ) : ?>
					<p><em><?php esc_html_e( 'The menu has not been captured yet: open any admin page as an administrator, then come back.', 'tamago-roles' ); ?></em></p>
				<?php endif; ?>
				<ul class="tmg-menu-tree">
					<?php foreach ( $snapshot as $item ) : ?>
						<li>
							<label><input type="checkbox" class="tmg-menu-parent" name="visible[]" value="<?php echo esc_attr( $item['id'] ); ?>" <?php checked( ! in_array( $item['id'], $hidden, true ) ); ?>> <strong><?php echo esc_html( $item['title'] ); ?></strong> <code><?php echo esc_html( $item['id'] ); ?></code></label>
							<?php if ( $item['children'] ) : ?>
								<ul>
									<?php foreach ( $item['children'] as $c ) : ?>
										<li><label><input type="checkbox" class="tmg-menu-child" name="visible[]" value="<?php echo esc_attr( $c['id'] ); ?>" <?php checked( ! in_array( $c['id'], $hidden, true ) ); ?>> <?php echo esc_html( $c['title'] ); ?></label></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php foreach ( $known as $id ) : ?>
					<input type="hidden" name="known[]" value="<?php echo esc_attr( $id ); ?>">
				<?php endforeach; ?>
				<p><label><?php esc_html_e( 'Other hidden menu items (one per line: "slug" or "parent|slug")', 'tamago-roles' ); ?><br>
					<textarea name="menu_extra" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $extra_menu ) ); ?></textarea></label></p>
			</div>

			<div class="tmg-card">
				<h3><?php esc_html_e( 'Admin bar', 'tamago-roles' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Ticked items are hidden.', 'tamago-roles' ); ?></p>
				<?php foreach ( $bar_known as $id => $label ) : ?>
					<label style="display:block"><input type="checkbox" name="bar[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $bar, true ) ); ?>> <?php echo esc_html( $label ); ?> <code><?php echo esc_html( $id ); ?></code></label>
				<?php endforeach; ?>
				<p><label><?php esc_html_e( 'Other node IDs to hide (comma-separated)', 'tamago-roles' ); ?><br><input name="bar_extra" class="regular-text code" value="<?php echo esc_attr( implode( ', ', array_diff( $bar, array_keys( $bar_known ) ) ) ); ?>"></label></p>
				<p><label><input type="checkbox" name="toolbar_front" value="1" <?php checked( in_array( $role, (array) $s['toolbar_front_hidden'], true ) ); ?>> <strong><?php esc_html_e( 'Hide the toolbar on the front end', 'tamago-roles' ); ?></strong></label></p>

				<h3><?php esc_html_e( 'Dashboard widgets', 'tamago-roles' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Ticked widgets are hidden. Widgets are listed after an administrator has opened the Dashboard.', 'tamago-roles' ); ?></p>
				<?php foreach ( TMG_Roles_Admin_Experience::widget_snapshot() as $id => $title ) : ?>
					<label style="display:block"><input type="checkbox" name="widgets[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $widgets, true ) ); ?>> <?php echo esc_html( $title ); ?></label>
				<?php endforeach; ?>
			</div>

			<div class="tmg-card">
				<h3><?php esc_html_e( 'Redirects and access', 'tamago-roles' ); ?></h3>
				<p><label><?php esc_html_e( 'After login go to', 'tamago-roles' ); ?><br><input name="login_redirect" class="regular-text code" value="<?php echo esc_attr( (string) ( $s['login_redirect'][ $role ] ?? '' ) ); ?>" placeholder="admin.php?page=my-dashboard"></label></p>
				<p><label><?php esc_html_e( 'After logout go to', 'tamago-roles' ); ?><br><input name="logout_redirect" class="regular-text code" value="<?php echo esc_attr( (string) ( $s['logout_redirect'][ $role ] ?? '' ) ); ?>" placeholder="/"></label></p>
				<p class="description"><?php esc_html_e( 'Full URL, a site path starting with "/", or a wp-admin page like "admin.php?page=x". The login page is only used when no specific page was requested; it is also where blocked screens send this role.', 'tamago-roles' ); ?></p>
				<p><label><input type="checkbox" name="no_admin" value="1" <?php checked( in_array( $role, (array) $s['no_admin'], true ) ); ?>> <strong><?php esc_html_e( 'No wp-admin access', 'tamago-roles' ); ?></strong></label><br>
					<span class="description"><?php esc_html_e( 'Users whose roles all have this are sent to the home page (AJAX and form posts keep working).', 'tamago-roles' ); ?></span></p>
			</div>
		</div>
		<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Save admin experience', 'tamago-roles' ); ?></button></p>
		</form>
		<?php
	}

	public static function handle_save(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_menus' );
		// phpcs:disable WordPress.Security.NonceVerification -- guarded above.
		$role = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
		if ( ! get_role( $role ) || ! TMG_Roles_Ranks::can_edit_role( $role ) ) {
			TMG_Roles_Admin_Screen::back( 'menus', array(), '', __( 'You can only change roles ranked below your own.', 'tamago-roles' ) );
		}
		$visible = array_map( array( 'TMG_Roles_Settings', 'clean_id' ), (array) wp_unslash( $_POST['visible'] ?? array() ) );
		$known   = array_map( array( 'TMG_Roles_Settings', 'clean_id' ), (array) wp_unslash( $_POST['known'] ?? array() ) );
		$extra   = preg_split( '/[\r\n]+/', (string) wp_unslash( $_POST['menu_extra'] ?? '' ) );
		$hidden  = array_merge( array_diff( $known, $visible ), array_map( 'trim', $extra ) );
		$bar     = array_merge( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bar'] ?? array() ) ), array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['bar_extra'] ?? '' ) ) ) ) );
		$widgets = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['widgets'] ?? array() ) );
		$login   = sanitize_text_field( wp_unslash( $_POST['login_redirect'] ?? '' ) );
		$logout  = sanitize_text_field( wp_unslash( $_POST['logout_redirect'] ?? '' ) );
		$front   = ! empty( $_POST['toolbar_front'] );
		$noadmin = ! empty( $_POST['no_admin'] );
		// phpcs:enable

		// Lock-out guard: whoever saves keeps this screen in their own menu.
		$me = wp_get_current_user();
		if ( in_array( $role, (array) $me->roles, true ) ) {
			$hidden = array_diff( $hidden, TMG_Roles_Admin_Screen::menu_ids() );
			$noadmin = false;
		}

		$s = TMG_Roles_Settings::all();
		$s['menu_rules'][ $role ]      = array_values( array_filter( $hidden ) );
		$s['adminbar_rules'][ $role ]  = array_values( array_filter( $bar ) );
		$s['widget_rules'][ $role ]    = array_values( array_filter( $widgets ) );
		$s['login_redirect'][ $role ]  = $login;
		$s['logout_redirect'][ $role ] = $logout;
		$s['toolbar_front_hidden']     = $front ? array_merge( (array) $s['toolbar_front_hidden'], array( $role ) ) : array_diff( (array) $s['toolbar_front_hidden'], array( $role ) );
		$s['no_admin']                 = $noadmin ? array_merge( (array) $s['no_admin'], array( $role ) ) : array_diff( (array) $s['no_admin'], array( $role ) );
		TMG_Roles_Settings::update(
			array_intersect_key( $s, array_flip( array( 'menu_rules', 'adminbar_rules', 'widget_rules', 'login_redirect', 'logout_redirect', 'toolbar_front_hidden', 'no_admin' ) ) ),
			'admin_experience:' . $role
		);
		TMG_Roles_Admin_Screen::back( 'menus', array( 'role' => $role ), __( 'Admin experience saved.', 'tamago-roles' ) );
	}
}

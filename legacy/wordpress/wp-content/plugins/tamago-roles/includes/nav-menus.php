<?php
/**
 * Navigation menu item visibility: everyone / logged-in / logged-out / selected roles.
 * Hidden items (and their sub-items) are removed from classic menus on the front end.
 *
 * Screens: Appearance → Menus (fields on each item). Used by: wp_nav_menu() on the front end.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Nav_Menus {

	const META = '_tmg_roles_visibility';

	public static function init(): void {
		add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, 'fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filter_items' ), 20, 3 );
	}

	public static function modes(): array {
		return array(
			'everyone'   => __( 'Everyone', 'tamago-roles' ),
			'logged_in'  => __( 'Logged-in users', 'tamago-roles' ),
			'logged_out' => __( 'Logged-out visitors', 'tamago-roles' ),
			'roles'      => __( 'Selected roles', 'tamago-roles' ),
		);
	}

	public static function rule( int $item_id ): array {
		$r = get_post_meta( $item_id, self::META, true );
		$r = is_array( $r ) ? $r : array();
		return array(
			'mode'  => array_key_exists( (string) ( $r['mode'] ?? '' ), self::modes() ) ? (string) $r['mode'] : 'everyone',
			'roles' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $r['roles'] ?? array() ) ) ) ),
		);
	}

	public static function set_rule( int $item_id, array $rule ): void {
		$mode  = array_key_exists( (string) ( $rule['mode'] ?? '' ), self::modes() ) ? (string) $rule['mode'] : 'everyone';
		$roles = array_values( array_filter( array_map( 'sanitize_key', (array) ( $rule['roles'] ?? array() ) ) ) );
		if ( 'everyone' === $mode ) {
			delete_post_meta( $item_id, self::META );
		} else {
			update_post_meta( $item_id, self::META, array( 'mode' => $mode, 'roles' => $roles ) );
		}
	}

	public static function fields( $item_id, $item ): void {
		$rule = self::rule( (int) $item_id );
		$name = 'tmg_roles_vis[' . (int) $item_id . ']';
		?>
		<div class="field-tmg-roles description description-wide tmg-roles-menu-item">
			<p><label><?php esc_html_e( 'Visible to', 'tamago-roles' ); ?><br>
				<select name="<?php echo esc_attr( $name ); ?>[mode]" class="widefat tmg-roles-mode">
					<?php foreach ( self::modes() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['mode'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<fieldset class="tmg-roles-roles">
				<?php foreach ( TMG_Roles_Ranks::sorted_roles() as $k => $l ) : ?>
					<label style="display:inline-block;margin-right:8px"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[roles][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $rule['roles'], true ) ); ?>> <?php echo esc_html( $l ); ?></label>
				<?php endforeach; ?>
			</fieldset>
		</div>
		<?php
	}

	public static function save( $menu_id, $item_id ): void {
		// nav-menus.php has already checked its own nonce (update-nav_menu).
		if ( ! current_user_can( 'edit_theme_options' ) || ! isset( $_POST['tmg_roles_vis'][ $item_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['update-nav-menu-nonce'] ?? '' ) ), 'update-nav_menu' ) ) {
			return;
		}
		$in = (array) wp_unslash( $_POST['tmg_roles_vis'][ $item_id ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in set_rule.
		self::set_rule( (int) $item_id, $in );
	}

	public static function visible( array $rule, ?WP_User $user ): bool {
		switch ( $rule['mode'] ) {
			case 'logged_in':
				return (bool) $user;
			case 'logged_out':
				return ! $user;
			case 'roles':
				return $user && ( array_intersect( (array) $user->roles, $rule['roles'] ) || in_array( 'administrator', (array) $user->roles, true ) );
		}
		return true;
	}

	public static function filter_items( $items, $menu = null, $args = array() ) {
		if ( is_admin() || ! TMG_Roles::active() || ! is_array( $items ) ) {
			return $items;
		}
		$user    = TMG_Roles_Ranks::resolve_user();
		$removed = array();
		foreach ( $items as $i => $item ) {
			$parent = (int) ( $item->menu_item_parent ?? 0 );
			if ( ( $parent && in_array( $parent, $removed, true ) ) || ! self::visible( self::rule( (int) $item->ID ), $user ) ) {
				$removed[] = (int) $item->ID;
				unset( $items[ $i ] );
			}
		}
		return array_values( $items );
	}
}

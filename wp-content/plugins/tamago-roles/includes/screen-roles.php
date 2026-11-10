<?php
/**
 * Roles tab: list with ranks and user counts, create / clone / rename / re-rank / delete (with reassignment),
 * default role for new users, reset to defaults / save as defaults, and the advanced capability editor.
 *
 * Screens: Users → Roles & Permissions → Roles (and ?role=<key> for one role).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Screen_Roles {

	public static function init(): void {
		foreach ( array( 'create', 'update', 'clone', 'delete', 'default', 'reset', 'save_defaults', 'caps' ) as $a ) {
			add_action( 'admin_post_tmg_roles_role_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	public static function render(): void {
		$role = sanitize_key( wp_unslash( $_GET['role'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $role && get_role( $role ) ) {
			self::render_role( $role );
			return;
		}
		$counts  = count_users();
		$counts  = (array) ( $counts['avail_roles'] ?? array() );
		$default = (string) get_option( 'default_role' );
		$mine    = TMG_Roles_Ranks::user_rank();
		?>
		<p class="description"><?php esc_html_e( 'Higher rank outranks lower. People may only manage users, assign roles and change permissions of roles ranked below their own. Administrator is always the top rank.', 'tamago-roles' ); ?></p>
		<table class="widefat striped tmg-roles-table">
			<thead><tr>
				<th><?php esc_html_e( 'Role', 'tamago-roles' ); ?></th>
				<th><?php esc_html_e( 'Key', 'tamago-roles' ); ?></th>
				<th class="c"><?php esc_html_e( 'Rank', 'tamago-roles' ); ?></th>
				<th class="c"><?php esc_html_e( 'Users', 'tamago-roles' ); ?></th>
				<th class="c"><?php esc_html_e( 'Capabilities', 'tamago-roles' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'tamago-roles' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( TMG_Roles_Ranks::sorted_roles() as $key => $label ) : ?>
				<?php $editable = TMG_Roles_Ranks::can_edit_role( $key ); ?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong>
						<?php if ( $default === $key ) : ?><span class="tmg-badge"><?php esc_html_e( 'default for new users', 'tamago-roles' ); ?></span><?php endif; ?>
						<?php if ( in_array( $key, (array) TMG_Roles_Settings::get( 'public_name_roles' ), true ) ) : ?><span class="tmg-badge tmg-badge--muted"><?php esc_html_e( 'staff (public name)', 'tamago-roles' ); ?></span><?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $key ); ?></code></td>
					<td class="c"><?php echo (int) TMG_Roles_Ranks::role_rank( $key ); ?></td>
					<td class="c"><?php echo (int) ( $counts[ $key ] ?? 0 ); ?></td>
					<td class="c"><?php echo (int) count( array_filter( (array) get_role( $key )->capabilities ) ); ?></td>
					<td>
						<?php if ( $editable ) : ?>
							<a class="button button-small" href="<?php echo esc_url( TMG_Roles::screen_url( array( 'tab' => 'roles', 'role' => $key ) ) ); ?>"><?php esc_html_e( 'Edit', 'tamago-roles' ); ?></a>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Ranked at or above you', 'tamago-roles' ); ?></span>
						<?php endif; ?>
						<?php if ( $default !== $key && TMG_Roles_Ranks::can_assign_role( $key ) ) : ?>
							<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_role_default', array( 'role' => $key ), 'class="tmg-inline"' ); ?>
								<button class="button button-small button-link"><?php esc_html_e( 'Make default', 'tamago-roles' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Add a role', 'tamago-roles' ); ?></h2>
		<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_role_create' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="tmg-new-name"><?php esc_html_e( 'Name', 'tamago-roles' ); ?></label></th><td><input id="tmg-new-name" name="name" class="regular-text" required></td></tr>
				<tr><th><label for="tmg-new-key"><?php esc_html_e( 'Key', 'tamago-roles' ); ?></label></th><td><input id="tmg-new-key" name="key" class="regular-text" pattern="[a-z0-9_\-]+" placeholder="<?php esc_attr_e( 'e.g. site_manager (lower case, no spaces)', 'tamago-roles' ); ?>"><p class="description"><?php esc_html_e( 'Leave empty to build it from the name. Cannot be changed later.', 'tamago-roles' ); ?></p></td></tr>
				<tr><th><label for="tmg-new-rank"><?php esc_html_e( 'Rank', 'tamago-roles' ); ?></label></th><td><input id="tmg-new-rank" type="number" min="0" name="rank" value="1" class="small-text"><?php if ( ! TMG_Roles_Ranks::is_top() ) : ?> <span class="description"><?php echo esc_html( sprintf( /* translators: rank */ __( 'Must be below your own rank (%d).', 'tamago-roles' ), $mine ) ); ?></span><?php endif; ?></td></tr>
				<tr><th><?php esc_html_e( 'Start from', 'tamago-roles' ); ?></th><td><?php TMG_Roles_Admin_Screen::role_select( 'copy_from', '', array_filter( TMG_Roles_Ranks::sorted_roles(), fn( $k ) => TMG_Roles_Ranks::can_edit_role( $k ), ARRAY_FILTER_USE_KEY ), __( '— Nothing (log-in only) —', 'tamago-roles' ) ); ?></td></tr>
			</table>
			<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Add role', 'tamago-roles' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_role( string $key ): void {
		if ( ! TMG_Roles_Ranks::can_edit_role( $key ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'You can only change roles ranked below your own.', 'tamago-roles' ) . '</p></div>';
			return;
		}
		$data     = wp_roles()->roles[ $key ];
		$role     = get_role( $key );
		$is_admin = 'administrator' === $key;
		$defaults = TMG_Roles_Role_Manager::defaults();
		$others   = array_filter( TMG_Roles_Ranks::sorted_roles(), fn( $k ) => $k !== $key && TMG_Roles_Ranks::can_assign_role( $k ), ARRAY_FILTER_USE_KEY );
		$counts   = count_users();
		$users    = (int) ( $counts['avail_roles'][ $key ] ?? 0 );
		?>
		<p><a href="<?php echo esc_url( TMG_Roles::screen_url( array( 'tab' => 'roles' ) ) ); ?>">&larr; <?php esc_html_e( 'All roles', 'tamago-roles' ); ?></a></p>
		<h2><?php echo esc_html( translate_user_role( $data['name'] ) ); ?> <code><?php echo esc_html( $key ); ?></code></h2>

		<div class="tmg-roles-columns">
			<div class="tmg-card">
				<h3><?php esc_html_e( 'Name and rank', 'tamago-roles' ); ?></h3>
				<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_role_update', array( 'role' => $key ) ); ?>
					<p><label><?php esc_html_e( 'Name', 'tamago-roles' ); ?><br><input name="name" class="regular-text" value="<?php echo esc_attr( $data['name'] ); ?>" required></label></p>
					<p><label><?php esc_html_e( 'Rank', 'tamago-roles' ); ?><br><input type="number" min="0" name="rank" class="small-text" value="<?php echo (int) ( $is_admin ? ( TMG_Roles_Settings::get( 'ranks' )['administrator'] ?? TMG_Roles_Ranks::top() ) : TMG_Roles_Ranks::role_rank( $key ) ); ?>"></label>
					<?php if ( $is_admin ) : ?><br><span class="description"><?php esc_html_e( 'Administrator is always at least as high as every other role.', 'tamago-roles' ); ?></span><?php endif; ?></p>
					<p><button class="button button-primary"><?php esc_html_e( 'Save', 'tamago-roles' ); ?></button></p>
				</form>
			</div>
			<div class="tmg-card">
				<h3><?php esc_html_e( 'Clone', 'tamago-roles' ); ?></h3>
				<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_role_clone', array( 'role' => $key ) ); ?>
					<p><label><?php esc_html_e( 'New name', 'tamago-roles' ); ?><br><input name="name" class="regular-text" required></label></p>
					<p><label><?php esc_html_e( 'New key', 'tamago-roles' ); ?><br><input name="key" class="regular-text" pattern="[a-z0-9_\-]+"></label></p>
					<p><button class="button"><?php esc_html_e( 'Clone role', 'tamago-roles' ); ?></button></p>
				</form>
			</div>
			<div class="tmg-card">
				<h3><?php esc_html_e( 'Defaults', 'tamago-roles' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Defaults are the capabilities this role had when the plugin first saw it, unless saved again.', 'tamago-roles' ); ?></p>
				<?php if ( ! $is_admin && isset( $defaults[ $key ] ) ) : ?>
					<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_role_reset', array( 'role' => $key ), 'class="tmg-inline" data-confirm="' . esc_attr__( 'Reset this role to its default capabilities?', 'tamago-roles' ) . '"' ); ?>
						<button class="button"><?php esc_html_e( 'Reset to defaults', 'tamago-roles' ); ?></button>
					</form>
				<?php endif; ?>
				<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_role_save_defaults', array( 'role' => $key ), 'class="tmg-inline"' ); ?>
					<button class="button"><?php esc_html_e( 'Save current as defaults', 'tamago-roles' ); ?></button>
				</form>
			</div>
			<?php if ( ! $is_admin ) : ?>
			<div class="tmg-card">
				<h3><?php esc_html_e( 'Delete', 'tamago-roles' ); ?></h3>
				<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_role_delete', array( 'role' => $key ), 'data-confirm="' . esc_attr__( 'Delete this role? Its users move to the chosen role.', 'tamago-roles' ) . '"' ); ?>
					<p><?php echo esc_html( sprintf( /* translators: count */ _n( '%d user has this role.', '%d users have this role.', $users, 'tamago-roles' ), $users ) ); ?></p>
					<p><label><?php esc_html_e( 'Move its users to', 'tamago-roles' ); ?><br><?php TMG_Roles_Admin_Screen::role_select( 'reassign', (string) get_option( 'default_role' ) === $key ? '' : (string) get_option( 'default_role' ), $others ); ?></label></p>
					<p><button class="button button-link-delete"><?php esc_html_e( 'Delete role', 'tamago-roles' ); ?></button></p>
				</form>
			</div>
			<?php endif; ?>
		</div>

		<h3><?php esc_html_e( 'Capabilities (advanced)', 'tamago-roles' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Every capability known on this site. Ticked = granted. Protected capabilities are removed from roles below the protection rank whatever is ticked here.', 'tamago-roles' ); ?>
			<?php if ( $is_admin ) : ?><strong><?php esc_html_e( 'The Administrator role can gain capabilities but never lose them.', 'tamago-roles' ); ?></strong><?php endif; ?></p>
		<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_role_caps', array( 'role' => $key ) ); ?>
			<p><input type="search" class="tmg-roles-search regular-text" data-target="#tmg-caps" placeholder="<?php esc_attr_e( 'Search capabilities…', 'tamago-roles' ); ?>">
				<label><input type="checkbox" class="tmg-roles-only-granted" data-target="#tmg-caps"> <?php esc_html_e( 'Only granted', 'tamago-roles' ); ?></label></p>
			<div class="tmg-caps-grid" id="tmg-caps">
				<?php
				$rank = TMG_Roles_Ranks::role_rank( $key );
				foreach ( TMG_Roles_Permissions::known_caps() as $cap ) :
					$on   = ! empty( $role->capabilities[ $cap ] );
					$prot = TMG_Roles_Capabilities::is_protected_for( $cap, $rank );
					$lock = ( $is_admin && $on ) || ( ! TMG_Roles_Ranks::is_top() && ! TMG_Roles_Capabilities::holds( wp_get_current_user(), $cap ) );
					?>
					<label class="tmg-roles-row<?php echo $on ? ' is-on' : ''; ?>" data-search="<?php echo esc_attr( $cap ); ?>">
						<input type="checkbox" name="caps[]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( $on ); ?> <?php disabled( $lock ); ?>>
						<code><?php echo esc_html( $cap ); ?></code>
						<?php if ( $prot ) : ?><span class="tmg-badge tmg-badge--warn"><?php esc_html_e( 'protected', 'tamago-roles' ); ?></span><?php endif; ?>
					</label>
				<?php endforeach; ?>
			</div>
			<p><label><?php esc_html_e( 'Add custom capabilities', 'tamago-roles' ); ?><br><input name="custom" class="regular-text" placeholder="<?php esc_attr_e( 'my_custom_cap, another_cap', 'tamago-roles' ); ?>"></label>
				<span class="description"><?php esc_html_e( 'Granted to this role and remembered in the list.', 'tamago-roles' ); ?></span></p>
			<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Save capabilities', 'tamago-roles' ); ?></button></p>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                            */
	/* ------------------------------------------------------------------ */

	private static function post( string $k ): string {
		return sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- guarded by the caller.
	}

	/** Non-top users may only use ranks below their own. */
	private static function rank_allowed( int $rank ): bool {
		return TMG_Roles_Ranks::is_top() || $rank < TMG_Roles_Ranks::user_rank();
	}

	public static function handle_create(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_role_create' );
		$name = self::post( 'name' );
		$key  = sanitize_key( self::post( 'key' ) );
		$key  = '' !== $key ? $key : sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( remove_accents( $name ) ) ) );
		$rank = (int) self::post( 'rank' );
		$from = sanitize_key( self::post( 'copy_from' ) );
		if ( ! self::rank_allowed( $rank ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array(), '', __( 'The rank must be below your own.', 'tamago-roles' ) );
		}
		$caps = array( 'read' => true );
		if ( $from ) {
			if ( ! get_role( $from ) || ! TMG_Roles_Ranks::can_edit_role( $from ) ) {
				TMG_Roles_Admin_Screen::back( 'roles', array(), '', __( 'You cannot copy that role.', 'tamago-roles' ) );
			}
			$caps = (array) get_role( $from )->capabilities;
		}
		$res = TMG_Roles_Role_Manager::create( $key, $name, $rank, $caps );
		if ( is_wp_error( $res ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array(), '', $res->get_error_message() );
		}
		TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $key ), __( 'Role added.', 'tamago-roles' ) );
	}

	private static function editable_from_post(): string {
		$role = sanitize_key( self::post( 'role' ) );
		if ( ! get_role( $role ) || ! TMG_Roles_Ranks::can_edit_role( $role ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array(), '', __( 'You can only change roles ranked below your own.', 'tamago-roles' ) );
		}
		return $role;
	}

	public static function handle_update(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_role_update' );
		$role = self::editable_from_post();
		$rank = (int) self::post( 'rank' );
		if ( ! self::rank_allowed( $rank ) || ( 'administrator' === $role && ! TMG_Roles_Ranks::is_top() ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), '', __( 'The rank must be below your own.', 'tamago-roles' ) );
		}
		$res = TMG_Roles_Role_Manager::rename( $role, self::post( 'name' ) );
		if ( is_wp_error( $res ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), '', $res->get_error_message() );
		}
		TMG_Roles_Role_Manager::set_rank( $role, $rank );
		TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), __( 'Role saved.', 'tamago-roles' ) );
	}

	public static function handle_clone(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_role_clone' );
		$from = self::editable_from_post();
		$name = self::post( 'name' );
		$key  = sanitize_key( self::post( 'key' ) );
		$key  = '' !== $key ? $key : sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( remove_accents( $name ) ) ) );
		$rank = TMG_Roles_Ranks::role_rank( $from );
		if ( ! self::rank_allowed( $rank ) ) {
			$rank = max( 0, TMG_Roles_Ranks::user_rank() - 1 );
		}
		$res = TMG_Roles_Role_Manager::clone_role( $from, $key, $name, $rank );
		if ( is_wp_error( $res ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $from ), '', $res->get_error_message() );
		}
		TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $key ), __( 'Role cloned.', 'tamago-roles' ) );
	}

	public static function handle_delete(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_role_delete' );
		$role     = self::editable_from_post();
		$reassign = sanitize_key( self::post( 'reassign' ) );
		if ( '' !== $reassign && ! TMG_Roles_Ranks::can_assign_role( $reassign ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), '', __( 'You cannot move users to that role.', 'tamago-roles' ) );
		}
		$res = TMG_Roles_Role_Manager::delete( $role, $reassign );
		if ( is_wp_error( $res ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), '', $res->get_error_message() );
		}
		TMG_Roles_Admin_Screen::back( 'roles', array(), __( 'Role deleted.', 'tamago-roles' ) );
	}

	public static function handle_default(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_role_default' );
		$role = sanitize_key( self::post( 'role' ) );
		if ( ! TMG_Roles_Ranks::can_assign_role( $role ) ) {
			TMG_Roles_Admin_Screen::back( 'roles', array(), '', __( 'You cannot assign that role.', 'tamago-roles' ) );
		}
		$res = TMG_Roles_Role_Manager::set_default_role( $role );
		TMG_Roles_Admin_Screen::back( 'roles', array(), is_wp_error( $res ) ? '' : __( 'Default role saved.', 'tamago-roles' ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	}

	public static function handle_reset(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_role_reset' );
		$role = self::editable_from_post();
		if ( 'administrator' === $role ) {
			TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), '', __( 'The Administrator role cannot be reset.', 'tamago-roles' ) );
		}
		$res = TMG_Roles_Role_Manager::reset( $role );
		TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), is_wp_error( $res ) ? '' : __( 'Role reset to its defaults.', 'tamago-roles' ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	}

	public static function handle_save_defaults(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_role_save_defaults' );
		$role = self::editable_from_post();
		TMG_Roles_Role_Manager::save_as_defaults( $role );
		TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), __( 'Current capabilities saved as this role\'s defaults.', 'tamago-roles' ) );
	}

	public static function handle_caps(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_role_caps' );
		$role    = self::editable_from_post();
		$checked = TMG_Roles_Settings::cap_list( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['caps'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$custom  = TMG_Roles_Settings::cap_list( self::post( 'custom' ) );
		$current = (array) get_role( $role )->capabilities;
		$top     = TMG_Roles_Ranks::is_top();
		$known   = TMG_Roles_Permissions::known_caps();
		// Caps not offered in the list (level_N leftovers and the like) stay untouched.
		$new = array_diff_key( $current, array_flip( $known ) );
		foreach ( $known as $cap ) {
			$may = $top || TMG_Roles_Capabilities::holds( wp_get_current_user(), $cap );
			if ( ! $may ) {
				if ( array_key_exists( $cap, $current ) ) {
					$new[ $cap ] = $current[ $cap ]; // Untouchable for this user: keep as is.
				}
				continue;
			}
			if ( in_array( $cap, $checked, true ) ) {
				$new[ $cap ] = true;
			}
		}
		// Explicit denials (false) set by other tools are kept unless the box is ticked now.
		foreach ( $current as $cap => $grant ) {
			if ( ! $grant && ! isset( $new[ $cap ] ) ) {
				$new[ $cap ] = false;
			}
		}
		foreach ( $custom as $cap ) {
			if ( $top || TMG_Roles_Capabilities::holds( wp_get_current_user(), $cap ) ) {
				$new[ $cap ] = true;
			}
		}
		if ( $custom ) {
			TMG_Roles_Settings::update( array( 'custom_caps' => array_merge( (array) TMG_Roles_Settings::get( 'custom_caps' ), $custom ) ), 'custom_caps' );
		}
		TMG_Roles_Role_Manager::set_caps( $role, $new );
		TMG_Roles_Admin_Screen::back( 'roles', array( 'role' => $role ), __( 'Capabilities saved.', 'tamago-roles' ) );
	}
}

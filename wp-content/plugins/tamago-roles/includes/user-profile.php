<?php
/**
 * User profile additions: extra roles (users may hold several roles) and per-user permission
 * overrides with the effective result. The overrides table is shared with the Users tab.
 *
 * Screens: Users → Edit user (user-edit.php); Roles & Permissions → Users.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_User_Profile {

	public static function init(): void {
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ), 20 );
		add_action( 'profile_update', array( __CLASS__, 'save' ), 20, 1 );
	}

	/** May the current user set overrides on this user? */
	public static function can_override( int $user_id ): bool {
		return current_user_can( TMG_Roles::CAP_MANAGE_USERS ) && get_current_user_id() !== $user_id && TMG_Roles_Ranks::can_manage_user( $user_id );
	}

	/** May the current user hand out this permission? Nobody grants what they do not hold (top rank excepted). */
	public static function may_hand_out( string $perm ): bool {
		return TMG_Roles_Ranks::is_top() || TMG_Roles_Permissions::user_has( wp_get_current_user(), $perm );
	}

	public static function render( WP_User $user ): void {
		$can_roles = current_user_can( 'promote_user', $user->ID ) && TMG_Roles::active();
		$can_over  = self::can_override( $user->ID );
		if ( ! $can_roles && ! $can_over ) {
			return;
		}
		wp_nonce_field( 'tmg_roles_profile_' . $user->ID, 'tmg_roles_profile_nonce' );
		echo '<h2>' . esc_html__( 'Roles & Permissions', 'tamago-roles' ) . '</h2>';
		if ( $can_roles ) {
			$roles   = (array) $user->roles;
			$primary = $roles[0] ?? '';
			?>
			<table class="form-table" role="presentation"><tr>
				<th><?php esc_html_e( 'Additional roles', 'tamago-roles' ); ?></th>
				<td><fieldset>
					<?php foreach ( TMG_Roles_Ranks::sorted_roles() as $key => $label ) : ?>
						<?php
						if ( $key === $primary ) {
							continue;
						}
						$assignable = TMG_Roles_Ranks::can_assign_role( $key );
						$has        = in_array( $key, $roles, true );
						if ( ! $assignable && ! $has ) {
							continue;
						}
						?>
						<label style="display:block"><input type="checkbox" name="tmg_roles_extra[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $has ); ?> <?php disabled( ! $assignable ); ?>> <?php echo esc_html( $label ); ?> <span class="description">(<?php echo esc_html( sprintf( /* translators: rank */ __( 'rank %d', 'tamago-roles' ), TMG_Roles_Ranks::role_rank( $key ) ) ); ?>)</span></label>
					<?php endforeach; ?>
					<input type="hidden" name="tmg_roles_extra_sent" value="1">
					<p class="description"><?php esc_html_e( 'On top of the main role above. The user gets the capabilities of every role; the highest rank counts.', 'tamago-roles' ); ?></p>
				</fieldset></td>
			</tr></table>
			<?php
		}
		if ( $can_over ) {
			echo '<h3>' . esc_html__( 'Permission overrides', 'tamago-roles' ) . '</h3>';
			self::overrides_table( $user );
		}
	}

	/** Grouped permissions with: from roles, override select, effective result. */
	public static function overrides_table( WP_User $user ): void {
		$over = TMG_Roles_Capabilities::overrides( $user->ID );
		?>
		<p class="description"><?php esc_html_e( 'Grant or deny individual permissions on top of this user\'s roles. Protected capabilities stay removed whatever is set here.', 'tamago-roles' ); ?></p>
		<p><input type="search" class="tmg-roles-search regular-text" data-target="#tmg-roles-overrides" placeholder="<?php esc_attr_e( 'Search permissions…', 'tamago-roles' ); ?>"></p>
		<table class="widefat striped tmg-roles-matrix" id="tmg-roles-overrides">
			<thead><tr><th><?php esc_html_e( 'Permission', 'tamago-roles' ); ?></th><th class="c"><?php esc_html_e( 'From roles', 'tamago-roles' ); ?></th><th class="c"><?php esc_html_e( 'Override', 'tamago-roles' ); ?></th><th class="c"><?php esc_html_e( 'Effective', 'tamago-roles' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( TMG_Roles_Permissions::grouped() as $group => $perms ) : ?>
				<tr class="tmg-roles-group"><th colspan="4"><?php echo esc_html( $group ); ?></th></tr>
				<?php
				foreach ( $perms as $key => $p ) :
					$from = TMG_Roles_Permissions::roles_grant( $user, $key );
					$o    = $over[ $key ] ?? 'inherit';
					$eff  = TMG_Roles_Permissions::user_has( $user, $key );
					?>
					<tr class="tmg-roles-row" data-search="<?php echo esc_attr( strtolower( $group . ' ' . $p['label'] . ' ' . implode( ' ', $p['caps'] ) ) ); ?>">
						<td><strong><?php echo esc_html( $p['label'] ); ?></strong> <code class="tmg-roles-caps"><?php echo esc_html( implode( ', ', $p['caps'] ) ); ?></code></td>
						<td class="c"><?php echo $from ? '<span class="tmg-yes">&#10004;</span>' : '<span class="tmg-no">&mdash;</span>'; ?></td>
						<td class="c">
							<select name="tmg_roles_ov[<?php echo esc_attr( $key ); ?>]" <?php disabled( ! self::may_hand_out( $key ) ); ?>>
								<option value="inherit" <?php selected( $o, 'inherit' ); ?>><?php esc_html_e( 'Use roles', 'tamago-roles' ); ?></option>
								<option value="grant" <?php selected( $o, 'grant' ); ?>><?php esc_html_e( 'Grant', 'tamago-roles' ); ?></option>
								<option value="deny" <?php selected( $o, 'deny' ); ?>><?php esc_html_e( 'Deny', 'tamago-roles' ); ?></option>
							</select>
						</td>
						<td class="c"><?php echo $eff ? '<span class="tmg-yes">' . esc_html__( 'Allowed', 'tamago-roles' ) . '</span>' : '<span class="tmg-no">' . esc_html__( 'Denied', 'tamago-roles' ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		<input type="hidden" name="tmg_roles_ov_sent" value="1">
		<?php
	}

	/**
	 * Save overrides from a submitted form. Permissions the actor cannot hand out keep their old value.
	 *
	 * @return true|WP_Error
	 */
	public static function save_overrides( int $user_id, array $input ) {
		if ( ! self::can_override( $user_id ) ) {
			return new WP_Error( 'tmg_roles_forbidden', __( 'You can only adjust users ranked below you.', 'tamago-roles' ) );
		}
		$old = TMG_Roles_Capabilities::overrides( $user_id );
		$new = array();
		foreach ( array_keys( TMG_Roles_Permissions::all() ) as $key ) {
			if ( ! self::may_hand_out( $key ) ) {
				if ( isset( $old[ $key ] ) ) {
					$new[ $key ] = $old[ $key ];
				}
				continue;
			}
			$v = (string) ( $input[ $key ] ?? 'inherit' );
			if ( in_array( $v, array( 'grant', 'deny' ), true ) ) {
				$new[ $key ] = $v;
			}
		}
		TMG_Roles_Capabilities::set_overrides( $user_id, $new );
		return true;
	}

	/** Set the user's extra roles (main role stays first). */
	public static function save_extra_roles( int $user_id, array $extra ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! current_user_can( 'promote_user', $user_id ) ) {
			return;
		}
		$roles   = (array) $user->roles;
		$primary = $roles[0] ?? '';
		$extra   = array_map( 'sanitize_key', $extra );
		$changed = false;
		foreach ( array_keys( wp_roles()->roles ) as $key ) {
			if ( $key === $primary || ! TMG_Roles_Ranks::can_assign_role( $key ) ) {
				continue; // Roles the actor may not assign are left as they are.
			}
			$want = in_array( $key, $extra, true );
			$has  = in_array( $key, $roles, true );
			if ( $want && ! $has ) {
				$user->add_role( $key );
				$changed = true;
			} elseif ( ! $want && $has ) {
				$user->remove_role( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			TMG_Roles_Capabilities::flush();
			TMG_Roles_Logger::log( 'user_roles', 'user:' . $user_id, array( 'roles' => array_values( get_userdata( $user_id )->roles ) ) );
		}
	}

	public static function save( $user_id ): void {
		$user_id = (int) $user_id;
		if ( empty( $_POST['tmg_roles_profile_nonce'] ) || (int) ( $_POST['user_id'] ?? 0 ) !== $user_id ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tmg_roles_profile_nonce'] ) ), 'tmg_roles_profile_' . $user_id ) ) {
			return;
		}
		static $done = array();
		if ( isset( $done[ $user_id ] ) ) {
			return; // add_role/remove_role do not fire profile_update, but stay safe.
		}
		$done[ $user_id ] = true;
		if ( ! empty( $_POST['tmg_roles_extra_sent'] ) && TMG_Roles::active() ) {
			self::save_extra_roles( $user_id, (array) wp_unslash( $_POST['tmg_roles_extra'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize_key per item.
		}
		if ( ! empty( $_POST['tmg_roles_ov_sent'] ) ) {
			self::save_overrides( $user_id, array_map( 'sanitize_key', (array) wp_unslash( $_POST['tmg_roles_ov'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
	}
}

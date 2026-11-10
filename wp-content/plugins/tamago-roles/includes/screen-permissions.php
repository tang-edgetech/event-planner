<?php
/**
 * Permissions tab: the matrix (permissions grouped in rows × roles in columns) with group
 * "select all", search and raw capabilities per permission; and the protected capabilities box
 * with the "Technical WordPress areas" preset.
 *
 * Screens: Users → Roles & Permissions → Permissions.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Screen_Permissions {

	public static function init(): void {
		add_action( 'admin_post_tmg_roles_matrix', array( __CLASS__, 'handle_matrix' ) );
		add_action( 'admin_post_tmg_roles_protected', array( __CLASS__, 'handle_protected' ) );
	}

	/** Every permission whose caps are all protected for this rank. */
	private static function locked_by_protection( array $p, int $rank ): bool {
		foreach ( $p['caps'] as $cap ) {
			if ( ! TMG_Roles_Capabilities::is_protected_for( $cap, $rank ) ) {
				return false;
			}
		}
		return true;
	}

	public static function render(): void {
		$roles  = TMG_Roles_Ranks::sorted_roles();
		$groups = TMG_Roles_Permissions::grouped();
		$cols   = count( $roles ) + 1;
		?>
		<p class="description"><?php esc_html_e( 'Tick what each role may do. Columns you cannot change are ranked at or above you; permissions you do not hold yourself cannot be handed out. Per-user overrides (Users tab) still apply on top.', 'tamago-roles' ); ?></p>
		<p class="tmg-toolbar">
			<input type="search" class="tmg-roles-search regular-text" data-target="#tmg-matrix" placeholder="<?php esc_attr_e( 'Search permissions or capabilities…', 'tamago-roles' ); ?>">
			<label><input type="checkbox" class="tmg-roles-show-caps" data-target="#tmg-matrix"> <?php esc_html_e( 'Show raw capabilities', 'tamago-roles' ); ?></label>
		</p>
		<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_matrix' ); ?>
		<div class="tmg-matrix-wrap">
		<table class="widefat tmg-roles-matrix" id="tmg-matrix">
			<thead><tr>
				<th><?php esc_html_e( 'Permission', 'tamago-roles' ); ?></th>
				<?php foreach ( $roles as $key => $label ) : ?>
					<th class="c"><?php echo esc_html( $label ); ?><br><span class="description"><?php echo esc_html( sprintf( /* translators: rank */ __( 'rank %d', 'tamago-roles' ), TMG_Roles_Ranks::role_rank( $key ) ) ); ?></span>
					<?php if ( ! TMG_Roles_Ranks::can_edit_role( $key ) ) : ?><br><span class="tmg-badge tmg-badge--muted"><?php esc_html_e( 'locked', 'tamago-roles' ); ?></span><?php endif; ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $groups as $group => $perms ) : ?>
				<tr class="tmg-roles-group" data-group="<?php echo esc_attr( sanitize_title( $group ) ); ?>">
					<th><?php echo esc_html( $group ); ?></th>
					<?php foreach ( $roles as $key => $label ) : ?>
						<td class="c"><?php if ( TMG_Roles_Ranks::can_edit_role( $key ) ) : ?><input type="checkbox" class="tmg-group-all" title="<?php esc_attr_e( 'Select all in this group', 'tamago-roles' ); ?>" data-group="<?php echo esc_attr( sanitize_title( $group ) ); ?>" data-role="<?php echo esc_attr( $key ); ?>"><?php endif; ?></td>
					<?php endforeach; ?>
				</tr>
				<?php foreach ( $perms as $pkey => $p ) : ?>
					<?php $hold = TMG_Roles_User_Profile::may_hand_out( $pkey ); ?>
					<tr class="tmg-roles-row" data-group="<?php echo esc_attr( sanitize_title( $group ) ); ?>" data-search="<?php echo esc_attr( strtolower( $group . ' ' . $p['label'] . ' ' . $p['description'] . ' ' . $pkey . ' ' . implode( ' ', $p['caps'] ) ) ); ?>">
						<td>
							<strong><?php echo esc_html( $p['label'] ); ?></strong>
							<?php if ( '' !== $p['description'] ) : ?><br><span class="description"><?php echo esc_html( $p['description'] ); ?></span><?php endif; ?>
							<div class="tmg-roles-caps"><code><?php echo esc_html( implode( ', ', $p['caps'] ) ); ?></code> <span class="description">(<?php echo esc_html( $pkey ); ?>)</span></div>
							<?php if ( $hold ) : ?><input type="hidden" name="rows[]" value="<?php echo esc_attr( $pkey ); ?>"><?php endif; ?>
						</td>
						<?php foreach ( $roles as $key => $label ) : ?>
							<?php
							$on        = TMG_Roles_Permissions::role_has( $key, $pkey );
							$protected = self::locked_by_protection( $p, TMG_Roles_Ranks::role_rank( $key ) );
							$disabled  = ! $hold || ! TMG_Roles_Ranks::can_edit_role( $key ) || $protected || ( 'administrator' === $key && $on );
							$title     = $protected ? __( 'Protected: only roles at or above the protection rank may hold this.', 'tamago-roles' ) : ( ! $hold ? __( 'You do not hold this permission yourself.', 'tamago-roles' ) : '' );
							?>
							<td class="c<?php echo $protected ? ' is-protected' : ''; ?>"><input type="checkbox" name="perm[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $pkey ); ?>]" value="1" data-role="<?php echo esc_attr( $key ); ?>" <?php checked( $on ); ?> <?php disabled( $disabled ); ?> title="<?php echo esc_attr( $title ); ?>"></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Save permissions', 'tamago-roles' ); ?></button></p>
		</form>
		<?php
		self::render_protected();
	}

	private static function render_protected(): void {
		$caps = (array) TMG_Roles_Settings::get( 'protected_caps' );
		$min  = (int) TMG_Roles_Settings::get( 'protected_min_rank' );
		$top  = TMG_Roles_Ranks::is_top();
		?>
		<h2 id="protected"><?php esc_html_e( 'Protected capabilities', 'tamago-roles' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Only roles at or above the chosen rank may hold these capabilities. They are removed from lower roles and users whatever else grants them (roles, overrides, other plugins). Leave the rank at 0 to switch protection off.', 'tamago-roles' ); ?></p>
		<?php if ( ! $top ) : ?>
			<p><em><?php esc_html_e( 'Only top-rank users can change protected capabilities.', 'tamago-roles' ); ?></em></p>
		<?php endif; ?>
		<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_protected' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="tmg-prot-rank"><?php esc_html_e( 'Minimum rank', 'tamago-roles' ); ?></label></th>
					<td><input id="tmg-prot-rank" type="number" min="0" name="min_rank" class="small-text" value="<?php echo (int) $min; ?>" <?php disabled( ! $top || TMG_Roles_Settings::is_locked( 'protected_min_rank' ) ); ?>>
					<?php echo TMG_Roles_Admin_Screen::locked_note( 'protected_min_rank' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th><label for="tmg-prot-caps"><?php esc_html_e( 'Capabilities', 'tamago-roles' ); ?></label></th>
					<td><textarea id="tmg-prot-caps" name="caps" rows="6" class="large-text code" <?php disabled( ! $top ); ?>><?php echo esc_textarea( implode( "\n", $caps ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line (or comma-separated).', 'tamago-roles' ); ?></p></td></tr>
			</table>
			<?php if ( $top ) : ?>
				<p class="submit">
					<button class="button button-primary" name="do" value="save"><?php esc_html_e( 'Save protected capabilities', 'tamago-roles' ); ?></button>
					<button class="button" name="do" value="technical" title="<?php echo esc_attr( implode( ', ', TMG_Roles_Settings::technical_preset() ) ); ?>"><?php esc_html_e( 'Add preset: Technical WordPress areas', 'tamago-roles' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'The preset covers posts, pages, categories, comments, appearance, plugins, WordPress settings, import/export, file editing, updates, dashboard and links.', 'tamago-roles' ); ?></p>
			<?php endif; ?>
		</form>
		<?php
	}

	public static function handle_matrix(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_matrix' );
		// phpcs:disable WordPress.Security.NonceVerification -- guarded above.
		$rows = array_map( 'sanitize_key', (array) wp_unslash( $_POST['rows'] ?? array() ) );
		$in   = (array) wp_unslash( $_POST['perm'] ?? array() );
		// phpcs:enable
		$changed = array();
		foreach ( array_keys( wp_roles()->roles ) as $role ) {
			if ( ! TMG_Roles_Ranks::can_edit_role( $role ) ) {
				continue;
			}
			$rank = TMG_Roles_Ranks::role_rank( $role );
			$set  = array();
			foreach ( $rows as $pkey ) {
				$p = TMG_Roles_Permissions::get( $pkey );
				if ( ! $p || ! TMG_Roles_User_Profile::may_hand_out( $pkey ) || self::locked_by_protection( $p, $rank ) ) {
					continue;
				}
				$on = ! empty( $in[ $role ][ $pkey ] );
				if ( 'administrator' === $role && ! $on ) {
					continue; // Never reduced.
				}
				if ( TMG_Roles_Permissions::role_has( $role, $pkey ) !== $on ) {
					$set[ $pkey ] = $on;
				}
			}
			if ( $set ) {
				TMG_Roles_Role_Manager::apply_matrix( $role, $set );
				$changed[] = $role;
			}
		}
		TMG_Roles_Admin_Screen::back( 'permissions', array(), $changed ? sprintf( /* translators: role keys */ __( 'Permissions saved for: %s.', 'tamago-roles' ), implode( ', ', $changed ) ) : __( 'Nothing changed.', 'tamago-roles' ) );
	}

	public static function handle_protected(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_protected' );
		if ( ! TMG_Roles_Ranks::is_top() ) {
			TMG_Roles_Admin_Screen::back( 'permissions', array(), '', __( 'Only top-rank users can change protected capabilities.', 'tamago-roles' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- guarded above.
		$caps = TMG_Roles_Settings::cap_list( sanitize_textarea_field( wp_unslash( $_POST['caps'] ?? '' ) ) );
		$do   = sanitize_key( wp_unslash( $_POST['do'] ?? 'save' ) );
		$min  = max( 0, (int) ( $_POST['min_rank'] ?? 0 ) );
		// phpcs:enable
		if ( 'technical' === $do ) {
			$caps = array_values( array_unique( array_merge( $caps, TMG_Roles_Settings::technical_preset() ) ) );
			if ( $min <= 0 ) {
				$min = TMG_Roles_Ranks::top();
			}
		}
		// Never protect this plugin's own capabilities away from the person saving.
		$caps = array_values( array_diff( $caps, array( TMG_Roles::CAP_MANAGE, TMG_Roles::CAP_MANAGE_USERS ) ) );
		TMG_Roles_Settings::update( array( 'protected_caps' => $caps, 'protected_min_rank' => $min ), 'protected' );
		TMG_Roles_Admin_Screen::back( 'permissions', array(), __( 'Protected capabilities saved.', 'tamago-roles' ) );
	}
}

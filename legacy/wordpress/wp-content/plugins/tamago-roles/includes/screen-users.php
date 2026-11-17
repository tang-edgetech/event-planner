<?php
/**
 * Users tab: users the current user may manage, with roles, rank and overrides; per-user
 * permission overrides (grant / deny) with the effective result.
 *
 * Screens: Roles & Permissions → Users (and ?user=<id>).
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Screen_Users {

	public static function init(): void {
		add_action( 'admin_post_tmg_roles_user_overrides', array( __CLASS__, 'handle_overrides' ) );
	}

	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$uid    = absint( $_GET['user'] ?? 0 );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		if ( $uid ) {
			self::render_user( $uid );
			return;
		}
		$args = array(
			'number'  => 30,
			'paged'   => $paged,
			'orderby' => 'display_name',
			'count_total' => true,
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$q     = new WP_User_Query( $args );
		$total = (int) $q->get_total();
		?>
		<p class="description"><?php esc_html_e( 'Give an individual user extra permissions, or take some away, without changing their role. You can only adjust users ranked below you. Extra roles are set on the user\'s profile.', 'tamago-roles' ); ?></p>
		<form method="get" class="tmg-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( TMG_Roles::PAGE ); ?>"><input type="hidden" name="tab" value="users">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search users…', 'tamago-roles' ); ?>"> <button class="button"><?php esc_html_e( 'Search', 'tamago-roles' ); ?></button>
		</form>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'User', 'tamago-roles' ); ?></th><th><?php esc_html_e( 'Roles', 'tamago-roles' ); ?></th><th class="c"><?php esc_html_e( 'Rank', 'tamago-roles' ); ?></th><th class="c"><?php esc_html_e( 'Overrides', 'tamago-roles' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $q->get_results() as $u ) : ?>
				<?php $over = TMG_Roles_Capabilities::overrides( $u->ID ); ?>
				<tr>
					<td><strong><?php echo esc_html( $u->display_name ); ?></strong><br><span class="description"><?php echo esc_html( $u->user_login . ' · ' . $u->user_email ); ?></span></td>
					<td><?php echo esc_html( implode( ', ', array_map( fn( $r ) => wp_roles()->role_names[ $r ] ?? $r, (array) $u->roles ) ) ); ?></td>
					<td class="c"><?php echo (int) TMG_Roles_Ranks::user_rank( $u ); ?></td>
					<td class="c"><?php echo $over ? (int) count( $over ) : '&mdash;'; ?></td>
					<td>
						<?php if ( TMG_Roles_User_Profile::can_override( $u->ID ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( TMG_Roles::screen_url( array( 'tab' => 'users', 'user' => $u->ID ) ) ); ?>"><?php esc_html_e( 'Overrides', 'tamago-roles' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( 'edit_user', $u->ID ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php esc_html_e( 'Profile & roles', 'tamago-roles' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / 30 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
		}
	}

	private static function render_user( int $uid ): void {
		$user = get_userdata( $uid );
		echo '<p><a href="' . esc_url( TMG_Roles::screen_url( array( 'tab' => 'users' ) ) ) . '">&larr; ' . esc_html__( 'All users', 'tamago-roles' ) . '</a></p>';
		if ( ! $user || ! TMG_Roles_User_Profile::can_override( $uid ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'You can only adjust users ranked below you.', 'tamago-roles' ) . '</p></div>';
			return;
		}
		echo '<h2>' . esc_html( $user->display_name ) . ' <span class="description">(' . esc_html( implode( ', ', (array) $user->roles ) ) . ')</span></h2>';
		TMG_Roles_Admin_Screen::form_open( 'tmg_roles_user_overrides', array( 'user' => $uid ) );
		TMG_Roles_User_Profile::overrides_table( $user );
		echo '<p class="submit"><button class="button button-primary">' . esc_html__( 'Save overrides', 'tamago-roles' ) . '</button></p></form>';
	}

	public static function handle_overrides(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_user_overrides', TMG_Roles::CAP_MANAGE_USERS );
		// phpcs:disable WordPress.Security.NonceVerification -- guarded above.
		$uid = absint( $_POST['user'] ?? 0 );
		$in  = array_map( 'sanitize_key', (array) wp_unslash( $_POST['tmg_roles_ov'] ?? array() ) );
		// phpcs:enable
		$res = TMG_Roles_User_Profile::save_overrides( $uid, $in );
		if ( is_wp_error( $res ) ) {
			TMG_Roles_Admin_Screen::back( 'users', array(), '', $res->get_error_message() );
		}
		TMG_Roles_Admin_Screen::back( 'users', array( 'user' => $uid ), __( 'Overrides saved.', 'tamago-roles' ) );
	}
}

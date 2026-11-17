<?php
/**
 * Settings tab: rank rules, staff public name, admin-rule scope, log size, delete-data switch,
 * settings JSON export / import (with a diff to confirm) and safe-mode instructions.
 *
 * Screens: Roles & Permissions → Settings.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Screen_Settings {

	const MAX_IMPORT = 2097152; // 2 MB.

	public static function init(): void {
		add_action( 'admin_post_tmg_roles_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_tmg_roles_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_tmg_roles_import_preview', array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_tmg_roles_import_apply', array( __CLASS__, 'handle_apply' ) );
	}

	private static function transient(): string {
		return 'tmg_roles_import_' . get_current_user_id();
	}

	private static function checkbox( string $key, string $label, string $help = '' ): void {
		$locked = TMG_Roles_Settings::is_locked( $key );
		echo '<label><input type="checkbox" name="s[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) TMG_Roles_Settings::get( $key ), true, false ) . ' ' . disabled( $locked, true, false ) . '> ' . esc_html( $label ) . '</label>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo TMG_Roles_Admin_Screen::locked_note( $key ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function render(): void {
		$s   = TMG_Roles_Settings::all();
		$top = TMG_Roles_Ranks::is_top();
		$preview = isset( $_GET['import'] ) ? get_transient( self::transient() ) : false; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $preview && $top ) {
			self::render_preview( (array) $preview );
			return;
		}
		?>
		<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_settings' ); ?>
		<h2><?php esc_html_e( 'Rank rules', 'tamago-roles' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'User list', 'tamago-roles' ); ?></th><td><?php self::checkbox( 'hide_higher_users', __( 'Hide users ranked above the viewer from user lists', 'tamago-roles' ), __( 'Applies to wp-admin and the REST API. Top-rank users always see everyone.', 'tamago-roles' ) ); ?>
				<p><label><?php esc_html_e( 'Only hide users ranked at or above', 'tamago-roles' ); ?> <input type="number" min="0" name="s[hide_higher_min_rank]" class="small-text" value="<?php echo (int) $s['hide_higher_min_rank']; ?>" <?php disabled( TMG_Roles_Settings::is_locked( 'hide_higher_min_rank' ) ); ?>></label> <span class="description"><?php esc_html_e( '0 = hide everyone ranked above the viewer.', 'tamago-roles' ); ?></span></p><?php echo TMG_Roles_Admin_Screen::locked_note( 'hide_higher_min_rank' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<tr><th><?php esc_html_e( 'Same rank', 'tamago-roles' ); ?></th><td><?php self::checkbox( 'peer_management', __( 'Users may also manage users and assign roles of their own rank', 'tamago-roles' ), __( 'Off: strictly below their own rank only (top rank can always manage its peers).', 'tamago-roles' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Unranked roles', 'tamago-roles' ); ?></th><td><?php self::checkbox( 'assign_unranked', __( 'Roles with rank 0 may be assigned by anyone who can assign roles', 'tamago-roles' ), __( 'Off: only top-rank users can assign roles that have no rank.', 'tamago-roles' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Administrators', 'tamago-roles' ); ?></th><td><?php self::checkbox( 'restrict_admins', __( 'Apply admin experience rules (menus, toolbar, widgets, redirects) to administrators too', 'tamago-roles' ), __( 'The Roles & Permissions screen always stays available to administrators.', 'tamago-roles' ) ); ?></td></tr>
		</table>

		<h2><?php esc_html_e( 'Public name for staff', 'tamago-roles' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Staff roles', 'tamago-roles' ); ?></th><td>
				<?php foreach ( TMG_Roles_Ranks::sorted_roles() as $k => $l ) : ?>
					<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="s[public_name_roles][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['public_name_roles'], true ) ); ?>> <?php echo esc_html( $l ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Users with these roles appear under the public name as authors and commenters, in oEmbed data and the REST API; they are left out of REST user lists and sitemaps, and their author archives return 404.', 'tamago-roles' ); ?></p>
			</td></tr>
			<tr><th><label for="tmg-pn"><?php esc_html_e( 'Public name', 'tamago-roles' ); ?></label></th><td><input id="tmg-pn" name="s[public_name]" class="regular-text" value="<?php echo esc_attr( (string) $s['public_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" <?php disabled( TMG_Roles_Settings::is_locked( 'public_name' ) ); ?>> <span class="description"><?php esc_html_e( 'Empty = site name.', 'tamago-roles' ); ?></span><?php echo TMG_Roles_Admin_Screen::locked_note( 'public_name' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<tr><th><label for="tmg-pr"><?php esc_html_e( 'Who sees real names', 'tamago-roles' ); ?></label></th><td><input id="tmg-pr" type="number" min="0" name="s[public_name_reveal_rank]" class="small-text" value="<?php echo (int) $s['public_name_reveal_rank']; ?>" <?php disabled( TMG_Roles_Settings::is_locked( 'public_name_reveal_rank' ) ); ?>> <span class="description"><?php esc_html_e( 'Logged-in users at or above this rank see real names. 0 = top rank only.', 'tamago-roles' ); ?></span><?php echo TMG_Roles_Admin_Screen::locked_note( 'public_name_reveal_rank' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<tr><th><?php esc_html_e( 'REST single user', 'tamago-roles' ); ?></th><td><?php self::checkbox( 'public_name_rest_hide', __( 'Answer /wp/v2/users/<id> of staff with "not found"', 'tamago-roles' ), __( 'Off: the user is returned under the public name, without slug, links or avatar.', 'tamago-roles' ) ); ?></td></tr>
		</table>

		<h2><?php esc_html_e( 'Log and data', 'tamago-roles' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><label for="tmg-ll"><?php esc_html_e( 'Keep log entries', 'tamago-roles' ); ?></label></th><td><input id="tmg-ll" type="number" min="100" name="s[log_limit]" class="small-text" value="<?php echo (int) $s['log_limit']; ?>" <?php disabled( TMG_Roles_Settings::is_locked( 'log_limit' ) ); ?>><?php echo TMG_Roles_Admin_Screen::locked_note( 'log_limit' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<tr><th><?php esc_html_e( 'Uninstall', 'tamago-roles' ); ?></th><td><?php self::checkbox( 'delete_data', __( 'Delete all data when the plugin is deleted', 'tamago-roles' ), __( 'Removes settings, the log table, overrides, content rules and this plugin\'s capabilities. Roles you created stay.', 'tamago-roles' ) ); ?></td></tr>
		</table>
		<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Save settings', 'tamago-roles' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Export / Import settings', 'tamago-roles' ); ?></h2>
		<div class="tmg-roles-columns">
			<div class="tmg-card">
				<h3><?php esc_html_e( 'Export', 'tamago-roles' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Roles (names, ranks, capabilities), matrix, default role, protected capabilities, menu / toolbar / widget rules, redirects, content defaults and term rules, privacy settings. Per-user overrides and per-item rules are not included.', 'tamago-roles' ); ?></p>
				<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_export' ); ?>
					<button class="button"><?php esc_html_e( 'Download JSON', 'tamago-roles' ); ?></button>
				</form>
			</div>
			<div class="tmg-card">
				<h3><?php esc_html_e( 'Import', 'tamago-roles' ); ?></h3>
				<?php if ( $top ) : ?>
					<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_import_preview', array(), 'enctype="multipart/form-data"' ); ?>
						<p><input type="file" name="file" accept=".json,application/json"></p>
						<p><label><?php esc_html_e( 'or paste JSON', 'tamago-roles' ); ?><br><textarea name="json" rows="4" class="large-text code"></textarea></label></p>
						<p><button class="button"><?php esc_html_e( 'Preview changes', 'tamago-roles' ); ?></button></p>
					</form>
					<p class="description"><?php esc_html_e( 'You will see every change before anything is applied. Roles not in the file are left alone, and the import never removes your own access.', 'tamago-roles' ); ?></p>
				<?php else : ?>
					<p><em><?php esc_html_e( 'Only top-rank users can import settings.', 'tamago-roles' ); ?></em></p>
				<?php endif; ?>
			</div>
			<div class="tmg-card">
				<h3><?php esc_html_e( 'Safe mode (recovery)', 'tamago-roles' ); ?></h3>
				<p><?php esc_html_e( 'If a setting ever locks people out, add this line to wp-config.php (above "That\'s all, stop editing!"). All restrictions stop until you remove it:', 'tamago-roles' ); ?></p>
				<p><code>define( 'TMG_ROLES_SAFE_MODE', true );</code></p>
				<p class="description"><?php echo TMG_Roles::safe_mode() ? esc_html__( 'Safe mode is ON right now.', 'tamago-roles' ) : esc_html__( 'Safe mode is off.', 'tamago-roles' ); ?> <?php esc_html_e( 'From the command line: wp tamago-roles safe-mode', 'tamago-roles' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function render_preview( array $data ): void {
		$plan = TMG_Roles_Transfer::plan( $data, wp_get_current_user() );
		?>
		<h2><?php esc_html_e( 'Import preview', 'tamago-roles' ); ?></h2>
		<p><?php echo esc_html( sprintf( /* translators: 1: date, 2: version */ __( 'File exported %1$s by Tamago Roles %2$s.', 'tamago-roles' ), (string) ( $data['exported'] ?? '?' ), (string) ( $data['version'] ?? '?' ) ) ); ?></p>
		<?php if ( $plan['notes'] ) : ?>
			<div class="notice notice-info inline"><ul class="ul-disc"><?php foreach ( $plan['notes'] as $n ) : ?><li><?php echo esc_html( $n ); ?></li><?php endforeach; ?></ul></div>
		<?php endif; ?>
		<?php if ( ! $plan['changes'] ) : ?>
			<p><strong><?php esc_html_e( 'Nothing would change: this site already matches the file.', 'tamago-roles' ); ?></strong></p>
		<?php else : ?>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html( sprintf( /* translators: count */ __( '%d changes', 'tamago-roles' ), count( $plan['changes'] ) ) ); ?></th></tr></thead><tbody>
			<?php foreach ( $plan['changes'] as $c ) : ?><tr><td><code><?php echo esc_html( $c ); ?></code></td></tr><?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_import_apply', array(), 'class="tmg-inline"' ); ?>
			<p class="submit"><button class="button button-primary" name="do" value="apply" <?php disabled( ! $plan['changes'] ); ?>><?php esc_html_e( 'Apply these changes', 'tamago-roles' ); ?></button>
			<button class="button" name="do" value="cancel"><?php esc_html_e( 'Cancel', 'tamago-roles' ); ?></button></p>
		</form>
		<?php
	}

	public static function handle_save(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_settings' );
		$in = (array) wp_unslash( $_POST['s'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- guarded; sanitized per key by TMG_Roles_Settings::sanitize().
		$values = array(
			'hide_higher_users'       => ! empty( $in['hide_higher_users'] ),
			'hide_higher_min_rank'    => (int) ( $in['hide_higher_min_rank'] ?? 0 ),
			'peer_management'         => ! empty( $in['peer_management'] ),
			'assign_unranked'         => ! empty( $in['assign_unranked'] ),
			'restrict_admins'         => ! empty( $in['restrict_admins'] ),
			'public_name_roles'       => (array) ( $in['public_name_roles'] ?? array() ),
			'public_name'             => (string) ( $in['public_name'] ?? '' ),
			'public_name_reveal_rank' => (int) ( $in['public_name_reveal_rank'] ?? 0 ),
			'public_name_rest_hide'   => ! empty( $in['public_name_rest_hide'] ),
			'log_limit'               => max( 100, (int) ( $in['log_limit'] ?? 5000 ) ),
			'delete_data'             => ! empty( $in['delete_data'] ),
		);
		// Rank rules shape what top users can do to everyone; only top rank may change them.
		if ( ! TMG_Roles_Ranks::is_top() ) {
			unset( $values['hide_higher_users'], $values['hide_higher_min_rank'], $values['peer_management'], $values['assign_unranked'], $values['restrict_admins'], $values['delete_data'], $values['public_name_reveal_rank'] );
		}
		TMG_Roles_Settings::update( $values, 'settings' );
		TMG_Roles_Admin_Screen::back( 'settings', array(), __( 'Settings saved.', 'tamago-roles' ) );
	}

	public static function handle_export(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_export' );
		TMG_Roles_Logger::log( 'export', 'settings' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tamago-roles-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo TMG_Roles_Transfer::export_json(); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
		exit;
	}

	public static function handle_preview(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_import_preview' );
		if ( ! TMG_Roles_Ranks::is_top() ) {
			TMG_Roles_Admin_Screen::back( 'settings', array(), '', __( 'Only top-rank users can import settings.', 'tamago-roles' ) );
		}
		$json = '';
		// phpcs:disable WordPress.Security.NonceVerification -- guarded above.
		if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$name = sanitize_file_name( wp_unslash( $_FILES['file']['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$size = (int) ( $_FILES['file']['size'] ?? 0 );
			if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || $size > self::MAX_IMPORT ) {
				TMG_Roles_Admin_Screen::back( 'settings', array(), '', __( 'Please upload a .json file under 2 MB.', 'tamago-roles' ) );
			}
			$json = (string) file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.Security.ValidatedSanitizedInput
			wp_delete_file( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( ! empty( $_POST['json'] ) ) {
			$json = (string) wp_unslash( $_POST['json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded and validated below.
		}
		// phpcs:enable
		if ( strlen( $json ) > self::MAX_IMPORT ) {
			$json = '';
		}
		$data = TMG_Roles_Transfer::decode( $json );
		if ( is_wp_error( $data ) ) {
			TMG_Roles_Admin_Screen::back( 'settings', array(), '', $data->get_error_message() );
		}
		set_transient( self::transient(), $data, 15 * MINUTE_IN_SECONDS );
		TMG_Roles_Admin_Screen::back( 'settings', array( 'import' => 'preview' ) );
	}

	public static function handle_apply(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_import_apply' );
		$data = get_transient( self::transient() );
		delete_transient( self::transient() );
		if ( 'apply' !== sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			TMG_Roles_Admin_Screen::back( 'settings', array(), __( 'Import cancelled.', 'tamago-roles' ) );
		}
		if ( ! is_array( $data ) || ! TMG_Roles_Ranks::is_top() ) {
			TMG_Roles_Admin_Screen::back( 'settings', array(), '', __( 'The import expired or is not allowed. Please upload the file again.', 'tamago-roles' ) );
		}
		$plan = TMG_Roles_Transfer::plan( $data, wp_get_current_user() );
		TMG_Roles_Transfer::apply( $plan );
		TMG_Roles_Admin_Screen::back( 'settings', array(), sprintf( /* translators: count */ __( 'Import applied: %d changes.', 'tamago-roles' ), count( $plan['changes'] ) ) );
	}
}

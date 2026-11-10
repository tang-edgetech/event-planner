<?php
/**
 * Log tab: who changed which role, permission, override or setting, and when; search, paging and CSV export.
 *
 * Screens: Users → Roles & Permissions → Log.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Screen_Log {

	public static function init(): void {
		add_action( 'admin_post_tmg_roles_log_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:enable
		$data  = TMG_Roles_Logger::rows( $paged, 50, $search );
		$pages = (int) ceil( $data['total'] / 50 );
		?>
		<div class="tmg-toolbar">
			<form method="get" class="tmg-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( TMG_Roles::PAGE ); ?>"><input type="hidden" name="tab" value="log">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search the log…', 'tamago-roles' ); ?>"> <button class="button"><?php esc_html_e( 'Search', 'tamago-roles' ); ?></button>
			</form>
			<?php TMG_Roles_Admin_Screen::form_open( 'tmg_roles_log_export', array(), 'class="tmg-inline"' ); ?>
				<button class="button"><?php esc_html_e( 'Export CSV', 'tamago-roles' ); ?></button>
			</form>
		</div>
		<table class="widefat striped tmg-log">
			<thead><tr><th><?php esc_html_e( 'When (UTC)', 'tamago-roles' ); ?></th><th><?php esc_html_e( 'Who', 'tamago-roles' ); ?></th><th><?php esc_html_e( 'Change', 'tamago-roles' ); ?></th><th><?php esc_html_e( 'Object', 'tamago-roles' ); ?></th><th><?php esc_html_e( 'Details', 'tamago-roles' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $data['rows'] ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No changes logged yet.', 'tamago-roles' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $data['rows'] as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['created'] ); ?></td>
					<td><?php echo esc_html( '' !== $r['user_login'] ? $r['user_login'] : '—' ); ?></td>
					<td><code><?php echo esc_html( $r['action'] ); ?></code></td>
					<td><?php echo esc_html( $r['object'] ); ?></td>
					<td><details><summary><?php esc_html_e( 'show', 'tamago-roles' ); ?></summary><pre><?php echo esc_html( (string) wp_json_encode( json_decode( (string) $r['details'], true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
		}
	}

	public static function handle_export(): void {
		TMG_Roles_Admin_Screen::guard( 'tmg_roles_log_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tamago-roles-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'id', 'created_utc', 'user_id', 'user_login', 'action', 'object', 'details' ), ',', '"', '' );
		foreach ( TMG_Roles_Logger::all_rows() as $r ) {
			$row = array( $r['id'], $r['created'], $r['user_id'], $r['user_login'], $r['action'], $r['object'], $r['details'] );
			// Spreadsheet formula injection guard.
			$row = array_map( fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v, $row );
			fputcsv( $out, $row, ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}

<?php
/**
 * Change log: a custom table of who changed which role, permission, override or setting, and when.
 * Capped to the "log_limit" setting (oldest rows are pruned).
 *
 * Used by: every module that changes something; Screens: Roles & Permissions → Log.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Logger {

	const DB_VERSION = '1';

	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tmg_roles_log';
	}

	public static function maybe_install(): void {
		if ( get_option( 'tmg_roles_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_login varchar(60) NOT NULL DEFAULT '',
				action varchar(64) NOT NULL DEFAULT '',
				object varchar(255) NOT NULL DEFAULT '',
				details longtext NULL,
				PRIMARY KEY  (id),
				KEY created (created)
			) {$charset};"
		);
		update_option( 'tmg_roles_db_version', self::DB_VERSION, false );
	}

	/**
	 * Record a change and fire tmg_roles_changed.
	 *
	 * @param string $action  Short machine name, e.g. role_created, matrix, override.
	 * @param string $object  What changed (role key, user ID, setting keys …).
	 * @param mixed  $details Anything JSON-encodable.
	 */
	public static function log( string $action, string $object, $details = array() ): void {
		global $wpdb;
		$user = wp_get_current_user();
		$who  = $user->exists() ? $user->user_login : ( ( defined( 'WP_CLI' ) && WP_CLI ) ? 'wp-cli' : '' );
		if ( get_option( 'tmg_roles_db_version' ) === self::DB_VERSION ) {
			$wpdb->insert(
				self::table(),
				array(
					'created'    => current_time( 'mysql', true ),
					'user_id'    => (int) $user->ID,
					'user_login' => substr( (string) $who, 0, 60 ),
					'action'     => substr( $action, 0, 64 ),
					'object'     => substr( $object, 0, 255 ),
					'details'    => wp_json_encode( $details ),
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			self::prune();
		}

		/**
		 * Something in Tamago Roles changed.
		 *
		 * @param string $action  Machine name of the change.
		 * @param string $object  Role key, user ID or setting keys.
		 * @param mixed  $details Before/after data.
		 */
		do_action( 'tmg_roles_changed', $action, $object, $details );
	}

	private static function prune(): void {
		global $wpdb;
		$limit = max( 100, (int) TMG_Roles_Settings::get( 'log_limit' ) );
		$table = self::table();
		// Only check now and then; deleting on every insert is wasteful.
		if ( wp_rand( 1, 20 ) !== 1 ) {
			return;
		}
		$cut = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $cut ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public static function rows( int $page = 1, int $per_page = 50, string $search = '' ): array {
		global $wpdb;
		$table  = self::table();
		$offset = max( 0, ( $page - 1 ) * $per_page );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action LIKE %s OR object LIKE %s OR user_login LIKE %s OR details LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d", $like, $like, $like, $like, $per_page, $offset ), ARRAY_A ); // phpcs:ignore
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action LIKE %s OR object LIKE %s OR user_login LIKE %s OR details LIKE %s", $like, $like, $like, $like ) ); // phpcs:ignore
		} else {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ); // phpcs:ignore
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		}
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	public static function all_rows(): array {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore
	}

	public static function drop(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore
		delete_option( 'tmg_roles_db_version' );
	}
}

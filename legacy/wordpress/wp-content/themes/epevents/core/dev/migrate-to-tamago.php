<?php
/**
 * One-time move of a site from the theme's old built-in features (EP Events ≤ 2.x) to the Tamago
 * plugins. Safe to run again: each part runs once and is remembered in the `epc_tamago_migrated` option.
 *
 *   wp plugin activate tamago-smtp tamago-reports tamago-io tamago-roles
 *   wp eval-file wp-content/themes/epevents/core/dev/migrate-to-tamago.php
 *
 * Moves: email server + sender settings (password re-encrypted), tracking settings and every saved
 * visit (registrations become the "registration" goal), roles: ranks, the permission matrix as it is
 * now, per-user overrides, the developer-only areas, menus, admin bar, login redirect, staff names.
 * Then removes the old options, capabilities, the epc_visits table and its cron job.
 *
 * Used by: WP-CLI only (dev / deployment step, see docs/event-plus-settings.md).
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

foreach ( array( 'TMG_SMTP_Settings', 'TMG_Reports_Settings', 'TMG_Roles_Settings', 'TMG_IO_Permissions' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		WP_CLI::error( 'Activate the four Tamago plugins first (missing ' . $class . ').' );
	}
}

global $wpdb;
$done = (array) get_option( 'epc_tamago_migrated', array() );
$opt  = fn( string $name, $default = '' ) => get_option( 'options_' . $name, $default );

/* --------------------------------------------------------------------------
 * 1. Email: server, sender, password.
 * ----------------------------------------------------------------------- */
if ( empty( $done['smtp'] ) ) {
	$pass   = '';
	$stored = (string) $opt( 'epc_smtp_pass' );
	if ( 0 === strpos( $stored, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
		$raw = base64_decode( substr( $stored, 4 ), true );
		$key = hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . 'epc-smtp', true );
		if ( false !== $raw && strlen( $raw ) > 16 ) {
			$pass = (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		}
	} elseif ( '' !== $stored ) {
		$pass = $stored;
	}
	$secure   = (string) $opt( 'epc_smtp_secure', 'tls' );
	$settings = array(
		'enabled'          => (bool) $opt( 'epc_smtp_enable', false ),
		'provider'         => 'other',
		'host'             => (string) $opt( 'epc_smtp_host' ),
		'port'             => (int) $opt( 'epc_smtp_port', 0 ) ?: ( 'ssl' === $secure ? 465 : ( 'none' === $secure ? 25 : 587 ) ),
		'encryption'       => in_array( $secure, array( 'none', 'ssl', 'tls' ), true ) ? $secure : 'tls',
		'auth'             => '' === (string) $opt( 'epc_smtp_auth', '1' ) ? false : (bool) $opt( 'epc_smtp_auth', '1' ),
		'username'         => (string) $opt( 'epc_smtp_user' ),
		'from_email'       => (string) $opt( 'epc_mail_from_email' ),
		'from_name'        => (string) $opt( 'epc_mail_from_name' ),
		'force_from_email' => true,
		'force_from_name'  => true,
	);
	if ( '' !== $pass ) {
		$settings['password'] = $pass;
	}
	TMG_SMTP_Settings::save( $settings, true );
	$done['smtp'] = time();
	WP_CLI::log( 'Email settings moved to Tamago SMTP' . ( '' !== $pass ? ' (password re-encrypted)' : '' ) . '.' );
}

/* --------------------------------------------------------------------------
 * 2. Reports: tracking settings, then every saved visit.
 * ----------------------------------------------------------------------- */
if ( empty( $done['reports'] ) ) {
	$exclude = array();
	if ( ! $opt( 'epc_track_admins', false ) ) {
		foreach ( wp_roles()->roles as $slug => $role ) {
			if ( ! empty( $role['capabilities']['edit_posts'] ) || ! empty( $role['capabilities']['edit_ep_events'] ) ) {
				$exclude[] = $slug;
			}
		}
	}
	$geo = (string) $opt( 'epc_geo_provider', 'headers' );
	TMG_Reports_Settings::save(
		array(
			'enabled'        => '' === (string) $opt( 'epc_track_enabled', '1' ) ? false : (bool) $opt( 'epc_track_enabled', '1' ),
			'exclude_roles'  => $exclude,
			'respect_dnt'    => (bool) $opt( 'epc_track_dnt', false ),
			'consent_mode'   => 'wait', // Consent is given by the theme at once (core/tamago.php): same as before.
			'geo_provider'   => in_array( $geo, array( 'none', 'headers', 'ipapi', 'ipinfo' ), true ) ? $geo : 'headers',
			'geo_token'      => (string) $opt( 'epc_geo_token' ),
			'retention_days' => (int) $opt( 'epc_track_retention', 730 ),
		)
	);
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'epc_visits' ) ) ) {
		WP_CLI::runcommand( 'tamago-reports copy-legacy --goal=registration' );
	}
	$done['reports'] = time();
	WP_CLI::log( 'Tracking settings and visits moved to Tamago Reports.' );
}

/* --------------------------------------------------------------------------
 * 3. Roles & permissions.
 * ----------------------------------------------------------------------- */
if ( empty( $done['roles'] ) ) {
	// Old permissions now provided by the plugins: old capability => new permission keys.
	$moved = array(
		'epc_view_reports'       => array( 'reports_view' ),
		'epc_export_reports'     => array( 'reports_export' ),
		'epc_export'             => array( 'io_export' ),
		'epc_import'             => array( 'io_import' ),
		'epc_manage_permissions' => array( 'tmg_roles_manage', 'tmg_roles_manage_users' ),
		'epc_manage_settings'    => array( 'smtp_settings', 'smtp_logs', 'reports_manage' ), // Settings used to include email + tracking.
	);
	$old_keys = array( 'reports_view' => 'reports_view', 'reports_export' => 'reports_export', 'data_export' => 'io_export', 'data_import' => 'io_import', 'permissions' => 'tmg_roles_manage' );
	$retired  = array( 'epc_view_reports', 'epc_export_reports', 'epc_export', 'epc_import', 'epc_manage_permissions', 'epc_manage_users' );

	TMG_Roles_Settings::update( array( 'ranks' => array( 'administrator' => 4, 'editor' => 0, 'author' => 0, 'contributor' => 0, 'subscriber' => 0 ) ), 'migration' );
	foreach ( array( 'ep_developer' => array( 'Developer', 4 ), 'ep_super_admin' => array( 'Super Admin', 3 ), 'ep_manager' => array( 'Manager', 2 ), 'ep_viewer' => array( 'Viewer', 1 ) ) as $key => $r ) {
		if ( ! get_role( $key ) ) {
			TMG_Roles_Role_Manager::create( $key, $r[0], $r[1], array( 'read' => true ), 'migration' );
		}
		TMG_Roles_Role_Manager::set_rank( $key, $r[1] );
	}

	// Developer and Administrator hold every permission, the plugins' ones included.
	foreach ( array( 'administrator', 'ep_developer' ) as $key ) {
		foreach ( array_keys( TMG_Roles_Permissions::all() ) as $perm ) {
			if ( 'registered' === ( TMG_Roles_Permissions::all()[ $perm ]['source'] ?? '' ) ) {
				TMG_Roles_Role_Manager::grant( $key, $perm );
			}
		}
	}
	// Other roles keep exactly what the old matrix gave them, under the new permission names.
	foreach ( array( 'ep_super_admin', 'ep_manager', 'ep_viewer' ) as $key ) {
		$role = get_role( $key );
		foreach ( $moved as $cap => $perms ) {
			if ( $role && ! empty( $role->capabilities[ $cap ] ) ) {
				foreach ( $perms as $perm ) {
					TMG_Roles_Role_Manager::grant( $key, $perm );
				}
			}
		}
	}
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $retired as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	// Per-user overrides: were written into the user's own capabilities; now stored as overrides.
	$perm_caps = array();
	foreach ( TMG_Roles_Permissions::all() as $p ) {
		$perm_caps = array_merge( $perm_caps, (array) ( $p['caps'] ?? array() ) );
	}
	$perm_caps = array_unique( array_merge( $perm_caps, $retired, TMG_Roles_Settings::technical_preset() ) );
	foreach ( get_users( array( 'meta_key' => 'epc_overrides', 'fields' => 'all' ) ) as $user ) { // phpcs:ignore WordPress.DB.SlowDBQuery
		$new = array();
		foreach ( (array) get_user_meta( $user->ID, 'epc_overrides', true ) as $key => $mode ) {
			$key = $old_keys[ $key ] ?? $key;
			$new[ $key ] = 'allow' === $mode ? 'grant' : 'deny';
			if ( 'tmg_roles_manage' === $key ) {
				$new['tmg_roles_manage_users'] = $new[ $key ];
			}
		}
		foreach ( $perm_caps as $cap ) {
			if ( array_key_exists( $cap, $user->caps ) && ! wp_roles()->is_role( $cap ) ) {
				$user->remove_cap( $cap );
			}
		}
		TMG_Roles_Capabilities::set_overrides( $user->ID, $new );
		delete_user_meta( $user->ID, 'epc_overrides' );
	}

	// The old rules: developer-only WordPress areas, menus, admin bar, landing page, staff names.
	$staff = array( 'ep_super_admin', 'ep_manager', 'ep_viewer' );
	TMG_Roles_Settings::update(
		array(
			'protected_caps'          => TMG_Roles_Settings::technical_preset(),
			'protected_min_rank'      => 4,
			'hide_higher_users'       => true,
			'hide_higher_min_rank'    => 4,
			'peer_management'         => true,
			'assign_unranked'         => false,
			'restrict_admins'         => false,
			'menu_rules'              => array_fill_keys( $staff, array( 'index.php', 'edit.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'tools.php', 'options-general.php', 'edit.php?post_type=acf-field-group' ) ),
			'adminbar_rules'          => array_fill_keys( $staff, array( 'comments', 'customize', 'updates', 'themes', 'widgets', 'dashboard', 'site-editor', 'menus', 'new-post', 'new-page', 'edit' ) ),
			'login_redirect'          => array_fill_keys( $staff, 'admin.php?page=epc' ),
			'public_name_roles'       => array( 'ep_developer', 'administrator' ),
			'public_name'             => '',
			'public_name_reveal_rank' => 4,
			'public_name_rest_hide'   => true,
		),
		'migration'
	);
	foreach ( array( 'ep_developer', 'ep_super_admin', 'ep_manager', 'ep_viewer' ) as $key ) {
		TMG_Roles_Role_Manager::save_as_defaults( $key );
	}
	$done['roles'] = time();
	WP_CLI::log( 'Roles, permissions and per-user overrides moved to Tamago Roles.' );
}

/* --------------------------------------------------------------------------
 * 4. Clean-up of what the theme no longer uses.
 * ----------------------------------------------------------------------- */
if ( empty( $done['cleanup'] ) && ! empty( $done['smtp'] ) && ! empty( $done['reports'] ) && ! empty( $done['roles'] ) ) {
	foreach ( array( 'epc_mail_from_name', 'epc_mail_from_email', 'epc_smtp_enable', 'epc_smtp_host', 'epc_smtp_secure', 'epc_smtp_port', 'epc_smtp_auth', 'epc_smtp_user', 'epc_smtp_pass', 'epc_track_enabled', 'epc_track_admins', 'epc_track_dnt', 'epc_geo_provider', 'epc_geo_token', 'epc_track_retention' ) as $name ) {
		delete_option( 'options_' . $name );
		delete_option( '_options_' . $name );
	}
	foreach ( array( 'epc_permissions', 'epc_roles_version', 'epc_db_version' ) as $name ) {
		delete_option( $name );
	}
	wp_clear_scheduled_hook( 'epc_analytics_cleanup' );
	$legacy = $wpdb->prefix . 'epc_visits';
	$copied = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TMG_Reports_Install::hits_table() ); // phpcs:ignore
	$old    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy}" ) : 0; // phpcs:ignore
	if ( $copied >= $old ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore
	} else {
		WP_CLI::warning( "Kept {$legacy}: it has more rows ({$old}) than Tamago Reports ({$copied})." );
	}
	$done['cleanup'] = time();
	WP_CLI::log( 'Old settings, capabilities and the epc_visits table removed.' );
}

update_option( 'epc_tamago_migrated', $done, false );
WP_CLI::success( 'Event Plus now uses the Tamago plugins.' );

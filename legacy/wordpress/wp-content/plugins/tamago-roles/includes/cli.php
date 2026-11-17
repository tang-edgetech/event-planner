<?php
/**
 * WP-CLI commands: wp tamago-roles list | permissions | grant | deny | settings export|import | safe-mode.
 * The command line acts as the site owner (no rank checks), but the Administrator role is still never reduced.
 *
 * Used by: WP-CLI.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage Tamago Roles from the command line.
 */
class TMG_Roles_CLI {

	/**
	 * List roles with rank, user count and capability count.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$counts = count_users();
		$rows   = array();
		foreach ( TMG_Roles_Ranks::sorted_roles() as $key => $label ) {
			$rows[] = array(
				'role'    => $key,
				'name'    => $label,
				'rank'    => TMG_Roles_Ranks::role_rank( $key ),
				'users'   => (int) ( $counts['avail_roles'][ $key ] ?? 0 ),
				'caps'    => count( array_filter( (array) get_role( $key )->capabilities ) ),
				'default' => get_option( 'default_role' ) === $key ? 'yes' : '',
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'role', 'name', 'rank', 'users', 'caps', 'default' ) );
	}

	/**
	 * List permissions (with the roles that hold them).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 */
	public function permissions( $args, $assoc ) {
		$rows = array();
		foreach ( TMG_Roles_Permissions::all() as $key => $p ) {
			$roles = array();
			foreach ( array_keys( wp_roles()->roles ) as $role ) {
				if ( TMG_Roles_Permissions::role_has( $role, $key ) ) {
					$roles[] = $role;
				}
			}
			$rows[] = array( 'permission' => $key, 'group' => $p['group'], 'label' => $p['label'], 'caps' => implode( ',', $p['caps'] ), 'roles' => implode( ',', $roles ) );
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'permission', 'group', 'label', 'caps', 'roles' ) );
	}

	/**
	 * Grant a permission (or a raw capability with --cap) to a role.
	 *
	 * ## OPTIONS
	 *
	 * <role>
	 * : Role key.
	 *
	 * <permission>
	 * : Permission key (see `wp tamago-roles permissions`), or a capability with --cap.
	 *
	 * [--cap]
	 * : Treat the second argument as a raw capability.
	 */
	public function grant( $args, $assoc ) {
		$this->change( $args, ! empty( $assoc['cap'] ), true );
	}

	/**
	 * Take a permission (or a raw capability with --cap) away from a role.
	 *
	 * ## OPTIONS
	 *
	 * <role>
	 * : Role key.
	 *
	 * <permission>
	 * : Permission key, or a capability with --cap.
	 *
	 * [--cap]
	 * : Treat the second argument as a raw capability.
	 */
	public function deny( $args, $assoc ) {
		$this->change( $args, ! empty( $assoc['cap'] ), false );
	}

	private function change( array $args, bool $raw, bool $grant ): void {
		list( $role, $perm ) = $args;
		$r                   = get_role( $role );
		if ( ! $r ) {
			WP_CLI::error( "Unknown role: {$role}" );
		}
		if ( ! $grant && 'administrator' === $role ) {
			WP_CLI::error( 'The Administrator role cannot be reduced.' );
		}
		if ( $raw ) {
			$caps          = (array) $r->capabilities;
			$caps[ $perm ] = true;
			if ( ! $grant ) {
				unset( $caps[ $perm ] );
			}
			TMG_Roles_Role_Manager::set_caps( $role, $caps, $grant ? 'grant' : 'deny' );
		} else {
			if ( ! TMG_Roles_Permissions::get( $perm ) ) {
				WP_CLI::error( "Unknown permission: {$perm}. See: wp tamago-roles permissions" );
			}
			$grant ? TMG_Roles_Role_Manager::grant( $role, $perm ) : TMG_Roles_Role_Manager::deny( $role, $perm );
		}
		WP_CLI::success( sprintf( '%s %s %s.', $grant ? 'Granted' : 'Denied', $perm, $grant ? "to {$role}" : "for {$role}" ) );
	}

	/**
	 * Export or import settings as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : export or import.
	 * ---
	 * options:
	 *   - export
	 *   - import
	 * ---
	 *
	 * [<file>]
	 * : File to write (export, default: stdout) or read (import).
	 *
	 * [--yes]
	 * : Apply an import without asking.
	 *
	 * [--dry-run]
	 * : Show what an import would change, change nothing.
	 */
	public function settings( $args, $assoc ) {
		$action = $args[0];
		$file   = $args[1] ?? '';
		if ( 'export' === $action ) {
			$json = TMG_Roles_Transfer::export_json();
			if ( '' === $file ) {
				WP_CLI::line( $json );
				return;
			}
			file_put_contents( $file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			TMG_Roles_Logger::log( 'export', 'settings', array( 'file' => basename( $file ) ) );
			WP_CLI::success( "Exported to {$file}" );
			return;
		}
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( 'Give a readable JSON file to import.' );
		}
		$data = TMG_Roles_Transfer::decode( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_wp_error( $data ) ) {
			WP_CLI::error( $data->get_error_message() );
		}
		$user = wp_get_current_user();
		$plan = TMG_Roles_Transfer::plan( $data, $user->exists() ? $user : null );
		foreach ( $plan['notes'] as $n ) {
			WP_CLI::warning( $n );
		}
		if ( ! $plan['changes'] ) {
			WP_CLI::success( 'Nothing to change.' );
			return;
		}
		foreach ( $plan['changes'] as $c ) {
			WP_CLI::line( '  ' . $c );
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			WP_CLI::success( count( $plan['changes'] ) . ' changes (dry run, nothing applied).' );
			return;
		}
		WP_CLI::confirm( 'Apply these ' . count( $plan['changes'] ) . ' changes?', $assoc );
		TMG_Roles_Transfer::apply( $plan );
		WP_CLI::success( 'Imported ' . count( $plan['changes'] ) . ' changes.' );
	}

	/**
	 * Explain how to recover when restrictions lock people out.
	 *
	 * @subcommand safe-mode
	 */
	public function safe_mode( $args, $assoc ) {
		WP_CLI::line( 'Safe mode is currently ' . ( TMG_Roles::safe_mode() ? 'ON' : 'off' ) . '.' );
		WP_CLI::line( '' );
		WP_CLI::line( 'To switch every Tamago Roles restriction off (ranks, protected capabilities, overrides,' );
		WP_CLI::line( 'menu / toolbar / dashboard rules, redirects, content access, staff privacy), add to wp-config.php:' );
		WP_CLI::line( '' );
		WP_CLI::line( "    define( 'TMG_ROLES_SAFE_MODE', true );" );
		WP_CLI::line( '' );
		WP_CLI::line( 'or run:  wp config set TMG_ROLES_SAFE_MODE true --raw' );
		WP_CLI::line( 'Then log in as an administrator, fix the settings in Roles & Permissions, and remove the line' );
		WP_CLI::line( '(wp config delete TMG_ROLES_SAFE_MODE). Role capabilities themselves are stored by WordPress and can be' );
		WP_CLI::line( 'repaired with: wp tamago-roles grant <role> <permission>   or   wp role reset <role>.' );
	}
}

WP_CLI::add_command( 'tamago-roles', 'TMG_Roles_CLI' );

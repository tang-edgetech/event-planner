<?php
/**
 * Settings JSON export / import: roles (names, ranks, capabilities), matrix, default role, protected
 * capabilities, menu / admin bar / widget rules, redirects, content defaults and term rules, privacy settings.
 * Import is planned first (a diff to confirm), then applied. It never removes the importer's own access.
 *
 * Used by: screen-settings.php (Export / Import box), cli.php (wp tamago-roles settings export|import), tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Roles_Transfer {

	/** Settings that are site-specific and never travel in a file. */
	const NOT_EXPORTED = array( 'delete_data', 'ranks' );

	public static function export(): array {
		$roles = array();
		foreach ( TMG_Roles_Ranks::sorted_roles() as $key => $label ) {
			$caps = (array) get_role( $key )->capabilities;
			ksort( $caps );
			$roles[ $key ] = array(
				'name' => wp_roles()->roles[ $key ]['name'],
				'rank' => 'administrator' === $key ? (int) ( TMG_Roles_Settings::get( 'ranks' )['administrator'] ?? TMG_Roles_Ranks::top() ) : TMG_Roles_Ranks::role_rank( $key ),
				'caps' => array_map( 'boolval', $caps ),
			);
		}
		$matrix = array();
		foreach ( array_keys( $roles ) as $role ) {
			foreach ( array_keys( TMG_Roles_Permissions::all() ) as $perm ) {
				if ( TMG_Roles_Permissions::role_has( $role, $perm ) ) {
					$matrix[ $role ][] = $perm;
				}
			}
		}
		$options = array();
		$saved   = get_option( TMG_Roles_Settings::OPTION, array() );
		$saved   = is_array( $saved ) ? array_merge( TMG_Roles_Settings::defaults(), $saved ) : TMG_Roles_Settings::defaults();
		foreach ( TMG_Roles_Settings::defaults() as $k => $d ) {
			if ( ! in_array( $k, self::NOT_EXPORTED, true ) ) {
				$options[ $k ] = $saved[ $k ];
			}
		}
		$terms = array();
		foreach ( TMG_Roles_Content_Access::term_rules() as $term_id => $rule ) {
			$term = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$terms[ $term->taxonomy . ':' . $term->slug ] = $rule;
			}
		}
		return array(
			'plugin'   => 'tamago-roles',
			'version'  => TMG_ROLES_VERSION,
			'exported' => gmdate( 'c' ),
			'settings' => array(
				'roles'        => $roles,
				'default_role' => (string) get_option( 'default_role' ),
				'matrix'       => $matrix,
				'options'      => $options,
				'term_rules'   => $terms,
			),
		);
	}

	public static function export_json(): string {
		return (string) wp_json_encode( self::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** @return array|WP_Error Decoded file. */
	public static function decode( string $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'tamago-roles' || ! is_array( $data['settings'] ?? null ) ) {
			return new WP_Error( 'tmg_roles_bad_file', __( 'This is not a Tamago Roles settings file.', 'tamago-roles' ) );
		}
		return $data;
	}

	/**
	 * Build the import plan: normalised data plus a human diff, with the importer's access protected.
	 *
	 * @param array        $data     Decoded file.
	 * @param WP_User|null $importer User running the import (null = WP-CLI / system).
	 * @return array { roles, default_role, options, term_rules, changes[], notes[] }
	 */
	public static function plan( array $data, ?WP_User $importer = null ): array {
		$in      = (array) $data['settings'];
		$plan    = array( 'roles' => array(), 'default_role' => '', 'options' => array(), 'term_rules' => array(), 'changes' => array(), 'notes' => array() );
		$current = TMG_Roles_Settings::all();

		// Roles.
		foreach ( (array) ( $in['roles'] ?? array() ) as $key => $r ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $r ) ) {
				continue;
			}
			$caps = array();
			foreach ( (array) ( $r['caps'] ?? array() ) as $cap => $grant ) {
				$cap = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $cap );
				if ( '' !== $cap ) {
					$caps[ $cap ] = (bool) $grant;
				}
			}
			$plan['roles'][ $key ] = array(
				'name' => sanitize_text_field( (string) ( $r['name'] ?? $key ) ),
				'rank' => max( 0, (int) ( $r['rank'] ?? 0 ) ),
				'caps' => $caps,
			);
		}
		if ( isset( $plan['roles']['administrator'] ) ) {
			// Administrator can never lose capabilities.
			$admin = get_role( 'administrator' );
			$lost  = $admin ? array_keys( array_diff_key( array_filter( $admin->capabilities ), array_filter( $plan['roles']['administrator']['caps'] ) ) ) : array();
			if ( $lost ) {
				$plan['notes'][] = sprintf( /* translators: count */ __( 'Administrator keeps %d capabilities the file does not list (the Administrator role cannot be reduced).', 'tamago-roles' ), count( $lost ) );
			}
		}

		$default = sanitize_key( (string) ( $in['default_role'] ?? '' ) );
		if ( '' !== $default && ( get_role( $default ) || isset( $plan['roles'][ $default ] ) ) ) {
			$plan['default_role'] = $default;
		}

		foreach ( (array) ( $in['options'] ?? array() ) as $k => $v ) {
			if ( array_key_exists( $k, TMG_Roles_Settings::defaults() ) && ! in_array( $k, self::NOT_EXPORTED, true ) ) {
				$plan['options'][ $k ] = TMG_Roles_Settings::sanitize( $k, $v );
			}
		}
		foreach ( (array) ( $in['term_rules'] ?? array() ) as $ref => $rule ) {
			$plan['term_rules'][ (string) $ref ] = TMG_Roles_Content_Access::sanitize_rule( (array) $rule );
		}

		self::protect_importer( $plan, $importer );
		$plan['changes'] = self::diff( $plan, $current );
		return $plan;
	}

	/** Keep the importer's access to this screen whatever the file says. */
	private static function protect_importer( array &$plan, ?WP_User $importer ): void {
		if ( ! $importer || ! $importer->exists() ) {
			return;
		}
		$roles = (array) $importer->roles;
		$is_admin = in_array( 'administrator', $roles, true ) || ( is_multisite() && is_super_admin( $importer->ID ) );

		// 1. One of their roles keeps the manage capability.
		if ( ! $is_admin ) {
			$keeps = false;
			foreach ( $roles as $role ) {
				$caps = isset( $plan['roles'][ $role ] ) ? $plan['roles'][ $role ]['caps'] : (array) ( get_role( $role ) ? get_role( $role )->capabilities : array() );
				if ( ! empty( $caps[ TMG_Roles::CAP_MANAGE ] ) ) {
					$keeps = true;
				}
			}
			if ( ! $keeps && $roles ) {
				$top = $roles[0];
				foreach ( $roles as $role ) {
					if ( TMG_Roles_Ranks::role_rank( $role ) > TMG_Roles_Ranks::role_rank( $top ) ) {
						$top = $role;
					}
				}
				if ( ! isset( $plan['roles'][ $top ] ) ) {
					$r                     = get_role( $top );
					$plan['roles'][ $top ] = array( 'name' => wp_roles()->roles[ $top ]['name'], 'rank' => TMG_Roles_Ranks::role_rank( $top ), 'caps' => $r ? (array) $r->capabilities : array() );
				}
				$plan['roles'][ $top ]['caps'][ TMG_Roles::CAP_MANAGE ] = true;
				$plan['notes'][] = sprintf( /* translators: role key */ __( 'Kept "Manage roles & permissions" on your role %s so you do not lock yourself out.', 'tamago-roles' ), $top );
			}

			// 2. Their rank does not drop.
			$old_rank = TMG_Roles_Ranks::user_rank( $importer );
			$new_rank = 0;
			foreach ( $roles as $role ) {
				$new_rank = max( $new_rank, isset( $plan['roles'][ $role ] ) ? $plan['roles'][ $role ]['rank'] : TMG_Roles_Ranks::role_rank( $role ) );
			}
			if ( $new_rank < $old_rank ) {
				foreach ( $roles as $role ) {
					if ( isset( $plan['roles'][ $role ] ) && TMG_Roles_Ranks::role_rank( $role ) === $old_rank ) {
						$plan['roles'][ $role ]['rank'] = $old_rank;
					}
				}
				$plan['notes'][] = __( 'Kept the rank of your own role so you do not lose control over other roles.', 'tamago-roles' );
			}
		}

		// 3. The plugin's caps are not protected away from them.
		$min  = (int) ( $plan['options']['protected_min_rank'] ?? TMG_Roles_Settings::get( 'protected_min_rank' ) );
		$prot = (array) ( $plan['options']['protected_caps'] ?? TMG_Roles_Settings::get( 'protected_caps' ) );
		if ( ! $is_admin && $min > TMG_Roles_Ranks::user_rank( $importer ) && array_intersect( $prot, array( TMG_Roles::CAP_MANAGE, TMG_Roles::CAP_MANAGE_USERS ) ) ) {
			$plan['options']['protected_caps'] = array_values( array_diff( $prot, array( TMG_Roles::CAP_MANAGE, TMG_Roles::CAP_MANAGE_USERS ) ) );
			$plan['notes'][]                   = __( 'Removed this plugin\'s capabilities from the protected list so you keep access.', 'tamago-roles' );
		}

		// 4. The screen stays in their menu, and wp-admin stays open to them.
		$ids = TMG_Roles_Admin_Screen::menu_ids();
		if ( isset( $plan['options']['menu_rules'] ) ) {
			foreach ( $roles as $role ) {
				if ( isset( $plan['options']['menu_rules'][ $role ] ) && array_intersect( $ids, $plan['options']['menu_rules'][ $role ] ) ) {
					$plan['options']['menu_rules'][ $role ] = array_values( array_diff( $plan['options']['menu_rules'][ $role ], $ids ) );
					$plan['notes'][]                        = sprintf( /* translators: role key */ __( 'Kept Roles & Permissions in the menu of your role %s.', 'tamago-roles' ), $role );
				}
			}
		}
		if ( isset( $plan['options']['no_admin'] ) && array_intersect( $roles, $plan['options']['no_admin'] ) ) {
			$plan['options']['no_admin'] = array_values( array_diff( $plan['options']['no_admin'], $roles ) );
			$plan['notes'][]             = __( 'Kept wp-admin access for your own roles.', 'tamago-roles' );
		}
	}

	private static function short( $v ): string {
		$s = is_scalar( $v ) ? var_export( $v, true ) : (string) wp_json_encode( $v );
		return strlen( $s ) > 160 ? substr( $s, 0, 157 ) . '…' : $s;
	}

	/** Human-readable list of what the plan changes. */
	public static function diff( array $plan, array $current ): array {
		$out = array();
		foreach ( $plan['roles'] as $key => $r ) {
			$role = get_role( $key );
			if ( ! $role ) {
				$out[] = sprintf( 'Role %s: create "%s", rank %d, %d capabilities', $key, $r['name'], $r['rank'], count( array_filter( $r['caps'] ) ) );
				continue;
			}
			$name = wp_roles()->roles[ $key ]['name'];
			if ( $name !== $r['name'] ) {
				$out[] = sprintf( 'Role %s: rename "%s" → "%s"', $key, $name, $r['name'] );
			}
			$rank = 'administrator' === $key ? (int) ( $current['ranks']['administrator'] ?? TMG_Roles_Ranks::top() ) : TMG_Roles_Ranks::role_rank( $key );
			if ( $rank !== $r['rank'] ) {
				$out[] = sprintf( 'Role %s: rank %d → %d', $key, $rank, $r['rank'] );
			}
			$old   = array_filter( (array) $role->capabilities );
			$new   = array_filter( $r['caps'] );
			$plus  = array_keys( array_diff_key( $new, $old ) );
			$minus = 'administrator' === $key ? array() : array_keys( array_diff_key( $old, $new ) );
			if ( $plus ) {
				$out[] = sprintf( 'Role %s: + %s', $key, implode( ', ', $plus ) );
			}
			if ( $minus ) {
				$out[] = sprintf( 'Role %s: − %s', $key, implode( ', ', $minus ) );
			}
		}
		if ( $plan['default_role'] && get_option( 'default_role' ) !== $plan['default_role'] ) {
			$out[] = sprintf( 'Default role: %s → %s', get_option( 'default_role' ), $plan['default_role'] );
		}
		foreach ( $plan['options'] as $k => $v ) {
			if ( TMG_Roles_Settings::is_locked( $k ) ) {
				continue;
			}
			if ( ( $current[ $k ] ?? null ) !== $v ) {
				$out[] = sprintf( 'Setting %s: %s → %s', $k, self::short( $current[ $k ] ?? null ), self::short( $v ) );
			}
		}
		foreach ( $plan['term_rules'] as $ref => $rule ) {
			$term = self::find_term( $ref );
			if ( ! $term ) {
				$out[] = sprintf( 'Term rule %s: term not found on this site (skipped)', $ref );
			} elseif ( TMG_Roles_Content_Access::term_rule( $term->term_id ) !== $rule ) {
				$out[] = sprintf( 'Term rule %s: %s', $ref, self::short( array( $rule['mode'], $rule['roles'], $rule['action'] ) ) );
			}
		}
		return $out;
	}

	private static function find_term( string $ref ): ?WP_Term {
		$parts = explode( ':', $ref, 2 );
		if ( 2 !== count( $parts ) || ! taxonomy_exists( $parts[0] ) ) {
			return null;
		}
		$term = get_term_by( 'slug', $parts[1], $parts[0] );
		return $term instanceof WP_Term ? $term : null;
	}

	/** Apply a plan from plan(). */
	public static function apply( array $plan ): void {
		$ranks = (array) TMG_Roles_Settings::get( 'ranks' );
		foreach ( $plan['roles'] as $key => $r ) {
			$ranks[ $key ] = $r['rank'];
		}
		TMG_Roles_Settings::update( array( 'ranks' => $ranks ), 'import_ranks' );
		foreach ( $plan['roles'] as $key => $r ) {
			if ( ! get_role( $key ) ) {
				TMG_Roles_Role_Manager::create( $key, $r['name'], $r['rank'], $r['caps'], 'import_role_created' );
			} else {
				TMG_Roles_Role_Manager::rename( $key, $r['name'] );
				TMG_Roles_Role_Manager::set_caps( $key, $r['caps'], 'import_role_caps' );
			}
		}
		if ( $plan['default_role'] ) {
			TMG_Roles_Role_Manager::set_default_role( $plan['default_role'] );
		}
		if ( $plan['options'] ) {
			TMG_Roles_Settings::update( $plan['options'], 'import_settings' );
		}
		foreach ( $plan['term_rules'] as $ref => $rule ) {
			$term = self::find_term( $ref );
			if ( $term ) {
				TMG_Roles_Content_Admin::store( 'term', $term->term_id, $rule );
			}
		}
		TMG_Roles_Capabilities::flush();
		TMG_Roles_Logger::log( 'import', 'settings', array( 'changes' => count( $plan['changes'] ), 'notes' => $plan['notes'] ) );
	}
}
